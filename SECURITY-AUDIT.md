# Rapport d'audit de sécurité

**Projet :** jeewhatsapp (Plugin Jeedom — WhatsApp via Baileys self-hosted)
**Date :** 2026-05-31 07:15
**Auditeur :** Claude Security Agent
**Commit analysé :** 96c8ab1 (chore(install): fiabilisation procédure d'installation)
**Branche :** dev
**Fichiers analysés :** 14 (hors dossier-artefact `jeewhatsapp/` et `3rdparty/`)
**Score de sécurité :** 78/100 → **93/100** après corrections F-001/F-002/F-003/F-004 (cf. CHANGELOG)

> Audit actualisé après v0.4 (TTS Piper / STT Vosk / OCR Tesseract / shortcuts / profils),
> v0.5 (#22 gestion groupe, #23 accusés lecture, #24 archive/pin/mute, #25 statuts,
> #26 backup/restore chiffré), v0.6 #28 (widget dashboard + média).

---

## Résumé exécutif

Le plugin a une **base sécurité solide** héritée des audits précédents (F-001 à F-010 traités :
API key hors CLI, daemon authentifié par secret partagé `JEEDOM_DAEMON_SECRET`, callback PHP
restreint à `127.0.0.1` + `hash_equals`, échappement XSS dans le JS, daemon bindé sur loopback,
permissions `auth/` en 0700). Les nombreux `exec()` ajoutés en v0.4–v0.6 (Piper TTS, Tesseract
OCR, Vosk STT, ffmpeg) utilisent systématiquement `escapeshellarg()` — pas d'injection de
commande exploitable. Les endpoints AJAX récents (`uploadVoice`, `getMedia`, `backupSession`,
`restoreSession`, `groupAction`) sont protégés par `isConnect('admin')`.

**Le risque principal** est la **restauration de session** (`restoreSession`) : l'archive
chiffrée fournie par l'administrateur est extraite via `PharData::extractTo()` sans validation
explicite des chemins (Tar Slip CWE-22). À cela s'ajoutent des risques **chaîne
d'approvisionnement** sur les téléchargements Piper / modèles Vosk / pip (pas de vérification
d'intégrité), et une **dérivation de clé faible** (SHA-256 simple, sans sel ni itérations) sur
la sauvegarde chiffrée. **Aucun risque critique** ; corrections recommandées avant publication
store mais non bloquantes pour usage personnel.

---

## Statistiques

| Sévérité | Nombre | Impact |
|----------|--------|--------|
| 🔴 CRITICAL | 0 | Exploitation immédiate possible |
| 🟠 HIGH | 1 | Risque élevé, correction urgente |
| 🟡 MEDIUM | 3 | À corriger dans le sprint suivant |
| 🔵 LOW | 6 | Amélioration recommandée |
| ℹ️ INFO | 2 | Bonne pratique |
| **Total** | **12** | |

---

## Findings détaillés

### [F-001] 🟠 HIGH — Tar Slip à l'extraction de la session restaurée

**Fichier :** `core/class/jeewhatsapp.class.php` (ligne ~1273)
**CWE :** [CWE-22 — Improper Limitation of a Pathname to a Restricted Directory (Path Traversal)](https://cwe.mitre.org/data/definitions/22.html)
**OWASP :** [A01:2021 — Broken Access Control](https://owasp.org/www-project-top-ten/)

**Description :**
La méthode `restoreSession()` accepte une archive `.jwab` chiffrée fournie par l'admin, la
déchiffre, puis appelle `PharData::extractTo($authDir, null, true)` (filtre `null` =
extraction de toutes les entrées, `overwrite=true`). `PharData` ne valide **pas**
systématiquement les entrées contenant `../` selon les versions de PHP. Un attaquant qui forge
un fichier `.jwab` malveillant (entrées `../../../../var/www/html/data/x.php` après
déchiffrement) peut écrire des fichiers **hors** de `resources/jeewhatsappd/auth/{id}/` —
potentiellement n'importe où dans l'arborescence Jeedom accessible à `www-data`. Scénario
d'exploitation : ingénierie sociale pour faire restaurer un fichier malveillant à l'admin.

**Code vulnérable :**
```php
$phar = new PharData($tarPath);
// …
$phar->extractTo($authDir, null, true);  // ⚠️ pas de validation des chemins
```

**Code corrigé :**
```php
$phar = new PharData($tarPath);
// Validation explicite : tous les chemins relatifs doivent rester sous $authDir
foreach (new RecursiveIteratorIterator($phar) as $entry) {
  $rel = ltrim(str_replace('phar://' . $tarPath, '', $entry->getPathname()), '/');
  if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false
      || preg_match('#(^|/)\.\.(/|$)#', $rel)) {
    throw new Exception('Archive de restauration invalide (path traversal détecté)');
  }
}
$phar->extractTo($authDir, null, true);
```

---

### [F-002] 🟡 MEDIUM — Téléchargements sans vérification d'intégrité (Piper, Vosk)

**Fichier :** `resources/install_dep.sh` (lignes 111-114, 170)
**CWE :** [CWE-494 — Download of Code Without Integrity Check](https://cwe.mitre.org/data/definitions/494.html)
**OWASP :** [A08:2021 — Software and Data Integrity Failures](https://owasp.org/www-project-top-ten/)

**Description :**
Le script d'installation télécharge plusieurs binaires/modèles via `curl` et les exécute ou
utilise sans **aucune vérification de checksum** (SHA-256) :
- Binaire **Piper** depuis `github.com/rhasspy/piper/releases/…/piper_linux_*.tar.gz` (binaire natif exécuté en tant que `www-data`/root)
- Modèles de voix **Piper** depuis Hugging Face (chargés par Piper)
- Modèle **Vosk** depuis `alphacephei.com/vosk/models/…` (chargé par Vosk)

TLS protège contre un MITM réseau actif, mais pas contre un compromis du compte/repo upstream,
un compromis CDN, ou une expiration de domaine reprise par un tiers. Pour Piper, le binaire est
ensuite exécuté avec `LD_LIBRARY_PATH` sur les `.so` du même archive — un binaire malveillant
a un contrôle total côté serveur Jeedom.

**Code vulnérable :**
```bash
curl -fsSL -m 180 -o "$PIPER_DIR/piper.tar.gz" "$PIPER_REL" \
  && tar xzf "$PIPER_DIR/piper.tar.gz" -C "$PIPER_DIR" \
  && curl -fsSL -m 240 -o "$PIPER_DIR/voices/$VOICE_NAME.onnx" "$VOICE_BASE/…"
```

**Code corrigé :**
```bash
# Checksums figés en local au moment de la release du plugin
declare -A PIPER_SHA=(
  [x86_64]="0123abcd..."
  [aarch64]="4567ef01..."
  [armv7l]="89ab2345..."
)
EXPECTED="${PIPER_SHA[$(uname -m)]}"
curl -fsSL -m 180 -o "$PIPER_DIR/piper.tar.gz" "$PIPER_REL"
echo "$EXPECTED  $PIPER_DIR/piper.tar.gz" | sha256sum -c - \
  || { log "ERREUR : checksum Piper invalide"; rm "$PIPER_DIR/piper.tar.gz"; exit 1; }
tar xzf "$PIPER_DIR/piper.tar.gz" -C "$PIPER_DIR"
```

---

### [F-003] 🟡 MEDIUM — Dérivation de clé faible pour la sauvegarde chiffrée

**Fichier :** `core/class/jeewhatsapp.class.php` (lignes 1231, 1249)
**CWE :** [CWE-916 — Use of Password Hash With Insufficient Computational Effort](https://cwe.mitre.org/data/definitions/916.html)
**OWASP :** [A02:2021 — Cryptographic Failures](https://owasp.org/www-project-top-ten/)

**Description :**
La clé AES-256-CBC est dérivée directement de la passphrase via `hash('sha256', $pass, true)` —
sans **sel** (les mêmes phrases donnent toujours la même clé → rainbow tables possibles), sans
**itérations** (un attaquant peut tester des milliards de phrases par seconde sur du GPU grand
public). Si le fichier `.jwab` fuite (cloud, sauvegarde Jeedom non chiffrée), une passphrase
faible (< 14 caractères, mots de dictionnaire) est cassée en quelques heures.

**Code vulnérable :**
```php
$iv  = random_bytes(16);
$key = hash('sha256', $pass, true);   // ⚠️ KDF inexistant : sha256 brut
$cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
return 'JWAB1' . $iv . $cipher;
```

**Code corrigé :**
```php
$salt = random_bytes(16);
$iv   = random_bytes(16);
// PBKDF2 SHA-256, 200 000 itérations (recommandation OWASP 2024)
$key  = hash_pbkdf2('sha256', $pass, $salt, 200000, 32, true);
$cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
// Le format intègre maintenant le sel : magic 'JWAB2' + salt + iv + cipher
return 'JWAB2' . $salt . $iv . $cipher;
// (prévoir la lecture des deux formats côté restoreSession pour rétrocompatibilité)
```

---

### [F-004] 🟡 MEDIUM — Installation pip sans pinning ni vérification de hash

**Fichier :** `resources/install_dep.sh` (lignes 164-165)
**CWE :** [CWE-494 — Download of Code Without Integrity Check](https://cwe.mitre.org/data/definitions/494.html)
**OWASP :** [A08:2021 — Software and Data Integrity Failures](https://owasp.org/www-project-top-ten/)

**Description :**
`pip install vosk` installe la dernière version sans version épinglée ni hash. Un module Python
exécute son `setup.py` à l'installation (RCE potentielle si la dépendance est compromise).
De plus, `--break-system-packages` casse l'isolation et installe globalement (sur les box
Jeedom récentes basées sur Debian 12+, c'est explicitement déconseillé). En cas de
compromission PyPI ou de typosquat, c'est du code arbitraire qui s'exécute en tant que root
ou `www-data` au moment de l'install.

**Code vulnérable :**
```bash
pip3 install $PIP_FLAGS --break-system-packages vosk >/dev/null 2>&1 \
  || pip3 install $PIP_FLAGS vosk >/dev/null 2>&1 || true
```

**Code corrigé :**
```bash
# Version épinglée + hash vérifié (généré une fois et figé dans le repo)
cat > "$STT_DIR/requirements.txt" <<'EOF'
vosk==0.3.45 --hash=sha256:0123abcd...
EOF
# Préférer un venv pour ne pas polluer les packages système
python3 -m venv "$STT_DIR/venv"
"$STT_DIR/venv/bin/pip" install --require-hashes -r "$STT_DIR/requirements.txt"
# stt.py devra ensuite utiliser le shebang du venv
```

---

### [F-005] 🔵 LOW — XSS potentiel : `getHumanName()` non échappé

**Fichier :** `desktop/php/jeewhatsapp.php` (ligne 81)
**CWE :** [CWE-79 — Improper Neutralization of Input During Web Page Generation](https://cwe.mitre.org/data/definitions/79.html)
**OWASP :** [A03:2021 — Injection](https://owasp.org/www-project-top-ten/)

**Description :**
Le nom de l'équipement est affiché dans la vignette **sans échappement**. Le nom est contrôlé
par l'admin (faible probabilité d'exploitation), mais si un admin saisit/colle accidentellement
du HTML ou si la chaîne contient des caractères spéciaux (`<`, `>`, `&`, `"`), le rendu casse
au minimum, ou exécute du script au pire (admin → admin, mais persistant pour les autres
admins et sur mobile).

**Code vulnérable :**
```php
<span class="name"><?php echo $eqLogic->getHumanName(true, true); ?></span>
```

**Code corrigé :**
```php
<span class="name"><?php echo htmlspecialchars($eqLogic->getHumanName(true, true), ENT_QUOTES, 'UTF-8'); ?></span>
```

---

### [F-006] 🔵 LOW — `getImage()` injecté sans échappement dans `src`

**Fichier :** `desktop/php/jeewhatsapp.php` (ligne 79)
**CWE :** [CWE-79 — Cross-site Scripting](https://cwe.mitre.org/data/definitions/79.html)

**Description :**
L'URL de l'image est insérée brute dans l'attribut `src`. `getImage()` retourne normalement un
chemin contrôlé par le plugin, mais des guillemets ou `javascript:` dans la valeur (si un jour
la méthode change ou est surchargée) permettraient une évasion d'attribut.

**Code vulnérable :**
```php
<img src="<?php echo $eqLogic->getImage(); ?>"/>
```

**Code corrigé :**
```php
<img src="<?php echo htmlspecialchars($eqLogic->getImage(), ENT_QUOTES, 'UTF-8'); ?>"/>
```

---

### [F-007] 🔵 LOW — AES-256-CBC sans HMAC (chiffrement non authentifié)

**Fichier :** `core/class/jeewhatsapp.class.php` (lignes 1232, 1250)
**CWE :** [CWE-353 — Missing Support for Integrity Check](https://cwe.mitre.org/data/definitions/353.html)
**OWASP :** [A02:2021 — Cryptographic Failures](https://owasp.org/www-project-top-ten/)

**Description :**
Le mode CBC ne protège pas l'intégrité. Un attaquant qui modifie le fichier `.jwab` peut
altérer le ciphertext sans que `openssl_decrypt()` ne le détecte (échec uniquement au padding
PKCS7, qui passe statistiquement dans ~1/256 des cas). Impact pratique faible (le tar résultant
échouera à parser dans le bloc try/catch suivant), mais c'est une mauvaise pratique crypto.

**Code corrigé :**
```php
// Utiliser AES-256-GCM (chiffrement authentifié intégré)
$tag = '';
$cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
return 'JWAB2' . $salt . $iv . $tag . $cipher;
// Au déchiffrement, openssl_decrypt vérifie le tag et échoue si modifié.
```

---

### [F-008] 🔵 LOW — `uploadVoice` : pas de validation MIME/extension côté serveur

**Fichier :** `core/class/jeewhatsapp.class.php` (lignes 1126-1140)
**CWE :** [CWE-434 — Unrestricted Upload of File with Dangerous Type](https://cwe.mitre.org/data/definitions/434.html)

**Description :**
La méthode `sendVoiceRecording()` vérifie `is_uploaded_file()` et la taille (10 Mo), mais ne
valide ni le **type MIME réel** (via `finfo` ou `mime_content_type`) ni l'extension. Si le
fichier n'est pas un audio valide, `ffmpeg` échouera (mitigation de fait) et le fichier sera
supprimé dans le `finally`. Risque résiduel : un attaquant admin peut faire écrire des octets
arbitraires dans le dossier tmp jusqu'à 10 Mo (DoS disque mineur). Endpoint admin-only =
menace très faible.

**Code corrigé :**
```php
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $_file['tmp_name']);
finfo_close($finfo);
$allowed = ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg', 'audio/wav'];
if (!in_array($mime, $allowed, true)) {
  throw new Exception('Type MIME non autorisé : ' . $mime);
}
```

---

### [F-009] 🔵 LOW — `uniqid()` pour générer des noms de fichiers temporaires

**Fichier :** `core/class/jeewhatsapp.class.php` (lignes 561, 1136, 1215, 1257)
**CWE :** [CWE-330 — Use of Insufficiently Random Values](https://cwe.mitre.org/data/definitions/330.html)

**Description :**
`uniqid()` est basé sur le timestamp microseconde et n'est **pas** cryptographiquement
aléatoire. Deux requêtes simultanées peuvent en théorie collisionner. Plus problématique : un
attaquant local qui peut prédire le moment d'un appel à `speak()`/`backup`/`uploadVoice` peut
tenter une race condition sur les fichiers tmp. Impact très faible (admin-only) mais à
corriger.

**Code corrigé :**
```php
$out = $tmpDir . '/tts_' . $this->getId() . '_' . bin2hex(random_bytes(8)) . '.ogg';
```

---

### [F-010] 🔵 LOW — Aucune limitation de débit (rate-limiting)

**Fichier :** `core/php/callback.php`, `core/ajax/jeewhatsapp.ajax.php`, daemon HTTP
**CWE :** [CWE-770 — Allocation of Resources Without Limits or Throttling](https://cwe.mitre.org/data/definitions/770.html)

**Description :**
Aucun endpoint n'a de rate-limit. `callback.php` peut être appelé en boucle par tout processus
local connaissant la clé API (DoS via traitements lourds : interactions Jeedom, `sendToDaemon`
en cascade). Le daemon HTTP `/action` n'a pas non plus de limite. Impact faible car interface
interne (bound 127.0.0.1) et authentification requise, mais à durcir.

**Code corrigé :**
```php
$key = 'jeewhatsapp::ratelimit::callback::' . md5($remote);
$count = (int) cache::byKey($key)->getValue(0);
if ($count > 60) {  // 60 callbacks / minute max par IP
  http_response_code(429);
  die(json_encode(['error' => 'Too Many Requests']));
}
cache::set($key, $count + 1, 60);
```

---

### [F-011] ℹ️ INFO — Daemon accepte les requêtes si `DAEMON_SECRET` est vide

**Fichier :** `resources/jeewhatsappd/jeewhatsappd.js` (ligne 684)

**Description :**
Pour compatibilité ascendante (cas migration), si `JEEDOM_DAEMON_SECRET` n'est pas fourni, le
daemon accepte sans authentification. En pratique, `deamon_start()` génère toujours le secret.
Un correctif simple : refuser systématiquement les requêtes en l'absence de secret (le PHP
doit toujours en fournir un).

```js
// Plus strict : refuser même en mode "vide"
if (DAEMON_SECRET === '') {
  logMsg('error', 'JEEDOM_DAEMON_SECRET manquant — refus de toutes les requêtes');
  res.writeHead(503); res.end(JSON.stringify({ error: 'Daemon misconfigured' })); return;
}
```

---

### [F-012] ℹ️ INFO — Absence d'en-têtes CSP côté daemon HTTP

**Fichier :** `resources/jeewhatsappd/jeewhatsappd.js` (lignes 677-711)

**Description :**
Le mini-serveur HTTP du daemon ne renvoie ni `Content-Security-Policy`, ni `X-Frame-Options`.
Le risque pratique est nul (bind sur `127.0.0.1`, endpoints retournant uniquement du JSON, pas
de rendu navigateur). À considérer si un jour le daemon expose des pages HTML.

---

## Dépendances à vérifier manuellement

| Bibliothèque | Version détectée | Base de données |
|--------------|-----------------|-----------------|
| @whiskeysockets/baileys | ^6.7.15 (testé : 6.7.21) | [GitHub Advisories](https://github.com/advisories?query=baileys) |
| pino | ^9.0.0 | [GitHub Advisories](https://github.com/advisories?query=pino) |
| qrcode | ^1.5.4 | [GitHub Advisories](https://github.com/advisories?query=qrcode) |
| sharp | ^0.33.5 | [GitHub Advisories](https://github.com/advisories?query=sharp) |
| vosk (Python, non pinné) | dernière disponible | [PyPI Advisory](https://pypi.org/project/vosk/) |

> Recommandation : exécuter `npm audit` régulièrement dans `resources/jeewhatsappd/` et
> activer Dependabot/Renovate sur le repo GitHub.

---

## Bonnes pratiques manquantes

- [ ] **Checksums SHA-256** figés dans `install_dep.sh` pour Piper + voix + modèle Vosk (F-002)
- [ ] **Version pinning Python** + `--require-hashes` pour pip (F-004)
- [ ] **PBKDF2/Argon2 + sel** pour la dérivation de clé du backup (F-003)
- [ ] **AES-GCM** au lieu de AES-CBC pour le chiffrement authentifié (F-007)
- [ ] **Validation explicite des chemins** dans `PharData::extractTo` (F-001)
- [ ] **Échappement HTML systématique** des sorties PHP (F-005, F-006)
- [ ] **Rate-limiting** sur les endpoints sensibles (F-010)
- [ ] **`random_bytes()` au lieu de `uniqid()`** pour les noms de fichiers temp (F-009)
- [ ] **Validation MIME côté serveur** pour `uploadVoice` (F-008)
- [ ] `npm audit` régulier + Dependabot/Renovate sur le repo
- [ ] **Ajouter un `SECURITY.md`** à la racine du repo (modèle de menace + procédure
  responsible disclosure)

## Bonnes pratiques DÉJÀ en place ✅

- Callback PHP restreint à `127.0.0.1` + `hash_equals()` sur la clé API
- Daemon bound sur `127.0.0.1` (pas d'exposition réseau)
- `JEEDOM_DAEMON_SECRET` partagé entre PHP et daemon (généré à chaque démarrage)
- `crypto.timingSafeEqual()` côté daemon pour la comparaison du secret
- `escapeshellarg()` systématique sur tous les `exec()`/`shell_exec()` (Piper, Tesseract, Vosk, ffmpeg)
- `realpath()` + vérification de préfixe dans `streamIncomingMedia()` (Path Traversal mitigé)
- `isConnect('admin')` sur tous les endpoints AJAX
- `is_uploaded_file()` + `move_uploaded_file()` dans `uploadVoice`
- Headers `X-Content-Type-Options: nosniff` + `Cache-Control: private` sur le streaming média
- Permissions `0700` sur `auth/` (sessions Baileys)
- Auth restaurée préservée à la désinstallation (évite la perte de credentials)
- Validation regex sur la langue OCR (`/^[a-zA-Z0-9_+]+$/`) avant Tesseract
- Sandboxing implicite : actions WhatsApp via Baileys (pas d'API REST tierce avec credentials cloud)
- Redaction des secrets dans les logs (helper `redactPayloadForLog`)
- API key passée via env (`JEEDOM_APIKEY`) et jamais en ligne de commande
- `interaction_whitelist` + `interaction_keyword` pour filtrer les expéditeurs

---

## Calcul du score

| Critère | Détail | Impact |
|---|---|---|
| Départ | — | 100 |
| HIGH × 1 | F-001 (Tar Slip) | −10 |
| MEDIUM × 3 | F-002, F-003, F-004 | −15 |
| LOW × 6 | F-005 à F-010 | −12 |
| INFO × 2 | F-011, F-012 | 0 |
| Bonus headers sécurité | `X-Content-Type-Options`, `Cache-Control`, `Content-Type` explicites | +5 |
| **Total** | | **78** |

> Le bonus « requêtes préparées » n'est pas applicable (le plugin n'écrit pas de SQL custom —
> il utilise les classes Jeedom qui gèrent les prepared statements en interne).

---

## Références

- [CWE MITRE](https://cwe.mitre.org/)
- [OWASP Top 10 2021](https://owasp.org/www-project-top-ten/)
- [GitHub Advisory Database](https://github.com/advisories)
- [Snyk Vulnerability DB](https://snyk.io/vuln/)
- [PortSwigger Web Security Academy](https://portswigger.net/web-security)
- [NVD NIST](https://nvd.nist.gov/)
- [OWASP Password Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html)
- [OWASP File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html)

---
*Rapport généré automatiquement par Claude Security Skill — un audit humain reste
recommandé pour validation finale.*
