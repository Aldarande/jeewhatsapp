/**
 * JeeWhatsApp — Daemon Node.js (ESM)
 * Utilise Baileys pour une connexion directe à WhatsApp Web.
 * Aucune donnée ne transite par un serveur tiers.
 * Mode canal groupe : Jeedom communique via un groupe WhatsApp dédié.
 */

import makeWASocket, {
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
  isJidGroup,
} from '@whiskeysockets/baileys';
import pino          from 'pino';
import QRCode        from 'qrcode';
import http          from 'http';
import fs            from 'fs';
import path          from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// ---------------------------------------------------------------------------
// Arguments CLI
// ---------------------------------------------------------------------------

function parseArgs(argv) {
  const result = {};
  for (let i = 0; i < argv.length; i += 2) {
    result[argv[i].replace(/^--/, '')] = argv[i + 1];
  }
  return result;
}

const args = parseArgs(process.argv.slice(2));

const PORT         = parseInt(args['port'] || 55148, 10);
const CALLBACK_URL = args['callback'];
const PID_FILE     = args['pid-file'];
const LOG_FILE     = args['log-file'] || '';
const DEBUG        = args['debug'] === 'true' || args['debug'] === '1';
// SECURITY (F-001): API key lue depuis l'environnement, jamais en argument CLI
const API_KEY      = process.env.JEEDOM_APIKEY || '';
// On efface immédiatement la variable du process pour éviter qu'un dump éventuel ne la révèle
delete process.env.JEEDOM_APIKEY;

let instances = [];
try {
  instances = JSON.parse(args['instances'] || '[]');
} catch (e) {
  logMsg('error', 'Impossible de parser --instances : ' + e.message);
  process.exit(1);
}

// ---------------------------------------------------------------------------
// Logger
// ---------------------------------------------------------------------------

function logMsg(level, msg) {
  const line = '[' + new Date().toISOString() + '] [' + level.toUpperCase() + '] ' + msg + '\n';
  process.stdout.write(line);
}

function debug(msg) { if (DEBUG) { logMsg('debug', msg); } }

// Logger silencieux pour Baileys (très verbeux par défaut)
const baileysLogger = pino({ level: 'silent' });

// ---------------------------------------------------------------------------
// PID file
// ---------------------------------------------------------------------------

if (PID_FILE) {
  const dir = path.dirname(PID_FILE);
  if (!fs.existsSync(dir)) { fs.mkdirSync(dir, { recursive: true }); }
  fs.writeFileSync(PID_FILE, String(process.pid));
}

let running = true;
process.on('SIGTERM', () => shutdown());
process.on('SIGINT',  () => shutdown());

// Empêche le crash du daemon sur les rejets de promesse non gérés (ex: code 1006 WebSocket)
process.on('unhandledRejection', (reason) => {
  logMsg('warning', 'Rejet non géré (daemon maintenu) : ' + String(reason));
});

function shutdown() {
  logMsg('info', 'Arrêt du daemon JeeWhatsApp');
  running = false;
  if (PID_FILE && fs.existsSync(PID_FILE)) { fs.unlinkSync(PID_FILE); }
  process.exit(0);
}

// ---------------------------------------------------------------------------
// Répertoires auth (un sous-dossier par équipement Jeedom)
// SECURITY: credentials Baileys sensibles — permissions strictes 0700 (CWE-732)
// ---------------------------------------------------------------------------

// umask 0o077 pour que tout fichier créé soit 0600 par défaut
process.umask(0o077);

const AUTH_BASE = path.join(__dirname, 'auth');
if (!fs.existsSync(AUTH_BASE)) { fs.mkdirSync(AUTH_BASE, { recursive: true, mode: 0o700 }); }
try { fs.chmodSync(AUTH_BASE, 0o700); } catch (_) {}

