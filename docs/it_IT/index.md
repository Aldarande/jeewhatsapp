# JeeWhatsApp — Documentazione

> Plugin ID : `jeewhatsapp`  
> Autore : Aldarande — Licenza : AGPL v3

---

## Installazione

### Prerequisiti

| Componente | Versione | Obbligatorio? |
|---|---|---|
| Jeedom | ≥ 4.4.0 | ✅ |
| Node.js | ≥ 18 | ✅ (già presente sulle box Jeedom recenti) |
| `ffmpeg` | qualsiasi | ⚠️ opzionale (note vocali/TTS/STT) — installato automaticamente |
| `python3` + `pip3` | 3.8+ | ⚠️ opzionale (STT Vosk) — già presente su Jeedom |
| `tesseract-ocr` | 4+ | ⚠️ opzionale (OCR immagini) — installato automaticamente |

> Compatibilità hardware : x86_64 (VM/PC/NUC/Docker) e **ARM** (Raspberry Pi, box Jeedom
> Smart/Atlas/Luna). Il binario Piper viene recuperato tramite `uname -m` per l'architettura di destinazione.

### Procedura

1. **Installare il plugin** tramite il market Jeedom (o caricando manualmente lo zip) poi **attivarlo**.
2. **Installare le dipendenze** : pulsante « Installa le dipendenze » nella pagina del plugin —
   installa Baileys + ffmpeg + Piper (TTS) + Tesseract (OCR) + Vosk (STT) in una volta sola.
   - Calcolare **da 5 a 15 minuti** in base alla connessione e alla macchina (download ~150 MB).
   - I componenti IA sono **non bloccanti** : se uno fallisce, il plugin funziona senza quella funzione.
3. **Creare un dispositivo** JeeWhatsApp (qualsiasi nome), poi **salvarlo**.
4. **Avviare il demone** : Jeedom lo avvia automaticamente dopo la creazione del dispositivo.
   In caso di problemi, riavviarlo manualmente tramite il pulsante « Avvia il demone ».
5. **Scansionare il QR code** : aprire il dispositivo → scheda **Configurazione** → viene
   visualizzato un QR code. Sul telefono : WhatsApp → **Impostazioni → Dispositivi collegati → Collega un dispositivo**,
   poi scansionare. Lo stato passa a **« connesso »** entro 5 secondi.
6. **Creare o cercare il gruppo canale** : pulsante « Crea » nella sezione *Gruppo collegato*
   (crea un gruppo vuoto chiamato `jeewhatsapp` di default), oppure « Cerca » se è già stato
   creato manualmente. Aggiungere poi i propri contatti in questo gruppo WhatsApp.
7. **Testare** : scheda **Test** → pulsante « Invia nel gruppo canale ». Si dovrebbe ricevere
   `🏠 Test JeeWhatsApp 🚀` nel gruppo.

### Aggiornamento

Il plugin gestisce l'aggiornamento automaticamente tramite `jeewhatsapp_update()` (creazione dei cron mancanti
se nuovi). Dopo un aggiornamento maggiore, rilanciare **« Installa le dipendenze »** se vengono
annunciati nuovi componenti nel changelog.

### Disinstallazione

La disinstallazione **conserva** la cartella `resources/jeewhatsappd/auth/` (sessioni WhatsApp)
per evitare di dover ri-scansionare il QR code dopo una reinstallazione. Per eliminare tutto :
eliminare manualmente questa cartella dopo la disinstallazione.

---

## Presentazione

