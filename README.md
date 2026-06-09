# JeeWhatsApp

**Plugin Jeedom pour intégrer WhatsApp à votre domotique**

[![License](https://img.shields.io/badge/licence-AGPL%20v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0.html)
[![Jeedom](https://img.shields.io/badge/Jeedom-4.4+-green.svg)](https://jeedom.com)
[![Node.js](https://img.shields.io/badge/Node.js-18+-brightgreen.svg)](https://nodejs.org)
[![Ko-fi](https://img.shields.io/badge/don-Ko--fi-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/aldarande)
[![Liberapay](https://img.shields.io/badge/don-Liberapay-F6C915?logo=liberapay&logoColor=black)](https://liberapay.com/Aldarande/donate)
[![GitHub Sponsors](https://img.shields.io/badge/don-GitHub%20Sponsors-ea4aaa?logo=github&logoColor=white)](https://github.com/sponsors/Aldarande)

---

## Description

JeeWhatsApp permet d'envoyer et recevoir des messages WhatsApp directement depuis Jeedom,
**sans passer par un serveur tiers**. La connexion s'établit entre votre serveur Jeedom
et les serveurs WhatsApp via [Baileys](https://github.com/WhiskeySockets/Baileys),
une implémentation open-source du protocole WhatsApp Web.

---

## Fonctionnalités

- **Envoi/réception de messages texte** dans un groupe WhatsApp canal dédié
- **Médias** — envoi d'images, vidéos, documents, notes vocales, stickers, localisations, contacts, sondages
- **Réception de médias** — affichage inline dans le widget dashboard (image, audio, vidéo)
- **Interactions Jeedom** — déclenchez des scénarios par message (whitelist + mot-clé)
- **Raccourcis slash** — commandes courtes `/allume`, `/température`… → exécution directe
- **TTS** (synthèse vocale) — réponses en note vocale via Piper (français)
- **STT** (reconnaissance vocale) — transcription des notes vocales reçues via Vosk
- **OCR** — extraction de texte des images reçues via Tesseract
- **Gestion des groupes** — ajouter/retirer membres, changer sujet/description, lien d'invitation
- **Sauvegarde chiffrée** de session (AES-256-GCM + PBKDF2) — restauration sans re-scanner le QR
- **Multi-comptes** — un équipement Jeedom = un compte WhatsApp (instances parallèles)
- **Widget dashboard** style WhatsApp — bulle de chat en direct, micro intégré, statut de connexion
- **Statuts WhatsApp** — publication de statuts éphémères 24h
- **100 % self-hosted** — aucune donnée ne transite par un serveur tiers

---

## Prérequis

| Composant | Version minimale |
|-----------|-----------------|
| Jeedom | 4.4.0 |
| PHP | 8.0 |
| Node.js | **18+** (LTS recommandé) |

Dépendances optionnelles (installées automatiquement par `install_dep.sh`) :

| Outil | Fonction | Requis ? |
|-------|----------|----------|
| `ffmpeg` | Conversion audio (note vocale, TTS, STT) | Optionnel |
| `Piper` | Synthèse vocale (TTS) — voix fr_FR-siwis-medium | Optionnel |
| `Vosk` | Transcription vocale (STT) | Optionnel |
| `Tesseract` | Extraction de texte des images (OCR) | Optionnel |

> **Note :** Le plugin fonctionne sans les outils optionnels — les fonctions audio/IA
> se désactivent proprement si leur dépendance est absente.

---

## Installation

### Méthode 1 — Via le market Jeedom ⭐ (recommandée)

C'est la méthode officielle : mises à jour automatiques, installation en un clic.

1. Dans Jeedom, ouvrez le menu **Plugins → Gestion des plugins**
2. Cliquez sur l'icône **Market** (panier en haut à droite)
3. Dans le champ de recherche, tapez **JeeWhatsApp**
4. Cliquez sur la fiche du plugin puis sur **Installer stable** (ou *beta* pour la dernière version de développement)
5. Confirmez — Jeedom télécharge et installe le plugin automatiquement
6. De retour dans **Gestion des plugins**, cliquez sur **Activer** sur la ligne JeeWhatsApp
7. Cliquez sur **Sauvegarder**

> Le plugin apparaît ensuite dans **Plugins → Communication → JeeWhatsApp**.

### Méthode 2 — Depuis GitHub (beta / développement)

Réservée aux contributeurs ou aux utilisateurs qui souhaitent tester la branche `dev`.

```bash
cd /var/www/html/plugins
git clone -b dev https://github.com/Aldarande/jeewhatsapp.git jeewhatsapp
chown -R www-data:www-data jeewhatsapp/
```

Puis activez le plugin dans **Plugins → Gestion des plugins → JeeWhatsApp → Activer**.

---

### Étapes communes (après toute méthode d'installation)

#### Étape A — Installer les dépendances

Les dépendances (Baileys, ffmpeg, Piper, Vosk, Tesseract) doivent être installées
**une seule fois** après la première installation, et après chaque mise à jour majeure.

1. Allez dans **Plugins → Communication → JeeWhatsApp**
2. Cliquez sur l'icône ⚙️ (roue dentée en haut à droite) → **Gestion des dépendances**
3. Cliquez sur **Installer** et attendez la fin (5 à 15 minutes selon la connexion)

> En ligne de commande (accès SSH) :
> ```bash
> bash /var/www/html/plugins/jeewhatsapp/resources/install_dep.sh /tmp/jwa_dep
> ```

#### Étape B — Créer un équipement

1. **Plugins → Communication → JeeWhatsApp → + Ajouter**
2. Donnez un nom à l'équipement (ex : *Mon WhatsApp*)
3. Dans la section **Groupe canal**, renseignez le nom exact du groupe WhatsApp
   qui servira de canal de communication (sera créé s'il n'existe pas)
4. Cliquez **Sauvegarder** — le daemon démarre automatiquement

#### Étape C — Scanner le QR code

Un QR code apparaît dans l'onglet **Équipement** après quelques secondes.

Sur votre téléphone :

```
WhatsApp → ⋮ (Android) ou ⚙ (iOS) → Appareils liés → Lier un appareil → Scanner
```

Le statut passe à **Connecté ✓** dès que le scan est réussi.
Le QR code expire après 30 secondes — cliquez **Rafraîchir** si besoin, ou **Agrandir** pour faciliter le scan.

#### Étape D — Créer ou lier le groupe WhatsApp

Dans l'onglet **Équipement** :

- **Bouton « Rechercher »** — si le groupe existe déjà parmi vos groupes WhatsApp
- **Bouton « Créer »** — crée un nouveau groupe vide avec le nom configuré, puis ajoutez-y vos contacts

#### Étape E — Tester

Onglet **Test** → **Envoyer dans le groupe canal**.  
Vous devez recevoir `🏠 Test JeeWhatsApp 🚀` dans le groupe WhatsApp.

---

## Configuration

### Paramètres de l'équipement

| Paramètre | Description |
|-----------|-------------|
| `Nom du groupe` | Nom exact du groupe WhatsApp canal |
| `Préfixe` | Préfixe ajouté à chaque message sortant (ex : `🏠 `) |
| `Interactions` | Activer/désactiver les interactions Jeedom par message |
| `Mot-clé` | Préfixe obligatoire pour déclencher une interaction (ex : `/jeedom`) |
| `Whitelist` | Numéros autorisés à déclencher des interactions (1 par ligne) |
| `TTS activé` | Réponses en note vocale (Piper requis) |
| `STT activé` | Transcription des notes vocales reçues (Vosk requis) |
| `OCR activé` | Extraction de texte des images (Tesseract requis) |
| `Présence` | Afficher « en train d'écrire… » avant chaque envoi |
| `Messages éphémères` | Durée d'expiration des messages (24h, 7j, 90j) |

### Configuration globale plugin

| Paramètre | Description |
|-----------|-------------|
| `Port daemon` | Port HTTP local du daemon (défaut : `55148`) |

---

## Commandes disponibles

### Actions

| logicalId | Description |
|-----------|-------------|
| `send_message` | Envoyer un message texte (vide = groupe canal) |
| `reply` | Répondre (quoted) au dernier message reçu |
| `send_media` | Envoyer un fichier (image, vidéo, audio, document) |
| `send_location` | Envoyer une localisation GPS |
| `send_contact` | Envoyer un contact vCard |
| `send_voice` | Envoyer une note vocale (TTS) |
| `send_poll` | Envoyer un sondage |
| `send_sticker` | Envoyer un sticker |
| `react` | Réagir avec un emoji au dernier message |
| `mark_read` | Marquer le dernier message comme lu |
| `post_status` | Publier un statut WhatsApp 24h |
| `mute_chat` | Mettre en sourdine la conversation |

### Infos

| logicalId | Description |
|-----------|-------------|
| `last_message` | Dernier message texte reçu |
| `last_sender` | Numéro de l'expéditeur |
| `last_sender_name` | Nom WhatsApp de l'expéditeur |
| `last_received_at` | Date/heure de réception |
| `sent_hour` | Nombre de messages envoyés dans l'heure en cours |
| `messages_today` | Nombre de messages reçus aujourd'hui |
| `last_attachment_path` | Chemin du dernier média reçu |
| `last_attachment_type` | Type du média (image, audio, video, document) |
| `last_voice_text` | Transcription STT du dernier audio reçu |
| `last_ocr_text` | Texte OCR de la dernière image reçue |
| `last_reaction` | Dernière réaction emoji reçue |

---

## Exemples de scénarios

### Alerte intrusion par WhatsApp

```
Déclencheur : [Capteur mouvement salon] = 1
Action       : JeeWhatsApp → send_message
               Message : "Alerte : mouvement détecté dans le salon à #time#"
```

### Contrôle de la domotique par message

Configuration : `Mot-clé = /jeedom`, `Whitelist = 33612345678`

```
Depuis WhatsApp : "/jeedom allume le salon"
→ Jeedom reçoit "allume le salon", interprète via InteractQuery
→ Exécute le scénario correspondant, répond "Salon allumé"
```

### Raccourcis slash

Configuration (champ `Raccourcis`) :

```
/temp = La température est de #1234# degrés
/alarme = #5678#
```

### Réponse vocale (TTS + STT)

Configuration : `TTS activé = oui`, `STT activé = oui`

```
Utilisateur envoie une note vocale : "Quelle est la température ?"
→ STT transcrit → InteractQuery répond → TTS synthétise → note vocale envoyée
```

---

## Sécurité

| Mesure | Détail |
|--------|--------|
| **Aucun serveur tiers** | Connexion directe Jeedom ↔ WhatsApp via Baileys |
| **API key hors CLI** | Passée via variable d'environnement uniquement |
| **Secret daemon** | JEEDOM_DAEMON_SECRET régénéré à chaque démarrage |
| **Callback restreint** | callback.php accepte uniquement 127.0.0.1 |
| **Rate-limiting** | 60 requêtes/minute max sur le callback |
| **Chiffrement session** | AES-256-GCM + PBKDF2-SHA256 (200k itérations) |
| **Score audit** | 100/100 — tous les findings corrigés (voir SECURITY-AUDIT.md) |

---

## Architecture

```
Jeedom (PHP)
├── eqLogic jeewhatsapp  ── commandes (send_message, last_message…)
├── core/ajax/*.ajax.php  ── endpoints UI (getQR, getStatus, findGroup…)
└── core/php/callback.php ← réception messages (POST depuis daemon)
        ↕ HTTP 127.0.0.1:55148
Daemon Node.js (ESM)
└── jeewhatsappd.js       ── Baileys WebSocket ── WhatsApp
    └── auth/{id}/        (sessions Baileys, permissions 0700)
```

---

## Contribuer

1. **Signaler un bug** : [ouvrir une issue](https://github.com/Aldarande/jeewhatsapp/issues)
2. **Proposer une PR** : branche `dev` uniquement, respectez le [DEV-WORKFLOW.md](DEV-WORKFLOW.md)
3. **Sécurité** : contactez [aldarande@gmail.com](mailto:aldarande@gmail.com)

Si vous appréciez ce plugin (gratuit et open-source), un don aide à financer
le développement, les tests et les mises à jour :

| Plateforme | Type | Lien |
|---|---|---|
| ☕ Ko-fi | Don ponctuel | [ko-fi.com/aldarande](https://ko-fi.com/aldarande) |
| 💙 GitHub Sponsors | Mensuel | [github.com/sponsors/Aldarande](https://github.com/sponsors/Aldarande) |
| 💛 Liberapay | Récurrent / anonyme possible | [liberapay.com/Aldarande](https://liberapay.com/Aldarande/donate) |

---

## Licence

[AGPL v3](https://www.gnu.org/licenses/agpl-3.0.html) — Aldarande
