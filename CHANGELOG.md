# Changelog

Tous les changements notables de ce projet sont documentés ici.

Le format est basé sur [Keep a Changelog 1.1.0](https://keepachangelog.com/fr/1.1.0/)
et ce projet adhère à [Semantic Versioning 2.0.0](https://semver.org/).

## [Unreleased]

### Added

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

### Changed

### Fixed

### Security

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
