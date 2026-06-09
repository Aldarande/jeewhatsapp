# JeeWhatsApp — Documentation

> Plugin ID: `jeewhatsapp`  
> Author: Aldarande — License: AGPL v3

---

## Installation

### Prerequisites

| Component | Version | Required? |
|---|---|---|
| Jeedom | ≥ 4.4.0 | ✅ |
| Node.js | ≥ 18 | ✅ (already present on recent Jeedom boxes) |
| `ffmpeg` | any | ⚠️ optional (voice notes/TTS/STT) — installed automatically |
| `python3` + `pip3` | 3.8+ | ⚠️ optional (STT Vosk) — already present on Jeedom |
| `tesseract-ocr` | 4+ | ⚠️ optional (image OCR) — installed automatically |

> Hardware compatibility: x86_64 (VM/PC/NUC/Docker) and **ARM** (Raspberry Pi, Jeedom Smart/Atlas/Luna boxes).
> The Piper binary is fetched via `uname -m` for the target architecture.

### Steps

1. **Install the plugin** via the Jeedom market (or by manually uploading the zip), then **activate** it.
2. **Install dependencies**: click the "Install dependencies" button on the plugin page —
   this installs Baileys + ffmpeg + Piper (TTS) + Tesseract (OCR) + Vosk (STT) all at once.
   - Allow **5 to 15 minutes** depending on your connection and machine (downloads ~150 MB).
   - AI components are **non-blocking**: if one fails, the plugin works without that feature.
3. **Create a JeeWhatsApp device** (any name), then **save** it.
4. **Start the daemon**: Jeedom launches it automatically after the device is created.
   If there is an issue, restart it manually using the "Start daemon" button.
5. **Scan the QR code**: open the device → **Configuration** tab → a QR code is displayed.
   On your phone: WhatsApp → **Settings → Linked devices → Link a device**, then scan.
   The status changes to **"connected"** within 5 seconds.
6. **Create or find the channel group**: click "Create" in the *Linked group* section
   (creates an empty group named `jeewhatsapp` by default), or "Search" if you already
   created it manually. Then add your contacts to this WhatsApp group.
7. **Test**: **Test** tab → click "Send to channel group". You should receive
   `🏠 Test JeeWhatsApp 🚀` in the group.

### Update

The plugin handles updates automatically via `jeewhatsapp_update()` (creating any missing crons
for new features). After a major update, re-run **"Install dependencies"** if new components
are announced in the changelog.

### Uninstallation

Uninstalling **preserves** the `resources/jeewhatsappd/auth/` folder (WhatsApp sessions)
to avoid having to re-scan the QR code after reinstallation. To wipe everything:
delete this folder manually after uninstalling.

---

## Overview

