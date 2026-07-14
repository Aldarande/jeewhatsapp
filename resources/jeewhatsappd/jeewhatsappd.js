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
  downloadMediaMessage,
  getAggregateVotesInPollMessage,
  decryptPollVote,
  getKeyAuthor,
  jidNormalizedUser,
} from '@whiskeysockets/baileys';
import crypto from 'crypto';
import pino          from 'pino';
import QRCode        from 'qrcode';
import http          from 'http';
import fs            from 'fs';
import path          from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// SECURITY (F-014, CWE-73) : liste blanche de répertoires autorisés pour les
// fichiers médias (sendMedia, sendSticker, postStatus, setGroupIcon).
// Empêche l'exfiltration de fichiers système arbitraires (/etc, /root, …) via
// media_path, tout en couvrant les emplacements légitimes où Jeedom et les
// scénarios génèrent des fichiers : toute l'arborescence data/, tmp/, cache/
// de l'installation Jeedom (racine = ../../../../ depuis resources/jeewhatsappd/).
// Le plugin lui-même utilise des sous-dossiers de data/jeewhatsapp et tmp/jeewhatsapp.
const JEEDOM_ROOT = path.resolve(__dirname, '../../../..');
const MEDIA_ALLOWED_BASES = [
  path.join(JEEDOM_ROOT, 'data'),    // data/jeewhatsapp + tout fichier généré par un plugin/scénario
  path.join(JEEDOM_ROOT, 'tmp'),     // fichiers temporaires Jeedom
  path.join(JEEDOM_ROOT, 'cache'),   // cache Jeedom (rapports, exports…)
  '/tmp',                            // /tmp système (chemins reconstruits dans les scénarios)
];

function assertMediaPathAllowed(filePath) {
  // Résout les liens symboliques et . / .. pour empêcher tout contournement
  // (ex : data/jeewhatsapp/../../etc/passwd). fs.realpathSync échoue si le
  // fichier n'existe pas → on retombe sur path.resolve (l'existence est
  // vérifiée juste après par l'appelant).
  let resolved;
  try { resolved = fs.realpathSync(filePath); }
  catch (e) { resolved = path.resolve(filePath); }
  for (const base of MEDIA_ALLOWED_BASES) {
    if (resolved === base || resolved.startsWith(base + path.sep)) { return; }
  }
  throw new Error('Chemin de fichier non autorisé (hors des répertoires Jeedom data/tmp/cache permis) : ' + filePath);
}

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
const API_KEY        = process.env.JEEDOM_APIKEY || '';
// SECURITY (F-004): secret partagé pour authentifier les requêtes PHP→daemon
const DAEMON_SECRET  = process.env.JEEDOM_DAEMON_SECRET || '';
// On efface immédiatement les variables du process pour éviter qu'un dump éventuel ne les révèle
delete process.env.JEEDOM_APIKEY;
delete process.env.JEEDOM_DAEMON_SECRET;

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
  // P3 — écrit sur disque les fichiers JSON en attente de debounce (events.json…)
  // avant de quitter, pour ne pas perdre les derniers événements.
  try { flushScheduledWrites(); } catch (_) {}
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

// Stockage des médias reçus — dossier data/jeewhatsapp/incoming/{eqId}/{YYYY-MM-DD}/
// Placé dans data/ pour ne pas être nettoyé lors d'une réinstallation du plugin
// et pour rester accessible depuis les scénarios Jeedom (lecture, copie, etc.).
const INCOMING_BASE = path.resolve(__dirname, '../../../../data/jeewhatsapp/incoming');

function incomingDir(eqId) {
  if (!/^\d+$/.test(String(eqId))) {
    throw new Error('jeewhatsappd.js::incomingDir() — eqId invalide : ' + eqId);
  }
  const date = new Date().toISOString().substring(0, 10);
  const dir  = path.join(INCOMING_BASE, String(eqId), date);
  if (!fs.existsSync(dir)) { fs.mkdirSync(dir, { recursive: true }); }
  return dir;
}

// Mapping inverse mime → extension pour nommer les fichiers téléchargés
const MIME_TO_EXT = {
  'image/jpeg':  'jpg',
  'image/png':   'png',
  'image/gif':   'gif',
  'image/webp':  'webp',
  'video/mp4':   'mp4',
  'video/quicktime': 'mov',
  'video/3gpp':  '3gp',
  'audio/mp4':   'm4a',
  'audio/mpeg':  'mp3',
  'audio/aac':   'aac',
  'audio/wav':   'wav',
  'audio/ogg':   'ogg',
  'audio/ogg; codecs=opus': 'opus',
  'application/pdf': 'pdf',
};

function extFromMime(mime) {
  if (!mime) return 'bin';
  const m = mime.toLowerCase().split(';')[0].trim();
  return MIME_TO_EXT[m] || MIME_TO_EXT[mime.toLowerCase()] || 'bin';
}

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
// Événements live — tampon circulaire pour le mode debug visuel (#31)
// Fichier : auth/{id}/events.json — 100 derniers événements, lecture par PHP
// Types : 'in' (message reçu) | 'out' (message envoyé) | 'sys' (connexion/groupe)
//         'warn' (avertissement) | 'err' (erreur)
// ---------------------------------------------------------------------------

function eventsFilePath(id) { return path.join(authDir(id), 'events.json'); }

// ---------------------------------------------------------------------------
// Écritures JSON groupées (debounce) — P3
// Les fichiers JSON volatils (events.json…) étaient réécrits intégralement à
// CHAQUE message → I/O permanent et usure de la carte SD sur Raspberry Pi.
// scheduleWrite() regroupe les écritures : au plus une écriture par fichier
// toutes les delayMs. dataGetter() est appelé au moment du flush afin de
// sérialiser l'état le plus récent. Écriture atomique (tmp + rename) systématique.
// Note : l'outbox (P2) n'utilise PAS le debounce — sa durabilité exige une
// écriture immédiate (un crash ne doit pas perdre un message déjà accusé).
// ---------------------------------------------------------------------------

function atomicWriteFileSync(file, data) {
  const tmp = file + '.tmp';
  fs.writeFileSync(tmp, data);
  fs.renameSync(tmp, file);
}

const _pendingWrites = new Map(); // file → { timer, getData }

function scheduleWrite(file, dataGetter, delayMs = 5000) {
  const existing = _pendingWrites.get(file);
  if (existing) {
    // Une écriture est déjà planifiée : on rafraîchit juste le contenu, sans
    // repousser l'échéance → garantit « au plus une écriture toutes les delayMs ».
    existing.getData = dataGetter;
    return;
  }
  const timer = setTimeout(() => {
    const entry = _pendingWrites.get(file);
    _pendingWrites.delete(file);
    if (!entry) { return; }
    try { atomicWriteFileSync(file, entry.getData()); }
    catch (e) { logMsg('error', 'scheduleWrite : écriture ' + file + ' échouée : ' + e.message); }
  }, delayMs);
  if (typeof timer.unref === 'function') { timer.unref(); } // ne maintient pas le process en vie
  _pendingWrites.set(file, { timer, getData: dataGetter });
}

