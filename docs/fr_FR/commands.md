# Référence des commandes — JeeWhatsApp

> Toutes les commandes créées automatiquement par `postSave()` à la création d'un équipement.
> Les logicalIds sont stables entre les versions.

---

## Commandes ACTION

### `send_message` — Envoyer un message texte

| Champ | Description |
|-------|-------------|
| **Type** | Action / Message |
| **Paramètre `message`** | Texte à envoyer |
| **Paramètre `title`** | Destinataire (vide = groupe canal, sinon numéro `336...` ou JID `...@s.whatsapp.net`) |

**Exemples :**
```
# Envoyer dans le groupe canal
Message : "Alerte détectée à #time#"
Titre   : (vide)

# Envoyer en direct à un contact
Message : "Bonjour !"
Titre   : "33612345678"
```

> Le préfixe configuré (ex : `🏠 `) est ajouté automatiquement avant le message.

---

### `reply` — Répondre (quoted) au dernier message reçu

| Champ | Description |
|-------|-------------|
| **Type** | Action / Message |
| **Paramètre `message`** | Texte de la réponse |
| **Paramètre `title`** | Ignoré |

La réponse est une **réponse citée** (quoted reply) qui référence le dernier message reçu
dans le groupe canal. Utile pour les interactions : le destinataire voit le contexte.

> Fallback : si aucun message n'a été reçu depuis le démarrage du daemon, un simple message
> est envoyé (sans quote).

---

### `send_media` — Envoyer un fichier

| Champ | Description |
|-------|-------------|
| **Type** | Action / Message |
| **Paramètre `message`** | Chemin absolu du fichier sur le serveur |
| **Paramètre `title`** | Légende optionnelle (caption) |

**Types supportés :**

| Extension | WhatsApp |
|-----------|----------|
| `.jpg`, `.jpeg`, `.png`, `.gif`, `.webp` | Image |
| `.mp4`, `.mkv`, `.avi` | Vidéo |
| `.mp3`, `.ogg`, `.m4a`, `.aac`, `.wav` | Audio |
| `.ogg` (PTT) | Note vocale |
| Autres | Document (téléchargeable) |

```
Message : "/var/www/html/data/img/cam_salon.jpg"
Titre   : "Caméra salon - #time#"
```

---

### `send_location` — Envoyer une localisation GPS

| Champ | Description |
|-------|-------------|
| **Type** | Action / Message |
| **Paramètre `message`** | `latitude|longitude|nom_optionnel` |

```
Message : "48.8566|2.3522|Tour Eiffel, Paris"
```

> Latitude : −90 à 90 | Longitude : −180 à 180

---

### `send_contact` — Envoyer un contact vCard

| Champ | Description |
|-------|-------------|
| **Type** | Action / Message |
| **Paramètre `message`** | Numéro de téléphone international (sans +) |
| **Paramètre `title`** | Nom du contact |

```
Message : "33612345678"
Titre   : "Jean Dupont"
```

---

### `send_voice` — Envoyer une note vocale (TTS)

| Champ | Description |
|-------|-------------|
| **Type** | Action / Message |
| **Paramètre `message`** | Texte à synthétiser |
| **Prérequis** | Piper TTS installé, `ffmpeg` disponible |

```
Message : "La température du salon est de 21 degrés."
```

> Si Piper n'est pas installé, un message texte est envoyé à la place (fallback).

---

### `send_poll` — Envoyer un sondage

| Champ | Description |
|-------|-------------|
| **Type** | Action / Message |
| **Paramètre `message`** | `Question|Option1|Option2|...` (2 à 12 options) |

```
Message : "Quel film ce soir ?|Action|Comédie|Documentaire"
```

> Les résultats sont mis à jour en temps réel dans `poll_results` (JSON).

---

### `send_sticker` — Envoyer un sticker

