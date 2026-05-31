# Changelog

Tous les changements notables de ce projet sont documentés ici.

Le format est basé sur [Keep a Changelog 1.1.0](https://keepachangelog.com/fr/1.1.0/)
et ce projet adhère à [Semantic Versioning 2.0.0](https://semver.org/).

## [Unreleased]

### Security

- **F-001 (HIGH, Tar Slip)** — `restoreSession()` valide désormais explicitement chaque entrée
  de l'archive `.jwab` avant `PharData::extractTo()` : refus si l'entrée contient `..`, débute
  par `/`, ou contient un octet nul (CWE-22). Garde-fou même si PharData laissait passer.
- **F-003 (MEDIUM, KDF faible)** — nouveau format **JWAB2** : sel aléatoire (16 o) + PBKDF2-
  SHA256 200 000 itérations (recommandation OWASP 2024) au lieu d'un simple `sha256(pass)`.
  Casse le brute-force GPU sur une passphrase volée. Format **JWAB1** legacy reste
  restaurable en lecture seule, ré-encodage automatique en JWAB2 à la prochaine sauvegarde.
- **F-004 (MEDIUM, pip pinning)** — `vosk` est installé via `resources/stt/requirements.txt`
  (`vosk==0.3.45`) au lieu de la dernière version disponible. Évite l'installation
  silencieuse d'une version compromise (typosquat, push malveillant).
- **F-002 (MEDIUM, intégrité downloads)** — vérification **SHA-256** des binaires/modèles
  téléchargés dans `install_dep.sh` : Piper (x86_64 / aarch64 / armv7l), voix française,
  modèle Vosk fr. Helper `check_sha256()`. Si le hash ne correspond pas, l'installation
  échoue proprement (TTS/STT désactivé, le plugin continue de fonctionner).

### Changed

- **Procédure d'installation** — passe de fiabilité :
  - `pluginVersion` mis à jour de `0.1` à `0.5` (était faux depuis plusieurs versions).
  - `maxDependancyInstallTime` passé de **60 → 600 secondes** (Piper + Vosk ne tiennent pas
    dans 1 minute, surtout sur ARM).
  - `install_dep.sh` : `cd && npm install` remplacé par bloc séparé (vrai code de retour de
    npm), flags `--omit=dev --no-audit --no-fund` (plus rapide, moins de Mo).
  - `install.php::jeewhatsapp_remove()` ne **supprime plus le dossier `auth/`** : les sessions
    WhatsApp survivent à une désinstallation/réinstallation (plus besoin de re-scanner le QR).
  - Doc : ajout d'une section **Installation** (prérequis, étapes, mise à jour, compat).

### Added

- **Sauvegarde / restauration de session** 🛡️ (v0.5 #26) — export **chiffré AES-256** du dossier
  d'authentification Baileys (`auth/{id}/`) protégé par une **phrase de passe**, pour restaurer la
  connexion **après une réinstallation sans re-scanner le QR code**. 100 % PHP natif
  (`openssl_encrypt` + `PharData`, aucune dépendance externe → portable). Boutons « Sauvegarder »
  (télécharge un fichier `.jwab`) et « Restaurer » (upload) dans la config de l'équipement.
  Endpoints AJAX `backupSession`/`restoreSession`, méthodes PHP `backupSession()`/`restoreSession()`.
  La restauration conserve l'ancienne session en `.bak` et redémarre le démon. Format du blob :
  magic `JWAB1` + IV + données chiffrées.
- **Compatibilité multi-plateforme** — `install_dep.sh` installe désormais **ffmpeg** (apt,
  non bloquant) requis par la conversion audio (note vocale du widget, TTS, STT) ; Piper utilise
  déjà `uname -m` pour le binaire adapté (x86_64 / aarch64 / armv7l). `validate.sh` gagne une
  section **« 3b. Dépendances média/audio »** affichant l'architecture et l'état (présent/absent)
  de ffmpeg, Piper, Tesseract, Vosk. Le cœur du traitement média (réception, affichage widget,
  envoi) reste 100 % portable (Node/PHP pur) ; les fonctions IA se désactivent proprement si
  leur binaire manque.
- **Widget dashboard style WhatsApp** ⭐ (v0.6 #28) — tuile personnalisée (`toHtml()` +
  template `core/template/dashboard/jeewhatsapp.html` + CSS `desktop/css/jeewhatsapp.css`)
  reprenant la charte WhatsApp : en-tête teal avec avatar (icône du plugin) et **statut de
  connexion** en direct (point vert/orange/rouge lu depuis `status.txt`), zone de **chat**
  (fond beige, bulle du dernier message reçu avec expéditeur/heure), **compteurs** (reçus
  aujourd'hui / envoyés), **zone d'envoi rapide** (champ + bouton avion, bulle optimiste +
  coches) et **bouton sourdine**. **Bouton micro adaptatif** : en contexte sécurisé
  (HTTPS/localhost) il **enregistre** une vraie note vocale via le micro du navigateur
  (`getUserMedia` + `MediaRecorder`) → upload AJAX `uploadVoice` → conversion ffmpeg Opus →
  envoi en note vocale ; sinon il bascule en **mode TTS** (texte tapé → voix synthétisée
  `send_voice`, icône haut-parleur). Envoi texte via `send_message`, sourdine via `mute_chat`.
  Endpoint `uploadVoice` + méthode PHP `sendVoiceRecording()`.
- **Widget : mise à jour live** — le widget s'enrichit désormais **en temps réel** sans
  recharger la page : abonnement aux valeurs des cmds info via `jeedom.cmd.addUpdateFunction`
  (`last_message`, expéditeur, heure). Chaque nouveau message reçu ajoute une bulle entrante.
- **Widget : rendu des médias** — les médias reçus sont affichés directement dans les bulles :
  **image** en miniature, **vidéo** en lecteur, **note vocale/audio** en **lecteur audio** natif,
  **document** en lien téléchargeable. Type déduit de l'extension du fichier (robuste). Les médias
  sont servis par un endpoint AJAX authentifié **`getMedia`** (méthode `streamIncomingMedia()`,
  chemin confiné au dossier `incoming/{eqId}` par `realpath` — contourne le `.htaccess` de `/data`).
  Quand **STT** est activé, la **transcription** de la note vocale apparaît en plus en bulle texte.
- **Gestion du groupe** (v0.5 #22) — section « Gestion du groupe » dans la config équipement :
  ajouter / retirer / promouvoir admin / rétrograder un participant (par numéro), changer le
  **sujet** et la **description**, générer/**révoquer le lien d'invitation**, **quitter** le
  groupe. Daemon : action `groupAction` (`sock.groupParticipantsUpdate` / `groupUpdateSubject` /
  `groupUpdateDescription` / `groupInviteCode` / `groupRevokeInvite` / `groupLeave`). Méthode PHP
  `groupAction()` + endpoint AJAX. Le compte WhatsApp lié doit être administrateur. (Le changement
  d'icône — `setGroupIcon` — complète cet ensemble.)
- **Statuts WhatsApp** (v0.5 #25) — cmd action **`post_status`** (« Publier un statut ») qui
  publie un statut éphémère 24h (story-like) : *Message* = texte/légende, *Titre* = chemin
  d'une image optionnelle. Daemon : action `postStatus` → `sock.sendMessage('status@broadcast', …)`.
  L'**audience** (`statusJidList`) est construite depuis les participants du groupe canal.
- **Archive / Épingle / Sourdine** (v0.5 #24) — trois cmds action sur la conversation du
  groupe canal : **`archive_chat`** (archiver/désarchiver), **`pin_chat`** (épingler/désépingler),
  **`mute_chat`** (sourdine, durée en heures ou réactivation). Daemon : action unique
  `chatModify` (op archive|unarchive|pin|unpin|mute|unmute) via `sock.chatModify()`. Méthode PHP
  `chatModify()`. Le champ *Titre* pilote le sens (vide/valeur vs `0`).
- **Accusés de lecture** (v0.5 #23) — cmd action **`mark_read`** (« Marquer comme lu ») qui
  marque le dernier message reçu comme lu (coches bleues) via `sock.readMessages()`. En sens
  inverse, le daemon écoute `messages.update` : quand un destinataire lit/écoute un message
  **envoyé** par Jeedom (statut READ/PLAYED), un callback `read_receipt` met à jour la cmd
  info **`last_read_at`** (« Lu le »).
- **Icône du groupe WhatsApp** — bouton « Icône » (à côté de Rechercher/Créer dans la config
  équipement) qui définit **l'icône du plugin** (`plugin_info/jeewhatsapp_icon.png`) comme
  photo du groupe canal. Action daemon `setGroupIcon` (conversion JPEG carré 640×640 via
  `sharp`, transparence aplatie sur blanc) → `sock.updateProfilePicture()`. Méthode PHP
  `setGroupIcon()` + endpoint AJAX. Nécessite que le compte WhatsApp lié soit administrateur
  du groupe.
- **STT sur notes vocales reçues** ⭐ — transcription **locale** des notes vocales via
  **Vosk** (offline, aucun service tiers, modèle français léger). Activable par équipement
  (`stt_enabled`). Le texte transcrit est exposé dans la nouvelle cmd info **`last_voice_text`**
  (« STT — note vocale ») **et réinjecté comme un message** → déclenche raccourcis et
  interactions Jeedom : **pilotage de Jeedom à la voix**. Combiné au mode vocal (`tts_enabled`),
  forme un **assistant vocal complet** (voix entrante → commande → réponse vocale). Script
  `resources/stt/stt.py` (ffmpeg → PCM 16 kHz → Vosk), méthode PHP `transcribe()` intégrée à
  `updateFromAttachment()` (médias audio). Échec silencieux si Vosk absent. Installation auto
  (pip + modèle, non bloquante) dans `install_dep.sh`. (v0.4 ROADMAP #17)
- **OCR sur images reçues** ⭐ — extraction **locale** de texte des images reçues via
  **Tesseract** (aucun service tiers). Activable par équipement (`ocr_enabled`), langue
  configurable (`ocr_lang`, défaut `fra`, combinable `fra+eng`). Le texte reconnu est exposé
  dans la nouvelle cmd info **`last_ocr_text`** (« OCR — texte image ») — utilisable dans les
  scénarios (lecture de compteur, ticket, panneau…). Méthode PHP `runOcr()` intégrée à
  `updateFromAttachment()` pour les médias de type image, échec silencieux (log warning) si
  Tesseract absent. Installation auto (apt, non bloquante) dans `install_dep.sh`. (v0.4 ROADMAP #20)
- **Réponses vocales / TTS** ⭐ — synthèse vocale **locale** via **Piper** (aucun service
  tiers). Nouvelle cmd action **`send_voice`** (`message` = texte à dire, `title` =
  destinataire optionnel) qui synthétise le texte en note vocale Opus (`.ogg` PTT) et
  l'envoie via le chemin média existant. Méthode PHP `speak()` (Piper → WAV → `ffmpeg`
  libopus 48 kHz mono) + script `resources/piper/tts.sh`. Mode **« vocal-first »** par
  équipement via config `tts_enabled` : les réponses automatiques aux interactions et aux
  raccourcis sont alors envoyées en note vocale (helper `sendReply()`), avec **repli
  automatique sur le texte** si la synthèse échoue. Voix configurable via `tts_voice`
  (défaut `fr_FR-siwis-medium`). Installation auto (non bloquante) du binaire Piper + voix
  française dans `install_dep.sh` ; `ffmpeg` requis. (v0.4 ROADMAP #18)
- **Reconnaissance utilisateur** ⭐ — config eqLogic `user_mapping` (textarea, une ligne
  `numéro=profil`). Le numéro/JID de l'expéditeur d'un message entrant est résolu en un
  profil Jeedom via le mapping (numéros normalisés au format international). Le profil
  résolu est exposé dans la nouvelle cmd info `last_sender_profile` (« Expéditeur — profil »)
  et transmis au moteur d'interactions via l'option `profile` de `interactQuery::tryToReply()`
  (compatible avec le plugin Profils — permet des règles d'accès / personnalisation par
  personne). Fallback : nom WhatsApp puis numéro brut si aucun mapping ne correspond.
  Helpers PHP `parseUserMapping()` (static) et `resolveSenderProfile()`. (v0.4 ROADMAP #21)
- **Commandes shortcuts (slash)** ⭐ — config eqLogic `interaction_shortcuts` (textarea,
  une ligne `/déclencheur=cible`). Un message reçu commençant par `/` est traité en
  priorité sur le moteur d'interactions (NLP). La cible peut être : une commande seule
  `#id#` (action → exécutée + confirmation `✅ Nom`, info → sa valeur `Nom : valeur unité`),
  ou un **texte modèle** contenant des tags `#id#` d'infos et les placeholders `#args#`
  (arguments complets) / `#1#`, `#2#`… (mots d'argument), évalué via
  `jeedom::evaluateExpression()`. Raccourci inconnu → réponse explicite `❓`. Helpers PHP
  `parseShortcuts()` (static) et `handleShortcut()`. Aucune dépendance externe.
  (v0.4 ROADMAP #19)
- **Multi-groupes par équipement** ⭐ — config eqLogic `extra_groups` (textarea, une
  ligne `tag=Nom du groupe WhatsApp`). Chaque équipement peut désormais écouter ET cibler
  plusieurs groupes canaux en plus du groupe principal. Design **additif** : le groupe par
  défaut (`groupJids[id]` / `group_jid.txt`) est inchangé, une couche `extraGroups[id] = {tag: jid}`
  est résolue au `connection.open` via `findGroupByName`. Le filtre de réception calcule un
  `group_tag` (`''` = groupe par défaut) propagé à tous les callbacks. Nouvelle cmd action
  **`send_group`** (`title` = tag, `message` = texte) et 2 cmds info `last_group` (tag) /
  `last_group_name` (nom). Helper PHP statique `parseExtraGroups()`, méthode `sendGroup()`,
  `resolveJid(id, phone, tag)` étendu côté daemon. Rétrocompatible avec les setups
  mono-groupe existants. (v0.3 ROADMAP #16)
- **`send_poll`** ⭐ — cmd action (subType=message) pour envoyer un sondage WhatsApp.
  `title` = question, `message` = options séparées par `|` (2 à 12). Daemon :
  `sock.sendMessage(jid, { poll: { name, values, selectableCount } })`. Le message de
  création est mémorisé (`lastPollMsg[id]`) pour décrypter les votes. (v0.3 ROADMAP #9)
- **Réception des votes de sondage** — déchiffrement du `pollUpdateMessage` directement
  dans `messages.upsert` (fonction `handlePollVote`). Baileys 6.7.x ayant **retiré** son
  traitement interne des votes (bloc commenté dans `Utils/process-message.js`, plus aucun
  `messages.update` émis), le daemon reproduit sa logique : `decryptPollVote()` avec le
  `messageSecret` du sondage d'origine + `getKeyAuthor`/`jidNormalizedUser`, puis agrégation
  via `getAggregateVotesInPollMessage()`. Cumul correct multi-votants (dernier vote conservé
  par votant). Callback `event_type: 'poll_vote'` → PHP `updateFromPollVote()` met à jour
  3 cmds info : `poll_question`, `poll_results` (JSON `[{name,votes}]`), `poll_total`
  (historisée). Permet de déclencher des scénarios sur le résultat d'un vote.
- **Messages éphémères** — config eqLogic `ephemeral_duration` (select : désactivé,
  24h, 7j, 90j). Si activée, tous les envois Jeedom (texte, reply, média, location,
  contact, sticker, forward) expirent automatiquement via l'option Baileys
  `ephemeralExpiration`. Helper PHP `ephemeralParam()`, daemon construit `sendOpts`
  une fois et le passe à chaque `sock.sendMessage`. (v0.3 ROADMAP #15)
- **`send_sticker`** — cmd action (subType=message) pour envoyer un sticker. `title` =
  chemin absolu. Les `.webp` sont envoyés tels quels ; les images `.png/.jpg/.gif/.bmp`
  sont converties en WebP 512×512 (fond transparent) via **`sharp`** (nouvelle dépendance
  npm, import dynamique avec message d'erreur clair si absente). Daemon :
  `sock.sendMessage(jid, { sticker: buffer })`. (v0.3 ROADMAP #10)
- **`edit_last`** — cmd action (subType=message) pour éditer le dernier message
  envoyé par Jeedom. `message` = nouveau texte. Daemon mémorise `lastSentMsg[id]`
  (clé + jid) après chaque envoi (`recordSent()`), édition via
  `sock.sendMessage(jid, { text, edit: key })`. Le préfixe Jeedom est réappliqué.
  (v0.3 ROADMAP #11)
- **`revoke_last`** — cmd action (subType=other, bouton) qui supprime "pour tous"
  le dernier message envoyé via `sock.sendMessage(jid, { delete: key })`.
  `lastSentMsg[id]` est purgé après suppression. (v0.3 ROADMAP #12)
- **`forward_to`** — cmd action (subType=message) qui transfère le dernier message
  reçu vers un destinataire. `title` = destinataire optionnel (vide = groupe canal),
  via `sock.sendMessage(jid, { forward: lastIncomingMsg })`. (v0.3 ROADMAP #13)
- **Présence "en train d'écrire"** — config eqLogic `presence_enabled` (checkbox). Si
  activée, le daemon envoie `composing` (ou `recording` pour l'audio) pendant ~1,2 s
  avant chaque envoi automatique (texte, reply, média), puis `paused`. Humanise les
  messages de Jeedom. Helper daemon `applyPresence(sock, jid, presence)`, erreurs
  silencieuses (la présence ne bloque jamais l'envoi). Helper PHP privé
  `presenceParam()`. (v0.3 ROADMAP #14)

- **`send_location`** — commande action pour envoyer une position GPS dans le groupe canal.
  Format du Titre : `lat|long` ou `lat|long|nom`. Validation lat ∈ [-90,90], long ∈ [-180,180].
  (v0.2 ROADMAP #3)
- **`send_contact`** — commande action pour envoyer une carte vCard. Titre = numéro,
  Message = nom affiché. Format français 0X automatiquement converti en 33X. (v0.2 ROADMAP #4)
- Refactor daemon : helper `resolveJid(id, phone)` mutualisé entre `send`, `sendMedia`,
  `sendLocation`, `sendContact`. Simplifie l'ajout des prochaines actions d'envoi.
- **`messages_today`** — cmd info numeric historisée. Compteur de messages reçus depuis
  minuit. Reset automatique via cron daily (`2 0 * * *`). (v0.2 ROADMAP #7)
- **`connected_since`** — cmd info string indiquant la date/heure de la dernière connexion
  WhatsApp établie. Mise à jour par le daemon dans `auth/{id}/connected_since.txt` et
  rafraîchie via cron toutes les 5 min (`*/5 * * * *`). (v0.2 ROADMAP #8)
- 2 nouveaux crons Jeedom : `cronResetMessagesToday`, `cronRefreshStatus`. Création
  idempotente dans `jeewhatsapp_install()`, nettoyés à la désinstallation.
- **Filtre mot-clé déclencheur** — config eqLogic `interaction_keyword`. Si renseigné,
  seuls les messages commençant par ce mot-clé (case-insensitive) déclenchent les
  interactions. Le mot-clé est ensuite retiré du message avant traitement. Permet
  des formulations naturelles côté Jeedom sans pollution du groupe. (v0.2 ROADMAP #6)
- **Whitelist d'expéditeurs** 🛡️ — config eqLogic `interaction_whitelist` (textarea).
  Si renseignée, seuls les numéros listés peuvent déclencher des interactions Jeedom.
  Refus silencieux (log debug) des autres. Normalisation auto FR 0X → 33X +
  acceptation de tous formats (avec espaces, +, etc.). Helper public statique
  `jeewhatsapp::normalizePhone($phone)`. (v0.2 ROADMAP #5)
- **Réactions emoji** — envoi et réception bidirectionnels (v0.2 ROADMAP #2) :
  - Cmd action `react_last` (subType=message) — message=emoji, réagit au dernier
    message reçu via `sock.sendMessage(jid, { react: { text: emoji, key: lastMsg.key } })`
  - Daemon détecte les `msg.message.reactionMessage` entrants et émet un callback
    avec `event_type: 'reaction'`
  - PHP : `updateFromReaction()` met à jour 3 nouvelles cmds info :
    `last_reaction`, `last_reaction_from`, `last_reaction_at`
  - Possibilité de déclencher des scénarios sur changement de `last_reaction`
    (ex: ❤️ → ambiance romantique, 🎉 → ambiance fête)
- Refactor `updateFromMessage()` : dispatcher par `event_type` (`'message'` par défaut,
  `'reaction'` pour les nouvelles réactions). Pattern extensible pour v0.3+ (poll votes,
  edit/revoke notifications, etc.).
- **Réception médias** ⭐ (v0.2 ROADMAP #1) — image/vidéo/audio/document/sticker reçus
  dans le groupe canal sont téléchargés automatiquement :
  - Daemon : `downloadMediaMessage()` de Baileys, sauvegarde dans
    `data/jeewhatsapp/incoming/{eqId}/{YYYY-MM-DD}/{uuid}.ext`
  - Mapping mime → extension propre (jpg/png/webp/mp4/m4a/mp3/ogg/opus/pdf...)
  - Callback `event_type: 'attachment'` avec path/mime/size/caption
  - 4 nouvelles cmds info : `last_attachment_path`, `last_attachment_type`,
    `last_attachment_mime`, `last_attachment_size`
  - Caption (si présente) MAJ aussi `last_message` pour cohérence scénarios existants
  - Cron daily `cronCleanupIncoming` (03:15) : suppression des fichiers > 30 jours

### Changed

- **Anti-écho `fromMe` par ID de message** — sur un compte lié, la distinction entre un
  envoi automatique de Jeedom et un message produit par l'humain ne repose plus uniquement
  sur le préfixe texte (inopérant pour les **notes vocales** et médias). Le daemon mémorise
  désormais l'**ID** de chaque message qu'il envoie (`sentMsgIds`, set borné) ; à la réception
  d'un `fromMe`, l'écho est ignoré de façon fiable par son ID, quel que soit le type. Tout
  autre `fromMe` (humain sur le compte lié) est traité — y compris une **note vocale**, ce qui
  rend le pilotage vocal opérant depuis le compte lié. Le garde-fou préfixe est conservé pour
  le texte. Les réactions envoyées par Jeedom (`reactLast`) sont désormais elles aussi
  enregistrées (évite le re-traitement de leur écho).
- **Interactions depuis le compte WhatsApp lié** — la réception filtre désormais les
  messages `fromMe` par **préfixe Jeedom** au lieu de les ignorer en bloc. Un message
  `fromMe` est ignoré s'il est vide/non-texte OU s'il commence par le préfixe de
  l'équipement (= envoi automatique de Jeedom, évite la boucle) ; un texte `fromMe`
  **non préfixé** est traité comme une interaction humaine. Permet de piloter Jeedom en
  tapant directement dans le groupe depuis le téléphone qui héberge le compte lié.

### Fixed

- **Réception des messages entrants / votes inopérante (`Bad MAC`)** — diagnostic : une
  session Signal corrompue (`Failed to decrypt message with any known session` / `Bad MAC`
  en boucle dans les logs) fait silencieusement jeter tous les messages entrants par
  Baileys avant l'émission de `messages.upsert`. Cause typique : usage concurrent de la
  même session par deux connexions (redémarrages rapprochés). **Remède : ré-appairage**
  (logout + nouveau QR) qui régénère des clés fraîches. Documenté dans la doc comme
  procédure de dépannage.
- **Votes de sondage non remontés** — voir entrée *Added* : le déchiffrement est désormais
  fait par le daemon (Baileys 6.7.x ne le fait plus en interne).

### Security

- **F-004 (MEDIUM, CWE-306)** — authentification du serveur HTTP local du daemon :
  - PHP `deamon_start()` génère un secret 256 bits via `random_bytes(32)` à chaque démarrage
  - Stocké en cache Jeedom (TTL 7j) et transmis via env var `JEEDOM_DAEMON_SECRET`
  - `sendToDaemon()` ajoute le header `X-Daemon-Secret` sur chaque requête
  - Daemon refuse 401 sur tout `/action` sans header valide (`crypto.timingSafeEqual`
    pour comparaison à temps constant — protection timing attack CWE-208)
  - Empêche un autre process local d'envoyer des messages WhatsApp via le daemon HTTP
- **F-007 (MEDIUM, CWE-79)** — XSS DOM dans le JS inline desktop : `groupName`
  passe désormais par `.text()` au lieu d'être concaténé dans `.html()`. Touche
  les fonctions Recherche/Création de groupe.
- **F-008 (MEDIUM, CWE-79)** — XSS stocké via objets/catégories Jeedom :
  `htmlspecialchars()` + cast `(int)` systématiques sur `$object->getName()`,
  `$value['name']`, `$object->getId()`, `$object->getConfiguration('parentNumber')`
  dans le template desktop.
- **F-009 (LOW, CWE-532)** — masquage des PII dans les logs debug :
  - Nouveau helper privé statique `redactPayloadForLog($action, $params)`
  - `message`/`caption` > 8 chars → tronqués à `"abc…(N chars)"`
  - `phone`/`mention`/`contact_phone` > 4 chars → masqués à `"33…78"` (2 premiers / 2 derniers)
  - Réponse daemon tronquée à 200 chars (évite dump complet d'un QR base64)
- Tests sécurité validés :
  - `curl -X POST http://127.0.0.1:55148/action` sans header → 401 attendu ✓
  - `sendMessage()` via PHP avec secret → OK ✓
  - Log debug `sendToDaemon` montre `"TES…(52 chars)"` au lieu du message complet ✓

Score sécurité estimé : 56→**~85/100** (toutes les failles HIGH + 5 MEDIUM clôturées).

---

## [0.1.0] - 2026-05-27

Version initiale — plugin opérationnel pour envoyer et recevoir des messages
WhatsApp via un groupe canal, avec interactions Jeedom, médias et soutien.

### Added

- **Envoi/réception texte** via Baileys (self-hosted, sans service tiers)
- **Mode groupe canal** : un groupe WhatsApp dédié à la communication Jeedom
- **Mentions** : envoi de messages avec `@numéro` pour notifier un membre
- **Réponse "quoted"** : commande `reply` qui cite le dernier message reçu
- **Envoi de médias** : commande `send_media` (image/vidéo/audio/document/PTT)
  - Auto-détection du type via extension (jpg/png/mp4/mp3/ogg/pdf/...)
  - Validation taille (max 100 MB) et lisibilité
  - Audio `.ogg`/`.opus` → note vocale (PTT)
- **Interactions Jeedom** : réponse automatique aux messages via `interactQuery`
- **Préfixe Jeedom** configurable (par défaut `🏠 `) sur les messages sortants
- **QR code UI** : onglet "Connexion WhatsApp" avec scan + statut temps réel
- **Recherche/création de groupe** depuis l'UI
- **Message de soutien mensuel** (opt-in) :
  - Pool de 12 messages préparés (`core/config/donation_messages.json`)
  - Cron horaire qui planifie un envoi aléatoire / mois (jour + heure 10h-19h)
  - Filtrage par catégorie ou occasion
- **Multi-instances** : un compte WhatsApp par eqLogic, sessions isolées
- **Daemon Node.js ESM** avec reconnexion auto et timeouts gracieux
- **Script de validation** `tools/validate.sh` (10 sections de checks)
- **Hook Claude Code** Stop : validation auto en fin de turn (`tools/validate-hook.ps1`)
- **Rapport sécurité** `SECURITY-AUDIT.md` (score 56→74/100)
- **Roadmap** `ROADMAP.md` : 40 features priorisées sur 12 mois

### Security

- **F-001 (HIGH, CWE-214)** : API key transmise via env var `JEEDOM_APIKEY` au
  lieu de l'argument CLI `--callback?apikey=` (visible dans `ps aux`)
- **F-002 (HIGH, CWE-732)** : permissions strictes `0700` + `umask 077` sur le
  dossier `resources/jeewhatsappd/auth/` (credentials Baileys)
- **F-003 (MEDIUM, CWE-346)** : `callback.php` rejette les IP non locales
- **F-005 (MEDIUM, CWE-22)** : `instance_id` validé par regex `^\d+$` + check
  `path.resolve` contre traversée de répertoire
- **F-006 (MEDIUM, CWE-598)** : API key envoyée via header `X-API-Key` au lieu
  de query string (évite la fuite dans access.log)
- **F-010 (LOW, CWE-20)** : `callback.php` exige `Content-Type: application/json`
- **CWE-208** (bonus) : `hash_equals()` pour comparer l'API key (timing attack)
- `.gitignore` : credentials Baileys, node_modules, package-lock exclus du dépôt

### Changed

- `incrementSentCounters()` : passage de `setConfiguration + save()` vers
  `cache::set` (élimine race condition au save)
- Réception : filtrage strict (group only + not fromMe + texte uniquement)
- Daemon : `removeAllListeners` avant reconnexion (évite fuite listeners)
- Daemon : retry `findGroupByName` à 3s si non trouvé au `connection.open`

### Fixed

- `postSave()` : `save()` ajouté à la création de la cmd `send_media` (était omis
  si placeholder déjà à jour, créant une orphelin invisible dans Jeedom)
- `callback.php` : cast explicite `(int)$_data['instance_id']`
- `deamon_info()` : fallback `posix_kill` → `/proc/$pid` (environnements sans
  extension POSIX, ex: Docker minimaliste)
- `install_dep.sh` : `with: { type: 'json' }` (Node 22 compat, remplace
  `assert: { type: 'json' }` déprécié)

---

[Unreleased]: https://github.com/Aldarande/jeewhatsapp/compare/v0.1.0...dev
[0.1.0]: https://github.com/Aldarande/jeewhatsapp/releases/tag/v0.1.0