// Flush synchrone de toutes les écritures en attente — appelé à l'arrêt (SIGTERM).
function flushScheduledWrites() {
  for (const [file, entry] of _pendingWrites) {
    clearTimeout(entry.timer);
    try { atomicWriteFileSync(file, entry.getData()); }
    catch (e) { logMsg('error', 'flushScheduledWrites : ' + file + ' : ' + e.message); }
  }
  _pendingWrites.clear();
}

// Tampon en mémoire des événements live par instance — évite de relire
// events.json à chaque appel ; l'écriture disque passe par scheduleWrite.
const eventsBuf = {}; // id → array

function writeEvent(id, type, text) {
  try {
    const file = eventsFilePath(id);
    if (!eventsBuf[id]) {
      // Charge l'existant une seule fois (reprise de l'historique après redémarrage)
      let events = [];
      try {
        const parsed = JSON.parse(fs.readFileSync(file, 'utf8'));
        if (Array.isArray(parsed)) { events = parsed; }
      } catch (_) {}
      eventsBuf[id] = events;
    }
    eventsBuf[id].push({
      ts:   new Date().toISOString(),
      type: type,
      text: String(text).substring(0, 200),
    });
    if (eventsBuf[id].length > 100) { eventsBuf[id] = eventsBuf[id].slice(-100); }
    scheduleWrite(file, () => JSON.stringify(eventsBuf[id]));
  } catch (_) {}
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
const groupJids       = {};  // id → JID du groupe WhatsApp canal par défaut (@g.us)
const extraGroups     = {};  // id → { tag: jid } — groupes canaux additionnels (v0.3 #16)
const instanceCfg     = {};  // id → { prefix, group_name, extra: [{tag,name}] }
const lastIncomingMsg = {};  // id → dernier msg reçu (pour reply quoted / forward)
const lastSentMsg     = {};  // id → clé du dernier msg envoyé (pour edit / revoke)
const lastPollMsg     = {};  // id → message de création du dernier sondage (pour décrypter les votes)
const sentMsgIds      = {};  // id → Set des key.id envoyés par Jeedom (anti-écho fromMe, v0.4)

// Mémorise la clé du dernier message envoyé par Jeedom (v0.3 #11/#12).
// Baileys renvoie l'objet message complet ; on ne garde que key + jid utiles.
// On mémorise aussi l'ID du message dans un Set borné (v0.4) : sur un compte lié,
// les envois Jeedom reviennent en fromMe ; les ignorer par ID est fiable pour TOUS
// les types (texte, note vocale, média) là où le préfixe texte ne suffit pas.
function recordSent(id, sent) {
  if (sent && sent.key) {
    lastSentMsg[id] = { key: sent.key, jid: sent.key.remoteJid };
    if (sent.key.id) {
      if (!sentMsgIds[id]) { sentMsgIds[id] = new Set(); }
      const set = sentMsgIds[id];
      set.add(sent.key.id);
      // Borne mémoire : conserve les ~200 derniers IDs
      if (set.size > 200) { set.delete(set.values().next().value); }
    }
  }
}

// ---------------------------------------------------------------------------
// Outbox — file d'envoi persistante avec retry (P2)
// Si WhatsApp est déconnecté au moment d'un envoi (ou erreur réseau), le message
// est mis en file dans data/outbox.json au lieu d'être perdu silencieusement,
// puis rejoué à la reconnexion. Limite 100 messages, TTL 1 h, 3 tentatives max.
// L'écriture est atomique et IMMÉDIATE (jamais debouncée) : la durabilité prime,
// un crash ne doit pas perdre un message d'alarme déjà accusé {queued:true}.
// ---------------------------------------------------------------------------

const DATA_DIR    = path.resolve(__dirname, '../../data');
const OUTBOX_FILE = path.join(DATA_DIR, 'outbox.json');

const OUTBOX_MAX          = 100;          // messages max en file (FIFO, drop du plus ancien)
const OUTBOX_TTL_MS       = 3600 * 1000;  // 1 h — au-delà le message est droppé
const OUTBOX_MAX_ATTEMPTS = 3;            // tentatives max par message au flush
const OUTBOX_FLUSH_DELAY  = 500;          // ms entre deux envois (anti-flood)

// L'outbox réutilise atomicWriteFileSync() (défini avec les helpers d'écriture)
// mais en mode IMMÉDIAT (jamais debouncé) : sa durabilité prime sur l'I/O.
let outbox = [];                // [{id, instance_id, phone, group_tag, payload, sendOpts, type, created_ts, attempts}]
const outboxFlushing = {};      // id → true pendant un flush (anti-réentrance)

try {
  if (fs.existsSync(OUTBOX_FILE)) {
    const parsed = JSON.parse(fs.readFileSync(OUTBOX_FILE, 'utf8'));
    if (Array.isArray(parsed)) {
      outbox = parsed;
      if (outbox.length > 0) {
        logMsg('info', 'Outbox : ' + outbox.length + ' message(s) en attente rechargé(s) au démarrage');
      }
    }
  }
} catch (e) {
  logMsg('warning', 'Outbox : fichier illisible (' + e.message + ') — file réinitialisée');
  outbox = [];
}

function persistOutbox() {
  try {
    if (!fs.existsSync(DATA_DIR)) { fs.mkdirSync(DATA_DIR, { recursive: true }); }
    atomicWriteFileSync(OUTBOX_FILE, JSON.stringify(outbox));
  } catch (e) {
    logMsg('error', 'Outbox : écriture impossible : ' + e.message);
  }
}

// Purge les entrées expirées (TTL). Retourne le nombre purgé.
function pruneOutbox() {
  const now = Date.now();
  const before = outbox.length;
  outbox = outbox.filter(e => {
    if (now - e.created_ts > OUTBOX_TTL_MS) {
      logMsg('warning', '[' + e.instance_id + '] Outbox : message expiré (>1h) droppé : '
        + String(e.payload?.text || e.type).substring(0, 60));
      return false;
    }
    return true;
  });
  return before - outbox.length;
}

function outboxPendingCount(id) {
  return outbox.filter(e => e.instance_id === String(id)).length;
}

// Met un message en file. Le JID n'est pas figé : on stocke phone + group_tag et
// on (re)résout au flush, car le cache de groupe peut évoluer entre-temps.
function enqueueOutbox(id, phone, group_tag, payload, sendOpts, type) {
  if (pruneOutbox() > 0) { persistOutbox(); }
  // Limite stricte : on droppe le plus ancien pour faire de la place au plus
  // récent (dans une alarme, le dernier état est le plus pertinent).
  while (outbox.length >= OUTBOX_MAX) {
    const dropped = outbox.shift();
    logMsg('warning', '[' + (dropped?.instance_id ?? '?') + '] Outbox pleine (' + OUTBOX_MAX
      + ') — plus ancien message droppé : ' + String(dropped?.payload?.text || dropped?.type || '').substring(0, 60));
  }
  outbox.push({
    id:          crypto.randomUUID(),
    instance_id: String(id),
    phone:       phone || '',
    group_tag:   group_tag || '',
    payload,
    sendOpts:    sendOpts || {},
    type:        type || 'text',
    created_ts:  Date.now(),
    attempts:    0,
  });
  persistOutbox();
  writeEvent(id, 'warn', 'Message mis en file (hors connexion) : ' + String(payload?.text || type).substring(0, 100));
  logMsg('info', '[' + id + '] Outbox : message mis en file (' + outboxPendingCount(id) + ' en attente)');
}

// Distingue une erreur d'envoi "réseau" (socket fermée → remettre en file) d'un
// simple dépassement de délai d'ACK.
//
// ⚠️ IMPORTANT (fix duplication 4x — forum #149964) : un timeout du Promise.race
// (« délai dépassé ») NE doit PAS être traité comme un échec réseau. Baileys, une
// fois sock.sendMessage() appelé, met le message dans sa file d'émission et le
// LIVRE même si l'ACK tarde au-delà de notre garde-fou. Le Promise.race qui expire
// n'annule pas cet envoi. Le remettre en file (outbox) provoque donc un envoi en
// double (voire 4x si l'ACK tarde à chaque tentative). On ne réenfile donc QUE sur
// une vraie erreur de socket (fermée/perdue), pas sur un timeout.
function isNetworkSendError(e) {
  const m = String(e?.message || e || '').toLowerCase();
  return m.includes('connection closed') || m.includes('connection lost')
      || m.includes('not open') || m.includes('socket')
      || m.includes('econnrefused') || m.includes('econnreset');
}

// Rejoue la file d'une instance après reconnexion — séquentiel, OUTBOX_FLUSH_DELAY
// entre messages, max OUTBOX_MAX_ATTEMPTS tentatives par message.
async function flushOutbox(id) {
  id = String(id);
  if (outboxFlushing[id]) { return; }
  if (pruneOutbox() > 0) { persistOutbox(); }
  const mine = outbox.filter(e => e.instance_id === id);
  if (mine.length === 0) { return; }

  outboxFlushing[id] = true;
  logMsg('info', '[' + id + '] Outbox : reconnecté — envoi de ' + mine.length + ' message(s) en attente…');
  try {
    for (const entry of mine) {
      const sock = sockets[id];
      if (!sock) {
        logMsg('warning', '[' + id + '] Outbox : connexion reperdue — flush interrompu ('
          + outboxPendingCount(id) + ' restant(s))');
        break;
      }
      if (Date.now() - entry.created_ts > OUTBOX_TTL_MS) {
        outbox = outbox.filter(e => e.id !== entry.id);
        persistOutbox();
        logMsg('warning', '[' + id + '] Outbox : message expiré (>1h) droppé au flush');
        continue;
      }
      try {
        const jid = resolveJid(id, entry.phone, entry.group_tag);
        // Timeout d'ACK (30 s) : comme pour le case 'send', un ACK lent ≠ échec.
        // On retire l'entrée de l'outbox AVANT l'attente longue pour éviter tout
        // rejeu concurrent, et on considère l'envoi lancé comme livré.
        const ACK_TIMEOUT = Symbol('ack_timeout');
        const t = new Promise((resolve) => setTimeout(() => resolve(ACK_TIMEOUT), 30000));
        const sent = await Promise.race([sock.sendMessage(jid, entry.payload, entry.sendOpts || {}), t]);
        if (sent !== ACK_TIMEOUT) { recordSent(id, sent); }
        outbox = outbox.filter(e => e.id !== entry.id);
        persistOutbox();
        writeEvent(id, 'out', '(file) → ' + String(entry.payload?.text || entry.type).substring(0, 100)
          + (sent === ACK_TIMEOUT ? ' (ACK lent)' : ''));
        logMsg('info', '[' + id + '] Outbox : message rejoué (' + outboxPendingCount(id) + ' restant(s))');
      } catch (e) {
        entry.attempts = (entry.attempts || 0) + 1;
        if (entry.attempts >= OUTBOX_MAX_ATTEMPTS) {
          outbox = outbox.filter(x => x.id !== entry.id);
          writeEvent(id, 'err', 'Message abandonné (3 échecs) : ' + String(entry.payload?.text || entry.type).substring(0, 80));
          logMsg('warning', '[' + id + '] Outbox : message abandonné après ' + OUTBOX_MAX_ATTEMPTS + ' tentatives : ' + e.message);
        } else {
          logMsg('warning', '[' + id + '] Outbox : tentative ' + entry.attempts + '/' + OUTBOX_MAX_ATTEMPTS
            + ' échouée (' + e.message + ') — réessai à la prochaine reconnexion');
        }
        persistOutbox();
      }
      await new Promise(r => setTimeout(r, OUTBOX_FLUSH_DELAY));
    }
  } finally {
    outboxFlushing[id] = false;
  }
}

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
  const extra      = Array.isArray(instance.groups) ? instance.groups : [];

  instanceCfg[id] = { prefix, group_name, extra };
  if (!extraGroups[id]) { extraGroups[id] = {}; }
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
    // v0.3 #9 — indispensable pour déchiffrer les votes de sondage : Baileys appelle
    // getMessage(key) lorsqu'un pollUpdateMessage arrive, pour récupérer le sondage
    // d'origine (contient le messageSecret). Sans ça, aucun 'messages.update' n'est émis.
    getMessage: async (key) => {
      const poll = lastPollMsg[id];
      if (poll && poll.key && poll.key.id === key.id) {
        return poll.message;
      }
      return undefined;
    },
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
        writeEvent(id, 'sys', 'QR code prêt — en attente du scan');
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
      writeEvent(id, 'sys', '✓ Connecté à WhatsApp');
      logMsg('info', '[' + id + '] ✓ Connecté à WhatsApp');

      // P2 — rejoue les messages mis en file pendant la déconnexion. Délai de 3 s
      // pour laisser la socket se stabiliser et le cache de groupe se charger.
      setTimeout(() => { flushOutbox(id).catch(e => logMsg('warning', '[' + id + '] flushOutbox : ' + e.message)); }, 3000);

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

        // Résolution des groupes canaux additionnels (v0.3 #16)
        extraGroups[id] = {};
        for (const g of (instanceCfg[id]?.extra || [])) {
          if (!g || !g.tag || !g.name) { continue; }
          const gj = await findGroupByName(sock, g.name);
          if (gj) {
            extraGroups[id][g.tag] = gj;
            logMsg('info', '[' + id + '] ✓ Groupe additionnel [' + g.tag + '] "' + g.name + '" → ' + gj);
          } else {
            logMsg('warning', '[' + id + '] Groupe additionnel [' + g.tag + '] "' + g.name + '" introuvable');
          }
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
        writeEvent(id, 'err', 'Session expirée (logout) — rescannez le QR code');
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
        writeEvent(id, 'warn', 'Connexion perdue (code ' + code + ') — reconnexion dans 5s');
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

      // ── Votes de sondage (v0.3 #9) ────────────────────────────────────────
      // Baileys 6.7.x ne traite plus les pollUpdateMessage en interne (bloc
      // commenté dans process-message.js) : aucun 'messages.update' n'est émis.
      // On déchiffre donc le vote ici nous-mêmes, comme le faisait Baileys.
      if (msg.message?.pollUpdateMessage) {
        await handlePollVote(id, sock, msg);
        continue;
      }

      // Accepter uniquement les messages du groupe canal
      if (!isJidGroup(remoteJid)) {
        debug('[' + id + '] Ignoré (message direct, mode groupe actif)');
        continue;
      }

      const configuredJid = groupJids[id];
      if (!configuredJid && Object.keys(extraGroups[id] || {}).length === 0) {
        debug('[' + id + '] Ignoré (aucun groupe canal configuré)');
        continue;
      }

      // Identifie le tag du groupe : '' = groupe par défaut, sinon le tag additionnel.
      // Un message d'un groupe non listé est ignoré (multi-groupes v0.3 #16).
      let groupTag = null;
      if (remoteJid === configuredJid) {
        groupTag = '';
      } else {
        for (const [tag, gj] of Object.entries(extraGroups[id] || {})) {
          if (gj === remoteJid) { groupTag = tag; break; }
        }
      }
      if (groupTag === null) {
        debug('[' + id + '] Ignoré (groupe ' + remoteJid + ' non listé)');
        continue;
      }
      const groupName = groupTag === ''
        ? (instanceCfg[id]?.group_name || '')
        : (instanceCfg[id]?.extra || []).find(g => g.tag === groupTag)?.name || '';

      // Gestion des messages fromMe (compte WhatsApp lié au daemon).
      // Sur un appareil lié, les messages produits par l'utilisateur ET les envois
      // automatiques de Jeedom sont tous marqués fromMe. Distinction en deux temps :
      //   1) ÉCHO Jeedom : si l'ID du message est dans sentMsgIds → c'est un de nos
      //      propres envois (texte, note vocale, média, réaction…) → ignoré de façon
      //      fiable, quel que soit le type. C'est ce qui permet à la note vocale TTS
      //      de ne pas être re-transcrite (le préfixe texte ne s'applique pas à l'audio).
      //   2) Sinon (message produit par l'humain sur le compte lié) → accepté, ce qui
      //      autorise le pilotage de Jeedom depuis le compte lié, y compris par note
      //      vocale. Garde-fou résiduel : un TEXTE préfixé Jeedom reste ignoré (au cas
      //      où un envoi n'aurait pas été capté par ID).
      if (msg.key.fromMe) {
        if (msg.key.id && sentMsgIds[id]?.has(msg.key.id)) {
          debug('[' + id + '] Ignoré (fromMe : écho d\'un envoi Jeedom, id connu)');
          continue;
        }
        const prefixSelf = (instanceCfg[id]?.prefix || '').trim();
        const textSelf   = extractText(msg.message) || '';
        if (textSelf !== '' && prefixSelf !== '' && textSelf.startsWith(prefixSelf)) {
          debug('[' + id + '] Ignoré (fromMe : texte préfixé Jeedom)');
          continue;
        }
        debug('[' + id + '] fromMe accepté (compte lié, non-écho) → traité');
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

      // ── Détection des médias entrants (v0.2) ──────────────────────────────
      // Téléchargement local + callback avec event_type='attachment'
      // Types Baileys : imageMessage, videoMessage, audioMessage, documentMessage, stickerMessage
      const mediaKind = msg.message.imageMessage    ? 'image'
                      : msg.message.videoMessage    ? 'video'
                      : msg.message.audioMessage    ? 'audio'
                      : msg.message.documentMessage ? 'document'
                      : msg.message.stickerMessage  ? 'sticker'
                      : null;
      if (mediaKind) {
        try {
          const buffer = await downloadMediaMessage(msg, 'buffer', {}, { logger: baileysLogger });
          if (!buffer || buffer.length === 0) {
            logMsg('warning', '[' + id + '] Média ' + mediaKind + ' vide (download failed)');
            continue;
          }
          const meta = msg.message[mediaKind + 'Message'] || {};
          const mime = meta.mimetype || '';
          const ext  = extFromMime(mime);
          const dir  = incomingDir(id);
          const uuid = crypto.randomBytes(6).toString('hex');
          const file = path.join(dir, uuid + '.' + ext);
          fs.writeFileSync(file, buffer);
          const caption = meta.caption || extractText(msg.message) || '';
          logMsg('info', '[' + id + '] 📥 ' + mediaKind + ' reçu de ' + sender
                       + ' (' + Math.round(buffer.length / 1024) + 'KB) → ' + file);
          await sendCallback(id, {
            event_type:      'attachment',
            attachment_kind: mediaKind,
            attachment_mime: mime,
            attachment_path: file,
            attachment_size: buffer.length,
            caption,
            sender,
            sender_name: senderName,
            received_at: ts,
            group_tag:   groupTag,
            group_name:  groupName,
          });
        } catch (e) {
          logMsg('error', '[' + id + '] Erreur téléchargement ' + mediaKind + ' : ' + e.message);
        }
        continue;
      }

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
          group_tag:   groupTag,
          group_name:  groupName,
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
      writeEvent(id, 'in', sender + ' : ' + text.substring(0, 120));
      await sendCallback(id, {
        message:     text,
        sender,
        sender_name: senderName,
        received_at: ts,
        group_tag:   groupTag,
        group_name:  groupName,
      });
    }
  });

  // ── Accusés de lecture (v0.5 #23) ────────────────────────────────────────
  // Baileys émet 'messages.update' avec un statut (WAMessageStatus) quand l'état
  // d'un message évolue. Pour un message ENVOYÉ par Jeedom (key.fromMe), un statut
  // READ (4) ou PLAYED (5) signifie que le destinataire l'a lu/écouté → on remonte
  // un accusé de lecture à Jeedom (cmd info last_read_at).
  sock.ev.on('messages.update', async (updates) => {
    for (const u of (updates || [])) {
      const st = u.update?.status;
      if (!u.key?.fromMe) { continue; }
      // 4 = READ, 5 = PLAYED (note vocale écoutée)
      if (st === 4 || st === 5) {
        const ts = new Date().toISOString().replace('T', ' ').substring(0, 19);
        debug('[' + id + '] Accusé de lecture (status=' + st + ') msg ' + (u.key.id || '?'));
        await sendCallback(id, {
          event_type:  'read_receipt',
          read_status: st === 5 ? 'played' : 'read',
          message_id:  u.key.id || '',
          read_at:     ts,
        });
      }
    }
  });

}

