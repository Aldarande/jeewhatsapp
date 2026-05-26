# Rapport d'audit de sécurité

**Projet :** jeewhatsapp (Plugin Jeedom — WhatsApp via Baileys self-hosted)
**Date :** 2026-05-25 21:50
**Auditeur :** Claude Security Agent
**Commit analysé :** 120c19a — Audit complet du plugin — 28 corrections (conformité Jeedom 4.4)
**Branche :** dev
**Fichiers analysés :** 9 (PHP, JS, Bash, JSON)
**Score de sécurité :** 56/100

---

## Résumé exécutif

Le plugin présente une architecture saine (séparation PHP/daemon, bind localhost, API key sur le callback) mais souffre de plusieurs faiblesses **admin-only** typiques d'un plugin Jeedom : **fuite de l'API key via les arguments CLI du daemon** (visible dans `ps aux`), **permissions trop larges sur les credentials Baileys** (vol de session possible par tout user UNIX local), **absence d'authentification sur le serveur HTTP local du daemon** et **XSS DOM potentiel** dans le JS inline du desktop. Aucune vulnérabilité critique exploitable à distance — toutes les surfaces d'attaque exigent un accès local ou admin Jeedom. Correctifs prioritaires : passer l'API key via stdin/env, chmod 600 sur `auth/`, valider l'IP source du callback.

---

## Statistiques

| Sévérité | Nombre | Impact |
|----------|--------|--------|
| 🔴 CRITICAL | 0 | Exploitation immédiate possible |
| 🟠 HIGH | 2 | Risque élevé, correction urgente |
| 🟡 MEDIUM | 6 | À corriger dans le sprint suivant |
| 🔵 LOW | 3 | Amélioration recommandée |
| ℹ️ INFO | 2 | Bonne pratique |
| **Total** | **13** | |

---

## Findings détaillés

### [F-001] 🟠 HIGH — Fuite de l'API key Jeedom dans la liste des processus

