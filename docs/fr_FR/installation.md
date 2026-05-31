# Guide d'installation — JeeWhatsApp

> Plugin ID : `jeewhatsapp` | Auteur : Aldarande | Licence : AGPL v3

---

## Prérequis détaillés

### Obligatoires

| Composant | Version minimale | Comment vérifier |
|-----------|-----------------|-----------------|
| **Jeedom** | 4.4.0 | Administration → Jeedom |
| **PHP** | 8.0 | `php -v` |
| **Node.js** | **18** (LTS) | `node --version` |
| **npm** | 8+ | `npm --version` |

> **Node.js 18+ est indispensable.** Les box Jeedom récentes (Atlas, Luna, Smart) l'incluent.
> Sur Raspberry Pi ou Docker, vérifiez avec `node --version` avant d'installer.

### Optionnels (installés automatiquement)

| Outil | Fonction | Impact si absent |
|-------|----------|-----------------|
| `ffmpeg` | Conversion audio (note vocale, TTS, STT) | Notes vocales et TTS indisponibles |
| `Piper` | Synthèse vocale (TTS) | Réponses en texte à la place |
| `Vosk` | Transcription vocale (STT) | Notes vocales reçues non transcrites |
| `Tesseract` | OCR (images) | Texte des images non extrait |

> Le plugin **fonctionne sans ces outils**. Les fonctions IA se désactivent proprement
> et un avertissement apparaît dans les logs.

---

## Installation

### Méthode 1 : Via le market Jeedom (recommandée)

1. Dans Jeedom, allez dans **Plugins → Gestion des plugins**
2. Recherchez **JeeWhatsApp**
3. Cliquez sur **Installer**
4. Activez le plugin

### Méthode 2 : Depuis GitHub (beta / développement)

```bash
cd /var/www/html/plugins
git clone -b dev https://github.com/Aldarande/jeewhatsapp.git jeewhatsapp
chown -R www-data:www-data jeewhatsapp/
```

Puis activez le plugin dans Jeedom.

### Méthode 3 : Upload manuel d'un zip