function authDir(id) {
  // SECURITY: instance_id doit être strictement numérique pour éviter path traversal (CWE-22)
  if (!/^\d+$/.test(String(id))) {
    throw new Error('jeewhatsappd.js::authDir() — instance_id invalide : ' + id);
  }
  const dir = path.join(AUTH_BASE, String(id));
  const resolved = path.resolve(dir);
  if (!resolved.startsWith(path.resolve(AUTH_BASE) + path.sep)) {
    throw new Error('jeewhatsappd.js::authDir() — path traversal détecté pour id=' + id);
  }
  if (!fs.existsSync(dir)) { fs.mkdirSync(dir, { recursive: true, mode: 0o700 }); }
  try { fs.chmodSync(dir, 0o700); } catch (_) {}
  return dir;
}
function qrFilePath(id)               { return path.join(authDir(id), 'qr.txt'); }
function statusFilePath(id)           { return path.join(authDir(id), 'status.txt'); }
function groupJidFilePath(id)         { return path.join(authDir(id), 'group_jid.txt'); }
function connectedSinceFilePath(id)   { return path.join(authDir(id), 'connected_since.txt'); }

function writeStatus(id, status) {
  try { fs.writeFileSync(statusFilePath(id), status); } catch (_) {}
}

// ---------------------------------------------------------------------------
// Extraction de texte — déroule les wrappers WhatsApp (éphémère, viewOnce…)
// ---------------------------------------------------------------------------

function extractText(m) {
  if (!m) { return ''; }
  return m.conversation
      || m.extendedTextMessage?.text
      || extractText(m.ephemeralMessage?.message)
      || extractText(m.viewOnceMessage?.message)
      || extractText(m.viewOnceMessageV2?.message)
      || '';
}

// ---------------------------------------------------------------------------
// État par instance
// ---------------------------------------------------------------------------

const sockets         = {};  // id → sock
const groupJids       = {};  // id → JID du groupe WhatsApp canal (@g.us)
const instanceCfg     = {};  // id → { prefix, group_name }
const lastIncomingMsg = {};  // id → dernier msg reçu (pour reply quoted)

// ---------------------------------------------------------------------------
// Recherche d'un groupe par nom
// ---------------------------------------------------------------------------

async function findGroupByName(sock, name) {
  const target = String(name || '').trim();
  try {
    const groups = await sock.groupFetchAllParticipating();
    for (const [jid, meta] of Object.entries(groups)) {
      // Trim sur subject : un espace invisible casserait le matching exact
      if ((meta.subject || '').trim() === target) { return jid; }
    }
  } catch (e) {
    logMsg('warning', 'findGroupByName — erreur : ' + e.message);
  }
  return null;
}

// ---------------------------------------------------------------------------
// Connexion Baileys par instance
// ---------------------------------------------------------------------------

