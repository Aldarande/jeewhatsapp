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
| **Présence "en train d'écrire"** | (v0.3) Affiche `en train d'écrire…` / `enregistre…` pendant ~1 s avant chaque envoi automatique. Humanise les messages. |
| **Messages éphémères** | (v0.3) Désactivé / 24 h / 7 j / 90 j. Tous les messages envoyés par Jeedom disparaissent automatiquement après le délai choisi. |
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

| Nom | logicalId | Sous-type | Historisé | Description |
|---|---|---|---|---|
| Dernier message | `last_message` | string | non | Texte du dernier message reçu dans le groupe canal |
| Expéditeur | `last_sender` | string | non | Numéro de l'expéditeur du dernier message |
| Nom expéditeur | `last_sender_name` | string | non | Pseudo WhatsApp de l'expéditeur |
| Reçu le | `last_received_at` | string | non | Horodatage du dernier message reçu |
| Envoyés (heure en cours) | `sent_hour` | numeric | oui | Compteur d'envois durant l'heure courante (reset toutes les heures) |
| Reçus aujourd'hui | `messages_today` | numeric | oui | Compteur de messages reçus depuis minuit (reset cron daily 00:02) |
| Connecté depuis | `connected_since` | string | non | Date/heure de la dernière connexion WhatsApp Web (refresh cron 5 min) |
| Dernière réaction | `last_reaction` | string | non | Emoji de la dernière réaction reçue dans le groupe (vide = réaction retirée) |
| Réaction — expéditeur | `last_reaction_from` | string | non | Numéro de l'auteur de la dernière réaction |
| Réaction — date | `last_reaction_at` | string | non | Horodatage de la dernière réaction |
| Dernier média — chemin | `last_attachment_path` | string | non | Chemin absolu serveur du dernier média reçu (image/vidéo/audio/document/sticker) |
| Dernier média — type | `last_attachment_type` | string | non | `image` / `video` / `audio` / `document` / `sticker` |
| Dernier média — mime | `last_attachment_mime` | string | non | Type MIME du dernier média reçu (`image/jpeg`, `audio/ogg; codecs=opus`, ...) |
| Dernier média — taille | `last_attachment_size` | numeric | non | Taille en octets du dernier média reçu |
| Sondage — question | `poll_question` | string | non | Question du dernier sondage dont un vote a été reçu |
| Sondage — résultats | `poll_results` | string | non | Résultats au format JSON `[{name, votes}]` |
| Sondage — total votes | `poll_total` | numeric | oui | Nombre total de votes reçus sur le dernier sondage |
| Dernier groupe — tag | `last_group` | string | non | (v0.3) Tag du groupe d'origine du dernier message reçu (`` vide = groupe canal principal) |
| Dernier groupe — nom | `last_group_name` | string | non | (v0.3) Nom du groupe WhatsApp d'origine du dernier message reçu |

### Commandes ACTION

| Nom | logicalId | Sous-type | Description |
|---|---|---|---|
| Envoyer un message | `send_message` | message | Envoie un message dans le groupe canal. Champ **Titre** = destinataire optionnel (vide = groupe canal, sinon numéro direct). |
| Répondre | `reply` | message | Réponse "quoted" au dernier message reçu dans le groupe (citation visible). |
| Envoyer un média | `send_media` | message | Envoie un fichier (image, vidéo, audio, document). Champ **Titre** = chemin absolu, **Message** = légende optionnelle. |
| Envoyer une localisation | `send_location` | message | Envoie une position GPS. Champ **Titre** = `lat\|long` ou `lat\|long\|nom`. |
| Envoyer un contact | `send_contact` | message | Envoie une carte vCard. Champ **Titre** = numéro, **Message** = nom affiché (optionnel). |
| Réagir au dernier message | `react_last` | message | Envoie une réaction emoji sur le dernier message reçu. Champ **Message** = emoji (❤️ 👍 🎉 …) ou vide pour retirer la réaction. |
| Éditer le dernier message | `edit_last` | message | (v0.3) Remplace le texte du dernier message **envoyé** par Jeedom. Champ **Message** = nouveau texte. |
| Supprimer le dernier message | `revoke_last` | other | (v0.3) Supprime "pour tous" le dernier message **envoyé** par Jeedom (bouton, aucun paramètre). |
| Transférer le dernier message reçu | `forward_to` | message | (v0.3) Transfère le dernier message **reçu** vers un destinataire. Champ **Titre** = destinataire optionnel (vide = groupe canal). |
| Envoyer un sticker | `send_sticker` | message | (v0.3) Envoie un sticker. Champ **Titre** = chemin absolu d'un `.webp` (ou `.png`/`.jpg` converti en WebP 512×512). |
| Envoyer un sondage | `send_poll` | message | (v0.3) Envoie un sondage. Champ **Titre** = question, **Message** = options séparées par `\|` (ex: `Oui\|Non\|Peut-être`, 2 à 12 options). Les votes alimentent les commandes info `poll_*`. |
| Envoyer dans un groupe additionnel | `send_group` | message | (v0.3) Envoie un message dans un groupe additionnel. Champ **Titre** = tag du groupe (cf config « Groupes additionnels »), **Message** = texte. |

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

