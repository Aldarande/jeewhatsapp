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
// ---------------------------------------------------------------------------

const AUTH_BASE = path.join(__dirname, 'auth');
if (!fs.existsSync(AUTH_BASE)) { fs.mkdirSync(AUTH_BASE, { recursive: true }); }

function authDir(id) {
  const dir = path.join(AUTH_BASE, String(id));
  if (!fs.existsSync(dir)) { fs.mkdirSync(dir, { recursive: true }); }
  return dir;
}
function qrFilePath(id)       { return path.join(authDir(id), 'qr.txt'); }
function statusFilePath(id)   { return path.join(authDir(id), 'status.txt'); }
function groupJidFilePath(id) { return path.join(authDir(id), 'group_jid.txt'); }

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

      const text = extractText(msg.message);
      if (!text) {
        debug('[' + id + '] Ignoré (pas de texte) — types : ' + Object.keys(msg.message).join(', '));
        continue;
      }

      // Dans un groupe, msg.key.participant = JID de l'expéditeur
      const participantJid = msg.key.participant || remoteJid;
      const sender         = participantJid.replace('@s.whatsapp.net', '').replace('@g.us', '');
      const senderName     = msg.pushName || sender;

      // Mémorise le dernier message reçu — utilisé par action 'replyLast' (quoted)
      lastIncomingMsg[id] = msg;

      logMsg('info', '[' + id + '] Message de ' + sender + ' (groupe) : ' + text.substring(0, 60));
      await sendCallback(id, {
        message:     text,
        sender,
        sender_name: senderName,
        received_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
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
      headers:  { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) },
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

async function handleAction({ action, instance_id, phone, message, mention }) {
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
      return {
        status:    st,
        connected: !!sockets[id],
        group_jid: groupJids[id] || null,
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
