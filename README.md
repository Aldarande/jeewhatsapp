# JeeWhatsApp
**Plugin Jeedom pour intégrer WhatsApp à votre domotique**

[![License](https://img.shields.io/badge/license-GPLv3-blue.svg)](LICENSE)
[![Jeedom](https://img.shields.io/badge/Jeedom-4.4+-green.svg)](https://jeedom.com)
[![GitHub Issues](https://img.shields.io/github/issues/Aldarande/jeewhatsapp.svg)](https://github.com/Aldarande/jeewhatsapp/issues)

---

## 📌 Description
JeeWhatsApp permet d'envoyer et recevoir des messages WhatsApp directement depuis Jeedom.
Idéal pour :
- Recevoir des **alertes domotiques** (ex. : intrusion, capteurs).
- **Contrôler des scénarios** via des commandes WhatsApp.
- **Gérer des groupes** pour centraliser les notifications.
- **Transcrire des notes vocales** en texte (via Vosk).
- **Envoyer des localisations, contacts, médias, sondages**, etc.

---

## ✅ Fonctionnalités
   Catégorie               | Fonctionnalités                                                                 |
 |-------------------------|---------------------------------------------------------------------------------|
 | **Messagerie**          | Envoi/réception de texte, médias, localisations, contacts, stickers.           |
 | **Gestion des groupes**| Création, modification, gestion des participants, statuts.                   |
 | **Vocal**              | STT (reconnaissance vocale) et TTS (synthèse vocale) via Vosk/Piper.             |
 | **Sécurité**           | Backup chiffré des sessions, whitelist des expéditeurs, rate-limiting.         |
 | **Intégrations**        | Interactions Jeedom, raccourcis clavier (`/commande`), OCR sur les images.     |

---
## 🛠️ Prérequis
- **Jeedom** 4.4+
- **PHP** 8.0+
- **Node.js** 18+ (pour le daemon)
- **Dépendances système** :
  - `ffmpeg` (pour la gestion des médias)
  - `Piper` (TTS)
  - `Vosk` (STT)
  - `Tesseract` (OCR)

