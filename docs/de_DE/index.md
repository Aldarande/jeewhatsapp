# JeeWhatsApp — Dokumentation

> Plugin-ID: `jeewhatsapp`  
> Autor: Aldarande — Lizenz: AGPL v3

---

## Installation

### Voraussetzungen

| Komponente | Version | Erforderlich? |
|---|---|---|
| Jeedom | ≥ 4.4.0 | ✅ |
| Node.js | ≥ 18 | ✅ (bereits auf neueren Jeedom-Boxen vorhanden) |
| `ffmpeg` | beliebig | ⚠️ optional (Sprachnachrichten/TTS/STT) — wird automatisch installiert |
| `python3` + `pip3` | 3.8+ | ⚠️ optional (STT Vosk) — bereits auf Jeedom vorhanden |
| `tesseract-ocr` | 4+ | ⚠️ optional (OCR Bilder) — wird automatisch installiert |

> Hardware-Kompatibilität: x86_64 (VM/PC/NUC/Docker) und **ARM** (Raspberry Pi, Jeedom
> Smart/Atlas/Luna). Das Piper-Binary wird über `uname -m` für die Zielarchitektur abgerufen.

### Schritte

1. **Plugin installieren** über den Jeedom-Market (oder manuellen Zip-Upload), dann **aktivieren**.
2. **Abhängigkeiten installieren**: Schaltfläche „Abhängigkeiten installieren" auf der Plugin-Seite —
   installiert in einem Schritt Baileys + ffmpeg + Piper (TTS) + Tesseract (OCR) + Vosk (STT).
   - Je nach Verbindung und Gerät **5 bis 15 Minuten** einplanen (Downloads ~150 MB).
   - Die KI-Komponenten sind **nicht blockierend**: Schlägt eine fehl, funktioniert das Plugin ohne diese Funktion.
3. **Ein JeeWhatsApp-Gerät erstellen** (beliebiger Name), dann **speichern**.
4. **Daemon starten**: Jeedom startet ihn nach der Geräteerstellung automatisch.
   Bei Problemen manuell über die Schaltfläche „Daemon starten" neu starten.
5. **QR-Code scannen**: Gerät öffnen → Reiter **Konfiguration** → ein QR-Code
   wird angezeigt. Auf dem Telefon: WhatsApp → **Einstellungen → Gekoppelte Geräte → Gerät koppeln**,
   dann scannen. Der Status wechselt innerhalb von 5 Sekunden auf **„verbunden"**.
6. **Kanalgruppe erstellen oder suchen**: Schaltfläche „Erstellen" im Abschnitt *Verknüpfte Gruppe*
   (erstellt eine leere Gruppe namens `jeewhatsapp` standardmäßig), oder „Suchen" falls bereits
   manuell erstellt. Dann die gewünschten Kontakte zu dieser WhatsApp-Gruppe hinzufügen.
7. **Testen**: Reiter **Test** → Schaltfläche „In Kanalgruppe senden". Sie sollten
   `🏠 Test JeeWhatsApp 🚀` in der Gruppe empfangen.

### Aktualisierung

Das Plugin verwaltet Updates automatisch über `jeewhatsapp_update()` (fehlende Crons werden
bei neuen Versionen erstellt). Nach einem Hauptupdate **„Abhängigkeiten installieren"** erneut
ausführen, wenn neue Komponenten im Changelog angekündigt werden.

### Deinstallation

Die Deinstallation **behält** den Ordner `resources/jeewhatsappd/auth/` (WhatsApp-Sitzungen),
um ein erneutes QR-Scannen nach einer Neuinstallation zu vermeiden. Zum vollständigen Löschen:
Diesen Ordner nach der Deinstallation manuell entfernen.

---

## Übersicht