| Champ | Description |
|-------|-------------|
| **Type** | Action / Message |
| **Paramètre `message`** | Chemin absolu d'un `.webp` (ou image jpg/png → convertie) |

```
Message : "/var/www/html/plugins/jeewhatsapp/plugin_info/jeewhatsapp_icon.png"
```

---

### `react` — Réagir avec un emoji

| Champ | Description |
|-------|-------------|
| **Type** | Action / Message |
| **Paramètre `message`** | Emoji de réaction (ex : `❤️`, `👍`, `🎉`) |

```
Message : "✅"
```

---

### `mark_read` — Marquer comme lu

| Champ | Description |
|-------|-------------|
| **Type** | Action |

Affiche les **coches bleues** sur le dernier message reçu dans le groupe canal.
Pas de paramètre.

---

### `post_status` — Publier un statut WhatsApp

| Champ | Description |
|-------|-------------|
| **Type** | Action / Message |
| **Paramètre `message`** | Texte du statut (ou chemin d'image pour un statut image) |

```
# Statut texte
Message : "Bonne journée depuis ma maison connectée !"

# Statut image (chemin absolu)
Message : "/tmp/graph_energie.png"
```

> Le statut est visible pendant **24 heures** par les contacts WhatsApp de ce compte.

---

### `mute_chat` — Mettre en sourdine

| Champ | Description |
|-------|-------------|
| **Type** | Action |

Met le groupe canal **en sourdine** pour 8 heures. Utile la nuit.

---

### `archive_chat` — Archiver la conversation

| Champ | Description |
|-------|-------------|
| **Type** | Action |

Archive le groupe canal dans WhatsApp (disparaît de la liste principale).

---

### `group_action` — Gérer le groupe

| Champ | Description |
|-------|-------------|
| **Type** | Action / Message |
| **Paramètre `message`** | `operation|valeur` |

| Opération | Valeur | Effet |
|-----------|--------|-------|
| `add` | numéro international | Ajouter un participant |
| `remove` | numéro international | Retirer un participant |
| `promote` | numéro international | Passer en administrateur |
| `demote` | numéro international | Rétrograder |
| `subject` | texte | Changer le nom du groupe |
| `description` | texte | Changer la description |
| `inviteLink` | (vide) | Générer un lien d'invitation |
| `revokeInvite` | (vide) | Révoquer le lien |
| `leave` | (vide) | Quitter le groupe |

```
Message : "add|33698765432"
Message : "subject|Maison Dupont - Alertes"
```

> Le compte WhatsApp lié doit être **administrateur** du groupe.

---

## Commandes INFO

### `last_message` — Dernier message reçu

| Champ | Valeur |
|-------|--------|
| **Type** | Info / String |
| **Historisé** | Non |
| **Déclenchement scénario** | Oui (changement de valeur) |

Texte du dernier message reçu dans le groupe canal. Mis à jour à chaque message.

---

### `last_sender` — Expéditeur (numéro)

| Champ | Valeur |
|-------|--------|
| **Type** | Info / String |
| **Format** | `33612345678` (sans `+`, sans espace) |

---

### `last_sender_name` — Nom de l'expéditeur

| Champ | Valeur |
|-------|--------|
| **Type** | Info / String |

Nom WhatsApp de l'expéditeur (tel qu'affiché dans l'application).

---

### `last_received_at` — Date/heure de réception

| Champ | Valeur |
|-------|--------|
| **Type** | Info / String |
| **Format** | `YYYY-MM-DD HH:MM:SS` |

---

### `last_group` — Tag du groupe d'origine

| Champ | Valeur |
|-------|--------|
| **Type** | Info / String |

Tag du groupe additionnel si le message vient d'un groupe secondaire configuré.
Vide si le message vient du groupe canal principal.

---

### `sent_hour` — Messages envoyés (heure en cours)

| Champ | Valeur |
|-------|--------|
| **Type** | Info / Numeric |
| **Historisé** | Oui |
| **Reset** | Chaque début d'heure |

Compteur des messages envoyés dans l'heure en cours. Stocké en cache Jeedom.

---

### `messages_today` — Messages reçus aujourd'hui

| Champ | Valeur |
|-------|--------|
| **Type** | Info / Numeric |
| **Historisé** | Oui |
| **Reset** | Chaque nuit à minuit |

---

### `last_attachment_path` — Chemin du dernier média reçu

| Champ | Valeur |
|-------|--------|
| **Type** | Info / String |

Chemin absolu du fichier téléchargé dans `data/jeewhatsapp/incoming/{id}/`.
Utilisable directement dans un scénario (ex : envoyer ailleurs, OCR manuel).

---

### `last_attachment_type` — Type du dernier média

| Champ | Valeur |
|-------|--------|
| **Type** | Info / String |
| **Valeurs** | `image`, `video`, `audio`, `document`, `sticker` |

---

### `last_attachment_mime` — MIME du dernier média

| Champ | Valeur |
|-------|--------|
| **Type** | Info / String |
| **Exemple** | `image/jpeg`, `audio/ogg; codecs=opus` |

---

### `last_voice_text` — Transcription STT

| Champ | Valeur |
|-------|--------|
| **Type** | Info / String |
| **Prérequis** | STT activé + Vosk installé |

Texte transcrit de la dernière note vocale reçue.

---

### `last_ocr_text` — Texte OCR

| Champ | Valeur |
|-------|--------|
| **Type** | Info / String |
| **Prérequis** | OCR activé + Tesseract installé |

Texte extrait de la dernière image reçue.

---

### `last_reaction` — Dernière réaction reçue

| Champ | Valeur |
|-------|--------|
| **Type** | Info / String |
| **Exemple** | `❤️`, `👍`, `` (vide si réaction retirée) |

---

### `poll_question` — Question du sondage actuel

| Champ | Valeur |
|-------|--------|
| **Type** | Info / String |

---

### `poll_results` — Résultats du sondage

| Champ | Valeur |
|-------|--------|
| **Type** | Info / String |
| **Format** | JSON : `[{"name":"Option1","votes":2},{"name":"Option2","votes":1}]` |

---

### `poll_total` — Total des votes

| Champ | Valeur |
|-------|--------|
| **Type** | Info / Numeric |

---

## Utilisation dans les scénarios

### Déclencher sur réception d'un message

```
Déclencheur : [JeeWhatsApp][Mon WhatsApp][last_message] change

Bloc Condition :
  [JeeWhatsApp][Mon WhatsApp][last_message] contient "urgent"

Bloc Action :
  Notification push : "Message urgent reçu !"
```

### Déclencher selon l'expéditeur

```
Déclencheur : [JeeWhatsApp][Mon WhatsApp][last_sender] change

Bloc Condition :
  [JeeWhatsApp][Mon WhatsApp][last_sender] == "33612345678"

Bloc Action :
  # Traitement spécifique pour ce contact
```

### Utiliser le texte transcrit (STT)

```
Déclencheur : [JeeWhatsApp][Mon WhatsApp][last_voice_text] change

Bloc Condition :
  [JeeWhatsApp][Mon WhatsApp][last_voice_text] contient "température"

Bloc Action :
  [JeeWhatsApp][Mon WhatsApp][send_voice]
  Message : "La température est de #[Maison][Capteur][Température]# degrés"
```

---

## Format des numéros de téléphone

| Format | Exemple | Accepté ? |
|--------|---------|-----------|
| International sans + | `33612345678` | ✅ Recommandé |
| International avec + | `+33612345678` | ✅ (converti) |
| National | `0612345678` | ⚠️ Éviter (ambiguïté de pays) |
| Avec espaces | `33 6 12 34 56 78` | ✅ (espaces supprimés) |

> Le daemon convertit automatiquement en JID Baileys : `33612345678@s.whatsapp.net`