async function connectInstance(instance) {
  const id         = String(instance.id);
  const prefix     = instance.prefix     || '';
  const group_name = instance.group_name || 'jeewhatsapp';

  instanceCfg[id] = { prefix, group_name };
  writeStatus(id, 'connecting');

  // Charger le JID de groupe depuis le cache fichier
  const gjf = groupJidFilePath(id);
  if (fs.existsSync(gjf)) {
    const cached = fs.readFileSync(gjf, 'utf8').trim();
    if (cached) {
      groupJids[id] = cached;
      debug('[' + id + '] JID groupe chargé (cache) : ' + cached);
    }
  }

  const { state, saveCreds } = await useMultiFileAuthState(authDir(id));
  const { version }          = await fetchLatestBaileysVersion();

  const sock = makeWASocket({
    version,
    auth:              state,
    logger:            baileysLogger,
    printQRInTerminal: false,
    browser:           ['JeeWhatsApp', 'Chrome', '1.0.0'],
    generateHighQualityLinkPreview: false,
  });

  sockets[id] = sock;
  sock.ev.on('creds.update', saveCreds);

  // ── Événements de connexion ────────────────────────────────────────────
  sock.ev.on('connection.update', async (update) => {
    const { connection, lastDisconnect, qr } = update;

    if (qr) {
      try {
        const dataUrl = await QRCode.toDataURL(qr);
        fs.writeFileSync(qrFilePath(id), dataUrl);
        writeStatus(id, 'qr_pending');
        logMsg('info', '[' + id + '] QR code prêt — scanner avec WhatsApp');
      } catch (e) {
        logMsg('error', '[' + id + '] Erreur QR : ' + e.message);
      }
    }

    if (connection === 'open') {
      writeStatus(id, 'connected');
      const qf = qrFilePath(id);
      if (fs.existsSync(qf)) { fs.unlinkSync(qf); }
      // Mémorise la date de connexion (exposée via getStatus pour cmd info connected_since)
      try { fs.writeFileSync(connectedSinceFilePath(id), new Date().toISOString()); } catch (_) {}
      logMsg('info', '[' + id + '] ✓ Connecté à WhatsApp');

      // Rechercher le groupe canal — retardé de 2 s pour laisser à Baileys
      // le temps de synchroniser la liste des groupes après l'open
      setTimeout(async () => {
        logMsg('info', '[' + id + '] Recherche du groupe canal "' + group_name + '"…');
        let jid = await findGroupByName(sock, group_name);
        if (!jid) {
          // Retry unique 3 s plus tard si rien trouvé (sync incomplète)
          await new Promise(r => setTimeout(r, 3000));
          jid = await findGroupByName(sock, group_name);
        }
        if (jid) {
          groupJids[id] = jid;
          try { fs.writeFileSync(gjf, jid); } catch (_) {}
          logMsg('info', '[' + id + '] ✓ Groupe "' + group_name + '" → ' + jid);
        } else {
          logMsg('warning', '[' + id + '] Groupe "' + group_name + '" introuvable — créez-le ou vérifiez le nom dans les paramètres');
        }
      }, 2000);
    }

    if (connection === 'close') {
      const code      = lastDisconnect?.error?.output?.statusCode;
      const loggedOut = code === DisconnectReason.loggedOut;
      // Cleanup des listeners pour éviter MaxListenersExceededWarning à chaque reconnexion
      try { sock.ev.removeAllListeners(); } catch (_) {}
      delete sockets[id];

      if (loggedOut) {
        logMsg('warning', '[' + id + '] Déconnecté (logout) — session supprimée');
        writeStatus(id, 'logged_out');
        const dir = authDir(id);
        for (const f of fs.readdirSync(dir)) {
          if (f !== 'status.txt') {
            try { fs.unlinkSync(path.join(dir, f)); } catch (_) {}
          }
        }
        delete groupJids[id];
      } else {
        logMsg('info', '[' + id + '] Connexion perdue (code ' + code + ') — reconnexion dans 5s');
        writeStatus(id, 'reconnecting');
        if (running) { setTimeout(() => connectInstance(instance), 5000); }
      }
    }
  });

  // ── Réception des messages du groupe canal ─────────────────────────────
  sock.ev.on('messages.upsert', async ({ messages, type }) => {
    // type 'append' = historique de synchronisation au démarrage, on l'ignore
    if (type !== 'notify') { return; }

    for (const msg of messages) {
      const remoteJid = msg.key.remoteJid || '';

      // Accepter uniquement les messages du groupe canal
      if (!isJidGroup(remoteJid)) {
        debug('[' + id + '] Ignoré (message direct, mode groupe actif)');
        continue;
      }

      const configuredJid = groupJids[id];
      if (!configuredJid) {
        debug('[' + id + '] Ignoré (groupe canal non configuré)');
        continue;
      }

      if (remoteJid !== configuredJid) {
        debug('[' + id + '] Ignoré (groupe ' + remoteJid + ' ≠ canal ' + configuredJid + ')');
        continue;
      }

      // Ignorer les messages envoyés par Jeedom lui-même
      if (msg.key.fromMe) {
        debug('[' + id + '] Ignoré (fromMe — message de Jeedom)');
        continue;
      }

      if (!msg.message) {
        debug('[' + id + '] Ignoré (message vide)');
        continue;
      }

      // Dans un groupe, msg.key.participant = JID de l'expéditeur
      const participantJid = msg.key.participant || remoteJid;
      const sender         = participantJid.replace('@s.whatsapp.net', '').replace('@g.us', '');
      const senderName     = msg.pushName || sender;
      const ts             = new Date().toISOString().replace('T', ' ').substring(0, 19);

      // ── Détection des réactions emoji (v0.2) ──────────────────────────────
      // Une réaction est un message dont le contenu est reactionMessage.
      // On envoie un payload de type 'reaction' au callback Jeedom — la classe
      // PHP distingue via le champ 'event_type' pour mettre à jour les cmds info
      // last_reaction et last_reaction_from sans toucher à last_message.
      if (msg.message.reactionMessage) {
        const r = msg.message.reactionMessage;
        const emoji = r.text || '';
        logMsg('info', '[' + id + '] Réaction ' + (emoji || '(vide=suppression)') + ' de ' + sender);
        await sendCallback(id, {
          event_type:  'reaction',
          reaction:    emoji,
          sender,
          sender_name: senderName,
          received_at: ts,
        });
        continue;
      }

      const text = extractText(msg.message);
      if (!text) {
        debug('[' + id + '] Ignoré (pas de texte) — types : ' + Object.keys(msg.message).join(', '));
        continue;
      }

      // Mémorise le dernier message reçu — utilisé par actions 'replyLast' et 'reactLast'
      lastIncomingMsg[id] = msg;

      logMsg('info', '[' + id + '] Message de ' + sender + ' (groupe) : ' + text.substring(0, 60));
      await sendCallback(id, {
        message:     text,
        sender,
        sender_name: senderName,
        received_at: ts,
      });
    }
  });
}