JeeWhatsApp integriert **WhatsApp** in Jeedom über [Baileys](https://github.com/WhiskeySockets/Baileys),
eine Open-Source-Bibliothek, die sich direkt mit WhatsApp Web verbindet.
**Keine Daten werden über einen Drittserver übertragen** — alles bleibt zwischen Ihrem Jeedom-Server und den WhatsApp-Servern.

### Funktionsprinzip — die Kanalgruppe

JeeWhatsApp basiert auf einer **dedizierten WhatsApp-Gruppe**, die als bidirektionaler Kommunikationskanal zwischen Jeedom und dem Benutzer dient.

- **Jeedom → Sie**: Jede Nachricht aus einem Szenario erscheint in der Gruppe mit einem Präfix (z.B. `🏠 `)
- **Sie → Jeedom**: Ihre Nachrichten in der Gruppe werden von Jeedom empfangen und lösen Ihre Szenarien oder die Interaktions-Engine aus
- Nachrichten außerhalb der Gruppe (Direktnachrichten, andere Gruppen) werden ignoriert

> Diese Gruppe kann vom Plugin automatisch über die Konfigurationsoberfläche erstellt werden.

### Funktionen

- 💬 **Nachrichten senden** aus einem Jeedom-Szenario in die Kanalgruppe
- 📥 **Empfang in Echtzeit** — persistentes WebSocket, kein Polling
- 🔄 **Bidirektional** — Jeedom über WhatsApp aus der Gruppe steuern
- 🤖 **Jeedom-Interaktionen** — automatische Antworten über die integrierte Interaktions-Engine
- 📱 **Ihre Nummer** — Verbindung per QR-Code, keine dedizierte Nummer erforderlich
- 🔒 **100 % self-hosted** — kein Drittanbieter-Konto, kein API-Schlüssel, kein Abonnement
- ⚡ **Echtzeit** — persistente WebSocket-Verbindung, sofortiger Empfang

### Was das Plugin (derzeit) nicht kann

- Medien senden (Bilder, Videos, Dokumente, Sprachnachrichten)
- An eine bei WhatsApp nicht registrierte Nummer senden
- Nachrichten außerhalb der Kanalgruppe empfangen
- **Push-Benachrichtigungen für den Kontoinhaber** (siehe unten)

### ⚠️ Einschränkung — Push-Benachrichtigungen

JeeWhatsApp verwendet **Ihr eigenes WhatsApp-Konto** (per QR-Code verknüpft).
WhatsApp benachrichtigt den Kontoinhaber niemals über Nachrichten, die er selbst gesendet hat —
diese Regel gilt auch für Erwähnungen (`@Sie`), die getestet wurden und ebenfalls keine Benachrichtigung auslösen.

**Folge:** Wenn Jeedom in der Kanalgruppe postet, erhalten Sie **keine Push-Benachrichtigung** auf Ihrem Telefon.
Sie können die Nachrichten durch Öffnen der Gruppe sehen, aber kein Tonsignal oder Banner.

**Lösung: Ein zweites WhatsApp-Konto dediziert für Jeedom verwenden**

| Konfiguration | Benachrichtigungen | Komplexität |
|---|---|---|
| Einzelnes Konto (Ihre Nummer) | ❌ Keine für Sie | Einfach |
| Zwei Konten (Jeedom-Bot + Ihre Nummer) | ✅ Echte Benachrichtigungen | Erfordert eine 2. Nummer |

Mit zwei Konten:
- Das „Jeedom-Bot"-Konto (virtuelle Nummer oder dedizierte SIM) ist in Jeedom verbunden
- Dieses Konto sendet die Nachrichten in die Gruppe
- Ihr persönliches Konto ist **Mitglied der Gruppe** und empfängt Benachrichtigungen normal
- Der Befehl „Nachricht senden" in einem Szenario benachrichtigt Ihr Telefon wie jede andere Gruppennachricht

> Eine virtuelle Nummer (Google Voice, günstige eSIM) reicht — sie muss nicht dauerhaft aktiv sein, damit WhatsApp funktioniert.

---

## Voraussetzungen

- Jeedom 4.4 oder höher
- Node.js **18 oder höher** auf dem Jeedom-Server
- Ein Telefon mit der WhatsApp-App zum Scannen des QR-Codes

---

## Installation

### Schritt 1 — Plugin installieren

Kopieren Sie den Ordner `jeewhatsapp` in das `plugins/`-Verzeichnis Ihrer Jeedom-Installation,
dann gehen Sie zu **Plugins → Plugin-Verwaltung** und aktivieren Sie **JeeWhatsApp**.

### Schritt 2 — Abhängigkeiten installieren

Klicken Sie auf der Plugin-Seite auf **Abhängigkeiten installieren**.
Das Skript installiert `@whiskeysockets/baileys`, `qrcode` und `pino` über npm.
Dieser Schritt kann je nach Internetverbindung **2 bis 5 Minuten** dauern.

> **Node.js-Voraussetzung**  
> Das Skript prüft die Node.js-Version. Liegt sie unter 18, schlägt die Installation fehl.  
> Zur Überprüfung: `node --version` in einem Terminal.

### Schritt 3 — Daemon starten

Klicken Sie auf der Plugin-Konfigurationsseite auf **Daemon starten**.
Der Status muss auf **OK** wechseln.

---

## Konfiguration

### Gerät erstellen

Gehen Sie zu **Plugins → Kommunikation → JeeWhatsApp** und klicken Sie auf **Hinzufügen**.

| Feld | Beschreibung |
|---|---|
| Name | In Jeedom angezeigter Name (z.B.: Mein WhatsApp) |
| Übergeordnetes Objekt | Jeedom-Objekt, dem das Gerät zugeordnet werden soll |
| Aktivieren | Muss aktiviert sein, damit der Daemon dieses Gerät berücksichtigt |
| **Kanalgruppe** | Genauer Name der als Kanal verwendeten WhatsApp-Gruppe (Standard: `jeewhatsapp`) |
| **Verknüpfte Gruppe** | JID der Gruppe, nach Suche oder Erstellung automatisch ausgefüllt — nur lesbar |
| Jeedom-Interaktionen | Aktiviert automatische Antworten über die Interaktions-Engine |
| **„Schreibt…"-Anwesenheit** | (v0.3) Zeigt `schreibt…` / `nimmt auf…` für ~1 Sek. vor jedem automatischen Versand. Macht Nachrichten menschlicher. |
| **Ephemere Nachrichten** | (v0.3) Deaktiviert / 24 Std. / 7 Tage / 90 Tage. Alle von Jeedom gesendeten Nachrichten verschwinden nach der gewählten Frist automatisch. |
| **Jeedom-Präfix** | Text, der am Anfang jeder von Jeedom gesendeten Nachricht hinzugefügt wird (Standard: `🏠 `) |

Speichern. Die Befehle werden automatisch erstellt.

### Kanalgruppe konfigurieren

Nach dem Speichern des Geräts müssen Sie eine WhatsApp-Gruppe verknüpfen.
**Sie müssen zunächst mit WhatsApp verbunden sein (QR-Code gescannt).**

Zwei Optionen im Feld **Kanalgruppe**:

**Option A — Bestehende Gruppe**

1. Erstellen Sie manuell eine WhatsApp-Gruppe auf Ihrem Telefon und benennen Sie sie (z.B.: `jeewhatsapp`)
2. Geben Sie diesen Namen in das Feld **Kanalgruppe** ein
3. Klicken Sie auf **Suchen** — der JID wird automatisch eingetragen
4. Speichern

**Option B — Gruppe aus Jeedom erstellen**

1. Geben Sie den gewünschten Namen in **Kanalgruppe** ein
2. Klicken Sie auf **Erstellen** — die Gruppe wird auf WhatsApp erstellt und der JID eingetragen
3. Speichern
4. Fügen Sie auf Ihrem Telefon die gewünschten Mitglieder zur Gruppe hinzu

> **Das Feld „Verknüpfte Gruppe" (JID)**  
> Dieses nur lesbare Feld enthält den technischen Bezeichner der WhatsApp-Gruppe (Format `120363…@g.us`).
> Es wird automatisch durch die Schaltflächen **Suchen** / **Erstellen** eingetragen und darf nicht manuell geändert werden.

---

## Verbindung per QR-Code (erste Verbindung)

Nach dem Erstellen und Speichern des Geräts navigieren Sie zum Reiter **WhatsApp-Verbindung**.

1. Ein QR-Code wird automatisch angezeigt (Aktualisierung alle 8 Sekunden)
2. Öffnen Sie WhatsApp auf Ihrem Telefon
3. Gehen Sie zu **Einstellungen → Gekoppelte Geräte → Gerät koppeln**
4. Scannen Sie den QR-Code
5. Der Status wechselt auf **Verbunden** ✅

> **Persistente Sitzung**  
> Nach der Verbindung werden die Anmeldedaten lokal in `resources/jeewhatsappd/auth/{id}/` gespeichert.
> Sie müssen den QR-Code bei jedem Daemon-Neustart nicht erneut scannen.

> **Automatische Gruppensuche**  
> Sobald die WhatsApp-Verbindung hergestellt ist, sucht der Daemon automatisch nach der konfigurierten Kanalgruppe.
> Das Ergebnis erscheint in den `jeewhatsapp`-Logs: `✓ Gruppe "jeewhatsapp" → 120363…@g.us`.

---

## Befehle

### INFO-Befehle (Lesezugriff)

| Name | logicalId | Untertyp | Historisiert | Beschreibung |
|---|---|---|---|---|
| Letzte Nachricht | `last_message` | string | nein | Text der letzten in der Kanalgruppe empfangenen Nachricht |
| Absender | `last_sender` | string | nein | Nummer des Absenders der letzten Nachricht |
| Absendername | `last_sender_name` | string | nein | WhatsApp-Alias des Absenders |
| Empfangen am | `last_received_at` | string | nein | Zeitstempel der letzten empfangenen Nachricht |
| Gesendet (aktuelle Stunde) | `sent_hour` | numeric | ja | Sendezähler in der aktuellen Stunde (stündliches Reset) |
| Heute empfangen | `messages_today` | numeric | ja | Zähler empfangener Nachrichten seit Mitternacht (Cron-Reset täglich 00:02) |
| Verbunden seit | `connected_since` | string | nein | Datum/Uhrzeit der letzten WhatsApp-Web-Verbindung (Cron-Refresh alle 5 Min.) |
| Letzte Reaktion | `last_reaction` | string | nein | Emoji der letzten in der Gruppe empfangenen Reaktion (leer = Reaktion entfernt) |
| Reaktion — Absender | `last_reaction_from` | string | nein | Nummer des Autors der letzten Reaktion |
| Reaktion — Datum | `last_reaction_at` | string | nein | Zeitstempel der letzten Reaktion |
| Letztes Medium — Pfad | `last_attachment_path` | string | nein | Absoluter Serverpfad des letzten empfangenen Mediums (Bild/Video/Audio/Dokument/Sticker) |
| Letztes Medium — Typ | `last_attachment_type` | string | nein | `image` / `video` / `audio` / `document` / `sticker` |
| Letztes Medium — MIME | `last_attachment_mime` | string | nein | MIME-Typ des letzten empfangenen Mediums (`image/jpeg`, `audio/ogg; codecs=opus`, ...) |
| Letztes Medium — Größe | `last_attachment_size` | numeric | nein | Größe in Bytes des letzten empfangenen Mediums |
| Umfrage — Frage | `poll_question` | string | nein | Frage der letzten Umfrage, für die eine Stimme empfangen wurde |
| Umfrage — Ergebnisse | `poll_results` | string | nein | Ergebnisse im JSON-Format `[{name, votes}]` |
| Umfrage — Gesamtstimmen | `poll_total` | numeric | ja | Gesamtzahl der bei der letzten Umfrage empfangenen Stimmen |
| Letzte Gruppe — Tag | `last_group` | string | nein | (v0.3) Tag der Ursprungsgruppe der letzten empfangenen Nachricht (leer = Hauptkanalgruppe) |
| Letzte Gruppe — Name | `last_group_name` | string | nein | (v0.3) Name der WhatsApp-Ursprungsgruppe der letzten empfangenen Nachricht |

### AKTIONS-Befehle

| Name | logicalId | Untertyp | Beschreibung |
|---|---|---|---|
| Nachricht senden | `send_message` | message | Sendet eine Nachricht in die Kanalgruppe. Feld **Titel** = optionaler Empfänger (leer = Kanalgruppe, sonst direkte Nummer). |
| Antworten | `reply` | message | „Zitierte" Antwort auf die letzte in der Gruppe empfangene Nachricht (sichtbares Zitat). |
| Medium senden | `send_media` | message | Sendet eine Datei (Bild, Video, Audio, Dokument). Feld **Titel** = absoluter Pfad, **Nachricht** = optionale Beschriftung. |
| Standort senden | `send_location` | message | Sendet eine GPS-Position. Feld **Titel** = `lat\|long` oder `lat\|long\|name`. |
| Kontakt senden | `send_contact` | message | Sendet eine vCard. Feld **Titel** = Nummer, **Nachricht** = angezeigter Name (optional). |
| Auf letzte Nachricht reagieren | `react_last` | message | Sendet eine Emoji-Reaktion auf die letzte empfangene Nachricht. Feld **Nachricht** = Emoji (❤️ 👍 🎉 …) oder leer zum Entfernen der Reaktion. |
| Letzte Nachricht bearbeiten | `edit_last` | message | (v0.3) Ersetzt den Text der letzten von Jeedom **gesendeten** Nachricht. Feld **Nachricht** = neuer Text. |
| Letzte Nachricht löschen | `revoke_last` | other | (v0.3) Löscht die letzte von Jeedom **gesendete** Nachricht „für alle" (Schaltfläche, kein Parameter). |
| Letzte empfangene Nachricht weiterleiten | `forward_to` | message | (v0.3) Leitet die letzte **empfangene** Nachricht an einen Empfänger weiter. Feld **Titel** = optionaler Empfänger (leer = Kanalgruppe). |
| Sticker senden | `send_sticker` | message | (v0.3) Sendet einen Sticker. Feld **Titel** = absoluter Pfad einer `.webp`-Datei (oder `.png`/`.jpg`, konvertiert in WebP 512×512). |
| Umfrage senden | `send_poll` | message | (v0.3) Sendet eine Umfrage. Feld **Titel** = Frage, **Nachricht** = durch `\|` getrennte Optionen (z.B.: `Ja\|Nein\|Vielleicht`, 2 bis 12 Optionen). Stimmen füllen die Info-Befehle `poll_*`. |
| In zusätzliche Gruppe senden | `send_group` | message | (v0.3) Sendet eine Nachricht in eine zusätzliche Gruppe. Feld **Titel** = Gruppen-Tag (siehe Konfiguration „Zusätzliche Gruppen"), **Nachricht** = Text. |

> **💡 Feld „Titel" des Befehls Nachricht senden**  
> Jeedom zeigt zwei Felder für Befehle vom Typ `message`: **Titel** und **Nachricht**.  
> In JeeWhatsApp ist das Feld **Titel** ein **optionaler Override**:  
> — Leer → die Nachricht wird in die **Kanalgruppe** gesendet  
> — Nummer (z.B.: `33612345678`) → direkter Versand an diese Nummer (außerhalb der Gruppe)  
> — Gruppen-JID (z.B.: `120363…@g.us`) → Versand in diese spezifische Gruppe

> **💡 Befehl Antworten**  
> Im Kanalgruppenmodus sendet `reply` in die Gruppe (für alle Mitglieder sichtbar).
> Die Antwort ist nicht privat — es ist eine öffentliche Nachricht im Kanal.

> **💡 Jeedom-Präfix**  
> Alle von Jeedom gesendeten Nachrichten werden automatisch mit einem Präfix versehen (z.B.: `🏠 `).
> Gruppenmitglieder können so Jeedom-Benachrichtigungen von ihren eigenen Nachrichten unterscheiden.
> Der Daemon ignoriert `fromMe`-Nachrichten in der Gruppe, um zu verhindern, dass Jeedom seine eigenen Sendungen verarbeitet.

> **📍 Standort senden (`send_location`)**  
> Format des Feldes **Titel**: `lat|long` oder `lat|long|name` (Trennzeichen `|`).  
> Beispiele:
> - `48.8566|2.3522` → Eiffelturm ohne Label
> - `48.8566|2.3522|Tour Eiffel` → mit Ortsname
> - `45.7640|4.8357|Place Bellecour, Lyon` → mit Adresse
>
> Validierung: lat ∈ [-90, 90], long ∈ [-180, 180]. Das Feld **Nachricht** wird ignoriert.

> **👤 Kontakt senden (`send_contact`)**  
> Format des Feldes **Titel**: internationale Nummer ohne `+` oder Leerzeichen (z.B.: `33612345678`).  
> Deutsches Format akzeptiert: `0612345678` (wird automatisch in `33612345678` konvertiert).  
> Feld **Nachricht** = angezeigter Name der vCard (optional, sonst wird die Nummer verwendet).

> **👥 Zusätzliche Gruppen (`send_group`) — v0.3**  
> Standardmäßig hört und schreibt ein Gerät nur in **einer** Kanalgruppe. Um
> mehrere Gruppen (Benachrichtigungen, Info, Familie…) mit dem **gleichen** WhatsApp-Konto zu verwalten,
> tragen Sie das Feld **Zusätzliche Gruppen** des Geräts ein, eine Zeile pro Gruppe im Format
> `tag=Exakter Name der WhatsApp-Gruppe`:
> ```
> benachrichtigungen=Haus-Benachrichtigungen
> familie=Familiengruppe
> ```
> - Der **Tag** (`benachrichtigungen`, `familie`…) dient zur Adressierung der Gruppe über den Befehl
>   **In zusätzliche Gruppe senden** (`send_group`): Feld **Titel** = Tag, **Nachricht** = Text.
> - **Empfangene** Nachrichten aus diesen Gruppen füllen ebenfalls die Info-Befehle,
>   und die Ursprungsgruppe wird über `last_group` (Tag, leer = Hauptgruppe) und `last_group_name` exponiert.
> - Die **Haupt**-Kanalgruppe bleibt unverändert; diese Funktion ist rein additiv
>   (rückwärtskompatibel mit bestehenden Konfigurationen).

---

## Jeedom-Interaktionen

Wenn die Option **Jeedom-Interaktionen** aktiviert ist, wird jede in der Kanalgruppe empfangene Nachricht
an die Jeedom-Interaktions-Engine übermittelt. Stimmt eine Interaktion überein, wird die Antwort
automatisch **in die Kanalgruppe** gesendet.

### Auslöser-Stichwortfilter (v0.2)

Feld **„Auslöser-Stichwort"** in der Gerätekonfiguration. Wenn ausgefüllt, lösen nur Nachrichten,
die mit diesem Stichwort **beginnen** (Groß-/Kleinschreibung ignoriert), Interaktionen aus. Das Stichwort wird
**vor der Übermittlung** an die Jeedom-Interaktions-Engine **entfernt** — ermöglicht natürliche Formulierungen
auf der Jeedom-Seite und vermeidet gleichzeitig Lärm in der Gruppe.

| Konfiguration | Empfangene Nachricht | Verhalten |
|---|---|---|
| Stichwort leer | `licht einschalten` | → interactQuery sucht `licht einschalten` |
| Stichwort = `!jeedom` | `hallo familie` | → ignoriert (Debug-Log) |
| Stichwort = `!jeedom` | `!jeedom licht einschalten` | → interactQuery sucht `licht einschalten` |
| Stichwort = `@jeedom` | `@JEEDOM status` | → interactQuery sucht `status` (Groß-/Kleinschreibung ignoriert) |

### Absender-Whitelist (v0.2 — Sicherheit)

Feld **„Absender-Whitelist"**: Wenn ausgefüllt, können nur aufgelistete Nummern Jeedom-Interaktionen auslösen.
Andere Gruppenmitglieder werden **stillschweigend ignoriert** (Debug-Log).

**Akzeptiertes Format**: 1 Nummer pro Zeile oder durch Komma getrennt, in beliebigem Format:
- `0612345678` (deutsches Kurzformat)
- `33612345678` (international)
- `+33 6 12 34 56 78` (mit Leerzeichen und +)

Alle Formate werden vor dem Vergleich in das internationale Format normalisiert.

> **🛡️ Sicherheit**: Die Whitelist schützt vor einem bösartigen Mitglied, das der Gruppe beitritt und versucht,
> Jeedom-Befehle zu senden. Kombiniert mit dem Stichwortfilter bietet sie doppelten Schutz.

Beispiele konfigurierbarer Interaktionen in Jeedom:

| Empfangene Nachricht | Automatische Antwort |
|---|---|
| `temperatur wohnzimmer` | `Die Temperatur im Wohnzimmer beträgt 21°C` |
| `licht einschalten` | `Licht eingeschaltet` |
| `status` | `Alle Geräte sind OK` |

> Konfigurieren Sie Ihre Interaktionen unter **Tools → Interaktionen** in Jeedom.

### Shortcut-Befehle — „Slash" (v0.4)

Feld **„Shortcut-Befehle"**: Schnell-Verknüpfungen, die durch eine Nachricht ausgelöst werden,
die mit `/` beginnt. Sie haben **Priorität** gegenüber der Interaktions-Engine (NLP) und erfordern
keine Konfiguration unter *Tools → Interaktionen* — ideal für häufige Befehle.

**Format**: eine Zeile pro Verknüpfung, `/auslöser=ziel`. Das Ziel kann sein:

| Zieltyp | Beispielzeile | Effekt der Nachricht `/auslöser` |
|---|---|---|
| **Aktionsbefehl** `#id#` | `/szene=#9012#` | Führt Aktionsbefehl `9012` aus, antwortet `✅ Befehlsname` |
| **Info-Befehl** `#id#` | `/temp=#1234#` | Antwortet mit dem aktuellen Wert: `Temperatur Wohnzimmer: 21 °C` |
| **Vorlagentext** | `/hallo=Hallo #args# !` | `/hallo Paul` → antwortet `Hallo Paul !` |
| **Vorlage + Jeedom-Tags** | `/haus=Wohnzimmer #1234# / Außen #5678#` | Ersetzt `#id#`-Infos durch ihre Werte |

**Verfügbare Variablen in einem Vorlagentext**:
- `#args#`: alle Argumente nach dem Auslöser (`/echo hallo welt` → `#args#` = `hallo welt`)
- `#1#`, `#2#`, …: jedes Argument-Wort einzeln

Für einen Aktionsbefehl vom Untertyp *message* wird das Argument als Nachrichtentext übergeben;
für einen *slider* als Wert; für eine *couleur* als Farbcode.

Ein unbekannter Auslöser gibt `❓ Unbekannte Verknüpfung: /xxx` zurück.

> **Vollständiges Beispiel**:
> ```
> /wohnzimmer=#1234#
> /einschalten=#1057#
> /status=🏠 Wohnzimmer: #1234# °C — Alarm: #1099#
> /sag=Nachricht empfangen: #args#
> ```
> Dann in der Gruppe: `/wohnzimmer` → `Temperatur Wohnzimmer: 21 °C`, `/sag Hallo` → `Nachricht empfangen: Hallo`.

---

### Benutzererkennung (v0.4)

Feld **„Benutzererkennung"**: Verknüpft die Nummer eines Absenders mit einem **Jeedom-Profil**.
Eine Zeile pro Zuordnung, im Format `nummer=profil`.

```
33612345678=Papa
0698765432=Mama
33700000000=Kind
```

Die Nummern werden in das internationale Format normalisiert (`0612345678`, `+33 6 12 34 56 78` und
`33612345678` sind äquivalent).

Wenn eine Nachricht von einer zugeordneten Nummer eingeht:

- Das aufgelöste Profil wird im Info-Befehl **„Absender — Profil"**
  (`last_sender_profile`) exponiert — verwendbar in Szenarien zur Personalisierung von Antworten;
- Es wird mit der Option `profile` an die Jeedom-Interaktions-Engine übermittelt, was es
  **kompatibel mit dem Profiles-Plugin** macht (Zugriffsregeln, Einschränkungen, personenbezogene Anpassung).

Stimmt keine Zuordnung überein, fällt das Profil auf den WhatsApp-Namen des Absenders zurück,
dann auf dessen rohe Nummer. Der Info-Befehl bleibt leer, wenn der Absender nicht zugeordnet ist.

> **Beispiel**: Mit `33612345678=Papa` wird eine Nachricht „schlafzimmerlicht aus" von dieser
> Nummer von den Jeedom-Interaktionen als vom Profil *Papa* stammend behandelt — Sie können
> so bestimmte Befehle nur diesem Profil über das Profiles-Plugin erlauben.

---

### Sprachantworten — Sprachsynthese / TTS (v0.4)

Das Plugin kann **sprechen**: Ein Text wird in eine **Sprachnachricht** (Opus `.ogg`,
in WhatsApp als Sprachnachricht angezeigt) synthetisiert, dank **Piper**, einer
**100 % lokalen** Synthese-Engine (kein Drittanbieter-Dienst, keine extern gesendeten Daten).

**Zwei Verwendungszwecke:**

1. **Aktionsbefehl „Sprachnachricht senden"** (`send_voice`) — in einem Szenario verwenden:
   Das Feld *Nachricht* enthält den zu sprechenden Text, das Feld *Titel* einen optionalen Empfänger
   (leer = Kanalgruppe). Beispiel: `[Mein WhatsApp][Sprachnachricht senden]` →
   Nachricht: `Die Temperatur im Wohnzimmer beträgt 21 Grad.`
2. **„Voice-first"-Modus** — aktivieren Sie **„Sprachantworten (TTS) → Sprachmodus aktivieren"**
   in der Gerätekonfiguration. Alle automatischen Antworten (Jeedom-Interaktionen und `/`-Verknüpfungen)
   werden dann als Sprachnachricht statt als Text gesendet. Bei Synthesefehlern **fällt das Plugin
   automatisch auf Text zurück**.

**Stimme**: Die deutsche Stimme `de_DE-...` oder standardmäßig `fr_FR-siwis-medium` wird installiert. Um
eine andere zu verwenden, legen Sie ein Piper-Modell (`.onnx` + `.onnx.json`) in `resources/piper/voices/` ab und
geben Sie seinen Dateinamen im Feld **„Synthesestimme"** an (oder einen absoluten Pfad).

> **Voraussetzung**: `ffmpeg` muss auf dem Server installiert sein (standardmäßig auf
> den meisten Jeedom-Installationen vorhanden). Das Piper-Binary und die Stimme werden
> automatisch bei der Installation der Plugin-Abhängigkeiten heruntergeladen. Schlägt die Piper-Installation
> fehl, funktioniert das Plugin weiterhin — nur Sprachantworten sind deaktiviert (Rückfall auf Text).

---

### OCR auf empfangenen Bildern (v0.4)

Das Plugin kann **Text aus Bildern lesen**, die empfangen werden, dank **Tesseract**, einer
**100 % lokalen** OCR-Engine (kein Drittanbieter-Dienst).

**Aktivierung**: Aktivieren Sie **„Bild-OCR → Aktivieren"** in der Gerätekonfiguration.
Von da an wird jedes in der Kanalgruppe empfangene Bild analysiert und der erkannte Text
im Info-Befehl **„OCR — Bildtext"** (`last_ocr_text`) gespeichert.

**Sprache**: `fra` (Französisch) standardmäßig. Das Feld akzeptiert mehrere Sprachen kombiniert mit
`+`, zum Beispiel `fra+eng` für Text der Französisch und Englisch mischt. Für Deutsch verwenden Sie `deu` oder `deu+eng`.

**Anwendungsfälle**: Zählerstand (Wasser, Gas, Strom), Kassenbeleglesung,
Schild, Seriennummer… Sie können dann auf die Änderung von
`last_ocr_text` in einem Szenario reagieren (Zahlen extrahieren, archivieren, Alarm…).

> **Voraussetzung**: Die Pakete `tesseract-ocr` und `tesseract-ocr-fra` werden
> automatisch (apt) bei der Installation der Plugin-Abhängigkeiten installiert. Schlägt die Installation
> fehl, wird OCR einfach deaktiviert — der Medienempfang läuft normal weiter.

---

### Sprachtranskription — STT (v0.4)

Das Plugin kann **Sprachnachrichten transkribieren**, dank **Vosk**, einer
**100 % lokalen und Offline**-Spracherkennungs-Engine (kein Drittanbieter-Dienst).

**Aktivierung**: Aktivieren Sie **„Sprachtranskription (STT) → Aktivieren"** in der
Gerätekonfiguration. Von da an wird jede in der Kanalgruppe empfangene Sprachnachricht transkribiert:

- Der Text wird im Info-Befehl **„STT — Sprachnachricht"** (`last_voice_text`) gespeichert;
- Er wird **als Textnachricht wieder eingespielt**, was **Verknüpfungen** (`/`) und
  **Jeedom-Interaktionen** genauso auslöst, als hätten Sie die Nachricht getippt.

Sie können also **Jeedom per Stimme steuern**: Senden Sie eine Sprachnachricht „*Wohnzimmerlicht einschalten*"
und die entsprechende Jeedom-Interaktion wird ausgeführt.

> **Vollständiger Sprachassistent**: Aktivieren Sie sowohl **„Sprachtranskription (STT)"** als auch
> **„Sprachantworten (TTS)"**. Die Schleife wird: eingehende Sprachnachricht → Transkription →
> Jeedom-Befehl → **in Sprachnachricht synthetisierte Antwort**. Alles bleibt lokal auf Ihrem Server.

> **Voraussetzung**: Das Python-Modul `vosk` und das leichte Sprachmodell werden
> automatisch bei der Installation der Plugin-Abhängigkeiten installiert (`ffmpeg` erforderlich). Schlägt
> die Installation fehl, wird die Transkription einfach deaktiviert — der Empfang von Sprachnachrichten
> läuft normal weiter.

---

### Lesebestätigungen (v0.5)

Zwei ergänzende Mechanismen:

- **Als gelesen markieren**: Der Aktionsbefehl **„Als gelesen markieren"** (`mark_read`) setzt
  die blauen Häkchen auf die letzte in der Kanalgruppe empfangene Nachricht. Praktisch, um Ihren
  Gesprächspartnern zu signalisieren, dass Jeedom (oder Sie) die Nachricht gelesen haben.
- **„Gelesen am"**: Der Info-Befehl **`last_read_at`** wird automatisch aktualisiert, wenn ein
  Empfänger eine von Jeedom **gesendete** Nachricht **liest oder anhört**. So können Sie in einem
  Szenario prüfen, ob Ihre Benachrichtigung tatsächlich konsultiert wurde.

---

### Archivieren / Anheften / Stummschalten (v0.5)

Drei Aktionsbefehle steuern den Status der Kanalgruppen-Konversation:

- **Konversation archivieren** (`archive_chat`) — *Titel* leer = archivieren, `0` = dearchivieren.
- **Konversation anheften** (`pin_chat`) — *Titel* leer = anheften, `0` = lösen.
- **Stummschalten** (`mute_chat`) — *Titel* = Dauer in Stunden (leer = 8 Std.), `0` = reaktivieren.

Nützlich z.B. um die Gruppe nachts über ein Szenario automatisch stummzuschalten,
dann morgens wieder zu aktivieren.

---

### WhatsApp-Status veröffentlichen (v0.5)

Der Aktionsbefehl **„Status veröffentlichen"** (`post_status`) veröffentlicht einen **24-Std.-Ephemerstatus**
(wie eine Story):

- *Nachricht*: der Statustext (oder Beschriftung, wenn ein Bild angegeben wird);
- *Titel*: absoluter Pfad eines optionalen **Bildes** (Bildstatus).

Das Publikum sind die **Teilnehmer der Kanalgruppe** (sie sehen den Status in ihrem Feed). Beispiel:
jeden Morgen einen Status „Haus gesichert ✅" über ein Szenario veröffentlichen.

---

### Gruppenverwaltung (v0.5)

Der Abschnitt **„Gruppenverwaltung"** (Gerätekonfiguration) fasst die Verwaltungsoperationen der
Kanalgruppe zusammen. **Das verknüpfte WhatsApp-Konto muss Gruppenadministrator sein.**

- **Teilnehmer**: Geben Sie eine Nummer ein und verwenden Sie die Schaltflächen zum **Hinzufügen**, **Entfernen**,
  **Zum Administrator befördern** oder **Herabstufen** eines Mitglieds.
- **Betreff**: Ändern Sie den Namen/Betreff der Gruppe.
- **Einladungslink**: Generiert den Link `https://chat.whatsapp.com/…` (angezeigt und anklickbar),
  oder **widerrufen** Sie den alten Link, um einen neuen zu erstellen.
- **Verlassen**: Das verknüpfte Konto verlässt die Gruppe (unwiderrufliche Aktion, Bestätigung erforderlich).
- **Symbol**: Die Schaltfläche „Symbol" (neben Suchen/Erstellen) setzt das Plugin-Symbol als Gruppenfoto.

---

### Dashboard-Widget (v0.6)

Auf dem Jeedom-Dashboard wird das Gerät als **Kachel im WhatsApp-Stil** angezeigt:

- **Kopfzeile**: Avatar (Plugin-Symbol), Gerätename und **Verbindungsstatus** in Echtzeit
  (grüner Punkt = verbunden, orange = Verbindung/QR ausstehend, rot = offline);
- **Chat**: Die letzte empfangene Nachricht als Sprechblase (Absender + Uhrzeit);
- **Zähler**: Heute empfangene und in der aktuellen Stunde gesendete Nachrichten;
- **Schnellversand**: Ein Textfeld + Sendeschaltfläche zum direkten Schreiben in die Kanalgruppe
  vom Dashboard aus;
- **Stummschalten-Schaltfläche**: Schaltet die Gruppe mit einem Klick für 8 Std. stumm.

Keine Konfiguration erforderlich: Das Widget ist aktiv, sobald das Gerät auf dem Dashboard sichtbar ist.

---

### Sitzung sichern / wiederherstellen (v0.5)

Die WhatsApp-Verbindung basiert auf lokal gespeicherten Anmeldedaten (`auth/{id}/`). Um den
**QR-Code nach einer Server-Neuinstallation oder Migration nicht erneut scannen** zu müssen,
können Sie ein **verschlüsseltes Sicherungs-Backup** der Sitzung exportieren:

- **Sichern**: Geben Sie eine **Passphrase** (mindestens 6 Zeichen) ein und klicken Sie auf
  *Sichern* → eine verschlüsselte `.jwab`-Datei (AES-256) wird heruntergeladen. Bewahren Sie die Datei **und**
  die Passphrase sicher auf (die Passphrase ist zum Wiederherstellen unbedingt erforderlich).
- **Wiederherstellen**: Wählen Sie die `.jwab`-Datei aus, geben Sie die **gleiche Passphrase** ein, dann
  klicken Sie auf *Wiederherstellen*. Die aktuelle Sitzung wird überschrieben (die alte wird als `.bak` gespeichert),
  dann startet der Daemon automatisch neu.

> Die Verschlüsselung ist vollständig lokal (natives PHP, kein Drittanbieter-Dienst). Ohne die richtige Passphrase
> ist die Sicherungsdatei nutzlos.

---

## Szenarien

### Eindringlingsalarm — Nachricht in der Gruppe

**Auslöser:** Bewegungsmelder aktiv zwischen 23 und 6 Uhr

**Aktionen:**
- `[Mein WhatsApp][Nachricht senden]` → Nachricht: `⚠️ Bewegung im Wohnzimmer erkannt!`

Die Nachricht kommt mit dem Jeedom-Präfix in der Kanalgruppe an.

### Befehl per Stichwort mit Antwort in der Gruppe

**Auslöser:** `[Mein WhatsApp][Letzte Nachricht]` ändert sich

**Bedingung:** `[Mein WhatsApp][Letzte Nachricht]` enthält `licht`

**Aktionen:**
- Wohnzimmerlicht einschalten
- `[Mein WhatsApp][Antworten]` → Nachricht: `💡 Licht eingeschaltet!`

### Täglicher Bericht in der Gruppe

**Auslöser:** Täglich um 8:00 Uhr

**Aktionen:**
- `[Mein WhatsApp][Nachricht senden]` → Nachricht: `☀️ Guten Morgen! Temperatur Wohnzimmer: [Wohnzimmer][Temperatur]°C`

### Standort teilen 📍

**Auslöser:** Virtueller Knopf „Mein Haus teilen"

**Aktionen:**
- `[Mein WhatsApp][Standort senden]` → Titel: `48.8566|2.3522|Haus`

### Arztkontaktkarte senden

**Auslöser:** In der Gruppe empfangenes Stichwort „arzt"

**Aktionen:**
- `[Mein WhatsApp][Kontakt senden]` → Titel: `33112345678`, Nachricht: `Dr. Müller — Praxis`

### Empfang eines Befehls mit einer Reaktion bestätigen ❤️

**Auslöser:** Ein Gruppenmitglied sendet das Wort „danke"

**Aktionen:**
- `[Mein WhatsApp][Auf letzte Nachricht reagieren]` → Nachricht: `❤️`

### Stimmung nach empfangener Reaktion einstellen

**Auslöser:** `[Mein WhatsApp][Letzte Reaktion]` ändert sich

**Bedingungen:**
- Wenn `[Mein WhatsApp][Letzte Reaktion]` = `❤️` → romantische Stimmung einschalten
- Wenn `[Mein WhatsApp][Letzte Reaktion]` = `🎉` → Partystimmung einschalten
- Wenn `[Mein WhatsApp][Letzte Reaktion]` = `🌙` → Nachtmodus

### Per WhatsApp gesendetes Zählerfoto verarbeiten

**Auslöser:** `[Mein WhatsApp][Letztes Medium — Typ]` ändert sich

**Bedingungen:** wenn `[...][Letztes Medium — Typ]` = `image`

**Aktionen:**
- `[...][Letztes Medium — Pfad]` nach `/var/www/html/data/zaehler/` kopieren
- (Fortgeschritten) Ein OCR-Skript auf dem Bild aufrufen, Wert extrahieren, Virtuelles aktualisieren

> **📥 Medienempfang (v0.2)**
> Bilder, Videos, Sprachnachrichten, Dokumente und Sticker, die in der Kanalgruppe empfangen werden,
> werden automatisch in `data/jeewhatsapp/incoming/{eqId}/{YYYY-MM-DD}/{uuid}.ext` heruntergeladen.
> Dateien werden **30 Tage** aufbewahrt, dann durch einen Cron gelöscht (`cronCleanupIncoming` um 03:15).
> Der Pfad wird über die 4 Info-Befehle `last_attachment_*` exponiert — Ihre Szenarien können
> sie an anderer Stelle kopieren, analysieren (OCR, Vision) oder weiterleiten.

---

## Fehlerbehebung

### Ich erhalte keine WhatsApp-Benachrichtigung, wenn Jeedom eine Nachricht sendet

Das ist eine WhatsApp-Einschränkung: Ein Konto erhält keine Benachrichtigung für eigene Nachrichten,
auch nicht mit einer `@Sie`-Erwähnung. Die einzige Lösung ist die Verwendung eines zweiten WhatsApp-Kontos
dediziert für Jeedom (siehe Abschnitt „Einschränkung — Push-Benachrichtigungen" in der Übersicht).

### Der Daemon startet nicht

- Prüfen Sie, ob Node.js 18+ installiert ist: `node --version` im Terminal
- Führen Sie die Installation der Abhängigkeiten erneut durch
- Konsultieren Sie das Log `jeewhatsapp` unter **Analyse → Logs**
- Prüfen Sie, ob der Port `55148` nicht bereits verwendet wird

### Der QR-Code wird nicht angezeigt

- Prüfen Sie, ob der Daemon gestartet ist (Status OK in der Plugin-Verwaltung)
- Starten Sie den Daemon neu und aktualisieren Sie die Seite
- Konsultieren Sie das Log `jeewhatsapp` auf Baileys-Fehler

### Die Kanalgruppe wird beim Start nicht gefunden

- Prüfen Sie, ob der Name in **Kanalgruppe** exakt dem Namen der WhatsApp-Gruppe entspricht (Groß-/Kleinschreibung beachten)
- Die Gruppe muss auf WhatsApp existieren und Ihr Konto muss Mitglied sein
- Klicken Sie auf **Suchen** auf der Konfigurationsseite, um die Suche manuell zu erzwingen
- Konsultieren Sie das Log `jeewhatsapp`: Der Daemon zeigt `Gruppe "xxx" nicht gefunden` mit dem gesuchten Namen an

### Nachrichten werden nicht empfangen

- Prüfen Sie, ob der Status im Reiter **WhatsApp-Verbindung** **Verbunden** anzeigt
- Prüfen Sie, ob die Kanalgruppe korrekt verknüpft ist (Feld **Verknüpfte Gruppe** ausgefüllt)
- Nur Textnachrichten der Kanalgruppe werden verarbeitet — Medien werden ignoriert
- Konsultieren Sie das Log `jeewhatsapp`, um zu überprüfen, ob der Daemon Nachrichten empfängt

### Keine empfangenen Nachrichten und das Log ist voll mit „Bad MAC"

Wenn das Log `jeewhatsapp` in einer Schleife `Failed to decrypt message with any known session`
oder `Session error: Bad MAC` anzeigt, ist die **verschlüsselte Sitzung (Signal) beschädigt**:
WhatsApp sendet die Nachrichten zwar, aber der Daemon kann sie nicht mehr entschlüsseln, sie werden
daher stillschweigend verworfen. Dies passiert insbesondere nach schnell aufeinanderfolgenden Daemon-Neustarts
oder gleichzeitiger Verwendung derselben Sitzung.

**Lösung: Gerät neu koppeln.**

1. Auf Ihrem Telefon: **WhatsApp → Verbundene Geräte**, löschen Sie das Gerät „JeeWhatsApp".
2. Löschen (oder umbenennen) Sie auf dem Server den Sitzungsordner des Geräts:
   `plugins/jeewhatsapp/resources/jeewhatsappd/auth/{Geräte-ID}/`
3. Starten Sie den Daemon über die Plugin-Verwaltung neu.
4. Ein neuer QR-Code wird im Reiter **WhatsApp-Verbindung** angezeigt — scannen Sie ihn erneut.

Die Verschlüsselungsschlüssel werden neu generiert und der Empfang funktioniert wieder.

### Der Versand schlägt fehl

- Prüfen Sie, ob der WhatsApp-Status **Verbunden** ist
- Prüfen Sie, ob die verknüpfte Gruppe eingetragen ist (Feld **Verknüpfte Gruppe** nicht leer)
- Testen Sie über den Reiter **Test** der Geräteseite (lassen Sie Empfänger leer, um in die Gruppe zu senden)
- Konsultieren Sie das Log `jeewhatsapp`

### WhatsApp hat die Sitzung getrennt (Abmeldung)

Wenn WhatsApp den Zugriff auf das verbundene Gerät widerruft:
1. Der Daemon erkennt die Trennung und löscht die Anmeldedaten
2. Im Reiter **WhatsApp-Verbindung** erscheint automatisch ein neuer QR-Code
3. Scannen Sie den QR-Code erneut, um wieder zu verbinden
4. Bei der Wiederverbindung sucht der Daemon automatisch nach der Kanalgruppe

---

## Technische Architektur

```
WhatsApp-Gruppe "jeewhatsapp"
       │
       │  (WebSocket Baileys — direkte Verbindung)
       │
  jeewhatsappd.js
       │
       ├─ messages.upsert
       │    ├─ Filter: remoteJid === groupJid ? → sonst ignoriert
       │    ├─ Filter: fromMe ? → ignoriert (Jeedom-Nachricht)
       │    └─ POST callback.php?apikey=
       │         └─ jeewhatsapp::callback()
       │              └─ cmd.event() → [last_message, last_sender, …]
       │
       └─ /action (HTTP lokal 127.0.0.1:55148)
            ├─ send        → sock.sendMessage(jid, { text: präfix + nachricht })
            ├─ findGroup   → sock.groupFetchAllParticipating()
            ├─ createGroup → sock.groupCreate(name, [])
            ├─ getQR       → liest auth/{id}/qr.txt
            └─ getStatus   → liest auth/{id}/status.txt
```

### Komponenten

| Komponente | Technologie | Rolle |
|---|---|---|
| `jeewhatsappd.js` | Node.js (ESM) | Daemon — Baileys-Verbindung + lokaler HTTP-Server |
| `jeewhatsapp.class.php` | PHP | Jeedom-Logik, Daemon-Lifecycle, Befehle |
| `callback.php` | PHP | Empfangs-Endpunkt für Daemon-Nachrichten |
| `jeewhatsapp.ajax.php` | PHP | AJAX-Aktionen (Test, QR-Code, findGroup, createGroup) |

### Authentifizierung und Sitzungen

Die Baileys-Anmeldedaten werden in `resources/jeewhatsappd/auth/{eqLogicId}/` gespeichert.
Ein Unterordner pro Gerät ermöglicht die gleichzeitige Verwaltung mehrerer WhatsApp-Konten.

| Datei | Inhalt |
|---|---|
| `*.json` | Baileys-Anmeldedaten (Multi-File-Auth-State) |
| `qr.txt` | Temporärer Base64-QR-Code (bei Verbindung gelöscht) |
| `status.txt` | Aktueller Status: `connecting`, `connected`, `qr_pending`, `reconnecting`, `logged_out` |
| `group_jid.txt` | JID der Kanalgruppe (im Cache für schnellere Neustarts) |

---

## Über das Plugin

- **Plugin:** JeeWhatsApp v0.1
- **Lizenz:** AGPL v3
- **Backend:** [Baileys](https://github.com/WhiskeySockets/Baileys) — Open-Source, MIT-Lizenz
- **WhatsApp** ist eine eingetragene Marke von Meta Platforms, Inc.
- Dieses Plugin ist weder mit Meta noch mit WhatsApp affiliiert.