// ---------------------------------------------------------------------------
// Votes de sondage (v0.3 #9)
// ---------------------------------------------------------------------------
// Baileys 6.7.x a retiré (bloc commenté dans Utils/process-message.js) le
// traitement interne des pollUpdateMessage : il n'émet plus de 'messages.update'
// et n'appelle plus getMessage. On reproduit donc ici sa logique : déchiffrer le
// vote avec le messageSecret du sondage d'origine, puis agréger les options.
async function handlePollVote(id, sock, msg) {
  const poll = lastPollMsg[id];
  if (!poll || !poll.message) {
    debug('[' + id + '] Vote reçu mais aucun sondage mémorisé');
    return;
  }
  const creationKey = msg.message.pollUpdateMessage.pollCreationMessageKey;
  // Ne traiter que les votes du sondage courant
  if (creationKey && creationKey.id && poll.key && poll.key.id && creationKey.id !== poll.key.id) {
    debug('[' + id + '] Vote ignoré (sondage différent du dernier mémorisé)');
    return;
  }
  try {
    const meId          = jidNormalizedUser(sock.user?.id || '');
    const pollCreatorJid = getKeyAuthor(creationKey || poll.key, meId);
    const voterJid       = getKeyAuthor(msg.key, meId);
    const pollEncKey     = poll.message.messageContextInfo?.messageSecret;
    if (!pollEncKey) {
      logMsg('warning', '[' + id + '] Vote sondage : messageSecret absent, déchiffrement impossible');
      return;
    }
    const voteMsg = decryptPollVote(msg.message.pollUpdateMessage.vote, {
      pollEncKey,
      pollCreatorJid,
      pollMsgId: (creationKey && creationKey.id) || poll.key.id,
      voterJid,
    });
    // Un vote contient la sélection COURANTE complète du votant : on conserve le
    // dernier vote par votant pour calculer un cumul correct sur tous les votants.
    if (!poll.votes) { poll.votes = {}; }
    poll.votes[voterJid] = {
      pollUpdateMessageKey: msg.key,
      vote: voteMsg,
      senderTimestampMs: Date.now(),
    };
    const aggregated = getAggregateVotesInPollMessage({
      message:     poll.message,
      pollUpdates: Object.values(poll.votes),
    }, meId);
    const results = (aggregated || []).map(o => ({
      name:  o.name,
      votes: Array.isArray(o.voters) ? o.voters.length : 0,
    }));
    const total    = results.reduce((s, r) => s + r.votes, 0);
    const question = poll.message?.pollCreationMessage?.name
                  || poll.message?.pollCreationMessageV3?.name || '';
    logMsg('info', '[' + id + '] 📊 Vote sondage — ' + total + ' vote(s) : '
      + results.map(r => r.name + '=' + r.votes).join(', '));
    await sendCallback(id, {
      event_type:   'poll_vote',
      poll_name:    question,
      poll_results: JSON.stringify(results),
      poll_total:   total,
      received_at:  new Date().toISOString().replace('T', ' ').substring(0, 19),
    });
  } catch (e) {
    logMsg('warning', '[' + id + '] Décryptage vote sondage échoué : ' + e.message);
  }
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

// SECURITY (F-004, CWE-208): comparaison à temps constant pour empêcher les timing attacks
function safeStringEquals(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string') return false;
  if (a.length !== b.length) return false;
  try {
    return crypto.timingSafeEqual(Buffer.from(a), Buffer.from(b));
  } catch (_) {
    return false;
  }
}

// SECURITY (F-012) : en-têtes de sécurité par défaut sur toutes les réponses HTTP
// du daemon. Le serveur est bound 127.0.0.1 et ne renvoie que du JSON, donc le
// risque est faible — mais en défense en profondeur on durcit quand même.
const jsonHeaders = {
  'Content-Type': 'application/json',
  'X-Content-Type-Options': 'nosniff',
  'Cache-Control': 'no-store',
  'X-Frame-Options': 'DENY',
  'Content-Security-Policy': "default-src 'none'",
};

http.createServer(async (req, res) => {
  if (req.method !== 'POST' || req.url !== '/action') {
    res.writeHead(404, jsonHeaders); res.end(JSON.stringify({ error: 'Not found' })); return;
  }
  // SECURITY (F-004 + F-011) : authentification stricte par secret partagé.
  // Si DAEMON_SECRET est vide (mauvaise config), on refuse toutes les requêtes —
  // plus de fallback "ouvert" (le PHP doit toujours fournir JEEDOM_DAEMON_SECRET).
  if (DAEMON_SECRET === '') {
    logMsg('error', 'Daemon HTTP : JEEDOM_DAEMON_SECRET non fourni — toutes les requêtes sont refusées');
    res.writeHead(503, jsonHeaders);
    res.end(JSON.stringify({ error: 'Daemon misconfigured: missing JEEDOM_DAEMON_SECRET' }));
    return;
  }
  const provided = req.headers['x-daemon-secret'] || '';
  if (!safeStringEquals(provided, DAEMON_SECRET)) {
    logMsg('warning', 'Daemon HTTP : requête refusée (X-Daemon-Secret manquant ou invalide) depuis ' + req.socket.remoteAddress);
    res.writeHead(401, jsonHeaders);
    res.end(JSON.stringify({ error: 'Unauthorized' }));
    return;
  }
  let body = '';
  req.on('data', chunk => { body += chunk; });
  req.on('end', async () => {
    let payload;
    try { payload = JSON.parse(body); }
    catch (e) { res.writeHead(400, jsonHeaders); res.end(JSON.stringify({ error: 'Invalid JSON' })); return; }

    debug('Action : ' + JSON.stringify(payload));
    try {
      const result = await handleAction(payload);
      res.writeHead(200, jsonHeaders);
      res.end(JSON.stringify({ status: 'ok', result }));
    } catch (e) {
      logMsg('error', 'Action [' + payload?.action + '] : ' + e.message);
      res.writeHead(500, jsonHeaders);
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

// Présence typing/recording avant envoi (v0.3 #14) — humanise l'UX côté WhatsApp.
// On envoie 'composing' (ou 'recording'), on patiente brièvement, puis 'paused'.
// Toutes les erreurs sont silencieuses : la présence ne doit jamais bloquer l'envoi.
async function applyPresence(sock, jid, presence) {
  if (!presence || !sock) { return; }
  const valid = ['composing', 'recording'];
  const state = valid.includes(presence) ? presence : 'composing';
  try {
    await sock.sendPresenceUpdate(state, jid);
    await new Promise(r => setTimeout(r, 1200));
    await sock.sendPresenceUpdate('paused', jid);
  } catch (_) { /* présence non critique */ }
}

// Résolution du JID destinataire — facteur commun à toutes les actions d'envoi.
// tag (v0.3 #16) : '' ou absent = groupe par défaut ; sinon groupe additionnel ciblé.
function resolveJid(id, phone, tag) {
  if (!phone || phone === '' || phone === 'group') {
    // Ciblage d'un groupe additionnel par tag
    if (tag && tag !== '' && extraGroups[id] && extraGroups[id][tag]) {
      return extraGroups[id][tag];
    }
    if (tag && tag !== '') {
      throw new Error('Groupe additionnel "' + tag + '" introuvable ou non résolu — vérifiez le nom du groupe dans les paramètres');
    }
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
                              contact_phone, contact_name, presence, ephemeral,
                              poll_question, poll_options, poll_selectable, group_tag,
                              chat_op, chat_value, group_op }) {
  const id = String(instance_id);

  // Messages éphémères (v0.3 #15) — si une durée est fournie (en secondes),
  // chaque envoi expire automatiquement côté WhatsApp. Passé en 3ᵉ argument
  // options de sock.sendMessage via ephemeralExpiration.
  const ephSecs  = parseInt(ephemeral, 10);
  const sendOpts = (!Number.isNaN(ephSecs) && ephSecs > 0) ? { ephemeralExpiration: ephSecs } : {};

  switch (action) {

    // ── Envoi d'un message (vers le groupe canal ou un destinataire direct) ─
    case 'send': {
      const sock = sockets[id];

      // Mention optionnelle — @numéro en tête de message. Construit avant la
      // vérification de connexion pour pouvoir mettre le payload complet en file.
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

      // P2 — WhatsApp déconnecté : on met en file au lieu de perdre le message
      // (scénario d'alarme pendant une coupure). Rejoué à la reconnexion.
      if (!sock) {
        enqueueOutbox(id, phone, group_tag, msgPayload, sendOpts, 'text');
        return { queued: true, pending: outboxPendingCount(id) };
      }

      // Résolution du destinataire — gère le groupe par défaut, les groupes
      // additionnels (group_tag, v0.3 #16), un JID complet ou un numéro.
      const jid = resolveJid(id, phone, group_tag);

      logMsg('info', '[' + id + '] → ' + jid + ' : ' + msgPayload.text.substring(0, 60));
      await applyPresence(sock, jid, presence);
      try {
        // Garde-fou de 30 s. Si sock.sendMessage n'a pas résolu (ACK lent), on
        // NE réenfile PAS : Baileys a déjà accepté le message et le livrera. On
        // renvoie {sent:true, ackTimeout:true} pour ne PAS générer de doublon
        // (fix forum #149964 — messages dupliqués 4x sur connexion lente).
        const ACK_TIMEOUT = Symbol('ack_timeout');
        const sendTimeout = new Promise((resolve) =>
          setTimeout(() => resolve(ACK_TIMEOUT), 30000)
        );
        const sent = await Promise.race([sock.sendMessage(jid, msgPayload, sendOpts), sendTimeout]);
        if (sent === ACK_TIMEOUT) {
          writeEvent(id, 'out', '→ ' + msgPayload.text.substring(0, 120) + ' (ACK lent)');
          logMsg('warning', '[' + id + '] Envoi lancé mais ACK non reçu sous 30 s — considéré envoyé (pas de réessai pour éviter les doublons) : ' + jid);
          return { sent: true, ackTimeout: true };
        }
        recordSent(id, sent);
        writeEvent(id, 'out', '→ ' + msgPayload.text.substring(0, 120));
        return { sent: true };
      } catch (e) {
        // Vraie erreur de socket (fermée/perdue) → mise en file + {queued:true}.
        // Une erreur métier (groupe introuvable, numéro invalide) est relancée.
        if (isNetworkSendError(e)) {
          enqueueOutbox(id, phone, group_tag, msgPayload, sendOpts, 'text');
          logMsg('warning', '[' + id + '] Envoi échoué (socket) — mis en file : ' + e.message);
          return { queued: true, pending: outboxPendingCount(id) };
        }
        throw e;
      }
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
      const jid = resolveJid(id, phone, group_tag);
      const loc = { degreesLatitude: lat, degreesLongitude: lng };
      if (location_name && String(location_name).trim() !== '') {
        loc.name = String(location_name).trim();
      }
      logMsg('info', '[' + id + '] → ' + jid + ' : 📍 ' + lat + ',' + lng + (loc.name ? ' (' + loc.name + ')' : ''));
      const t = new Promise((_, r) => setTimeout(() => r(new Error('Envoi location — délai dépassé')), 10000));
      const sentLoc = await Promise.race([sock.sendMessage(jid, { location: loc }, sendOpts), t]);
      recordSent(id, sentLoc);
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
      const jid = resolveJid(id, phone, group_tag);
      logMsg('info', '[' + id + '] → ' + jid + ' : 👤 contact ' + cName + ' (' + cDigits + ')');
      const t = new Promise((_, r) => setTimeout(() => r(new Error('Envoi contact — délai dépassé')), 10000));
      const sentContact = await Promise.race([
        sock.sendMessage(jid, { contacts: { displayName: cName, contacts: [{ vcard }] } }, sendOpts),
        t,
      ]);
      recordSent(id, sentContact);
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
      assertMediaPathAllowed(media_path);
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

      // Présence cohérente avec le type de média (audio → recording, sinon composing)
      if (presence) { await applyPresence(sock, jid, kind === 'audio' ? 'recording' : 'composing'); }

      // Timeout 60s pour les médias (upload plus long que texte)
      const sendTimeout = new Promise((_, reject) =>
        setTimeout(() => reject(new Error('Envoi média échoué — délai dépassé (60s) pour ' + jid)), 60000)
      );
      const sentMedia = await Promise.race([sock.sendMessage(jid, payload, sendOpts), sendTimeout]);
      recordSent(id, sentMedia);

      // Audio : pas de caption native — envoyer un message texte séparé si fourni
      if (kind === 'audio' && message && String(message).trim() !== '') {
        await sock.sendMessage(jid, { text: message }, sendOpts);
      }

      return { sent: true, kind, file: path.basename(media_path), bytes: stat.size };
    }

    // ── Envoi d'un sticker (.webp, ou image convertie via sharp) (v0.3 #10) ─
    case 'sendSticker': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      if (!media_path || typeof media_path !== 'string') {
        throw new Error('Paramètre media_path obligatoire (chemin absolu .webp/.png/.jpg)');
      }
      if (!path.isAbsolute(media_path)) { throw new Error('media_path doit être un chemin absolu : ' + media_path); }
      assertMediaPathAllowed(media_path);
      if (!fs.existsSync(media_path)) { throw new Error('Fichier introuvable : ' + media_path); }
      const sStat = fs.statSync(media_path);
      if (!sStat.isFile() || sStat.size === 0) { throw new Error('Fichier invalide/vide : ' + media_path); }

      const sExt = path.extname(media_path).slice(1).toLowerCase();
      let stickerBuf;
      if (sExt === 'webp') {
        stickerBuf = fs.readFileSync(media_path);
      } else if (['jpg', 'jpeg', 'png', 'gif', 'bmp'].includes(sExt)) {
        // Conversion en WebP 512×512 (format sticker WhatsApp) via sharp
        let sharp;
        try { sharp = (await import('sharp')).default; }
        catch (e) { throw new Error('Conversion sticker impossible : dépendance "sharp" absente. Relancez l\'installation des dépendances du plugin.'); }
        stickerBuf = await sharp(media_path)
          .resize(512, 512, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
          .webp()
          .toBuffer();
      } else {
        throw new Error('Type non supporté pour un sticker (.' + sExt + ') — attendu .webp, .png, .jpg, .gif, .bmp');
      }

      const jid = resolveJid(id, phone, group_tag);
      logMsg('info', '[' + id + '] → ' + jid + ' : 🏷 sticker ' + path.basename(media_path)
        + ' (' + Math.round(stickerBuf.length / 1024) + 'KB)');
      const tSt = new Promise((_, r) => setTimeout(() => r(new Error('Envoi sticker — délai dépassé')), 30000));
      const sentSticker = await Promise.race([sock.sendMessage(jid, { sticker: stickerBuf }, sendOpts), tSt]);
      recordSent(id, sentSticker);
      return { sent: true, kind: 'sticker', file: path.basename(media_path) };
    }

    // ── Envoi d'un sondage (poll) (v0.3 #9) ────────────────────────────────
    case 'sendPoll': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      const q = String(poll_question || '').trim();
      if (q === '') { throw new Error('Question du sondage obligatoire'); }
      // Les options arrivent dans poll_options sous forme "opt1|opt2|opt3"
      const options = String(poll_options || '')
        .split('|').map(s => s.trim()).filter(s => s !== '');
      if (options.length < 2) { throw new Error('Au moins 2 options requises (séparées par |)'); }
      if (options.length > 12) { throw new Error('12 options maximum (limite WhatsApp)'); }
      const jid = resolveJid(id, phone, group_tag);
      const selectable = (parseInt(poll_selectable, 10) > 1) ? parseInt(poll_selectable, 10) : 1;
      logMsg('info', '[' + id + '] → ' + jid + ' : 📊 sondage "' + q + '" (' + options.length + ' options)');
      const tP = new Promise((_, r) => setTimeout(() => r(new Error('Envoi sondage — délai dépassé')), 15000));
      const sentPoll = await Promise.race([
        sock.sendMessage(jid, { poll: { name: q, values: options, selectableCount: Math.min(selectable, options.length) } }, sendOpts),
        tP,
      ]);
      recordSent(id, sentPoll);
      // Mémorise le message de création pour pouvoir décrypter les votes (messageSecret)
      lastPollMsg[id] = sentPoll;
      return { sent: true, question: q, options };
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
      const sentReact = await Promise.race([
        sock.sendMessage(jid, { react: { text: emoji, key: target.key } }),
        t,
      ]);
      recordSent(id, sentReact);
      return { sent: true, emoji, target: target.key.id };
    }

    // ── Édition du dernier message envoyé (v0.3 #11) ───────────────────────
    case 'editLast': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      const last = lastSentMsg[id];
      if (!last) { throw new Error('Aucun message envoyé à éditer — envoyez d\'abord un message via Jeedom'); }
      const newText = String(message || '').trim();
      if (newText === '') { throw new Error('Nouveau texte obligatoire (champ message)'); }
      logMsg('info', '[' + id + '] ✎ Edit → ' + last.jid + ' : ' + newText.substring(0, 60));
      const t = new Promise((_, r) => setTimeout(() => r(new Error('Édition — délai dépassé')), 10000));
      await Promise.race([sock.sendMessage(last.jid, { text: newText, edit: last.key }), t]);
      return { edited: true, target: last.key.id };
    }

    // ── Suppression "pour tous" du dernier message envoyé (v0.3 #12) ────────
    case 'revokeLast': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      const last = lastSentMsg[id];
      if (!last) { throw new Error('Aucun message envoyé à supprimer — envoyez d\'abord un message via Jeedom'); }
      logMsg('info', '[' + id + '] 🗑 Revoke → ' + last.jid + ' (' + last.key.id + ')');
      const t = new Promise((_, r) => setTimeout(() => r(new Error('Suppression — délai dépassé')), 10000));
      await Promise.race([sock.sendMessage(last.jid, { delete: last.key }), t]);
      delete lastSentMsg[id];
      return { revoked: true };
    }

    // ── Transfert du dernier message reçu vers un destinataire (v0.3 #13) ───
    case 'forwardTo': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      const src = lastIncomingMsg[id];
      if (!src) { throw new Error('Aucun message reçu à transférer — attendez un message dans le groupe canal'); }
      const jid = resolveJid(id, phone, group_tag);
      logMsg('info', '[' + id + '] ⇪ Forward → ' + jid + ' (depuis ' + (src.key.id || '?') + ')');
      const t = new Promise((_, r) => setTimeout(() => r(new Error('Transfert — délai dépassé')), 15000));
      const fwd = await Promise.race([sock.sendMessage(jid, { forward: src }, sendOpts), t]);
      recordSent(id, fwd);
      return { forwarded: true, to: jid };
    }

    // ── Gestion du groupe (v0.5 #22) ───────────────────────────────────────
    // group_op : add|remove|promote|demote|subject|description|inviteLink|
    //            revokeInvite|leave. phone = participant (ops participant),
    //            message = texte (subject/description). Le compte doit être admin.
    case 'groupAction': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      const jid = resolveJid(id, '', group_tag);
      if (!String(jid).endsWith('@g.us')) {
        throw new Error('La cible n\'est pas un groupe');
      }
      const op = String(group_op || '').trim();
      switch (op) {
        case 'add':
        case 'remove':
        case 'promote':
        case 'demote': {
          const digits = String(phone || '').replace(/\D/g, '').replace(/^0([67]\d{8})$/, '33$1');
          if (!digits) { throw new Error('Numéro du participant requis'); }
          const pjid = digits + '@s.whatsapp.net';
          logMsg('info', '[' + id + '] 👥 groupAction ' + op + ' ' + pjid + ' → ' + jid);
          const res = await sock.groupParticipantsUpdate(jid, [pjid], op);
          // res = [{ status: '200'|..., jid }]
          const st = Array.isArray(res) && res[0] ? res[0].status : '?';
          if (st && String(st) !== '200') {
            throw new Error('WhatsApp a refusé l\'opération (code ' + st + ') — êtes-vous admin ? le numéro est-il sur WhatsApp ?');
          }
          return { ok: true, op, participant: digits, status: st };
        }
        case 'subject': {
          const subj = String(message || '').trim();
          if (subj === '') { throw new Error('Sujet vide'); }
          logMsg('info', '[' + id + '] 👥 Sujet → ' + subj);
          await sock.groupUpdateSubject(jid, subj);
          return { ok: true, op, subject: subj };
        }
        case 'description': {
          logMsg('info', '[' + id + '] 👥 Description mise à jour');
          await sock.groupUpdateDescription(jid, String(message || ''));
          return { ok: true, op };
        }
        case 'inviteLink': {
          const code = await sock.groupInviteCode(jid);
          return { ok: true, op, link: 'https://chat.whatsapp.com/' + code };
        }
        case 'revokeInvite': {
          const code = await sock.groupRevokeInvite(jid);
          return { ok: true, op, link: 'https://chat.whatsapp.com/' + code };
        }
        case 'leave': {
          logMsg('info', '[' + id + '] 👥 Quitte le groupe ' + jid);
          await sock.groupLeave(jid);
          return { ok: true, op };
        }
        default:
          throw new Error('Opération groupe inconnue : ' + op);
      }
    }

    // ── Publie un statut WhatsApp (story éphémère 24h) (v0.5 #25) ──────────
    // message = texte du statut (statut texte) ; media_path = image (statut image,
    // message = légende). L'audience (statusJidList) est construite à partir des
    // participants du groupe canal — sans audience, personne ne verrait le statut.
    case 'postStatus': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }

      // Construit l'audience depuis les participants du groupe canal
      let audience = [];
      try {
        const gjid = groupJids[id];
        if (gjid) {
          const meta = await sock.groupMetadata(gjid);
          audience = (meta.participants || []).map(p => p.id).filter(Boolean);
        }
      } catch (e) {
        debug('[' + id + '] postStatus — audience indisponible : ' + e.message);
      }
      if (audience.length === 0) {
        throw new Error('Audience vide — aucun participant connu pour diffuser le statut (groupe canal requis)');
      }

      let content;
      if (media_path) {
        if (!path.isAbsolute(media_path)) { throw new Error('media_path doit être un chemin absolu : ' + media_path); }
        assertMediaPathAllowed(media_path);
      }
      if (media_path && fs.existsSync(media_path)) {
        content = { image: fs.readFileSync(media_path) };
        if (message && String(message).trim() !== '') { content.caption = message; }
      } else {
        const txt = String(message || '').trim();
        if (txt === '') { throw new Error('Statut vide — fournir un texte (message) ou une image (media_path)'); }
        content = { text: txt };
      }

      logMsg('info', '[' + id + '] 📢 Publication statut (' + (content.image ? 'image' : 'texte') + ') → ' + audience.length + ' contact(s)');
      const t = new Promise((_, r) => setTimeout(() => r(new Error('Publication statut — délai dépassé')), 20000));
      const sentStatus = await Promise.race([
        sock.sendMessage('status@broadcast', content, { statusJidList: audience }),
        t,
      ]);
      recordSent(id, sentStatus);
      return { posted: true, recipients: audience.length };
    }

    // ── Archive / épingle / met en sourdine une conversation (v0.5 #24) ────
    // chat_op : archive|unarchive|pin|unpin|mute|unmute
    // chat_value : pour mute, durée en heures (0/absent = 8h par défaut)
    case 'chatModify': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      const jid = resolveJid(id, phone, group_tag);
      const op  = String(chat_op || '').trim().toLowerCase();
      let mod;
      switch (op) {
        case 'archive':
        case 'unarchive': {
          // L'archivage nécessite la référence du dernier message de la conversation
          const last = lastIncomingMsg[id];
          const lastMessages = last
            ? [{ key: last.key, messageTimestamp: last.messageTimestamp }]
            : [];
          mod = { archive: op === 'archive', lastMessages };
          break;
        }
        case 'pin':
        case 'unpin':
          mod = { pin: op === 'pin' };
          break;
        case 'mute':
        case 'unmute': {
          if (op === 'unmute') {
            mod = { mute: null };
          } else {
            const hours = parseInt(chat_value, 10);
            const ms = (!Number.isNaN(hours) && hours > 0) ? hours * 3600 * 1000 : 8 * 3600 * 1000;
            mod = { mute: ms };
          }
          break;
        }
        default:
          throw new Error('Opération inconnue : ' + op + ' (archive|unarchive|pin|unpin|mute|unmute)');
      }
      logMsg('info', '[' + id + '] ⚙ chatModify ' + op + ' → ' + jid);
      const t = new Promise((_, r) => setTimeout(() => r(new Error('chatModify — délai dépassé')), 10000));
      await Promise.race([sock.chatModify(mod, jid), t]);
      return { modified: true, op, jid };
    }

    // ── Marque le dernier message reçu comme lu (coches bleues) (v0.5 #23) ─
    case 'markRead': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      const target = lastIncomingMsg[id];
      if (!target) { throw new Error('Aucun message reçu à marquer comme lu'); }
      logMsg('info', '[' + id + '] ✓✓ Marque comme lu : ' + (target.key.id || '?'));
      const t = new Promise((_, r) => setTimeout(() => r(new Error('Marquage lu — délai dépassé')), 10000));
      await Promise.race([sock.readMessages([target.key]), t]);
      return { read: true, target: target.key.id };
    }

    // ── Définit l'icône (photo de profil) d'un groupe (v0.4) ───────────────
    // L'image (icône du plugin par défaut) est convertie en JPEG carré 640×640
    // via sharp (la transparence est aplatie sur fond blanc). Le compte lié doit
    // être administrateur du groupe pour que WhatsApp accepte la modification.
    case 'setGroupIcon': {
      const sock = sockets[id];
      if (!sock) { throw new Error('Non connecté à WhatsApp — scanner le QR code dans Jeedom'); }
      const jid = resolveJid(id, phone, group_tag);
      if (!String(jid).endsWith('@g.us')) {
        throw new Error('La cible n\'est pas un groupe — l\'icône ne s\'applique qu\'aux groupes');
      }
      if (!media_path || !path.isAbsolute(media_path)) {
        throw new Error('media_path doit être un chemin absolu valide');
      }
      assertMediaPathAllowed(media_path);
      if (!fs.existsSync(media_path)) {
        throw new Error('Image introuvable : ' + media_path);
      }
      let sharp;
      try { sharp = (await import('sharp')).default; }
      catch (e) { throw new Error('Conversion image impossible : dépendance "sharp" absente. Relancez l\'installation des dépendances du plugin.'); }
      const jpeg = await sharp(media_path)
        .resize(640, 640, { fit: 'cover' })
        .flatten({ background: { r: 255, g: 255, b: 255 } })
        .jpeg({ quality: 90 })
        .toBuffer();
      logMsg('info', '[' + id + '] 🖼 Mise à jour icône groupe → ' + jid);
      const t = new Promise((_, r) => setTimeout(() => r(new Error('Mise à jour icône — délai dépassé')), 15000));
      await Promise.race([sock.updateProfilePicture(jid, jpeg), t]);
      return { updated: true, jid };
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
        const sentFb = await Promise.race([sock.sendMessage(jid, { text: message }, sendOpts), t]);
        recordSent(id, sentFb);
        return { sent: true, quoted: false };
      }
      logMsg('info', '[' + id + '] ↩ Reply (quoted) → ' + jid + ' : ' + String(message).substring(0, 60));
      await applyPresence(sock, jid, presence);
      const t = new Promise((_, r) => setTimeout(() => r(new Error('Envoi échoué — délai dépassé')), 10000));
      const sentReply = await Promise.race([sock.sendMessage(jid, { text: message }, Object.assign({ quoted }, sendOpts)), t]);
      recordSent(id, sentReply);
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

    // ── Déconnexion propre du compte WhatsApp ──────────────────────────────
    // Envoie un logout à WhatsApp (délie l'appareil côté téléphone), ferme la
    // socket, puis supprime les credentials locaux (auth/{id}/*) sauf status.txt.
    // Après ça, un nouveau QR code sera nécessaire pour se reconnecter.
    case 'logout': {
      const sock = sockets[id];
      // 1) logout WhatsApp si la socket est active (délie l'appareil côté tel)
      if (sock) {
        try {
          await Promise.race([
            sock.logout(),
            new Promise((_, r) => setTimeout(() => r(new Error('logout timeout')), 8000)),
          ]);
        } catch (e) {
          logMsg('warning', '[' + id + '] logout WhatsApp : ' + e.message + ' — nettoyage local quand même');
        }
        try { sock.ev.removeAllListeners(); } catch (_) {}
        try { sock.end(undefined); } catch (_) {}
        delete sockets[id];
      }
      // 2) nettoyage des credentials locaux (tout sauf status.txt)
      const dir = authDir(id);
      let removed = 0;
      try {
        for (const f of fs.readdirSync(dir)) {
          if (f !== 'status.txt') {
            try { fs.unlinkSync(path.join(dir, f)); removed++; } catch (_) {}
          }
        }
      } catch (_) {}
      // 3) reset état mémoire de l'instance
      delete groupJids[id];
      delete lastIncomingMsg[id];
      delete lastSentMsg[id];
      writeStatus(id, 'logged_out');
      logMsg('info', '[' + id + '] Déconnexion demandée — ' + removed + ' fichier(s) de session supprimé(s)');
      return { logged_out: true, files_removed: removed };
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
        outbox_pending:  outboxPendingCount(id),
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