// ---------------------------------------------------------------------------
// Callback vers Jeedom
// ---------------------------------------------------------------------------

function sendCallback(instanceId, data) {
  return new Promise((resolve) => {
    if (!CALLBACK_URL) { resolve(); return; }
    const payload = JSON.stringify(Object.assign({ instance_id: instanceId }, data));
    const u       = new URL(CALLBACK_URL);
    const options = {
      hostname: u.hostname,
      port:     u.port || 80,
      path:     u.pathname + u.search,
      method:   'POST',
      headers:  {
        'Content-Type':   'application/json',
        'Content-Length': Buffer.byteLength(payload),
        // SECURITY (F-001/F-006): API key en header au lieu de query string (évite logs Apache)
        'X-API-Key':      API_KEY,
      },
    };
    const req = http.request(options, (res) => { res.resume(); debug('Callback HTTP ' + res.statusCode); resolve(); });
    req.on('error', (e) => { logMsg('error', 'Callback Jeedom : ' + e.message); resolve(); });
    req.setTimeout(30000, () => { req.destroy(); resolve(); });
    req.write(payload);
    req.end();
  });
}

// ---------------------------------------------------------------------------
// Serveur HTTP local — commandes depuis PHP
// ---------------------------------------------------------------------------

http.createServer(async (req, res) => {
  if (req.method !== 'POST' || req.url !== '/action') {
    res.writeHead(404); res.end(JSON.stringify({ error: 'Not found' })); return;
  }
  let body = '';
  req.on('data', chunk => { body += chunk; });
  req.on('end', async () => {
    let payload;
    try { payload = JSON.parse(body); }
    catch (e) { res.writeHead(400); res.end(JSON.stringify({ error: 'Invalid JSON' })); return; }

    debug('Action : ' + JSON.stringify(payload));
    try {
      const result = await handleAction(payload);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ status: 'ok', result }));
    } catch (e) {
      logMsg('error', 'Action [' + payload?.action + '] : ' + e.message);
      res.writeHead(500);
      res.end(JSON.stringify({ error: e.message }));
    }
  });
}).listen(PORT, '127.0.0.1', () => {
  logMsg('info', 'Daemon démarré — port ' + PORT + ', ' + instances.length + ' instance(s)');
});

// ---------------------------------------------------------------------------
// Détection du type de média via extension de fichier
// ---------------------------------------------------------------------------