1. Téléchargez le zip depuis GitHub → [Releases](https://github.com/Aldarande/jeewhatsapp/releases)
2. Dans Jeedom : **Plugins → Gestion des plugins → icône Upload**
3. Déposez le zip et confirmez

---

## Installer les dépendances

### Via l'interface Jeedom (recommandé)

1. Allez dans **Plugins → Communication → JeeWhatsApp**
2. Cliquez sur l'icône ⚙️ (Configuration)
3. Cliquez sur **Installer les dépendances**
4. Attendez 5 à 15 minutes (selon la connexion et la machine)

### Via la ligne de commande

```bash
bash /var/www/html/plugins/jeewhatsapp/resources/install_dep.sh /tmp/jwa_dep_progress
```

Les logs s'affichent en temps réel. L'installation inclut :
- `@whiskeysockets/baileys` + `qrcode` + `pino` + `sharp` (npm)
- `ffmpeg` (apt, si disponible)
- Piper TTS + voix `fr_FR-siwis-medium` (optionnel)
- Tesseract OCR + langue française (apt, optionnel)
- Vosk STT + modèle `vosk-model-small-fr-0.22` (optionnel)

> **Checksums SHA-256** vérifiés automatiquement pour Piper, les voix et Vosk.

---

## Premier démarrage

### 1. Activer le plugin

**Plugins → Gestion des plugins → JeeWhatsApp → Activer**

### 2. Créer un équipement

1. **Plugins → Communication → JeeWhatsApp → Ajouter**
2. Donnez un nom à votre équipement (ex : "Mon WhatsApp")
3. Renseignez le **nom du groupe WhatsApp** canal dans la configuration
4. Cliquez **Sauvegarder**

### 3. Scanner le QR code

Le daemon démarre automatiquement et génère un QR code dans l'onglet **Équipement**.

Sur votre téléphone Android/iOS :

```
WhatsApp → ⋮ (menu) → Appareils liés → Lier un appareil → Scanner
```

> Le QR code expire en 60 secondes. Si vous avez raté le délai, cliquez **Rafraîchir**.

### 4. Créer ou lier le groupe canal

Dans l'onglet **Configuration** :

- **Bouton « Créer »** — crée un groupe WhatsApp vide nommé `jeewhatsapp` (ou le nom configuré)
  et l'associe automatiquement. Ajoutez ensuite vos contacts dans ce groupe.
- **Bouton « Rechercher »** — si le groupe existe déjà dans vos groupes WhatsApp.

### 5. Tester l'envoi

Onglet **Test** → **Envoyer dans le groupe canal**. Vous devez recevoir
`🏠 Test JeeWhatsApp 🚀` dans le groupe.

---

## Configuration de la connexion Wi-Fi / réseau

JeeWhatsApp se connecte directement à `web.whatsapp.com` via HTTPS (WebSocket).
Aucun port entrant à ouvrir. Vérifiez que votre serveur Jeedom a accès à Internet.

---

## Mise à jour

### Plugin

Via le market Jeedom (bouton Mettre à jour) ou :

```bash
cd /var/www/html/plugins/jeewhatsapp
git pull
```

Après une mise à jour majeure, re-lancez **Installer les dépendances** si le changelog
le mentionne.

### Node.js (si nécessaire)

```bash
# Via NodeSource (Debian/Ubuntu)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo bash -
sudo apt-get install -y nodejs
```

---

## Désinstallation

La désinstallation **conserve** le dossier `auth/` (sessions WhatsApp) pour éviter
de perdre la connexion. Si vous souhaitez tout supprimer :

```bash
rm -rf /var/www/html/plugins/jeewhatsapp/resources/jeewhatsappd/auth/
```

---

## Dépannage

### QR code non affiché

1. Vérifiez que le daemon est démarré (statut vert dans le plugin)
2. Vérifiez les logs : **Analyse → Logs → jeewhatsapp**
3. Vérifiez que Node.js 18+ est installé : `node --version`

### QR code non reconnu par WhatsApp

- Le compte est déjà lié à 4 appareils (limite WhatsApp) → déliez un appareil sur votre téléphone
- Tentative de connexion trop rapide → attendez 30 secondes et réessayez

### Daemon ne démarre pas

```bash
# Vérifiez les dépendances
node --version  # doit être >= 18
ls /var/www/html/plugins/jeewhatsapp/resources/jeewhatsappd/node_modules/@whiskeysockets/baileys/

# Vérifiez les logs
tail -100 /var/www/html/log/jeewhatsapp
```

### Messages non reçus

1. Vérifiez que le bon groupe est configuré (bouton **Rechercher**)
2. Vérifiez que le daemon est connecté (statut **connected** dans l'équipement)
3. Vérifiez que le message provient d'un **message de groupe** (pas un message direct)

### ffmpeg / Piper / Vosk non trouvés

```bash
# Relancez l'installation des dépendances
bash /var/www/html/plugins/jeewhatsapp/resources/install_dep.sh /tmp/dep_progress
```

---

## Sauvegarde et restauration de session

Pour éviter de re-scanner le QR code après une réinstallation :

1. Onglet **Sécurité** → **Sauvegarder la session** → saisissez une phrase de passe robuste
2. Conservez le fichier `.jwab` en lieu sûr
3. Après réinstallation → **Restaurer la session** → uploadez le fichier + saisissez la phrase

> Le fichier est chiffré en **AES-256-GCM + PBKDF2-SHA256** (200 000 itérations).

---

## Ressources

- [Documentation Jeedom](https://doc.jeedom.com)
- [Baileys GitHub](https://github.com/WhiskeySockets/Baileys)
- [Forum Jeedom](https://community.jeedom.com)
- [Issues GitHub](https://github.com/Aldarande/jeewhatsapp/issues)