> **👥 Groupes additionnels (`send_group`) — v0.3**  
> Par défaut, un équipement n'écoute et n'écrit que dans **un** groupe canal. Pour gérer
> plusieurs groupes (alertes, info, famille…) avec le **même** compte WhatsApp, renseignez le
> champ **Groupes additionnels** de l'équipement, une ligne par groupe au format
> `tag=Nom exact du groupe WhatsApp` :
> ```
> alertes=Alertes Maison
> famille=Groupe Famille
> ```
> - Le **tag** (`alertes`, `famille`…) sert à cibler le groupe via la commande
>   **Envoyer dans un groupe additionnel** (`send_group`) : champ **Titre** = tag, **Message** = texte.
> - Les messages **reçus** dans ces groupes alimentent aussi les commandes info,
>   et le groupe d'origine est exposé via `last_group` (tag, vide = groupe principal) et `last_group_name`.
> - Le groupe canal **principal** reste inchangé ; cette fonction est purement additive
>   (rétrocompatible avec les configurations existantes).

---

## Interactions Jeedom

Lorsque l'option **Interactions Jeedom** est activée, chaque message reçu dans le groupe canal
est transmis au moteur d'interactions Jeedom. Si une interaction correspond, la réponse est
automatiquement envoyée **dans le groupe canal**.

### Filtre par mot-clé déclencheur (v0.2)

Champ **« Mot-clé déclencheur »** dans la configuration équipement. Si renseigné, seuls les messages
**commençant** par ce mot-clé (insensible à la casse) déclenchent les interactions. Le mot-clé est
**retiré** du message avant transmission au moteur d'interactions Jeedom — permet d'avoir des
formulations naturelles côté Jeedom tout en évitant le bruit dans le groupe.

| Configuration | Message reçu | Comportement |
|---|---|---|
| keyword vide | `allume salon` | → interactQuery cherche `allume salon` |
| keyword = `!jeedom` | `bonjour la famille` | → ignoré (debug log) |
| keyword = `!jeedom` | `!jeedom allume salon` | → interactQuery cherche `allume salon` |
| keyword = `@jeedom` | `@JEEDOM statut` | → interactQuery cherche `statut` (casse ignorée) |

### Whitelist d'expéditeurs (v0.2 — sécurité)

Champ **« Whitelist expéditeurs »** : si renseigné, seuls les numéros listés peuvent déclencher
des interactions Jeedom. Les autres membres du groupe sont **silencieusement ignorés** (log debug).

**Format accepté** : 1 numéro par ligne ou séparés par virgule, dans n'importe quel format :
- `0612345678` (français court)
- `33612345678` (international)
- `+33 6 12 34 56 78` (avec espaces et +)

Tous les formats sont normalisés au format international avant comparaison.

> **🛡️ Sécurité** : la whitelist protège contre un membre malveillant qui s'inviterait au groupe et tenterait
> d'envoyer des commandes Jeedom. Combinée au filtre mot-clé, elle offre une double couche de protection.