JeeWhatsApp integrates **WhatsApp** into Jeedom via [Baileys](https://github.com/WhiskeySockets/Baileys),
an open-source library that connects directly to WhatsApp Web.
**No data passes through a third-party server** — everything stays between your Jeedom server and WhatsApp's servers.

### How it works — the channel group

JeeWhatsApp relies on a **dedicated WhatsApp group** that serves as a bidirectional communication channel between Jeedom and the user.

- **Jeedom → you**: every message sent from a scenario arrives in the group, prefixed (e.g. `🏠 `)
- **You → Jeedom**: your messages in the group are received by Jeedom and trigger your scenarios or the interactions engine
- Messages outside the group (direct messages, other groups) are ignored

> This group can be created automatically by the plugin from the configuration interface.

### Features

- 💬 **Message sending** from a Jeedom scenario to the channel group
- 📥 **Real-time reception** — persistent WebSocket, no polling
- 🔄 **Bidirectional** — control Jeedom via WhatsApp from the group
- 🤖 **Jeedom interactions** — automatic replies via the built-in interactions engine
- 📱 **Your number** — QR code connection, no dedicated number required
- 🔒 **100% self-hosted** — no third-party account, no API key, no subscription
- ⚡ **Real-time** — persistent WebSocket connection, instant reception

### What the plugin does NOT do (currently)

- Sending media (images, videos, documents, voice notes)
- Sending to a number not registered on WhatsApp
- Receiving messages outside the channel group
- **Push notifications for the account owner** (see below)

### ⚠️ Limitation — push notifications

JeeWhatsApp uses **your own WhatsApp account** (linked via QR code).
WhatsApp never notifies the account owner for messages they send themselves —
this rule also applies to mentions (`@you`), which have been tested and do not trigger notifications either.

**Consequence:** when Jeedom posts to the channel group, you receive **no push notification** on your phone.
You can see the messages by opening the group, but there is no sound alert or banner.

**Solution: use a second WhatsApp account dedicated to Jeedom**

| Configuration | Notifications | Complexity |
|---|---|---|
| Single account (your number) | ❌ None for you | Simple |
| Two accounts (Jeedom bot + your number) | ✅ Real notifications | Requires a 2nd number |

With two accounts:
- The "Jeedom bot" account (virtual number or dedicated SIM) is connected in Jeedom
- This account sends messages to the group
- Your personal account is **a member of the group** and receives notifications normally
- The "Send message" command in a scenario notifies your phone like any group message

> A virtual number (Google Voice, cheap eSIM) is enough — it does not need to be active at all times for WhatsApp.

---

## Prerequisites

- Jeedom 4.4 or higher
- Node.js **18 or higher** on the Jeedom machine
- A phone with the WhatsApp application to scan the QR code

---

## Installation

### Step 1 — Install the plugin

Copy the `jeewhatsapp` folder into the `plugins/` directory of your Jeedom installation,
then go to **Plugins → Plugin management** and activate **JeeWhatsApp**.

### Step 2 — Install dependencies

On the plugin page, click **Install dependencies**.
The script installs `@whiskeysockets/baileys`, `qrcode` and `pino` via npm.
This step may take **2 to 5 minutes** depending on your internet connection.

> **Node.js prerequisite**  
> The script checks the Node.js version. If it is below 18, installation fails.  
> To check: `node --version` in a terminal.

### Step 3 — Start the daemon

Click **Start daemon** on the plugin configuration page.
The status should change to **OK**.

---

## Configuration

### Create a device

Go to **Plugins → Communication → JeeWhatsApp** and click **Add**.

| Field | Description |
|---|---|
| Name | Name displayed in Jeedom (e.g. My WhatsApp) |
| Parent object | Jeedom object to attach the device to |
| Enable | Must be checked for the daemon to handle this device |
| **Channel group** | Exact name of the WhatsApp group used as the channel (default: `jeewhatsapp`) |
| **Linked group** | Group JID, automatically filled after search or creation — read-only |
| Jeedom interactions | Enables automatic replies via the interactions engine |
| **"Typing" presence** | (v0.3) Shows `typing…` / `recording…` for ~1 s before each automatic send. Humanizes messages. |
| **Ephemeral messages** | (v0.3) Disabled / 24 h / 7 d / 90 d. All messages sent by Jeedom disappear automatically after the chosen delay. |
| **Jeedom prefix** | Text added at the beginning of each message sent by Jeedom (default: `🏠 `) |

Save. Commands are created automatically.

### Configure the channel group

After saving the device, you need to link a WhatsApp group.
**You must first be connected to WhatsApp (QR code scanned).**

Two options in the **Channel group** field:

**Option A — Existing group**

1. Manually create a WhatsApp group from your phone and name it (e.g. `jeewhatsapp`)
2. Enter this name in the **Channel group** field
3. Click **Search** — the JID is automatically filled in
4. Save

**Option B — Create the group from Jeedom**

1. Enter the desired name in **Channel group**
2. Click **Create** — the group is created on WhatsApp and the JID is filled in
3. Save
4. From your phone, add the desired members to the group

> **The "Linked group" field (JID)**  
> This read-only field contains the technical identifier of the WhatsApp group (format `120363…@g.us`).
> It is filled automatically by the **Search** / **Create** buttons and must not be edited manually.

---

## QR code connection (first connection)

After creating and saving the device, go to the **WhatsApp Connection** tab.

1. A QR code is displayed automatically (refreshed every 8 seconds)
2. Open WhatsApp on your phone
3. Go to **Settings → Linked devices → Link a device**
4. Scan the QR code
5. The status changes to **Connected** ✅

> **Persistent session**  
> Once connected, credentials are saved locally in `resources/jeewhatsappd/auth/{id}/`.
> You will not need to re-scan the QR code every time the daemon restarts.

> **Automatic group search**  
> As soon as the WhatsApp connection is established, the daemon automatically searches for the configured channel group.
> The result is shown in the `jeewhatsapp` logs: `✓ Group "jeewhatsapp" → 120363…@g.us`.

---

## Commands

### INFO commands (read)

| Name | logicalId | Sub-type | Logged | Description |
|---|---|---|---|---|
| Last message | `last_message` | string | no | Text of the last message received in the channel group |
| Sender | `last_sender` | string | no | Phone number of the sender of the last message |
| Sender name | `last_sender_name` | string | no | WhatsApp display name of the sender |
| Received at | `last_received_at` | string | no | Timestamp of the last received message |
| Sent (current hour) | `sent_hour` | numeric | yes | Send counter for the current hour (reset every hour) |
| Received today | `messages_today` | numeric | yes | Message counter since midnight (reset by daily cron at 00:02) |
| Connected since | `connected_since` | string | no | Date/time of the last WhatsApp Web connection (refreshed by 5-min cron) |
| Last reaction | `last_reaction` | string | no | Emoji of the last reaction received in the group (empty = reaction removed) |
| Reaction — sender | `last_reaction_from` | string | no | Phone number of the last reaction author |
| Reaction — date | `last_reaction_at` | string | no | Timestamp of the last reaction |
| Last media — path | `last_attachment_path` | string | no | Absolute server path of the last received media (image/video/audio/document/sticker) |
| Last media — type | `last_attachment_type` | string | no | `image` / `video` / `audio` / `document` / `sticker` |
| Last media — mime | `last_attachment_mime` | string | no | MIME type of the last received media (`image/jpeg`, `audio/ogg; codecs=opus`, ...) |
| Last media — size | `last_attachment_size` | numeric | no | Size in bytes of the last received media |
| Poll — question | `poll_question` | string | no | Question of the last poll from which a vote was received |
| Poll — results | `poll_results` | string | no | Results in JSON format `[{name, votes}]` |
| Poll — total votes | `poll_total` | numeric | yes | Total number of votes received on the last poll |
| Last group — tag | `last_group` | string | no | (v0.3) Tag of the source group of the last received message (empty = main channel group) |
| Last group — name | `last_group_name` | string | no | (v0.3) Name of the WhatsApp source group of the last received message |

### ACTION commands

| Name | logicalId | Sub-type | Description |
|---|---|---|---|
| Send a message | `send_message` | message | Sends a message to the channel group. **Title** field = optional recipient (empty = channel group, otherwise direct number). |
| Reply | `reply` | message | Quoted reply to the last message received in the group (visible citation). |
| Send media | `send_media` | message | Sends a file (image, video, audio, document). **Title** field = absolute path, **Message** = optional caption. |
| Send location | `send_location` | message | Sends a GPS position. **Title** field = `lat\|long` or `lat\|long\|name`. |
| Send contact | `send_contact` | message | Sends a vCard. **Title** field = number, **Message** = display name (optional). |
| React to last message | `react_last` | message | Sends an emoji reaction to the last received message. **Message** field = emoji (❤️ 👍 🎉 …) or empty to remove the reaction. |
| Edit last message | `edit_last` | message | (v0.3) Replaces the text of the last message **sent** by Jeedom. **Message** field = new text. |
| Delete last message | `revoke_last` | other | (v0.3) Deletes "for everyone" the last message **sent** by Jeedom (button, no parameter). |
| Forward last received message | `forward_to` | message | (v0.3) Forwards the last **received** message to a recipient. **Title** field = optional recipient (empty = channel group). |
| Send sticker | `send_sticker` | message | (v0.3) Sends a sticker. **Title** field = absolute path to a `.webp` (or `.png`/`.jpg` converted to WebP 512×512). |
| Send poll | `send_poll` | message | (v0.3) Sends a poll. **Title** field = question, **Message** = options separated by `\|` (e.g. `Yes\|No\|Maybe`, 2 to 12 options). Votes feed the `poll_*` info commands. |
| Send to additional group | `send_group` | message | (v0.3) Sends a message to an additional group. **Title** field = group tag (see "Additional groups" config), **Message** = text. |

> **💡 "Title" field of the Send message command**  
> Jeedom displays two fields for `message`-type commands: **Title** and **Message**.  
> In JeeWhatsApp, the **Title** field is an **optional override**:  
> — Empty → the message is sent to the **channel group**  
> — Number (e.g. `33612345678`) → direct send to that number (outside the group)  
> — Group JID (e.g. `120363…@g.us`) → sent to that specific group

> **💡 Reply command**  
> In channel group mode, `reply` sends to the group (visible to all members).
> The reply is not private — it is a public message in the channel.

> **💡 Jeedom prefix**  
> All messages sent by Jeedom are automatically prefixed (e.g. `🏠 `).
> Group members can thus distinguish Jeedom alerts from their own messages.
> The daemon ignores `fromMe` messages in the group, preventing Jeedom from processing its own sends.

> **📍 Send a location (`send_location`)**  
> **Title** field format: `lat|long` or `lat|long|name` (separator `|`).  
> Examples:
> - `48.8566|2.3522` → Eiffel Tower without label
> - `48.8566|2.3522|Eiffel Tower` → with place name
> - `45.7640|4.8357|Place Bellecour, Lyon` → with address
>
> Validation: lat ∈ [-90, 90], long ∈ [-180, 180]. The **Message** field is ignored.

> **👤 Send a contact (`send_contact`)**  
> **Title** field format: international number without `+` or spaces (e.g. `33612345678`).  
> French format accepted: `0612345678` (automatically converted to `33612345678`).  
> **Message** field = vCard display name (optional, otherwise the number is used).

> **👥 Additional groups (`send_group`) — v0.3**  
> By default, a device only listens and writes to **one** channel group. To manage
> multiple groups (alerts, info, family…) with the **same** WhatsApp account, fill in the
> **Additional groups** field on the device, one group per line in the format
> `tag=Exact WhatsApp group name`:
> ```
> alerts=Home Alerts
> family=Family Group
> ```
> - The **tag** (`alerts`, `family`…) is used to target the group via the
>   **Send to additional group** command (`send_group`): **Title** field = tag, **Message** = text.
> - Messages **received** in these groups also feed the info commands,
>   and the source group is exposed via `last_group` (tag, empty = main group) and `last_group_name`.
> - The **main** channel group remains unchanged; this feature is purely additive
>   (backward-compatible with existing configurations).

---

## Jeedom Interactions

When the **Jeedom interactions** option is enabled, each message received in the channel group
is passed to the Jeedom interactions engine. If an interaction matches, the reply is
automatically sent **to the channel group**.

### Trigger keyword filter (v0.2)

**"Trigger keyword"** field in the device configuration. If set, only messages
**starting** with this keyword (case-insensitive) trigger interactions. The keyword is
**removed** from the message before passing it to the Jeedom interactions engine — allows natural
phrasing on the Jeedom side while avoiding noise in the group.

| Configuration | Message received | Behavior |
|---|---|---|
| empty keyword | `turn on living room` | → interactQuery searches `turn on living room` |
| keyword = `!jeedom` | `hi family` | → ignored (debug log) |
| keyword = `!jeedom` | `!jeedom turn on living room` | → interactQuery searches `turn on living room` |
| keyword = `@jeedom` | `@JEEDOM status` | → interactQuery searches `status` (case ignored) |

### Sender whitelist (v0.2 — security)

**"Sender whitelist"** field: if set, only the listed numbers can trigger
Jeedom interactions. Other group members are **silently ignored** (debug log).

**Accepted format**: 1 number per line or comma-separated, in any format:
- `0612345678` (French short format)
- `33612345678` (international)
- `+33 6 12 34 56 78` (with spaces and +)

All formats are normalized to international format before comparison.

> **🛡️ Security**: the whitelist protects against a malicious member who joins the group and tries
> to send Jeedom commands. Combined with the keyword filter, it provides a double layer of protection.

Configurable interaction examples in Jeedom:

| Message received | Automatic reply |
|---|---|
| `living room temperature` | `The living room temperature is 21°C` |
| `turn on the light` | `Light turned on` |
| `status` | `All devices are OK` |

> Configure your interactions in **Tools → Interactions** in Jeedom.

### Shortcut commands — "slash" (v0.4)

**"Shortcut commands"** field: quick shortcuts triggered by a message
starting with `/`. They are **prioritized** over the interactions engine (NLP) and require
no configuration in *Tools → Interactions* — ideal for frequently used commands.

**Format**: one line per shortcut, `/trigger=target`. The target can be:

| Target type | Example line | Effect of `/trigger` message |
|---|---|---|
| **Action command** `#id#` | `/scene=#9012#` | Executes action command `9012`, replies `✅ Command name` |
| **Info command** `#id#` | `/temp=#1234#` | Replies current value: `Living room temperature: 21 °C` |
| **Template text** | `/hello=Hi #args# !` | `/hello Paul` → replies `Hi Paul !` |
| **Template + Jeedom tags** | `/home=Living #1234# / Ext #5678#` | Replaces info `#id#` tags with their value |

**Variables available in a template text**:
- `#args#`: all arguments after the trigger (`/echo hello world` → `#args#` = `hello world`)
- `#1#`, `#2#`, …: each argument word taken separately

For an action command of sub-type *message*, the argument is passed as the message text;
for a *slider*, as the value; for a *color*, as the color code.

An unknown trigger returns `❓ Unknown shortcut: /xxx`.

> **Complete example**:
> ```
> /living=#1234#
> /turnon=#1057#
> /status=🏠 Living room: #1234# °C — Alarm: #1099#
> /say=Message received: #args#
> ```
> Then in the group: `/living` → `Living room temperature: 21 °C`, `/say Hello` → `Message received: Hello`.

---

### User recognition (v0.4)

**"User recognition"** field: associates a sender's number with a **Jeedom profile**.
One line per mapping, in the format `number=profile`.

```
33612345678=Dad
0698765432=Mom
33700000000=Child
```

Numbers are normalized to international format (`0612345678`, `+33 6 12 34 56 78` and
`33612345678` are equivalent).

When a message arrives from a mapped number:

- the resolved profile is exposed in the info command **"Sender — profile"**
  (`last_sender_profile`) — usable in scenarios to personalize replies;
- it is passed to the Jeedom interactions engine via the `profile` option, making it
  **compatible with the Profiles plugin** (access rules, restrictions, per-person customization).

If no mapping matches, the profile falls back to the sender's WhatsApp name,
then to their raw number. The info command remains empty when the sender is not mapped.

> **Example**: with `33612345678=Dad`, a message "turn off the bedroom" sent from that
> number is processed by Jeedom interactions as coming from the *Dad* profile — you
> can thus authorize certain commands only for that profile via the Profiles plugin.

---

### Voice replies — text-to-speech / TTS (v0.4)

The plugin can **speak**: text is synthesized into a **voice note** (Opus `.ogg`,
displayed as a voice message in WhatsApp) using **Piper**, a **100% local** synthesis engine
(no third-party service, no data sent externally).

**Two use cases:**

1. **"Send voice note" action command** (`send_voice`) — to use in a scenario: the *Message* field
   contains the text to say, the *Title* field an optional recipient (empty = channel group).
   Example: `[My WhatsApp][Send voice note]` →
   Message: `The living room temperature is 21 degrees.`
2. **"Voice-first" mode** — check **"Voice replies (TTS) → Enable voice mode"**
   in the device configuration. All automatic replies (Jeedom interactions and `/` shortcuts)
   are then sent as voice notes instead of text. If synthesis fails, the plugin
   **automatically falls back to text**.

**Voice**: the French voice `fr_FR-siwis-medium` is installed by default. To use a different one,
place a Piper model (`.onnx` + `.onnx.json`) in `resources/piper/voices/` and
enter its filename in the **"Synthesis voice"** field (or an absolute path).

> **Prerequisite**: `ffmpeg` must be installed on the server (present by default on most
> Jeedom installations). The Piper binary and French voice are downloaded automatically
> during plugin dependency installation. If Piper installation fails, the plugin continues
> to work — only voice replies are disabled (text fallback).

---

### OCR on received images (v0.4)

The plugin can **read text from images** received using **Tesseract**, a **100% local** OCR engine
(no third-party service).

**Activation**: check **"Image OCR → Enable"** in the device configuration.
From then on, every image received in the channel group is analyzed and the recognized text
is placed in the info command **"OCR — image text"** (`last_ocr_text`).

**Language**: `fra` (French) by default. The field accepts multiple languages combined with
`+`, for example `fra+eng` for text mixing French and English.

**Use cases**: meter reading (water, gas, electricity), reading a receipt,
a sign, a serial number… You can then react to changes in
`last_ocr_text` in a scenario (number extraction, archiving, alert…).

> **Prerequisite**: the `tesseract-ocr` and `tesseract-ocr-fra` packages are installed
> automatically (apt) during plugin dependency installation. If installation fails,
> OCR is simply disabled — media reception continues normally.

---

### Voice transcription — STT (v0.4)

The plugin can **transcribe voice notes** received using **Vosk**, a
**100% local and offline** speech recognition engine (no third-party service).

**Activation**: check **"Voice transcription (STT) → Enable"** in the device
configuration. From then on, every voice note received in the channel group is transcribed:

- the text is placed in the info command **"STT — voice note"** (`last_voice_text`);
- it is **re-injected as a text message**, triggering **shortcuts** (`/`) and
  **Jeedom interactions** exactly as if you had typed the message.

You can therefore **control Jeedom by voice**: send a voice note "*turn on the living room light*"
and the corresponding Jeedom interaction executes.

> **Complete voice assistant**: enable both **"Voice transcription (STT)"** and
> **"Voice replies (TTS)"**. The loop becomes: incoming voice note → transcription →
> Jeedom command → **synthesized voice note reply**. Everything stays local on your server.

> **Prerequisite**: the Python `vosk` module and the lightweight French model are installed
> automatically during plugin dependency installation (`ffmpeg` required). If
> installation fails, transcription is simply disabled — voice note reception continues normally.

---

### Read receipts (v0.5)

Two complementary mechanisms:

- **Mark as read**: the **"Mark as read"** action command (`mark_read`) places
  blue ticks on the last message received in the channel group. Useful to signal to your
  contacts that Jeedom (or you) have acknowledged the message.
- **"Read at"**: the **`last_read_at`** info command is updated automatically when a
  recipient **reads or listens to** a message *sent* by Jeedom. You can thus know, in
  a scenario, whether your alert has been seen.

---

### Archive / Pin / Mute (v0.5)

Three action commands control the state of the channel group conversation:

- **Archive conversation** (`archive_chat`) — empty *Title* = archive, `0` = unarchive.
- **Pin conversation** (`pin_chat`) — empty *Title* = pin, `0` = unpin.
- **Mute** (`mute_chat`) — *Title* = duration in hours (empty = 8 h), `0` = unmute.

Useful for example to automatically mute the group at night via a scenario,
then unmute it in the morning.

---

### Publish a WhatsApp status (v0.5)

The **"Publish status"** action command (`post_status`) publishes a **24 h ephemeral status**
(like a story):

- *Message*: the status text (or caption if an image is provided);
- *Title*: absolute path of an optional **image** (image status).

The audience consists of the **channel group participants** (they are the ones who will see the
status in their feed). Example: publish a "Home secured ✅" status every morning via a scenario.

---

### Group management (v0.5)

The **"Group management"** section (device configuration) groups together
administrative operations for the channel group. **The linked WhatsApp account must be a group administrator.**

- **Participants**: enter a number then use the buttons to **add**, **remove**,
  **promote to administrator** or **demote** a member.
- **Subject**: change the group name/subject.
- **Invite link**: generates the `https://chat.whatsapp.com/…` link (displayed and clickable),
  or **revoke** the old link to create a new one.
- **Leave**: makes the linked account leave the group (irreversible action, confirmation required).
- **Icon**: the "Icon" button (next to Search/Create) applies the plugin icon as the group photo.

---

### Dashboard widget (v0.6)

On the Jeedom dashboard, the device is displayed as a **WhatsApp-style tile**:

- **Header**: avatar (plugin icon), device name and **live connection status**
  (green dot = connected, orange = connecting/QR pending, red = offline);
- **Chat**: the last received message as a bubble (sender + time);
- **Counters**: messages received today and sent in the current hour;
- **Quick send**: a text field + send button to write directly to the channel group
  from the dashboard;
- **Mute button**: mutes the group (8 h) with one click.

No configuration needed: the widget is active as soon as the device is visible on the dashboard.

---

### Session backup / restore (v0.5)

The WhatsApp connection relies on credentials stored locally (`auth/{id}/`). To avoid
having to **re-scan the QR code** after a server reinstallation or migration, you
can export an **encrypted backup** of the session:

- **Backup**: enter a **passphrase** (minimum 6 characters) then click
  *Backup* → an encrypted `.jwab` file (AES-256) is downloaded. Keep the file **and**
  the passphrase in a safe place (the passphrase is required to restore).
- **Restore**: select the `.jwab` file, enter the **same passphrase**, then
  click *Restore*. The current session is overwritten (the old one is kept as `.bak`),
  then the daemon restarts automatically.

> Encryption is entirely local (native PHP, no third-party service). Without the correct passphrase,
> the backup file is unusable.

---

## Scenarios

### Intruder alert — message in the group

**Trigger:** Motion detector active between 11 PM and 6 AM

**Actions:**
- `[My WhatsApp][Send a message]` → Message: `⚠️ Motion detected in the living room!`

The message arrives in the channel group with the Jeedom prefix.

### Keyword command with group reply

**Trigger:** `[My WhatsApp][Last message]` changes

**Condition:** `[My WhatsApp][Last message]` contains `light`

**Actions:**
- Turn on the living room light
- `[My WhatsApp][Reply]` → Message: `💡 Light turned on!`

### Daily report in the group

**Trigger:** Every day at 8:00 AM

**Actions:**
- `[My WhatsApp][Send a message]` → Message: `☀️ Good morning! Living room temperature: [Living room][Temperature]°C`

### Share a location 📍

**Trigger:** Virtual button "Share my home"

**Actions:**
- `[My WhatsApp][Send location]` → Title: `48.8566|2.3522|Home`

### Send the doctor's contact card

**Trigger:** Keyword "doctor" received in the group

**Actions:**
- `[My WhatsApp][Send contact]` → Title: `33112345678`, Message: `Dr Smith — office`

### Confirm receipt of a command with a ❤️ reaction

**Trigger:** A group member sends the word "thanks"

**Actions:**
- `[My WhatsApp][React to last message]` → Message: `❤️`

### Set a mood based on the received reaction

**Trigger:** `[My WhatsApp][Last reaction]` changes

**Conditions:**
- If `[My WhatsApp][Last reaction]` = `❤️` → activate romantic mood
- If `[My WhatsApp][Last reaction]` = `🎉` → activate party mood
- If `[My WhatsApp][Last reaction]` = `🌙` → night mode

### Process a meter photo sent via WhatsApp

**Trigger:** `[My WhatsApp][Last media — type]` changes

**Conditions:** if `[...][Last media — type]` = `image`

**Actions:**
- Copy `[...][Last media — path]` to `/var/www/html/data/counters/`
- (advanced) Call an OCR script on the image, extract the value, update a virtual device

> **📥 Media reception (v0.2)**
> Images, videos, voice notes, documents and stickers received in the channel group
> are automatically downloaded to `data/jeewhatsapp/incoming/{eqId}/{YYYY-MM-DD}/{uuid}.ext`.
> Files are kept **30 days** then deleted by cron (`cronCleanupIncoming` at 03:15).
> The path is exposed via the 4 `last_attachment_*` info commands — your scenarios can
> copy them elsewhere, analyze them (OCR, vision), or forward them.

---

## Troubleshooting

### I don't receive WhatsApp notifications when Jeedom sends a message

This is a WhatsApp limitation: an account never receives notifications for its own messages,
even with a `@you` mention. The only solution is to use a second WhatsApp account dedicated to Jeedom
(see the "Limitation — push notifications" section in the Overview).

### The daemon doesn't start

- Check that Node.js 18+ is installed: `node --version` in a terminal
- Re-run dependency installation
- Check the `jeewhatsapp` log in **Analysis → Logs**
- Check that port `55148` is not already in use

### The QR code doesn't display

- Check that the daemon is started (OK status in plugin management)
- Restart the daemon then refresh the page
- Check the `jeewhatsapp` log to detect a Baileys error

### The channel group is not found on startup

- Check that the name in **Channel group** exactly matches the WhatsApp group name (case-sensitive)
- The group must exist on WhatsApp and your account must be a member
- Click **Search** on the configuration page to force the search manually
- Check the `jeewhatsapp` log: the daemon displays `Group "xxx" not found` with the searched name

### Messages are not received

- Check that the status in the **WhatsApp Connection** tab shows **Connected**
- Check that the channel group is linked (the **Linked group** field is filled)
- Only text messages from the channel group are processed — media is ignored
- Check the `jeewhatsapp` log to verify that the daemon is receiving messages

### No messages received and the log is flooded with "Bad MAC"

If the `jeewhatsapp` log displays in a loop `Failed to decrypt message with any known session`
or `Session error: Bad MAC`, the **encrypted (Signal) session is corrupted**:
WhatsApp is sending messages but the daemon can no longer decrypt them, so they are
silently dropped. This happens particularly after rapid daemon restarts
or concurrent use of the same session.

**Solution: re-pair the device.**

1. On your phone: **WhatsApp → Linked devices**, remove the "JeeWhatsApp" device.
2. On the server, delete (or rename) the device session folder:
   `plugins/jeewhatsapp/resources/jeewhatsappd/auth/{device_ID}/`
3. Restart the daemon from plugin management.
4. A new QR code appears in the **WhatsApp Connection** tab — re-scan it.

Encryption keys are regenerated and reception resumes.

### Sending fails

- Check that WhatsApp status is **Connected**
- Check that the linked group field is filled (the **Linked group** field is not empty)
- Test from the **Test** tab on the device page (leave Recipient empty to send to the group)
- Check the `jeewhatsapp` log

### WhatsApp disconnected the session (logout)

When WhatsApp revokes access to the linked device:
1. The daemon detects the disconnection and deletes the credentials
2. In the **WhatsApp Connection** tab, a new QR code appears automatically
3. Re-scan the QR code to reconnect
4. On reconnection, the daemon automatically finds the channel group again

---

## Technical architecture

```
WhatsApp Group "jeewhatsapp"
       │
       │  (Baileys WebSocket — direct connection)
       │
  jeewhatsappd.js
       │
       ├─ messages.upsert
       │    ├─ Filter: remoteJid === groupJid ? → otherwise ignored
       │    ├─ Filter: fromMe ? → ignored (Jeedom message)
       │    └─ POST callback.php?apikey=
       │         └─ jeewhatsapp::callback()
       │              └─ cmd.event() → [last_message, last_sender, …]
       │
       └─ /action (HTTP local 127.0.0.1:55148)
            ├─ send        → sock.sendMessage(jid, { text: prefix + message })
            ├─ findGroup   → sock.groupFetchAllParticipating()
            ├─ createGroup → sock.groupCreate(name, [])
            ├─ getQR       → reads auth/{id}/qr.txt
            └─ getStatus   → reads auth/{id}/status.txt
```

### Components

| Component | Technology | Role |
|---|---|---|
| `jeewhatsappd.js` | Node.js (ESM) | Daemon — Baileys connection + local HTTP server |
| `jeewhatsapp.class.php` | PHP | Jeedom logic, daemon lifecycle, commands |
| `callback.php` | PHP | Message reception endpoint from the daemon |
| `jeewhatsapp.ajax.php` | PHP | AJAX actions (test, QR code, findGroup, createGroup) |

### Auth and sessions

Baileys credentials are stored in `resources/jeewhatsappd/auth/{eqLogicId}/`.
One sub-folder per device allows managing multiple WhatsApp accounts simultaneously.

| File | Content |
|---|---|
| `*.json` | Baileys credentials (multi-file auth state) |
| `qr.txt` | Temporary base64 QR code (deleted on connection) |
| `status.txt` | Current status: `connecting`, `connected`, `qr_pending`, `reconnecting`, `logged_out` |
| `group_jid.txt` | Channel group JID (cached for faster restarts) |

---

## About

- **Plugin:** JeeWhatsApp v0.1
- **License:** AGPL v3
- **Backend:** [Baileys](https://github.com/WhiskeySockets/Baileys) — open-source, MIT license
- **WhatsApp** is a registered trademark of Meta Platforms, Inc.
- This plugin is not affiliated with Meta or WhatsApp.
