# JeeWhatsApp — Plugin Jeedom pour WhatsApp

[![Ko-fi](https://img.shields.io/badge/Ko--fi-Soutenir-FF5E5B?logo=kofi)](https://ko-fi.com/aldarande)
[![GitHub Sponsors](https://img.shields.io/badge/GitHub-Sponsor-EA4AAA?logo=githubsponsors)](https://github.com/sponsors/Aldarande)
[![Licence](https://img.shields.io/badge/Licence-AGPL%20v3-blue)](LICENSE)
[![Jeedom](https://img.shields.io/badge/Jeedom-4.4%2B-green)](https://www.jeedom.com)

**Plugin Jeedom pour intégrer WhatsApp à votre domotique.**
Pilotez votre installation Jeedom via WhatsApp : recevez des alertes, contrôlez des scénarios, gérez des groupes, et utilisez des fonctionnalités avancées comme la **reconnaissance vocale (STT)** et la **synthèse vocale (TTS)**.

---

## ✨ Fonctionnalités

### 📱 Messagerie et contrôle
- **Envoi/réception de messages** texte, médias (images, vidéos, audio), localisations GPS et contacts.
- **Gestion des groupes WhatsApp** : Créer, modifier, ajouter/supprimer des participants, changer le sujet ou l’icône.
- **Réactions emoji** : Envoyer et recevoir des réactions aux messages.
- **Sondages** : Créer et gérer des sondages WhatsApp.
- **Messages éphémères** : Configurer une durée d’expiration pour les messages envoyés (24h, 7j, 90j).

### 🎤 Vocal et IA
- **STT (Speech-to-Text)** : Transcription locale des notes vocales reçues via **Vosk** (modèle français).
- **TTS (Text-to-Speech)** : Synthèse vocale locale via **Piper** (voix française).
- **OCR** : Extraction de texte depuis les images reçues via **Tesseract**.

### 🔄 Intégration Jeedom
- **Interactions** : Répondre automatiquement aux messages via le moteur d’interactions Jeedom.
- **Raccourcis clavier** : Utiliser des commandes préfixées (ex. : `/jeedom allume salon`).
- **Whitelist des expéditeurs** : Restreindre les interactions aux numéros autorisés.
- **Filtre par mot-clé** : Seuls les messages commençant par un mot-clé déclenchent des actions.

### 📊 Widget et UX
- **Widget dashboard** style WhatsApp : Affichage du statut de connexion, dernier message, compteurs, et envoi rapide.
- **Zoom sur le QR code** pour faciliter le scan.
- **Messages d’erreur clairs** pour le dépannage.

### 🔒 Sécurité
- **Backup chiffré** des sessions WhatsApp (AES-256-GCM).
- **Authentification du daemon** via un secret partagé (`JEEDOM_DAEMON_SECRET`).
- **Rate-limiting** sur les endpoints pour éviter les attaques par force brute.
- **Validation des uploads** (MIME type, extensions) pour éviter les fichiers malveillants.

---

## 📋 Providers et dépendances

JeeWhatsApp utilise les bibliothèques suivantes pour fonctionner :
   **Dépendance**       | **Type**       | **Licence**       | **Description**                                  |
 |----------------------|----------------|-------------------|--------------------------------------------------|
 | **Baileys**          | Node.js        | MIT               | Bibliothèque pour interagir avec WhatsApp.      |
 | **Vosk**             | Python         | Apache 2.0        | Reconnaissance vocale locale (STT).              |
 | **Piper**            | Binaire        | MIT               | Synthèse vocale locale (TTS).                     |
 | **Tesseract**        | Binaire        | Apache 2.0        | OCR pour extraire du texte des images.           |
 | **ffmpeg**           | Binaire        | LGPLv2.1+ / GPLv3 | Conversion audio/vidéo.                          |

> **Note** : Toutes les dépendances sont **100% locales** (pas de service cloud tiers).

---

## 📥 Installation

### 1. Prérequis
- **Jeedom** 4.4 ou supérieur.
- **PHP** 8.0+.
- **Node.js** 18+ (pour le daemon).
- **Dépendances système** :
  - `ffmpeg` (pour la gestion des médias).
  - `git` (pour cloner le dépôt).

### 2. Installer le plugin
1. Cloner le dépôt dans le répertoire `plugins/` de Jeedom :
   ```bash
   git clone -b dev https://github.com/Aldarande/jeewhatsapp.git /var/www/html/plugins/jeewhatsapp
