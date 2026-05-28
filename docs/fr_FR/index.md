# JeeWhatsApp — Documentation

> Plugin ID : `jeewhatsapp`  
> Auteur : Aldarande — Licence : AGPL v3

---

## Présentation

JeeWhatsApp intègre **WhatsApp** dans Jeedom via [Baileys](https://github.com/WhiskeySockets/Baileys),
une bibliothèque open-source qui se connecte directement à WhatsApp Web.
**Aucune donnée ne transite par un serveur tiers** — tout reste entre votre serveur Jeedom et les serveurs WhatsApp.

### Principe de fonctionnement — le groupe canal

JeeWhatsApp repose sur un **groupe WhatsApp dédié** qui sert de canal de communication bidirectionnel entre Jeedom et l'utilisateur.

- **Jeedom → vous** : chaque message envoyé depuis un scénario arrive dans le groupe, préfixé (ex : `🏠 `)
- **Vous → Jeedom** : vos messages dans le groupe sont reçus par Jeedom et déclenchent vos scénarios ou le moteur d'interactions
- Les messages en dehors du groupe (messages directs, autres groupes) sont ignorés

> Ce groupe peut être créé automatiquement par le plugin depuis l'interface de configuration.

### Fonctionnalités

- 💬 **Envoi de messages** depuis un scénario Jeedom vers le groupe canal
- 📥 **Réception temps réel** — WebSocket persistant, pas de polling
- 🔄 **Bidirectionnel** — pilotez Jeedom via WhatsApp depuis le groupe
- 🤖 **Interactions Jeedom** — réponses automatiques via le moteur d'interactions intégré
- 📱 **Votre numéro** — connexion par QR code, aucun numéro dédié requis
- 🔒 **100 % self-hosted** — aucun compte tiers, aucune clé API, aucun abonnement
- ⚡ **Temps réel** — connexion WebSocket persistante, réception instantanée

### Ce que le plugin ne fait pas (actuellement)

- Envoi de médias (images, vidéos, documents, vocaux)
- Envoi vers un numéro non enregistré sur WhatsApp
- Réception de messages hors du groupe canal
- **Notifications push pour le propriétaire du compte** (voir ci-dessous)

### ⚠️ Limitation — notifications push

JeeWhatsApp utilise **votre propre compte WhatsApp** (lié par QR code).
WhatsApp ne notifie jamais le propriétaire du compte pour les messages qu'il envoie lui-même —
cette règle s'applique aussi aux mentions (`@vous`), qui ont été testées et ne déclenchent pas de notification non plus.

**Conséquence :** quand Jeedom poste dans le groupe canal, vous ne recevez **aucune notification push** sur votre téléphone.
Vous pouvez voir les messages en ouvrant le groupe, mais pas d'alerte sonore ou bannière.

**Solution : utiliser un deuxième compte WhatsApp dédié à Jeedom**

| Configuration | Notifications | Complexité |
|---|---|---|
| Compte unique (votre numéro) | ❌ Aucune pour vous | Simple |
| Deux comptes (Jeedom bot + votre numéro) | ✅ Vraies notifications | Nécessite un 2ème numéro |

Avec deux comptes :
- Le compte "Jeedom bot" (numéro virtuel ou SIM dédiée) est connecté dans Jeedom
- Ce compte envoie les messages dans le groupe
- Votre compte personnel est **membre du groupe** et reçoit les notifications normalement
- La commande "Envoyer un message" dans un scénario notifie votre téléphone comme n'importe quel message de groupe

> Un numéro virtuel (Google Voice, numéro eSIM bas de gamme) suffit — il n'a pas besoin d'être actif en permanence pour WhatsApp.

---

## Prérequis

- Jeedom 4.4 ou supérieur
- Node.js **18 ou supérieur** sur la machine Jeedom
- Un téléphone avec l'application WhatsApp pour scanner le QR code

---

## Installation

### Étape 1 — Installer le plugin

Copiez le dossier `jeewhatsapp` dans `plugins/` de votre installation Jeedom,
puis allez dans **Plugins → Gestion des plugins** et activez **JeeWhatsApp**.

### Étape 2 — Installer les dépendances

Dans la page du plugin, cliquez sur **Installer les dépendances**.
Le script installe `@whiskeysockets/baileys`, `qrcode` et `pino` via npm.
Cette étape peut prendre **2 à 5 minutes** selon votre connexion internet.

> **Prérequis Node.js**  
> Le script vérifie la version de Node.js. Si elle est inférieure à 18, l'installation échoue.  
> Pour vérifier : `node --version` dans un terminal.

### Étape 3 — Démarrer le daemon

Cliquez sur **Démarrer le daemon** dans la page de configuration du plugin.
Le statut doit passer à **OK**.

---

## Configuration

### Créer un équipement

Allez dans **Plugins → Communication → JeeWhatsApp** et cliquez sur **Ajouter**.

| Champ | Description |
|---|---|
| Nom | Nom affiché dans Jeedom (ex : Mon WhatsApp) |
| Objet parent | Objet Jeedom auquel rattacher l'équipement |
| Activer | Doit être coché pour que le daemon prenne en compte cet équipement |
| **Groupe canal** | Nom exact du groupe WhatsApp utilisé comme canal (défaut : `jeewhatsapp`) |
| **Groupe lié** | JID du groupe renseigné automatiquement après recherche ou création — en lecture seule |
| Interactions Jeedom | Active les réponses automatiques via le moteur d'interactions |
| **Préfixe Jeedom** | Texte ajouté au début de chaque message envoyé par Jeedom (défaut : `🏠 `) |

Sauvegardez. Les commandes sont créées automatiquement.

### Configurer le groupe canal

Après la sauvegarde de l'équipement, vous devez lier un groupe WhatsApp.
**Vous devez d'abord être connecté à WhatsApp (QR code scanné).**

Deux options dans le champ **Groupe canal** :

**Option A — Groupe existant**

1. Créez manuellement un groupe WhatsApp depuis votre téléphone et nommez-le (ex : `jeewhatsapp`)
2. Renseignez ce nom dans le champ **Groupe canal**
3. Cliquez sur **Rechercher** — le JID est automatiquement renseigné
4. Sauvegardez

**Option B — Créer le groupe depuis Jeedom**

1. Renseignez le nom souhaité dans **Groupe canal**
2. Cliquez sur **Créer** — le groupe est créé sur WhatsApp et le JID est renseigné
3. Sauvegardez
4. Depuis votre téléphone, ajoutez les membres souhaités au groupe

> **Le champ "Groupe lié" (JID)**  
> Ce champ en lecture seule contient l'identifiant technique du groupe WhatsApp (format `120363…@g.us`).
> Il est renseigné automatiquement par les boutons **Rechercher** / **Créer** et ne doit pas être modifié manuellement.

---

## Connexion par QR code (première connexion)

Après avoir créé et sauvegardé l'équipement, rendez-vous sur l'onglet **Connexion WhatsApp**.

1. Un QR code s'affiche automatiquement (rafraîchissement toutes les 8 secondes)
2. Ouvrez WhatsApp sur votre téléphone
3. Allez dans **Paramètres → Appareils liés → Lier un appareil**
4. Scannez le QR code
5. Le statut passe à **Connecté** ✅

> **Session persistante**  
> Une fois connecté, les credentials sont sauvegardés localement dans `resources/jeewhatsappd/auth/{id}/`.
> Vous n'aurez pas à rescanner le QR code à chaque redémarrage du daemon.

> **Recherche automatique du groupe**  
> Dès que la connexion WhatsApp est établie, le daemon recherche automatiquement le groupe canal configuré.
> Le résultat est affiché dans les logs `jeewhatsapp` : `✓ Groupe "jeewhatsapp" → 120363…@g.us`.

---

## Commandes

### Commandes INFO (lecture)

| Nom | logicalId | Type | Description |
|---|---|---|---|
| Dernier message | `last_message` | string | Texte du dernier message reçu dans le groupe canal |
| Expéditeur | `last_sender` | string | Numéro du membre du groupe qui a envoyé le message |
| Nom expéditeur | `last_sender_name` | string | Nom WhatsApp de l'expéditeur |
| Reçu le | `last_received_at` | string | Horodatage du dernier message |
| Envoyés (heure) | `sent_hour` | numeric | Nombre de messages envoyés sur l'heure en cours |

### Commandes ACTION

| Nom | logicalId | Sous-type | Description |
|---|---|---|---|
| Envoyer un message | `send_message` | message | Envoie un message dans le groupe canal. Champ **Titre** = destinataire optionnel (vide = groupe canal, sinon numéro direct). |
| Répondre | `reply` | message | Réponse "quoted" au dernier message reçu dans le groupe (citation visible). |
| Envoyer un média | `send_media` | message | Envoie un fichier (image, vidéo, audio, document). Champ **Titre** = chemin absolu, **Message** = légende optionnelle. |
| Envoyer une localisation | `send_location` | message | Envoie une position GPS. Champ **Titre** = `lat\|long` ou `lat\|long\|nom`. |
| Envoyer un contact | `send_contact` | message | Envoie une carte vCard. Champ **Titre** = numéro, **Message** = nom affiché (optionnel). |

> **💡 Champ "Titre" de la commande Envoyer un message**  
> Jeedom affiche deux champs pour les commandes de type `message` : **Titre** et **Message**.  
> Dans JeeWhatsApp, le champ **Titre** est un **override optionnel** :  
> — Vide → le message est envoyé dans le **groupe canal**  
> — Numéro (ex : `33612345678`) → envoi direct à ce numéro (hors groupe)  
> — JID de groupe (ex : `120363…@g.us`) → envoi dans ce groupe spécifique

> **💡 Commande Répondre**  
> En mode groupe canal, `reply` envoie dans le groupe (visible de tous les membres).
> La réponse n'est pas privée — c'est un message public dans le canal.

> **💡 Préfixe Jeedom**  
> Tous les messages envoyés par Jeedom sont automatiquement préfixés (ex : `🏠 `).
> Les membres du groupe peuvent ainsi distinguer les alertes Jeedom de leurs propres messages.
> Le daemon ignore les messages `fromMe` dans le groupe, évitant que Jeedom traite ses propres envois.

> **📍 Envoyer une localisation (`send_location`)**  
> Format du champ **Titre** : `lat|long` ou `lat|long|nom` (séparateur `|`).  
> Exemples :
> - `48.8566|2.3522` → Tour Eiffel sans label
> - `48.8566|2.3522|Tour Eiffel` → avec nom du lieu
> - `45.7640|4.8357|Place Bellecour, Lyon` → avec adresse
>
> Validation : lat ∈ [-90, 90], long ∈ [-180, 180]. Le champ **Message** est ignoré.

> **👤 Envoyer un contact (`send_contact`)**  
> Format du champ **Titre** : numéro international sans `+` ni espaces (ex : `33612345678`).  
> Format français accepté : `0612345678` (converti automatiquement en `33612345678`).  
> Champ **Message** = nom affiché de la vCard (optionnel, sinon le numéro est utilisé).

---

## Interactions Jeedom

Lorsque l'option **Interactions Jeedom** est activée, chaque message reçu dans le groupe canal
est transmis au moteur d'interactions Jeedom. Si une interaction correspond, la réponse est
automatiquement envoyée **dans le groupe canal**.

Exemples d'interactions configurables dans Jeedom :

| Message reçu | Réponse automatique |
|---|---|
| `température salon` | `La température du salon est de 21°C` |
| `allume la lumière` | `Lumière allumée` |
| `statut` | `Tous les équipements sont OK` |

> Configurez vos interactions dans **Outils → Interactions** dans Jeedom.

---

## Scénarios

### Alerte intrus — message dans le groupe

**Déclencheur :** Détecteur de mouvement actif entre 23h et 6h

**Actions :**
- `[Mon WhatsApp][Envoyer un message]` → Message : `⚠️ Mouvement détecté dans le salon !`

Le message arrive dans le groupe canal avec le préfixe Jeedom.

### Commande par mot-clé avec réponse dans le groupe

**Déclencheur :** `[Mon WhatsApp][Dernier message]` change

**Condition :** `[Mon WhatsApp][Dernier message]` contient `lumière`

**Actions :**
- Allumer la lumière du salon
- `[Mon WhatsApp][Répondre]` → Message : `💡 Lumière allumée !`

### Rapport journalier dans le groupe

**Déclencheur :** Tous les jours à 8h00

**Actions :**
- `[Mon WhatsApp][Envoyer un message]` → Message : `☀️ Bonjour ! Température salon : [Salon][Température]°C`

### Partager une localisation 📍

**Déclencheur :** Bouton virtuel "Partager ma maison"

**Actions :**
- `[Mon WhatsApp][Envoyer une localisation]` → Titre : `48.8566|2.3522|Maison`

### Envoyer la carte de contact du médecin

**Déclencheur :** Mot-clé "docteur" reçu dans le groupe

**Actions :**
- `[Mon WhatsApp][Envoyer un contact]` → Titre : `33112345678`, Message : `Dr Dupont — cabinet`

---

## Dépannage

### Je ne reçois pas de notification WhatsApp quand Jeedom envoie un message

C'est une limitation de WhatsApp : un compte ne reçoit pas de notification pour ses propres messages,
même avec une mention `@vous`. La seule solution est d'utiliser un deuxième compte WhatsApp dédié à Jeedom
(voir la section "Limitation — notifications push" dans la Présentation).

### Le daemon ne démarre pas

- Vérifiez que Node.js 18+ est installé : `node --version` dans un terminal
- Relancez l'installation des dépendances
- Consultez le log `jeewhatsapp` dans **Analyse → Logs**
- Vérifiez que le port `55148` n'est pas déjà utilisé

### Le QR code ne s'affiche pas

- Vérifiez que le daemon est démarré (statut OK dans la gestion du plugin)
- Redémarrez le daemon puis rafraîchissez la page
- Consultez le log `jeewhatsapp` pour détecter une erreur Baileys

### Le groupe canal n'est pas trouvé au démarrage

- Vérifiez que le nom dans **Groupe canal** correspond exactement au nom du groupe WhatsApp (sensible à la casse)
- Le groupe doit exister sur WhatsApp et votre compte doit en être membre
- Cliquez sur **Rechercher** dans la page de configuration pour forcer la recherche manuellement
- Consultez le log `jeewhatsapp` : le daemon affiche `Groupe "xxx" introuvable` avec le nom recherché

### Les messages ne sont pas reçus

- Vérifiez que le statut dans l'onglet **Connexion WhatsApp** affiche **Connecté**
- Vérifiez que le groupe canal est bien lié (champ **Groupe lié** renseigné)
- Seuls les messages texte du groupe canal sont traités — les médias sont ignorés
- Consultez le log `jeewhatsapp` pour vérifier que le daemon reçoit bien les messages

### L'envoi échoue

- Vérifiez que le statut WhatsApp est **Connecté**
- Vérifiez que le groupe lié est renseigné (champ **Groupe lié** non vide)
- Testez depuis l'onglet **Test** de la page équipement (laissez Destinataire vide pour envoyer dans le groupe)
- Consultez le log `jeewhatsapp`

### WhatsApp a déconnecté la session (logout)

Quand WhatsApp révoque l'accès à l'appareil lié :
1. Le daemon détecte la déconnexion et supprime les credentials
2. Dans l'onglet **Connexion WhatsApp**, un nouveau QR code apparaît automatiquement
3. Rescannez le QR code pour reconnecter
4. À la reconnexion, le daemon recherche automatiquement le groupe canal

---

## Architecture technique

```
Groupe WhatsApp "jeewhatsapp"
       │
       │  (WebSocket Baileys — connexion directe)
       │
  jeewhatsappd.js
       │
       ├─ messages.upsert
       │    ├─ Filtre : remoteJid === groupJid ? → sinon ignoré
       │    ├─ Filtre : fromMe ? → ignoré (message de Jeedom)
       │    └─ POST callback.php?apikey=
       │         └─ jeewhatsapp::callback()
       │              └─ cmd.event() → [last_message, last_sender, …]
       │
       └─ /action (HTTP local 127.0.0.1:55148)
            ├─ send        → sock.sendMessage(jid, { text: préfixe + message })
            ├─ findGroup   → sock.groupFetchAllParticipating()
            ├─ createGroup → sock.groupCreate(name, [])
            ├─ getQR       → lit auth/{id}/qr.txt
            └─ getStatus   → lit auth/{id}/status.txt
```

### Composants

| Composant | Technologie | Rôle |
|---|---|---|
| `jeewhatsappd.js` | Node.js (ESM) | Daemon — connexion Baileys + serveur HTTP local |
| `jeewhatsapp.class.php` | PHP | Logique Jeedom, lifecycle daemon, commandes |
| `callback.php` | PHP | Endpoint de réception des messages du daemon |
| `jeewhatsapp.ajax.php` | PHP | Actions AJAX (test, QR code, findGroup, createGroup) |

### Auth et sessions

Les credentials Baileys sont stockés dans `resources/jeewhatsappd/auth/{eqLogicId}/`.
Un sous-dossier par équipement permet de gérer plusieurs comptes WhatsApp simultanément.

| Fichier | Contenu |
|---|---|
| `*.json` | Credentials Baileys (multi-file auth state) |
| `qr.txt` | QR code base64 temporaire (supprimé à la connexion) |
| `status.txt` | Statut courant : `connecting`, `connected`, `qr_pending`, `reconnecting`, `logged_out` |
| `group_jid.txt` | JID du groupe canal (mis en cache pour accélération au redémarrage) |

---

## À propos

- **Plugin :** JeeWhatsApp v0.1
- **Licence :** AGPL v3
- **Backend :** [Baileys](https://github.com/WhiskeySockets/Baileys) — open-source, MIT licence
- **WhatsApp** est une marque déposée de Meta Platforms, Inc.
- Ce plugin n'est pas affilié à Meta ni à WhatsApp.