JeeWhatsApp integra **WhatsApp** in Jeedom tramite [Baileys](https://github.com/WhiskeySockets/Baileys),
una libreria open-source che si connette direttamente a WhatsApp Web.
**Nessun dato transita tramite un server di terze parti** — tutto rimane tra il server Jeedom e i server WhatsApp.

### Principio di funzionamento — il gruppo canale

JeeWhatsApp si basa su un **gruppo WhatsApp dedicato** che funge da canale di comunicazione bidirezionale tra Jeedom e l'utente.

- **Jeedom → te** : ogni messaggio inviato da uno scenario arriva nel gruppo, con un prefisso (es. : `🏠 `)
- **Te → Jeedom** : i tuoi messaggi nel gruppo vengono ricevuti da Jeedom e attivano i tuoi scenari o il motore di interazioni
- I messaggi fuori dal gruppo (messaggi diretti, altri gruppi) vengono ignorati

> Questo gruppo può essere creato automaticamente dal plugin dall'interfaccia di configurazione.

### Funzionalità

- 💬 **Invio di messaggi** da uno scenario Jeedom verso il gruppo canale
- 📥 **Ricezione in tempo reale** — WebSocket persistente, nessun polling
- 🔄 **Bidirezionale** — controlla Jeedom tramite WhatsApp dal gruppo
- 🤖 **Interazioni Jeedom** — risposte automatiche tramite il motore di interazioni integrato
- 📱 **Il tuo numero** — connessione tramite QR code, nessun numero dedicato richiesto
- 🔒 **100 % self-hosted** — nessun account di terze parti, nessuna chiave API, nessun abbonamento
- ⚡ **Tempo reale** — connessione WebSocket persistente, ricezione istantanea

### Cosa il plugin non fa (attualmente)

- Invio di media (immagini, video, documenti, vocali)
- Invio verso un numero non registrato su WhatsApp
- Ricezione di messaggi fuori dal gruppo canale
- **Notifiche push per il proprietario dell'account** (vedere più avanti)

### ⚠️ Limitazione — notifiche push

JeeWhatsApp utilizza **il tuo stesso account WhatsApp** (collegato tramite QR code).
WhatsApp non notifica mai il proprietario dell'account per i messaggi che lui stesso invia —
questa regola si applica anche alle menzioni (`@te`), testate e confermate senza effetto.

**Conseguenza :** quando Jeedom pubblica nel gruppo canale, non ricevi **nessuna notifica push** sul tuo telefono.
Puoi vedere i messaggi aprendo il gruppo, ma nessun avviso sonoro né banner.

**Soluzione : utilizzare un secondo account WhatsApp dedicato a Jeedom**

| Configurazione | Notifiche | Complessità |
|---|---|---|
| Account unico (il tuo numero) | ❌ Nessuna per te | Semplice |
| Due account (bot Jeedom + il tuo numero) | ✅ Vere notifiche | Richiede un 2° numero |

Con due account :
- L'account "bot Jeedom" (numero virtuale o SIM dedicata) è connesso in Jeedom
- Questo account invia i messaggi nel gruppo
- Il tuo account personale è **membro del gruppo** e riceve le notifiche normalmente
- Il comando "Invia un messaggio" in uno scenario notifica il tuo telefono come qualsiasi messaggio di gruppo

> Un numero virtuale (Google Voice, numero eSIM low cost) è sufficiente — non deve essere attivo in permanenza per WhatsApp.

---

## Prerequisiti

- Jeedom 4.4 o superiore
- Node.js **18 o superiore** sulla macchina Jeedom
- Un telefono con l'applicazione WhatsApp per scansionare il QR code

---

## Installazione

### Passaggio 1 — Installare il plugin

Copia la cartella `jeewhatsapp` in `plugins/` della tua installazione Jeedom,
poi vai in **Plugin → Gestione dei plugin** e attiva **JeeWhatsApp**.

### Passaggio 2 — Installare le dipendenze

Nella pagina del plugin, fai clic su **Installa le dipendenze**.
Lo script installa `@whiskeysockets/baileys`, `qrcode` e `pino` tramite npm.
Questo passaggio può richiedere **da 2 a 5 minuti** in base alla connessione internet.

> **Prerequisiti Node.js**  
> Lo script verifica la versione di Node.js. Se è inferiore a 18, l'installazione fallisce.  
> Per verificare : `node --version` in un terminale.

### Passaggio 3 — Avviare il demone

Fai clic su **Avvia il demone** nella pagina di configurazione del plugin.
Lo stato deve passare a **OK**.

---

## Configurazione

### Creare un dispositivo

Vai in **Plugin → Comunicazione → JeeWhatsApp** e fai clic su **Aggiungi**.

| Campo | Descrizione |
|---|---|
| Nome | Nome visualizzato in Jeedom (es. : Il mio WhatsApp) |
| Oggetto padre | Oggetto Jeedom a cui associare il dispositivo |
| Attiva | Deve essere selezionato affinché il demone tenga conto di questo dispositivo |
| **Gruppo canale** | Nome esatto del gruppo WhatsApp usato come canale (default : `jeewhatsapp`) |
| **Gruppo collegato** | JID del gruppo inserito automaticamente dopo la ricerca o la creazione — di sola lettura |
| Interazioni Jeedom | Attiva le risposte automatiche tramite il motore di interazioni |
| **Presenza "sta scrivendo"** | (v0.3) Mostra `sta scrivendo…` / `sta registrando…` per ~1 s prima di ogni invio automatico. Umanizza i messaggi. |
| **Messaggi effimeri** | (v0.3) Disattivato / 24 h / 7 g / 90 g. Tutti i messaggi inviati da Jeedom scompaiono automaticamente dopo il tempo scelto. |
| **Prefisso Jeedom** | Testo aggiunto all'inizio di ogni messaggio inviato da Jeedom (default : `🏠 `) |

Salva. I comandi vengono creati automaticamente.

### Configurare il gruppo canale

Dopo il salvataggio del dispositivo, devi collegare un gruppo WhatsApp.
**Devi prima essere connesso a WhatsApp (QR code scansionato).**

Due opzioni nel campo **Gruppo canale** :

**Opzione A — Gruppo esistente**

1. Crea manualmente un gruppo WhatsApp dal tuo telefono e dagli un nome (es. : `jeewhatsapp`)
2. Inserisci questo nome nel campo **Gruppo canale**
3. Fai clic su **Cerca** — il JID viene inserito automaticamente
4. Salva

**Opzione B — Creare il gruppo da Jeedom**

1. Inserisci il nome desiderato in **Gruppo canale**
2. Fai clic su **Crea** — il gruppo viene creato su WhatsApp e il JID viene inserito
3. Salva
4. Dal tuo telefono, aggiungi i membri desiderati al gruppo

> **Il campo "Gruppo collegato" (JID)**  
> Questo campo di sola lettura contiene l'identificatore tecnico del gruppo WhatsApp (formato `120363…@g.us`).
> Viene inserito automaticamente dai pulsanti **Cerca** / **Crea** e non deve essere modificato manualmente.

---

## Connessione tramite QR code (prima connessione)

Dopo aver creato e salvato il dispositivo, vai alla scheda **Connessione WhatsApp**.

1. Un QR code viene visualizzato automaticamente (aggiornamento ogni 8 secondi)
2. Apri WhatsApp sul tuo telefono
3. Vai in **Impostazioni → Dispositivi collegati → Collega un dispositivo**
4. Scansiona il QR code
5. Lo stato passa a **Connesso** ✅

> **Sessione persistente**  
> Una volta connesso, le credenziali vengono salvate localmente in `resources/jeewhatsappd/auth/{id}/`.
> Non sarà necessario ri-scansionare il QR code ad ogni riavvio del demone.

> **Ricerca automatica del gruppo**  
> Non appena la connessione WhatsApp viene stabilita, il demone cerca automaticamente il gruppo canale configurato.
> Il risultato viene visualizzato nei log `jeewhatsapp` : `✓ Gruppo "jeewhatsapp" → 120363…@g.us`.

---

## Comandi

### Comandi INFO (lettura)

| Nome | logicalId | Sotto-tipo | Storicizzato | Descrizione |
|---|---|---|---|---|
| Ultimo messaggio | `last_message` | string | no | Testo dell'ultimo messaggio ricevuto nel gruppo canale |
| Mittente | `last_sender` | string | no | Numero del mittente dell'ultimo messaggio |
| Nome mittente | `last_sender_name` | string | no | Nome WhatsApp del mittente |
| Ricevuto il | `last_received_at` | string | no | Timestamp dell'ultimo messaggio ricevuto |
| Inviati (ora corrente) | `sent_hour` | numeric | sì | Contatore degli invii nell'ora corrente (reset ogni ora) |
| Ricevuti oggi | `messages_today` | numeric | sì | Contatore dei messaggi ricevuti dalla mezzanotte (reset cron giornaliero 00:02) |
| Connesso da | `connected_since` | string | no | Data/ora dell'ultima connessione WhatsApp Web (refresh cron 5 min) |
| Ultima reazione | `last_reaction` | string | no | Emoji dell'ultima reazione ricevuta nel gruppo (vuoto = reazione rimossa) |
| Reazione — mittente | `last_reaction_from` | string | no | Numero dell'autore dell'ultima reazione |
| Reazione — data | `last_reaction_at` | string | no | Timestamp dell'ultima reazione |
| Ultimo media — percorso | `last_attachment_path` | string | no | Percorso assoluto server dell'ultimo media ricevuto (immagine/video/audio/documento/sticker) |
| Ultimo media — tipo | `last_attachment_type` | string | no | `image` / `video` / `audio` / `document` / `sticker` |
| Ultimo media — mime | `last_attachment_mime` | string | no | Tipo MIME dell'ultimo media ricevuto (`image/jpeg`, `audio/ogg; codecs=opus`, ...) |
| Ultimo media — dimensione | `last_attachment_size` | numeric | no | Dimensione in byte dell'ultimo media ricevuto |
| Sondaggio — domanda | `poll_question` | string | no | Domanda dell'ultimo sondaggio di cui è stato ricevuto un voto |
| Sondaggio — risultati | `poll_results` | string | no | Risultati in formato JSON `[{name, votes}]` |
| Sondaggio — voti totali | `poll_total` | numeric | sì | Numero totale di voti ricevuti sull'ultimo sondaggio |
| Ultimo gruppo — tag | `last_group` | string | no | (v0.3) Tag del gruppo di origine dell'ultimo messaggio ricevuto (`` vuoto = gruppo canale principale) |
| Ultimo gruppo — nome | `last_group_name` | string | no | (v0.3) Nome del gruppo WhatsApp di origine dell'ultimo messaggio ricevuto |

### Comandi ACTION

| Nome | logicalId | Sotto-tipo | Descrizione |
|---|---|---|---|
| Invia un messaggio | `send_message` | message | Invia un messaggio nel gruppo canale. Campo **Titolo** = destinatario opzionale (vuoto = gruppo canale, altrimenti numero diretto). |
| Rispondi | `reply` | message | Risposta "citata" all'ultimo messaggio ricevuto nel gruppo (citazione visibile). |
| Invia un media | `send_media` | message | Invia un file (immagine, video, audio, documento). Campo **Titolo** = percorso assoluto, **Messaggio** = didascalia opzionale. |
| Invia una posizione | `send_location` | message | Invia una posizione GPS. Campo **Titolo** = `lat\|long` o `lat\|long\|nome`. |
| Invia un contatto | `send_contact` | message | Invia una scheda vCard. Campo **Titolo** = numero, **Messaggio** = nome visualizzato (opzionale). |
| Reagisci all'ultimo messaggio | `react_last` | message | Invia una reazione emoji sull'ultimo messaggio ricevuto. Campo **Messaggio** = emoji (❤️ 👍 🎉 …) o vuoto per rimuovere la reazione. |
| Modifica l'ultimo messaggio | `edit_last` | message | (v0.3) Sostituisce il testo dell'ultimo messaggio **inviato** da Jeedom. Campo **Messaggio** = nuovo testo. |
| Elimina l'ultimo messaggio | `revoke_last` | other | (v0.3) Elimina "per tutti" l'ultimo messaggio **inviato** da Jeedom (pulsante, nessun parametro). |
| Trasferisci l'ultimo messaggio ricevuto | `forward_to` | message | (v0.3) Trasferisce l'ultimo messaggio **ricevuto** a un destinatario. Campo **Titolo** = destinatario opzionale (vuoto = gruppo canale). |
| Invia uno sticker | `send_sticker` | message | (v0.3) Invia uno sticker. Campo **Titolo** = percorso assoluto di un `.webp` (o `.png`/`.jpg` convertito in WebP 512×512). |
| Invia un sondaggio | `send_poll` | message | (v0.3) Invia un sondaggio. Campo **Titolo** = domanda, **Messaggio** = opzioni separate da `\|` (es. : `Sì\|No\|Forse`, da 2 a 12 opzioni). I voti alimentano i comandi info `poll_*`. |
| Invia in un gruppo aggiuntivo | `send_group` | message | (v0.3) Invia un messaggio in un gruppo aggiuntivo. Campo **Titolo** = tag del gruppo (vedi config « Gruppi aggiuntivi »), **Messaggio** = testo. |

> **💡 Campo "Titolo" del comando Invia un messaggio**  
> Jeedom mostra due campi per i comandi di tipo `message` : **Titolo** e **Messaggio**.  
> In JeeWhatsApp, il campo **Titolo** è un **override opzionale** :  
> — Vuoto → il messaggio viene inviato nel **gruppo canale**  
> — Numero (es. : `33612345678`) → invio diretto a questo numero (fuori dal gruppo)  
> — JID di gruppo (es. : `120363…@g.us`) → invio in questo gruppo specifico

> **💡 Comando Rispondi**  
> In modalità gruppo canale, `reply` invia nel gruppo (visibile a tutti i membri).
> La risposta non è privata — è un messaggio pubblico nel canale.

> **💡 Prefisso Jeedom**  
> Tutti i messaggi inviati da Jeedom vengono automaticamente prefissati (es. : `🏠 `).
> I membri del gruppo possono così distinguere gli avvisi Jeedom dai propri messaggi.
> Il demone ignora i messaggi `fromMe` nel gruppo, evitando che Jeedom elabori i propri invii.

> **📍 Inviare una posizione (`send_location`)**  
> Formato del campo **Titolo** : `lat|long` o `lat|long|nome` (separatore `|`).  
> Esempi :
> - `48.8566|2.3522` → Torre Eiffel senza etichetta
> - `48.8566|2.3522|Tour Eiffel` → con nome del luogo
> - `45.7640|4.8357|Piazza Bellecour, Lione` → con indirizzo
>
> Validazione : lat ∈ [-90, 90], long ∈ [-180, 180]. Il campo **Messaggio** viene ignorato.

> **👤 Inviare un contatto (`send_contact`)**  
> Formato del campo **Titolo** : numero internazionale senza `+` né spazi (es. : `33612345678`).  
> Formato francese accettato : `0612345678` (convertito automaticamente in `33612345678`).  
> Campo **Messaggio** = nome visualizzato della vCard (opzionale, altrimenti viene usato il numero).

> **👥 Gruppi aggiuntivi (`send_group`) — v0.3**  
> Per default, un dispositivo ascolta e scrive solo in **un** gruppo canale. Per gestire
> più gruppi (avvisi, info, famiglia…) con lo **stesso** account WhatsApp, inserisci nel
> campo **Gruppi aggiuntivi** del dispositivo, una riga per gruppo nel formato
> `tag=Nome esatto del gruppo WhatsApp` :
> ```
> avvisi=Avvisi Casa
> famiglia=Gruppo Famiglia
> ```
> - Il **tag** (`avvisi`, `famiglia`…) serve a indicare il gruppo tramite il comando
>   **Invia in un gruppo aggiuntivo** (`send_group`) : campo **Titolo** = tag, **Messaggio** = testo.
> - I messaggi **ricevuti** in questi gruppi alimentano anche i comandi info,
>   e il gruppo di origine è esposto tramite `last_group` (tag, vuoto = gruppo principale) e `last_group_name`.
> - Il gruppo canale **principale** rimane invariato ; questa funzione è puramente additiva
>   (retrocompatibile con le configurazioni esistenti).

---

## Interazioni Jeedom

Quando l'opzione **Interazioni Jeedom** è attivata, ogni messaggio ricevuto nel gruppo canale
viene trasmesso al motore di interazioni Jeedom. Se un'interazione corrisponde, la risposta viene
automaticamente inviata **nel gruppo canale**.

### Filtro per parola chiave di attivazione (v0.2)

Campo **« Parola chiave di attivazione »** nella configurazione del dispositivo. Se inserita, solo i messaggi
**che iniziano** con questa parola chiave (insensibile alle maiuscole) attivano le interazioni. La parola chiave viene
**rimossa** dal messaggio prima della trasmissione al motore di interazioni Jeedom — permette di avere formulazioni
naturali lato Jeedom evitando il rumore nel gruppo.

| Configurazione | Messaggio ricevuto | Comportamento |
|---|---|---|
| keyword vuoto | `accendi il salone` | → interactQuery cerca `accendi il salone` |
| keyword = `!jeedom` | `buongiorno a tutti` | → ignorato (log debug) |
| keyword = `!jeedom` | `!jeedom accendi il salone` | → interactQuery cerca `accendi il salone` |
| keyword = `@jeedom` | `@JEEDOM stato` | → interactQuery cerca `stato` (maiuscole ignorate) |

### Whitelist mittenti (v0.2 — sicurezza)

Campo **« Whitelist mittenti »** : se inserito, solo i numeri elencati possono attivare
le interazioni Jeedom. Gli altri membri del gruppo vengono **silenziati** (log debug).

**Formato accettato** : 1 numero per riga o separati da virgola, in qualsiasi formato :
- `0612345678` (formato corto francese)
- `33612345678` (internazionale)
- `+33 6 12 34 56 78` (con spazi e +)

Tutti i formati vengono normalizzati al formato internazionale prima del confronto.

> **🛡️ Sicurezza** : la whitelist protegge da un membro malintenzionato che si aggiunge al gruppo e tenta
> di inviare comandi Jeedom. Combinata con il filtro parola chiave, offre un doppio livello di protezione.

Esempi di interazioni configurabili in Jeedom :

| Messaggio ricevuto | Risposta automatica |
|---|---|
| `temperatura salone` | `La temperatura del salone è di 21°C` |
| `accendi la luce` | `Luce accesa` |
| `stato` | `Tutti i dispositivi sono OK` |

> Configura le tue interazioni in **Strumenti → Interazioni** in Jeedom.

### Comandi shortcuts — « slash » (v0.4)

Campo **« Comandi shortcuts »** : scorciatoie rapide attivate da un messaggio
che inizia con `/`. Sono **prioritarie** rispetto al motore di interazioni (NLP) e non
richiedono alcuna configurazione in *Strumenti → Interazioni* — ideale per i comandi
frequenti.

**Formato** : una riga per scorciatoia, `/attivatore=destinazione`. La destinazione può essere :

| Tipo di destinazione | Esempio di riga | Effetto del messaggio `/attivatore` |
|---|---|---|
| **Comando azione** `#id#` | `/scene=#9012#` | Esegue il comando azione `9012`, risponde `✅ Nome del comando` |
| **Comando info** `#id#` | `/temp=#1234#` | Risponde il valore corrente : `Temperatura salone : 21 °C` |
| **Testo modello** | `/ciao=Ciao #args# !` | `/ciao Paolo` → risponde `Ciao Paolo !` |
| **Modello + tag Jeedom** | `/casa=Salone #1234# / Est #5678#` | Sostituisce gli `#id#` di info con il loro valore |

**Variabili disponibili in un testo modello** :
- `#args#` : tutti gli argomenti dopo l'attivatore (`/echo ciao mondo` → `#args#` = `ciao mondo`)
- `#1#`, `#2#`, … : ogni parola dell'argomento presa separatamente

Per un comando azione di sotto-tipo *message*, l'argomento viene passato come testo del
messaggio ; per uno *slider*, come valore ; per un *colore*, come codice colore.

Un attivatore sconosciuto restituisce `❓ Scorciatoia sconosciuta : /xxx`.

> **Esempio completo** :
> ```
> /salone=#1234#
> /accendi=#1057#
> /stato=🏠 Salone : #1234# °C — Allarme : #1099#
> /di=Messaggio ricevuto : #args#
> ```
> Poi nel gruppo : `/salone` → `Temperatura salone : 21 °C`, `/di Ciao` → `Messaggio ricevuto : Ciao`.

---

### Riconoscimento utente (v0.4)

Campo **« Riconoscimento utente »** : associa il numero di un mittente a un **profilo
Jeedom**. Una riga per corrispondenza, nel formato `numero=profilo`.

```
33612345678=Papa
0698765432=Mamma
33700000000=Figlio
```

I numeri vengono normalizzati al formato internazionale (`0612345678`, `+33 6 12 34 56 78` e
`33612345678` sono equivalenti).

Quando arriva un messaggio da un numero mappato :

- il profilo risolto è esposto nel comando info **« Mittente — profilo »**
  (`last_sender_profile`) — utilizzabile negli scenari per personalizzare le risposte ;
- viene trasmesso al motore di interazioni Jeedom tramite l'opzione `profile`, il che lo rende
  **compatibile con il plugin Profili** (regole di accesso, restrizioni, personalizzazione per
  persona).

Se nessuna corrispondenza trovata, il profilo ricade sul nome WhatsApp del mittente,
poi sul suo numero grezzo. Il comando info rimane vuoto quando il mittente non è mappato.

> **Esempio** : con `33612345678=Papa`, un messaggio « spegni la camera » inviato da questo
> numero viene elaborato dalle interazioni Jeedom come proveniente dal profilo *Papa* — puoi
> così autorizzare certi comandi solo a questo profilo tramite il plugin Profili.

---

### Risposte vocali — sintesi vocale / TTS (v0.4)

Il plugin può **parlare** : un testo viene sintetizzato in **nota vocale** (Opus `.ogg`,
visualizzata come messaggio vocale in WhatsApp) grazie a **Piper**, un motore di sintesi
**100 % locale** (nessun servizio di terze parti, nessun dato inviato all'esterno).

**Due utilizzi :**

1. **Comando azione « Invia una nota vocale »** (`send_voice`) — da usare in uno
   scenario : il campo *Messaggio* contiene il testo da dire, il campo *Titolo* un destinatario
   opzionale (vuoto = gruppo canale). Esempio : `[Il mio WhatsApp][Invia una nota vocale]` →
   Messaggio : `La temperatura del salone è di 21 gradi.`
2. **Modalità « vocal-first »** — seleziona **« Risposte vocali (TTS) → Attiva la modalità vocale »**
   nella configurazione del dispositivo. Tutte le risposte automatiche (interazioni
   Jeedom e scorciatoie `/`) vengono quindi inviate come nota vocale invece che testo. In caso
   di errore di sintesi, il plugin **ritorna automaticamente al testo**.

**Voce** : la voce francese `fr_FR-siwis-medium` è installata di default. Per usarne
una altra, posiziona un modello Piper (`.onnx` + `.onnx.json`) in `resources/piper/voices/` e
indica il suo nome file nel campo **« Voce di sintesi »** (o un percorso assoluto).

> **Prerequisiti** : `ffmpeg` deve essere installato sul server (presente di default sulla
> maggior parte delle installazioni Jeedom). Il binario Piper e la voce francese vengono scaricati
> automaticamente durante l'installazione delle dipendenze del plugin. Se l'installazione di
> Piper fallisce, il plugin continua a funzionare — solo le risposte vocali vengono
> disattivate (ripiego sul testo).

---

### OCR sulle immagini ricevute (v0.4)

Il plugin può **leggere il testo delle immagini** ricevute grazie a **Tesseract**, un motore OCR
**100 % locale** (nessun servizio di terze parti).

**Attivazione** : seleziona **« OCR immagini ricevute → Attiva »** nella configurazione del
dispositivo. Da quel momento, ogni immagine ricevuta nel gruppo canale viene analizzata e il testo
riconosciuto viene inserito nel comando info **« OCR — testo immagine »** (`last_ocr_text`).

**Lingua** : `fra` (francese) di default. Il campo accetta più lingue combinate con
`+`, per esempio `fra+eng` per testo che mescola francese e inglese.

**Casi d'uso** : lettura di contatori (acqua, gas, elettricità), lettura di uno scontrino,
di un cartello, di un numero di serie… Puoi poi reagire al cambiamento di
`last_ocr_text` in uno scenario (estrazione di cifre, archiviazione, avviso…).

> **Prerequisiti** : i pacchetti `tesseract-ocr` e `tesseract-ocr-fra` vengono installati
> automaticamente (apt) durante l'installazione delle dipendenze del plugin. Se l'installazione
> fallisce, l'OCR viene semplicemente disattivato — la ricezione dei media continua normalmente.

---

### Trascrizione vocale — STT (v0.4)

Il plugin può **trascrivere le note vocali** ricevute grazie a **Vosk**, un motore di
riconoscimento vocale **100 % locale e offline** (nessun servizio di terze parti).

**Attivazione** : seleziona **« Trascrizione vocale (STT) → Attiva »** nella configurazione
del dispositivo. Da quel momento, ogni nota vocale ricevuta nel gruppo canale viene trascritta :

- il testo viene inserito nel comando info **« STT — nota vocale »** (`last_voice_text`) ;
- viene **reiniettato come messaggio testo**, il che attiva le **scorciatoie** (`/`) e
  le **interazioni Jeedom** esattamente come se avessi digitato il messaggio.

Puoi quindi **controllare Jeedom a voce** : invia una nota vocale « *accendi la luce
del salone* » e la corrispondente interazione Jeedom viene eseguita.

> **Assistente vocale completo** : attiva sia **« Trascrizione vocale (STT) »** che
> **« Risposte vocali (TTS) »**. Il ciclo diventa : nota vocale in entrata → trascrizione →
> comando Jeedom → **risposta sintetizzata come nota vocale**. Tutto rimane locale sul tuo server.

> **Prerequisiti** : il modulo Python `vosk` e il modello francese leggero vengono installati
> automaticamente durante l'installazione delle dipendenze del plugin (`ffmpeg` richiesto). Se
> l'installazione fallisce, la trascrizione viene semplicemente disattivata — la ricezione delle note
> vocali continua normalmente.

---

### Conferme di lettura (v0.5)

Due meccanismi complementari :

- **Segna come letto** : il comando azione **« Segna come letto »** (`mark_read`) mette le
  spunte blu sull'ultimo messaggio ricevuto nel gruppo canale. Utile per segnalare ai tuoi
  corrispondenti che Jeedom (o tu) ha preso visione del messaggio.
- **« Letto il »** : il comando info **`last_read_at`** viene aggiornato automaticamente quando un
  destinatario **legge o ascolta** un messaggio *inviato* da Jeedom. Puoi così sapere, in
  uno scenario, se il tuo avviso è stato consultato.

---

### Archivia / Fissa / Disattiva notifiche (v0.5)

Tre comandi azione controllano lo stato della conversazione del gruppo canale :

- **Archivia la conversazione** (`archive_chat`) — *Titolo* vuoto = archiviare, `0` = dearchiviare.
- **Fissa la conversazione** (`pin_chat`) — *Titolo* vuoto = fissare, `0` = defissare.
- **Disattiva notifiche** (`mute_chat`) — *Titolo* = durata in ore (vuoto = 8 h), `0` = riattivare.

Utile ad esempio per mettere automaticamente il gruppo in modalità silenziosa la notte tramite uno scenario,
poi riattivarlo al mattino.

---

### Pubblicare uno stato WhatsApp (v0.5)

Il comando azione **« Pubblica uno stato »** (`post_status`) pubblica uno **stato effimero 24 h**
(come una storia) :

- *Messaggio* : il testo dello stato (o la didascalia se viene fornita un'immagine) ;
- *Titolo* : percorso assoluto di un'**immagine** opzionale (stato immagine).

Il pubblico è costituito dai **partecipanti del gruppo canale** (sono loro che vedranno lo
stato nel loro feed). Esempio : pubblicare ogni mattina uno stato « Casa sicura ✅ » tramite uno
scenario.

---

### Gestione del gruppo (v0.5)

La sezione **« Gestione del gruppo »** (configurazione del dispositivo) raggruppa le operazioni
di amministrazione del gruppo canale. **L'account WhatsApp collegato deve essere amministratore del gruppo.**

- **Partecipanti** : inserisci un numero poi usa i pulsanti per **aggiungere**, **rimuovere**,
  **promuovere ad amministratore** o **retrocedere** un membro.
- **Oggetto** : cambia il nome/oggetto del gruppo.
- **Link di invito** : genera il link `https://chat.whatsapp.com/…` (visualizzato e cliccabile),
  oppure **revoca** il vecchio link per crearne uno nuovo.
- **Esci** : fa abbandonare il gruppo all'account collegato (azione irreversibile, conferma richiesta).
- **Icona** : il pulsante « Icona » (accanto a Cerca/Crea) applica l'icona del plugin come
  foto del gruppo.

---

### Widget dashboard (v0.6)

Sul dashboard Jeedom, il dispositivo viene visualizzato come **riquadro in stile WhatsApp** :

- **Intestazione** : avatar (icona del plugin), nome del dispositivo e **stato della connessione** in
  tempo reale (punto verde = connesso, arancione = connessione/QR in attesa, rosso = offline) ;
- **Chat** : l'ultimo messaggio ricevuto come bolla (mittente + ora) ;
- **Contatori** : messaggi ricevuti oggi e inviati nell'ora corrente ;
- **Invio rapido** : un campo testo + pulsante di invio per scrivere direttamente nel gruppo
  canale dal dashboard ;
- **Pulsante silenzia** : mette il gruppo in modalità silenziosa (8 h) con un clic.

Nessuna configurazione necessaria : il widget è attivo appena il dispositivo è visibile sul
dashboard.

---

### Backup / ripristino della sessione (v0.5)

La connessione WhatsApp si basa su credenziali memorizzate localmente (`auth/{id}/`). Per non dover
**ri-scansionare il QR code** dopo una reinstallazione del server o una migrazione, puoi esportare un
**backup cifrato** della sessione :

- **Salva** : inserisci una **passphrase** (minimo 6 caratteri) poi fai clic su
  *Salva* → viene scaricato un file `.jwab` cifrato (AES-256). Conserva il file **e**
  la passphrase in un posto sicuro (la passphrase è indispensabile per il ripristino).
- **Ripristina** : seleziona il file `.jwab`, inserisci la **stessa passphrase**, poi
  fai clic su *Ripristina*. La sessione corrente viene sovrascritta (la vecchia è conservata come `.bak`),
  poi il demone si riavvia automaticamente.

> La cifratura è interamente locale (PHP nativo, nessun servizio di terze parti). Senza la passphrase corretta,
> il file di backup è inutilizzabile.

---

## Scenari

### Avviso intruso — messaggio nel gruppo

**Attivatore :** Rilevatore di movimento attivo tra le 23h e le 6h

**Azioni :**
- `[Il mio WhatsApp][Invia un messaggio]` → Messaggio : `⚠️ Movimento rilevato nel salone !`

Il messaggio arriva nel gruppo canale con il prefisso Jeedom.

### Comando per parola chiave con risposta nel gruppo

**Attivatore :** `[Il mio WhatsApp][Ultimo messaggio]` cambia

**Condizione :** `[Il mio WhatsApp][Ultimo messaggio]` contiene `luce`

**Azioni :**
- Accendere la luce del salone
- `[Il mio WhatsApp][Rispondi]` → Messaggio : `💡 Luce accesa !`

### Rapporto giornaliero nel gruppo

**Attivatore :** Ogni giorno alle 8h00

**Azioni :**
- `[Il mio WhatsApp][Invia un messaggio]` → Messaggio : `☀️ Buongiorno ! Temperatura salone : [Salone][Temperatura]°C`

### Condividere una posizione 📍

**Attivatore :** Pulsante virtuale "Condividi la mia casa"

**Azioni :**
- `[Il mio WhatsApp][Invia una posizione]` → Titolo : `48.8566|2.3522|Casa`

### Inviare la scheda contatto del medico

**Attivatore :** Parola chiave "dottore" ricevuta nel gruppo

**Azioni :**
- `[Il mio WhatsApp][Invia un contatto]` → Titolo : `33112345678`, Messaggio : `Dr. Rossi — studio`

### Confermare la ricezione di un comando con una reazione ❤️

**Attivatore :** Un membro del gruppo invia la parola "grazie"

**Azioni :**
- `[Il mio WhatsApp][Reagisci all'ultimo messaggio]` → Messaggio : `❤️`

### Accendere un'atmosfera in base alla reazione ricevuta

**Attivatore :** `[Il mio WhatsApp][Ultima reazione]` cambia

**Condizioni :**
- Se `[Il mio WhatsApp][Ultima reazione]` = `❤️` → accendere atmosfera romantica
- Se `[Il mio WhatsApp][Ultima reazione]` = `🎉` → accendere atmosfera festa
- Se `[Il mio WhatsApp][Ultima reazione]` = `🌙` → modalità notte

### Elaborare una foto di un contatore inviata tramite WhatsApp

**Attivatore :** `[Il mio WhatsApp][Ultimo media — tipo]` cambia

**Condizioni :** se `[...][Ultimo media — tipo]` = `image`

**Azioni :**
- Copia `[...][Ultimo media — percorso]` verso `/var/www/html/data/contatori/`
- (avanzato) Chiama uno script OCR sull'immagine, estrai il valore, aggiorna un virtuale

> **📥 Ricezione media (v0.2)**
> Le immagini, i video, le note vocali, i documenti e gli sticker ricevuti nel gruppo canale
> vengono automaticamente scaricati in `data/jeewhatsapp/incoming/{eqId}/{YYYY-MM-DD}/{uuid}.ext`.
> I file vengono conservati **30 giorni** poi eliminati dal cron (`cronCleanupIncoming` alle 03:15).
> Il percorso è esposto tramite i 4 comandi info `last_attachment_*` — i tuoi scenari possono
> copiarli altrove, analizzarli (OCR, visione), o trasferirli.

---

## Risoluzione dei problemi

### Non ricevo notifiche WhatsApp quando Jeedom invia un messaggio

È una limitazione di WhatsApp : un account non riceve notifiche per i propri messaggi,
nemmeno con una menzione `@te`. L'unica soluzione è utilizzare un secondo account WhatsApp dedicato a Jeedom
(vedi la sezione "Limitazione — notifiche push" nella Presentazione).

### Il demone non si avvia

- Verifica che Node.js 18+ sia installato : `node --version` in un terminale
- Rilancia l'installazione delle dipendenze
- Consulta il log `jeewhatsapp` in **Analisi → Log**
- Verifica che la porta `55148` non sia già in uso

### Il QR code non viene visualizzato

- Verifica che il demone sia avviato (stato OK nella gestione del plugin)
- Riavvia il demone poi aggiorna la pagina
- Consulta il log `jeewhatsapp` per rilevare un errore Baileys

### Il gruppo canale non viene trovato all'avvio

- Verifica che il nome in **Gruppo canale** corrisponda esattamente al nome del gruppo WhatsApp (sensibile alle maiuscole)
- Il gruppo deve esistere su WhatsApp e il tuo account deve essere membro
- Fai clic su **Cerca** nella pagina di configurazione per forzare la ricerca manualmente
- Consulta il log `jeewhatsapp` : il demone mostra `Gruppo "xxx" non trovato` con il nome cercato

### I messaggi non vengono ricevuti

- Verifica che lo stato nella scheda **Connessione WhatsApp** mostri **Connesso**
- Verifica che il gruppo canale sia correttamente collegato (campo **Gruppo collegato** inserito)
- Solo i messaggi testo del gruppo canale vengono elaborati — i media vengono ignorati
- Consulta il log `jeewhatsapp` per verificare che il demone riceva correttamente i messaggi

### Nessun messaggio ricevuto e il log è saturo di « Bad MAC »

Se il log `jeewhatsapp` mostra in loop `Failed to decrypt message with any known session`
o `Session error: Bad MAC`, significa che la **sessione cifrata (Signal) è corrotta** :
WhatsApp invia correttamente i messaggi ma il demone non può più decifrarli, quindi vengono
silenziosamente abbandonati. Questo accade in particolare dopo riavvii ravvicinati del demone
o un uso concorrente della stessa sessione.

**Soluzione : riassociare il dispositivo.**

1. Sul tuo telefono : **WhatsApp → Dispositivi connessi**, elimina il dispositivo « JeeWhatsApp ».
2. Lato server, elimina (o rinomina) la cartella di sessione del dispositivo :
   `plugins/jeewhatsapp/resources/jeewhatsappd/auth/{ID_dispositivo}/`
3. Riavvia il demone dalla gestione del plugin.
4. Un nuovo QR code viene visualizzato nella scheda **Connessione WhatsApp** — ri-scansionalo.

Le chiavi di cifratura vengono rigenerate e la ricezione torna a funzionare.

### L'invio fallisce

- Verifica che lo stato WhatsApp sia **Connesso**
- Verifica che il gruppo collegato sia inserito (campo **Gruppo collegato** non vuoto)
- Testa dalla scheda **Test** della pagina dispositivo (lascia Destinatario vuoto per inviare nel gruppo)
- Consulta il log `jeewhatsapp`

### WhatsApp ha disconnesso la sessione (logout)

Quando WhatsApp revoca l'accesso al dispositivo collegato :
1. Il demone rileva la disconnessione e elimina le credenziali
2. Nella scheda **Connessione WhatsApp**, un nuovo QR code appare automaticamente
3. Ri-scansiona il QR code per riconnettersi
4. Alla riconnessione, il demone cerca automaticamente il gruppo canale

---

## Architettura tecnica

```
Gruppo WhatsApp "jeewhatsapp"
       │
       │  (WebSocket Baileys — connessione diretta)
       │
  jeewhatsappd.js
       │
       ├─ messages.upsert
       │    ├─ Filtro : remoteJid === groupJid ? → altrimenti ignorato
       │    ├─ Filtro : fromMe ? → ignorato (messaggio di Jeedom)
       │    └─ POST callback.php?apikey=
       │         └─ jeewhatsapp::callback()
       │              └─ cmd.event() → [last_message, last_sender, …]
       │
       └─ /action (HTTP locale 127.0.0.1:55148)
            ├─ send        → sock.sendMessage(jid, { text: prefisso + messaggio })
            ├─ findGroup   → sock.groupFetchAllParticipating()
            ├─ createGroup → sock.groupCreate(name, [])
            ├─ getQR       → legge auth/{id}/qr.txt
            └─ getStatus   → legge auth/{id}/status.txt
```

### Componenti

| Componente | Tecnologia | Ruolo |
|---|---|---|
| `jeewhatsappd.js` | Node.js (ESM) | Demone — connessione Baileys + server HTTP locale |
| `jeewhatsapp.class.php` | PHP | Logica Jeedom, lifecycle demone, comandi |
| `callback.php` | PHP | Endpoint di ricezione dei messaggi del demone |
| `jeewhatsapp.ajax.php` | PHP | Azioni AJAX (test, QR code, findGroup, createGroup) |

### Auth e sessioni

Le credenziali Baileys sono memorizzate in `resources/jeewhatsappd/auth/{eqLogicId}/`.
Una sottocartella per dispositivo permette di gestire più account WhatsApp simultaneamente.

| File | Contenuto |
|---|---|
| `*.json` | Credenziali Baileys (multi-file auth state) |
| `qr.txt` | QR code base64 temporaneo (eliminato alla connessione) |
| `status.txt` | Stato corrente : `connecting`, `connected`, `qr_pending`, `reconnecting`, `logged_out` |
| `group_jid.txt` | JID del gruppo canale (memorizzato nella cache per accelerare il riavvio) |

---

## Informazioni

- **Plugin :** JeeWhatsApp v0.1
- **Licenza :** AGPL v3
- **Backend :** [Baileys](https://github.com/WhiskeySockets/Baileys) — open-source, licenza MIT
- **WhatsApp** è un marchio registrato di Meta Platforms, Inc.
- Questo plugin non è affiliato a Meta né a WhatsApp.