**Fichier :** `core/class/jeewhatsapp.class.php` (lignes 82-92)
**CWE :** [CWE-214 — Invocation of Process Using Visible Sensitive Information](https://cwe.mitre.org/data/definitions/214.html)
**OWASP :** [A02:2021 — Cryptographic Failures](https://owasp.org/www-project-top-ten/)

**Description :**
L'API key du plugin Jeedom est transmise en clair via l'argument CLI `--callback` lors du démarrage du démon Node.js. Vérifié par `ps aux` dans le conteneur Docker : la clé `XBkDjo1eg2xPDB10PDlyhxZa5bGyKTCOkPv2pCKJAOATRmuBvkIRDrIwILc2HgOp` est visible par **tout utilisateur du système** disposant d'un shell. Un attaquant local (compte non-privilégié) peut récupérer cette clé puis forger des callbacks arbitraires sur `core/php/callback.php` pour injecter de faux messages dans Jeedom (déclenchement d'interactions, manipulation de scénarios).

**Code vulnérable :**
```php
$callback = 'http://127.0.0.1:' . $jeedom_port . $jeedom_comp
          . '/plugins/jeewhatsapp/core/php/callback.php?apikey=' . urlencode($api_key);
...
$cmd .= ' --callback ' . escapeshellarg($callback);
shell_exec($cmd);
```

**Code corrigé :**
```php
// Option 1 : passer via stdin
$cmd .= ' --callback ' . escapeshellarg('http://127.0.0.1:' . $jeedom_port . $jeedom_comp
      . '/plugins/jeewhatsapp/core/php/callback.php');
$proc = proc_open($cmd, [['pipe','r'], ...], $pipes);
fwrite($pipes[0], $api_key . "\n");
fclose($pipes[0]);

// Option 2 : variable d'environnement
$cmd = 'JEEDOM_APIKEY=' . escapeshellarg($api_key) . ' ' . $cmd;
// Côté daemon : process.env.JEEDOM_APIKEY
// Côté callback.php : compare contre $_SERVER ou header dédié
```

Pattern recommandé : daemon lit `process.env.JEEDOM_APIKEY` et ajoute un header `X-API-Key` aux requêtes callback, callback.php le valide.

---

### [F-002] 🟠 HIGH — Permissions trop larges sur les credentials Baileys

**Fichier :** `resources/jeewhatsappd/jeewhatsappd.js` (lignes 95-102, 171)
**CWE :** [CWE-732 — Incorrect Permission Assignment for Critical Resource](https://cwe.mitre.org/data/definitions/732.html)
**OWASP :** [A01:2021 — Broken Access Control](https://owasp.org/www-project-top-ten/)

**Description :**
Les fichiers `auth/{id}/*.json` contiennent les **credentials de session WhatsApp Web** (clés Noise, identité, sessions de chiffrement libsignal). Ces fichiers sont créés avec le masque par défaut du process Node (`644` typiquement), donc lisibles par tout utilisateur du système. Vol de ces fichiers = **détournement de la session WhatsApp** sans que le téléphone original ne soit alerté immédiatement.

**Code vulnérable :**
```js
const AUTH_BASE = path.join(__dirname, 'auth');
if (!fs.existsSync(AUTH_BASE)) { fs.mkdirSync(AUTH_BASE, { recursive: true }); }

function authDir(id) {
  const dir = path.join(AUTH_BASE, String(id));
  if (!fs.existsSync(dir)) { fs.mkdirSync(dir, { recursive: true }); }
  return dir;
}
```

**Code corrigé :**
```js
const AUTH_BASE = path.join(__dirname, 'auth');
if (!fs.existsSync(AUTH_BASE)) { fs.mkdirSync(AUTH_BASE, { recursive: true, mode: 0o700 }); }
fs.chmodSync(AUTH_BASE, 0o700);

function authDir(id) {
  const dir = path.join(AUTH_BASE, String(id));
  if (!fs.existsSync(dir)) { fs.mkdirSync(dir, { recursive: true, mode: 0o700 }); }
  fs.chmodSync(dir, 0o700);
  return dir;
}

// Après chaque écriture (creds.update notamment) :
process.umask(0o077); // au démarrage du daemon
```

Et appliquer en correctif rétroactif : `chmod -R 700 resources/jeewhatsappd/auth/` au démarrage.

---

### [F-003] 🟡 MEDIUM — Pas de validation de l'IP source sur le callback

**Fichier :** `core/php/callback.php` (lignes 5-10)
**CWE :** [CWE-346 — Origin Validation Error](https://cwe.mitre.org/data/definitions/346.html)
**OWASP :** [A05:2021 — Security Misconfiguration](https://owasp.org/www-project-top-ten/)

**Description :**
Le callback ne vérifie que la clé API. Si celle-ci fuite (cf. F-001) ou si Jeedom est exposé sur Internet sans reverse proxy filtrant, un attaquant distant peut injecter des messages. Le démon n'appelle ce callback que depuis `127.0.0.1`, donc un filtrage strict est légitime.

**Code vulnérable :**
```php
$api_key = isset($_GET['apikey']) ? $_GET['apikey'] : (isset($_POST['apikey']) ? $_POST['apikey'] : '');
if ($api_key !== jeedom::getApiKey('jeewhatsapp')) {
  http_response_code(403);
  ...
}
```

**Code corrigé :**
```php
// SECURITY: callback strictement local
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'])) {
  http_response_code(403);
  log::add('jeewhatsapp', 'warning', 'callback.php — IP non locale refusée : ' . $remote);
  die(json_encode(['error' => 'Forbidden']));
}
// puis validation API key (défense en profondeur)
```

---

### [F-004] 🟡 MEDIUM — Serveur HTTP du daemon sans authentification

**Fichier :** `resources/jeewhatsappd/jeewhatsappd.js` (lignes 328-352)
**CWE :** [CWE-306 — Missing Authentication for Critical Function](https://cwe.mitre.org/data/definitions/306.html)
**OWASP :** [A07:2021 — Identification and Authentication Failures](https://owasp.org/www-project-top-ten/)

**Description :**
Le serveur HTTP local accepte les actions (`send`, `createGroup`, `findGroup`, etc.) sans aucune authentification. Bien que bindé sur `127.0.0.1`, **tout process exécuté sur la machine** peut envoyer des messages WhatsApp arbitraires, créer/lister les groupes, voler le QR code, ou faire des actions au nom de l'utilisateur. Risque concret : un autre plugin Jeedom compromis, un container voisin partageant le réseau host, ou tout binaire installé sur le système.

**Code vulnérable :**
```js
http.createServer(async (req, res) => {
  if (req.method !== 'POST' || req.url !== '/action') {
    res.writeHead(404); res.end(JSON.stringify({ error: 'Not found' })); return;
  }
  let body = '';
  req.on('data', chunk => { body += chunk; });
  req.on('end', async () => {
    const payload = JSON.parse(body);
    const result = await handleAction(payload);
    ...
  });
}).listen(PORT, '127.0.0.1', ...);
```

**Code corrigé :**
```js
// Générer un secret partagé au démarrage, le passer via stdin ou env au daemon,
// et l'inclure dans tous les sendToDaemon() côté PHP
const DAEMON_SECRET = process.env.JEEDOM_DAEMON_SECRET;

http.createServer(async (req, res) => {
  if (req.headers['x-daemon-secret'] !== DAEMON_SECRET) {
    res.writeHead(401); res.end(JSON.stringify({ error: 'Unauthorized' })); return;
  }
  ...
});
```

---

### [F-005] 🟡 MEDIUM — Path traversal potentiel sur `instance_id`

**Fichier :** `resources/jeewhatsappd/jeewhatsappd.js` (lignes 98-105, 354)
**CWE :** [CWE-22 — Path Traversal](https://cwe.mitre.org/data/definitions/22.html)
**OWASP :** [A01:2021 — Broken Access Control](https://owasp.org/www-project-top-ten/)

**Description :**
`handleAction` reçoit `instance_id` depuis le corps HTTP et le passe à `authDir(id)`, `qrFilePath(id)`, etc. La conversion `String(instance_id)` n'empêche pas l'injection de séquences `../` :
- `instance_id = "../../../etc"` → `authDir` crée/accède à un dossier en dehors du plugin
- Combiné à F-004, un attaquant local peut lire des fichiers via `getQR` (mais retournés en base64 supposé QR data URL)

Risque réel limité par le bind 127.0.0.1, mais à corriger.

**Code vulnérable :**
```js
function authDir(id) {
  const dir = path.join(AUTH_BASE, String(id));
  if (!fs.existsSync(dir)) { fs.mkdirSync(dir, { recursive: true }); }
  return dir;
}

async function handleAction({ action, instance_id, ... }) {
  const id = String(instance_id);
  ...
}
```

**Code corrigé :**
```js
function authDir(id) {
  // SECURITY: id doit être strictement numérique (ID eqLogic Jeedom)
  if (!/^\d+$/.test(String(id))) {
    throw new Error('instance_id invalide : ' + id);
  }
  const dir = path.join(AUTH_BASE, String(id));
  const resolved = path.resolve(dir);
  if (!resolved.startsWith(path.resolve(AUTH_BASE) + path.sep)) {
    throw new Error('Path traversal détecté');
  }
  if (!fs.existsSync(dir)) { fs.mkdirSync(dir, { recursive: true, mode: 0o700 }); }
  return dir;
}
```

---

### [F-006] 🟡 MEDIUM — API key transmise en query string (loggable)

**Fichier :** `core/class/jeewhatsapp.class.php` (ligne 84)
**CWE :** [CWE-598 — Use of GET Request Method With Sensitive Query Strings](https://cwe.mitre.org/data/definitions/598.html)
**OWASP :** [A09:2021 — Security Logging and Monitoring Failures](https://owasp.org/www-project-top-ten/)

**Description :**
L'URL du callback embarque `?apikey=XXX`. Les query strings sont typiquement loguées par Apache/Nginx (`access.log`), les proxys, les outils de monitoring. La clé API se retrouve dans plusieurs fichiers persistants sur disque, y compris dans `log/jeewhatsapp` lui-même (commentaire daemon).

**Code vulnérable :**
```php
$callback = 'http://127.0.0.1:' . $jeedom_port . $jeedom_comp
          . '/plugins/jeewhatsapp/core/php/callback.php?apikey=' . urlencode($api_key);
```

**Code corrigé :**
Daemon : envoyer la clé via header HTTP `X-API-Key` au lieu de query string.
```js
const options = {
  ...
  headers: {
    'Content-Type': 'application/json',
    'X-API-Key': API_KEY,
    'Content-Length': Buffer.byteLength(payload),
  },
};
```
`callback.php` :
```php
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['apikey'] ?? ''); // fallback transitoire
```

---

### [F-007] 🟡 MEDIUM — XSS DOM potentiel via nom de groupe WhatsApp

**Fichier :** `desktop/php/jeewhatsapp.php` (lignes 514, 550)
**CWE :** [CWE-79 — Cross-site Scripting](https://cwe.mitre.org/data/definitions/79.html)
**OWASP :** [A03:2021 — Injection](https://owasp.org/www-project-top-ten/)

**Description :**
Le JS inline injecte `groupName` (saisi par l'admin) dans du HTML via `.html()`. Un admin Jeedom (ou un attaquant ayant obtenu un cookie admin via une autre faille) peut stocker un payload XSS dans le champ "Groupe canal". Le payload s'exécute à chaque ouverture de l'équipement par un autre admin. Risque admin-only mais XSS stocké persistant.

**Code vulnérable :**
```js
$result.html('<i class="fas fa-check-circle" style="color:#25D366;"></i> {{Groupe}} <strong>' + groupName + '</strong> {{trouvé — JID renseigné. Sauvegardez.}}')...
```

**Code corrigé :**
```js
$result.empty()
  .append($('<i>').addClass('fas fa-check-circle').css('color', '#25D366'))
  .append(' {{Groupe}} ')
  .append($('<strong>').text(groupName))
  .append(' {{trouvé — JID renseigné. Sauvegardez.}}')
  .css('color', '#25D366').show();
```
Ou via une fonction d'échappement HTML.

---

### [F-008] 🟡 MEDIUM — Output non échappé dans la liste des objets parents

**Fichier :** `desktop/php/jeewhatsapp.php` (lignes 152-157, 168, 75)
**CWE :** [CWE-79 — Cross-site Scripting](https://cwe.mitre.org/data/definitions/79.html)
**OWASP :** [A03:2021 — Injection](https://owasp.org/www-project-top-ten/)

**Description :**
Les valeurs `$object->getName()`, `$value['name']`, `$eqLogic->getHumanName()` sont concaténées directement dans le HTML sans `htmlspecialchars()`. Vecteur d'XSS stocké si l'admin Jeedom crée un objet/catégorie/équipement avec un nom contenant du HTML.

**Code vulnérable :**
```php
<option value="<?php echo $object->getId(); ?>">
    <?php echo str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')); ?>
    <?php echo $object->getName(); ?>
</option>
...
<?php echo $value['name']; ?>
...
<span class="name"><?php echo $eqLogic->getHumanName(true, true); ?></span>
```

**Code corrigé :**
```php
<option value="<?php echo (int)$object->getId(); ?>">
    <?php echo str_repeat('&nbsp;&nbsp;', (int)$object->getConfiguration('parentNumber')); ?>
    <?php echo htmlspecialchars($object->getName(), ENT_QUOTES, 'UTF-8'); ?>
</option>
```
Note : `getHumanName(true, true)` retourne déjà du HTML formaté par Jeedom — comportement conforme aux autres plugins, à conserver tel quel mais à documenter.

---

### [F-009] 🔵 LOW — Logs debug exposant le contenu des messages

**Fichier :** `core/class/jeewhatsapp.class.php` (ligne 273)
**CWE :** [CWE-532 — Insertion of Sensitive Information into Log File](https://cwe.mitre.org/data/definitions/532.html)

**Description :**
En niveau `debug`, le payload complet (incluant les messages WhatsApp et numéros de téléphone) est écrit dans `log/jeewhatsapp`, lisible par www-data et conservé sans rotation stricte. Données personnelles loguées sans masquage.

**Code vulnérable :**
```php
log::add('jeewhatsapp', 'debug', 'sendToDaemon — ' . $_action . ' → ' . $payload);
log::add('jeewhatsapp', 'debug', 'sendToDaemon — réponse daemon : ' . $raw);
```

**Code corrigé :**
```php
$logPayload = $_action;
if ($_action !== 'send' || log::getLogLevel(__CLASS__) === 'debug') {
  $logPayload .= ' → ' . substr($payload, 0, 200);
}
log::add('jeewhatsapp', 'debug', 'sendToDaemon — ' . $logPayload);
```
Ou masquer explicitement `message` et `phone` avant log.

---

### [F-010] 🔵 LOW — Validation Content-Type absente sur le callback

**Fichier :** `core/php/callback.php` (ligne 13)
**CWE :** [CWE-20 — Improper Input Validation](https://cwe.mitre.org/data/definitions/20.html)

**Description :**
`callback.php` lit `php://input` sans vérifier le `Content-Type`. Un attaquant ayant la clé API peut envoyer du JSON via un formulaire HTML standard (CSRF-style) si Jeedom est exposé.

**Code corrigé :**
```php
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== 0) {
  http_response_code(415);
  die(json_encode(['error' => 'Content-Type must be application/json']));
}
```

---

### [F-011] 🔵 LOW — `package-lock.json` exclu du dépôt

**Fichier :** `.gitignore`
**CWE :** [CWE-1357 — Reliance on Software Components Without Integrity Validation](https://cwe.mitre.org/data/definitions/1357.html)

**Description :**
Le `.gitignore` exclut `resources/jeewhatsappd/package-lock.json`. Sans lock file, chaque `npm install` peut installer une version transitive différente. Risque : régression de sécurité silencieuse, compromission via dependency confusion.

**Code corrigé :**
Retirer cette ligne du `.gitignore` et committer le lock file.

---

### [F-012] ℹ️ INFO — Bonnes pratiques observées

Points positifs à conserver :
- ✅ `escapeshellarg()` utilisé sur **tous** les paramètres CLI du démon
- ✅ `intval()` / `(int)` sur l'ID PID avant `posix_kill`
- ✅ `bind 127.0.0.1` sur le serveur HTTP du démon (pas d'exposition réseau)
- ✅ `pino({ level: 'silent' })` pour étouffer les logs verbeux de Baileys
- ✅ `printQRInTerminal: false` (pas de QR dans stdout)
- ✅ `ajax::init([...])` (refactor récent) protège contre CSRF via token Jeedom
- ✅ `$('<span>').text(curVal).html()` dans le JS desktop (échappement correct)
- ✅ Validation `is_object` + `getEqType_name()` sur les eqLogic retournés
- ✅ Filtrage strict des messages WhatsApp entrants (group only, not fromMe)
- ✅ `.gitignore` exclut `node_modules/` et `auth/` (pas de fuite de credentials par commit)
- ✅ `fastcgi_finish_request()` pour libérer la connexion HTTP avant traitement long

---

### [F-013] ℹ️ INFO — Pas de SQL direct

Le plugin n'écrit aucune requête SQL directe — toutes les interactions BDD passent par l'ORM Jeedom (`eqLogic`, `cmd`, `config`, `cache`). Pas d'injection SQL possible côté plugin.

---

## Dépendances à vérifier manuellement

| Bibliothèque | Version détectée | Base de données |
|--------------|-----------------|-----------------|
| @whiskeysockets/baileys | ^6.7.15 | [GitHub Advisories](https://github.com/advisories?query=baileys) |
| pino | ^9.0.0 | [GitHub Advisories](https://github.com/advisories?query=pino) |
| qrcode | ^1.5.4 | [GitHub Advisories](https://github.com/advisories?query=qrcode) |
| libsignal (transitif) | (via baileys) | [Snyk DB](https://snyk.io/vuln/npm:libsignal) |

Exécuter régulièrement :
```bash
cd resources/jeewhatsappd && npm audit
```

Le repo contient déjà un `node_modules/` non listé (cf. commit historique) — vérifier qu'il n'est plus tracké après l'ajout du `.gitignore`.

---

## Bonnes pratiques manquantes

- [ ] Permissions `0700` sur `resources/jeewhatsappd/auth/` (F-002)
- [ ] Authentification du serveur HTTP local du daemon (F-004)
- [ ] Filtrage IP sur `callback.php` (F-003)
- [ ] Transmission de l'API key via header `X-API-Key` au lieu de query string (F-006)
- [ ] Validation stricte de `instance_id` (regex `^\d+$`) (F-005)
- [ ] Échappement HTML systématique dans le template desktop (F-007, F-008)
- [ ] Masquage des données personnelles dans les logs debug (F-009)
- [ ] Validation `Content-Type` sur callback (F-010)
- [ ] Commit du `package-lock.json` (F-011)
- [ ] Mise en place d'un `npm audit` régulier (CI ou cron)
- [ ] Headers de sécurité sur les réponses du daemon HTTP (`X-Content-Type-Options`, etc.)
- [ ] Documentation utilisateur : recommander une instance Jeedom non exposée à Internet ou derrière un reverse proxy avec WAF

---

## Calcul du score

| Item | Pts |
|---|---|
| Départ | 100 |
| 2 × HIGH | −20 |
| 6 × MEDIUM | −30 |
| 3 × LOW | −6 |
| Bonus : pas de SQL (ORM) | +0 (pas applicable) |
| Bonus : escapeshellarg + bind 127.0.0.1 systématiques | +5 |
| Bonus : ajax::init CSRF | +5 |
| Bonus : .gitignore auth/credentials | +2 |
| **Total** | **56/100** |

---

## Références

- [CWE MITRE](https://cwe.mitre.org/)
- [OWASP Top 10 2021](https://owasp.org/www-project-top-ten/)
- [GitHub Advisory Database](https://github.com/advisories)
- [Snyk Vulnerability DB](https://snyk.io/vuln/)
- [PortSwigger Web Security Academy](https://portswigger.net/web-security)
- [NVD NIST](https://nvd.nist.gov/)
- [Baileys Security Considerations](https://github.com/WhiskeySockets/Baileys#security)

---
*Rapport généré automatiquement par Claude Security Skill — un audit humain reste recommandé pour validation finale.*