Exemples d'interactions configurables dans Jeedom :

| Message reçu | Réponse automatique |
|---|---|
| `température salon` | `La température du salon est de 21°C` |
| `allume la lumière` | `Lumière allumée` |
| `statut` | `Tous les équipements sont OK` |

> Configurez vos interactions dans **Outils → Interactions** dans Jeedom.

### Commandes shortcuts — « slash » (v0.4)

Champ **« Commandes shortcuts »** : des raccourcis rapides déclenchés par un message
commençant par `/`. Ils sont **prioritaires** sur le moteur d'interactions (NLP) et ne
nécessitent aucune configuration dans *Outils → Interactions* — idéal pour les commandes
fréquentes.

**Format** : une ligne par raccourci, `/déclencheur=cible`. La cible peut être :

| Type de cible | Exemple de ligne | Effet du message `/déclencheur` |
|---|---|---|
| **Commande action** `#id#` | `/scene=#9012#` | Exécute la commande action `9012`, répond `✅ Nom de la commande` |
| **Commande info** `#id#` | `/temp=#1234#` | Répond la valeur courante : `Température salon : 21 °C` |
| **Texte modèle** | `/bonjour=Salut #args# !` | `/bonjour Paul` → répond `Salut Paul !` |
| **Modèle + tags Jeedom** | `/maison=Salon #1234# / Ext #5678#` | Remplace les `#id#` d'infos par leur valeur |

**Variables disponibles dans un texte modèle** :
- `#args#` : tous les arguments après le déclencheur (`/echo bonjour le monde` → `#args#` = `bonjour le monde`)
- `#1#`, `#2#`, … : chaque mot d'argument pris séparément

Pour une commande action de sous-type *message*, l'argument est passé comme texte du
message ; pour un *slider*, comme valeur ; pour une *couleur*, comme code couleur.

Un déclencheur inconnu renvoie `❓ Raccourci inconnu : /xxx`.

> **Exemple complet** :
> ```
> /salon=#1234#
> /allumer=#1057#
> /statut=🏠 Salon : #1234# °C — Alarme : #1099#
> /dis=Message reçu : #args#
> ```
> Puis dans le groupe : `/salon` → `Température salon : 21 °C`, `/dis Coucou` → `Message reçu : Coucou`.

---

### Reconnaissance utilisateur (v0.4)

Champ **« Reconnaissance utilisateur »** : associe le numéro d'un expéditeur à un **profil
Jeedom**. Une ligne par correspondance, au format `numéro=profil`.

```
33612345678=Papa
0698765432=Maman
33700000000=Enfant
```

Les numéros sont normalisés au format international (`0612345678`, `+33 6 12 34 56 78` et
`33612345678` sont équivalents).

Lorsqu'un message arrive d'un numéro mappé :

- le profil résolu est exposé dans la commande info **« Expéditeur — profil »**
  (`last_sender_profile`) — utilisable dans des scénarios pour personnaliser les réponses ;
- il est transmis au moteur d'interactions Jeedom via l'option `profile`, ce qui le rend
  **compatible avec le plugin Profils** (règles d'accès, restrictions, personnalisation par
  personne).

Si aucun mapping ne correspond, le profil retombe sur le nom WhatsApp de l'expéditeur,
puis sur son numéro brut. La commande info reste vide quand l'expéditeur n'est pas mappé.

> **Exemple** : avec `33612345678=Papa`, un message « éteins la chambre » envoyé depuis ce
> numéro est traité par les interactions Jeedom comme provenant du profil *Papa* — vous
> pouvez ainsi autoriser certaines commandes uniquement à ce profil via le plugin Profils.

---

### Réponses vocales — synthèse vocale / TTS (v0.4)