const MEDIA_EXT = {
  // Images
  jpg: { kind: 'image',    mime: 'image/jpeg' },
  jpeg:{ kind: 'image',    mime: 'image/jpeg' },
  png: { kind: 'image',    mime: 'image/png'  },
  gif: { kind: 'image',    mime: 'image/gif'  },
  webp:{ kind: 'image',    mime: 'image/webp' },
  bmp: { kind: 'image',    mime: 'image/bmp'  },
  // Vidéos
  mp4: { kind: 'video',    mime: 'video/mp4'  },
  mov: { kind: 'video',    mime: 'video/quicktime' },
  webm:{ kind: 'video',    mime: 'video/webm' },
  '3gp':{ kind: 'video',   mime: 'video/3gpp' },
  // Audio (.ogg/.opus = note vocale, .mp3/.m4a = audio normal)
  ogg: { kind: 'audio',    mime: 'audio/ogg; codecs=opus', ptt: true },
  opus:{ kind: 'audio',    mime: 'audio/ogg; codecs=opus', ptt: true },
  mp3: { kind: 'audio',    mime: 'audio/mpeg' },
  m4a: { kind: 'audio',    mime: 'audio/mp4'  },
  aac: { kind: 'audio',    mime: 'audio/aac'  },
  wav: { kind: 'audio',    mime: 'audio/wav'  },
  // Documents
  pdf: { kind: 'document', mime: 'application/pdf' },
  doc: { kind: 'document', mime: 'application/msword' },
  docx:{ kind: 'document', mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' },
  xls: { kind: 'document', mime: 'application/vnd.ms-excel' },
  xlsx:{ kind: 'document', mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
  ppt: { kind: 'document', mime: 'application/vnd.ms-powerpoint' },
  pptx:{ kind: 'document', mime: 'application/vnd.openxmlformats-officedocument.presentationml.presentation' },
  txt: { kind: 'document', mime: 'text/plain' },
  csv: { kind: 'document', mime: 'text/csv'  },
  zip: { kind: 'document', mime: 'application/zip' },
};

const MEDIA_MAX_SIZE = 100 * 1024 * 1024; // 100 MB — limite WhatsApp vidéo

function detectMedia(filePath) {
  const ext = path.extname(filePath).slice(1).toLowerCase();
  const info = MEDIA_EXT[ext];
  if (!info) {
    throw new Error('Type de fichier non supporté (.' + ext + ') — ' +
      'extensions autorisées : ' + Object.keys(MEDIA_EXT).join(', '));
  }
  return info;
}

function buildMediaPayload(filePath, caption) {
  const info     = detectMedia(filePath);
  const buffer   = fs.readFileSync(filePath);
  const fileName = path.basename(filePath);
  const payload  = {};

  switch (info.kind) {
    case 'image':
      payload.image   = buffer;
      if (caption) { payload.caption = caption; }
      break;
    case 'video':
      payload.video   = buffer;
      payload.mimetype= info.mime;
      if (caption) { payload.caption = caption; }
      break;
    case 'audio':
      payload.audio    = buffer;
      payload.mimetype = info.mime;
      payload.ptt      = !!info.ptt;
      // Audio n'accepte pas de caption — la légende sera envoyée séparément
      break;
    case 'document':
      payload.document = buffer;
      payload.mimetype = info.mime;
      payload.fileName = fileName;
      if (caption) { payload.caption = caption; }
      break;
  }
  return { payload, kind: info.kind };
}

// Résolution du JID destinataire — facteur commun à toutes les actions d'envoi
function resolveJid(id, phone) {
  if (!phone || phone === '' || phone === 'group') {
    const jid = groupJids[id];
    if (!jid) {
      throw new Error('Groupe canal non configuré — recherchez ou créez le groupe dans les paramètres de l\'équipement');
    }
    return jid;
  }
  if (String(phone).includes('@')) {
    return String(phone).trim();
  }
  const digits = String(phone).replace(/\D/g, '').replace(/^0([67]\d{8})$/, '33$1');
  if (!digits) { throw new Error('Numéro de téléphone invalide (' + phone + ')'); }
  return digits + '@s.whatsapp.net';
}

async function handleAction({ action, instance_id, phone, message, mention, media_path,
                              latitude, longitude, location_name,
                              contact_phone, contact_name }) {
  const id = String(instance_id);

  switch (action) {

    // ── Envoi d'un message (vers le groupe canal ou un destinataire direct) ─
    case 'send': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }

      let jid;
      if (!phone || phone === '' || phone === 'group') {
        jid = groupJids[id];
        if (!jid) {
          throw new Error('Groupe canal non configuré — recherchez ou créez le groupe dans les paramètres de l\'équipement');
        }
      } else if (String(phone).includes('@')) {
        // JID complet fourni directement (ex : 33612345678@s.whatsapp.net ou 120363…@g.us)
        jid = String(phone).trim();
      } else {
        // Normalisation : supprime les non-chiffres et convertit le format français 06/07 → 336/337
        const digits = String(phone).replace(/\D/g, '').replace(/^0([67]\d{8})$/, '33$1');
        if (!digits) { throw new Error('Numéro de téléphone invalide (' + phone + ') — format attendu : indicatif + numéro, ex : 33612345678'); }
        jid = digits + '@s.whatsapp.net';
      }

      // Mention optionnelle — @numéro en tête de message
      const msgPayload = { text: message };
      if (mention) {
        // Même normalisation que pour le destinataire : 06… → 336…
        const mentionDigits = String(mention).replace(/\D/g, '').replace(/^0([67]\d{8})$/, '33$1');
        if (mentionDigits) {
          const mentionJid    = mentionDigits + '@s.whatsapp.net';
          // Préfixe @numéro dans le texte + champ mentions pour que WhatsApp génère la notification
          msgPayload.text     = '@' + mentionDigits + ' ' + message;
          msgPayload.mentions = [mentionJid];
          logMsg('info', '[' + id + '] Mention de ' + mentionJid);
        }
      }

      logMsg('info', '[' + id + '] → ' + jid + ' : ' + msgPayload.text.substring(0, 60));
      const sendTimeout = new Promise((_, reject) =>
        setTimeout(() => reject(new Error('Envoi échoué — délai dépassé pour ' + jid)), 10000)
      );
      await Promise.race([sock.sendMessage(jid, msgPayload), sendTimeout]);
      return { sent: true };
    }

    // ── Envoi d'une position GPS ───────────────────────────────────────────
    case 'sendLocation': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      const lat  = parseFloat(latitude);
      const lng  = parseFloat(longitude);
      if (Number.isNaN(lat) || Number.isNaN(lng)) {
        throw new Error('latitude/longitude invalides (reçu lat=' + latitude + ', long=' + longitude + ')');
      }
      if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
        throw new Error('Coordonnées GPS hors plage (lat ∈ [-90,90], long ∈ [-180,180])');
      }
      const jid = resolveJid(id, phone);
      const loc = { degreesLatitude: lat, degreesLongitude: lng };
      if (location_name && String(location_name).trim() !== '') {
        loc.name = String(location_name).trim();
      }
      logMsg('info', '[' + id + '] → ' + jid + ' : 📍 ' + lat + ',' + lng + (loc.name ? ' (' + loc.name + ')' : ''));
      const t = new Promise((_, r) => setTimeout(() => r(new Error('Envoi location — délai dépassé')), 10000));
      await Promise.race([sock.sendMessage(jid, { location: loc }), t]);
      return { sent: true, latitude: lat, longitude: lng, name: loc.name || null };
    }

    // ── Envoi d'une carte de contact (vCard) ───────────────────────────────
    case 'sendContact': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      if (!contact_phone || String(contact_phone).trim() === '') {
        throw new Error('contact_phone obligatoire');
      }
      const cName  = (contact_name && String(contact_name).trim() !== '')
                     ? String(contact_name).trim() : String(contact_phone);
      const cDigits= String(contact_phone).replace(/\D/g, '').replace(/^0([67]\d{8})$/, '33$1');
      if (!cDigits) { throw new Error('contact_phone invalide : ' + contact_phone); }
      const vcard =
        'BEGIN:VCARD\n' +
        'VERSION:3.0\n' +
        'FN:' + cName + '\n' +
        'TEL;type=CELL;type=VOICE;waid=' + cDigits + ':+' + cDigits + '\n' +
        'END:VCARD';
      const jid = resolveJid(id, phone);
      logMsg('info', '[' + id + '] → ' + jid + ' : 👤 contact ' + cName + ' (' + cDigits + ')');
      const t = new Promise((_, r) => setTimeout(() => r(new Error('Envoi contact — délai dépassé')), 10000));
      await Promise.race([
        sock.sendMessage(jid, { contacts: { displayName: cName, contacts: [{ vcard }] } }),
        t,
      ]);
      return { sent: true, name: cName, phone: cDigits };
    }

    // ── Envoi d'un média (image/vidéo/audio/document) ──────────────────────
    case 'sendMedia': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }

      // SECURITY : path est obligatoire, doit pointer vers un fichier existant et lisible
      if (!media_path || typeof media_path !== 'string') {
        throw new Error('Paramètre media_path obligatoire (chemin absolu du fichier)');
      }
      if (!path.isAbsolute(media_path)) {
        throw new Error('media_path doit être un chemin absolu : ' + media_path);
      }
      if (!fs.existsSync(media_path)) {
        throw new Error('Fichier introuvable : ' + media_path);
      }
      const stat = fs.statSync(media_path);
      if (!stat.isFile()) {
        throw new Error('Le chemin n\'est pas un fichier : ' + media_path);
      }
      if (stat.size === 0) {
        throw new Error('Fichier vide : ' + media_path);
      }
      if (stat.size > MEDIA_MAX_SIZE) {
        throw new Error('Fichier trop volumineux (' + Math.round(stat.size / 1024 / 1024) + 'MB) — max 100MB');
      }

      // Destinataire (même logique que 'send')
      let jid;
      if (!phone || phone === '' || phone === 'group') {
        jid = groupJids[id];
        if (!jid) {
          throw new Error('Groupe canal non configuré — recherchez ou créez le groupe dans les paramètres de l\'équipement');
        }
      } else if (String(phone).includes('@')) {
        jid = String(phone).trim();
      } else {
        const digits = String(phone).replace(/\D/g, '').replace(/^0([67]\d{8})$/, '33$1');
        if (!digits) { throw new Error('Numéro de téléphone invalide (' + phone + ')'); }
        jid = digits + '@s.whatsapp.net';
      }

      const { payload, kind } = buildMediaPayload(media_path, message || '');

      logMsg('info', '[' + id + '] → ' + jid + ' : [' + kind + '] ' + path.basename(media_path)
        + ' (' + Math.round(stat.size / 1024) + 'KB)' + (message ? ' caption="' + String(message).substring(0, 40) + '"' : ''));

      // Timeout 60s pour les médias (upload plus long que texte)
      const sendTimeout = new Promise((_, reject) =>
        setTimeout(() => reject(new Error('Envoi média échoué — délai dépassé (60s) pour ' + jid)), 60000)
      );
      await Promise.race([sock.sendMessage(jid, payload), sendTimeout]);

      // Audio : pas de caption native — envoyer un message texte séparé si fourni
      if (kind === 'audio' && message && String(message).trim() !== '') {
        await sock.sendMessage(jid, { text: message });
      }

      return { sent: true, kind, file: path.basename(media_path), bytes: stat.size };
    }

    // ── Réaction emoji sur le dernier message reçu (v0.2) ──────────────────
    case 'reactLast': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      const target = lastIncomingMsg[id];
      if (!target) { throw new Error('Aucun message à réagir — attendez d\'avoir reçu un message dans le groupe canal'); }
      const emoji = String(message || '').trim();
      if (emoji === '') { throw new Error('Emoji obligatoire (champ message, ex: ❤️ 👍 🎉)'); }
      const jid = target.key.remoteJid;
      logMsg('info', '[' + id + '] ↪ React ' + emoji + ' → ' + jid);
      const t = new Promise((_, r) => setTimeout(() => r(new Error('Envoi réaction — délai dépassé')), 10000));
      await Promise.race([
        sock.sendMessage(jid, { react: { text: emoji, key: target.key } }),
        t,
      ]);
      return { sent: true, emoji, target: target.key.id };
    }

    // ── Réponse "quoted" au dernier message reçu dans le groupe canal ──────
    case 'replyLast': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      const jid = groupJids[id];
      if (!jid) {
        throw new Error('Groupe canal non configuré — recherchez ou créez le groupe dans les paramètres de l\'équipement');
      }
      const quoted = lastIncomingMsg[id];
      if (!quoted) {
        // Fallback : envoi simple si pas de message à citer
        logMsg('warning', '[' + id + '] replyLast — aucun message à citer, envoi simple');
        const t = new Promise((_, r) => setTimeout(() => r(new Error('Envoi échoué — délai dépassé')), 10000));
        await Promise.race([sock.sendMessage(jid, { text: message }), t]);
        return { sent: true, quoted: false };
      }
      logMsg('info', '[' + id + '] ↩ Reply (quoted) → ' + jid + ' : ' + String(message).substring(0, 60));
      const t = new Promise((_, r) => setTimeout(() => r(new Error('Envoi échoué — délai dépassé')), 10000));
      await Promise.race([sock.sendMessage(jid, { text: message }, { quoted }), t]);
      return { sent: true, quoted: true };
    }

    // ── Recherche du groupe canal par nom ──────────────────────────────────
    case 'findGroup': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      const name = String(message || instanceCfg[id]?.group_name || 'jeewhatsapp');
      logMsg('info', '[' + id + '] Recherche du groupe "' + name + '"…');
      const jid = await findGroupByName(sock, name);
      if (!jid) { throw new Error('Groupe "' + name + '" introuvable dans vos groupes WhatsApp'); }
      groupJids[id] = jid;
      fs.writeFileSync(groupJidFilePath(id), jid);
      logMsg('info', '[' + id + '] Groupe "' + name + '" → ' + jid);
      return { jid, name };
    }

    // ── Création d'un groupe WhatsApp ──────────────────────────────────────
    case 'createGroup': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      const groupName = String(message || instanceCfg[id]?.group_name || 'jeewhatsapp');
      const createTimeout = new Promise((_, reject) =>
        setTimeout(() => reject(new Error('Création du groupe — délai dépassé')), 15000)
      );
      const result = await Promise.race([sock.groupCreate(groupName, []), createTimeout]);
      groupJids[id] = result.id;
      fs.writeFileSync(groupJidFilePath(id), result.id);
      logMsg('info', '[' + id + '] Groupe créé : "' + groupName + '" → ' + result.id);
      return { jid: result.id, name: groupName };
    }

    // ── QR code ────────────────────────────────────────────────────────────
    case 'getQR': {
      const qf = qrFilePath(id);
      if (fs.existsSync(qf)) {
        return { qr: fs.readFileSync(qf, 'utf8'), status: 'qr_pending' };
      }
      const st = fs.existsSync(statusFilePath(id))
        ? fs.readFileSync(statusFilePath(id), 'utf8').trim() : 'unknown';
      return { qr: null, status: st };
    }

    // ── Statut de connexion ────────────────────────────────────────────────
    case 'getStatus': {
      const st = fs.existsSync(statusFilePath(id))
        ? fs.readFileSync(statusFilePath(id), 'utf8').trim() : 'unknown';
      let connectedSince = null;
      const csf = connectedSinceFilePath(id);
      if (sockets[id] && fs.existsSync(csf)) {
        connectedSince = fs.readFileSync(csf, 'utf8').trim();
      }
      return {
        status:          st,
        connected:       !!sockets[id],
        group_jid:       groupJids[id] || null,
        connected_since: connectedSince,
      };
    }

    default:
      throw new Error('Action inconnue : ' + action);
  }
}

// ---------------------------------------------------------------------------
// Démarrage de toutes les instances
// ---------------------------------------------------------------------------

for (const instance of instances) {
  connectInstance(instance).catch(e => {
    logMsg('error', '[' + instance.id + '] Connexion initiale : ' + e.message);
  });
}