Le plugin peut **parler** : un texte est synthétisé en **note vocale** (Opus `.ogg`,
affichée comme un message vocal dans WhatsApp) grâce à **Piper**, un moteur de synthèse
**100 % local** (aucun service tiers, aucune donnée envoyée à l'extérieur).

**Deux usages :**

1. **Commande action « Envoyer une note vocale »** (`send_voice`) — à utiliser dans un
   scénario : le champ *Message* contient le texte à dire, le champ *Titre* un destinataire
   optionnel (vide = groupe canal). Exemple : `[Mon WhatsApp][Envoyer une note vocale]` →
   Message : `La température du salon est de 21 degrés.`
2. **Mode « vocal-first »** — cochez **« Réponses vocales (TTS) → Activer le mode vocal »**
   dans la configuration de l'équipement. Toutes les réponses automatiques (interactions
   Jeedom et raccourcis `/`) sont alors envoyées en note vocale au lieu de texte. En cas
   d'échec de la synthèse, le plugin **retombe automatiquement sur le texte**.

**Voix** : la voix française `fr_FR-siwis-medium` est installée par défaut. Pour en utiliser
une autre, placez un modèle Piper (`.onnx` + `.onnx.json`) dans `resources/piper/voices/` et
indiquez son nom de fichier dans le champ **« Voix de synthèse »** (ou un chemin absolu).

> **Prérequis** : `ffmpeg` doit être installé sur le serveur (présent par défaut sur la
> plupart des installations Jeedom). Le binaire Piper et la voix française sont téléchargés
> automatiquement lors de l'installation des dépendances du plugin. Si l'installation de
> Piper échoue, le plugin continue de fonctionner — seules les réponses vocales sont
> désactivées (repli sur le texte).

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

### Confirmer la réception d'une commande avec une réaction ❤️

**Déclencheur :** Un membre du groupe envoie le mot "thanks"

**Actions :**
- `[Mon WhatsApp][Réagir au dernier message]` → Message : `❤️`

### Allumer une ambiance suivant la réaction reçue

**Déclencheur :** `[Mon WhatsApp][Dernière réaction]` change

**Conditions :**
- Si `[Mon WhatsApp][Dernière réaction]` = `❤️` → allumer ambiance romantique
- Si `[Mon WhatsApp][Dernière réaction]` = `🎉` → allumer ambiance fête
- Si `[Mon WhatsApp][Dernière réaction]` = `🌙` → mode nuit

### Traiter une photo de compteur envoyée par WhatsApp

**Déclencheur :** `[Mon WhatsApp][Dernier média — type]` change

**Conditions :** si `[...][Dernier média — type]` = `image`

**Actions :**
- Copier `[...][Dernier média — chemin]` vers `/var/www/html/data/compteurs/`
- (avancé) Appeler un script OCR sur l'image, extraire la valeur, mettre à jour un virtuel

> **📥 Réception médias (v0.2)**
> Les images, vidéos, notes vocales, documents et stickers reçus dans le groupe canal
> sont automatiquement téléchargés dans `data/jeewhatsapp/incoming/{eqId}/{YYYY-MM-DD}/{uuid}.ext`.
> Les fichiers sont conservés **30 jours** puis supprimés par cron (`cronCleanupIncoming` à 03:15).
> Le chemin est exposé via les 4 cmds info `last_attachment_*` — vos scénarios peuvent
> les copier ailleurs, les analyser (OCR, vision), ou les transférer.

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

### Aucun message reçu et le log est saturé de « Bad MAC »

Si le log `jeewhatsapp` affiche en boucle `Failed to decrypt message with any known session`
ou `Session error: Bad MAC`, c'est que la **session chiffrée (Signal) est corrompue** :
WhatsApp envoie bien les messages mais le daemon ne peut plus les déchiffrer, ils sont donc
silencieusement abandonnés. Cela arrive notamment après des redémarrages rapprochés du daemon
ou un usage concurrent de la même session.

**Solution : ré-appairer l'appareil.**

1. Sur votre téléphone : **WhatsApp → Appareils connectés**, supprimez l'appareil « JeeWhatsApp ».
2. Côté serveur, supprimez (ou renommez) le dossier de session de l'équipement :
   `plugins/jeewhatsapp/resources/jeewhatsappd/auth/{ID_équipement}/`
3. Redémarrez le daemon depuis la gestion du plugin.
4. Un nouveau QR code s'affiche dans l'onglet **Connexion WhatsApp** — rescannez-le.

Les clés de chiffrement sont régénérées et la réception refonctionne.

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
