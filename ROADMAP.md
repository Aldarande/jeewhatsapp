# Roadmap JeeWhatsApp

> Vision : devenir **le** plugin WhatsApp de référence pour Jeedom, avec une UX au niveau des intégrations Home Assistant les plus matures, tout en restant 100 % self-hosted (zéro service tiers).

**État actuel (v0.1)** : envoi/réception texte dans un groupe canal, mentions, quoted reply, envoi médias (image/vidéo/audio/doc/PTT), interactions Jeedom, message de soutien mensuel automatique, audit sécurité 56→74/100.

---

## Inspiration & veille concurrentielle

- **Home Assistant — FaserF/ha-whatsapp** : réactions, polls, boutons, vocaux, commandes depuis WhatsApp, whitelist par numéro, webhooks REST. ([repo](https://github.com/FaserF/ha-whatsapp) · [doc](https://faserf.github.io/ha-whatsapp/))
- **HA — cometrulz/homeassistant-whatsapp** : bridge node-RED, intégrations multi-bridge. ([repo](https://github.com/cometrulz/homeassistant-whatsapp))
- **Baileys 7.x** : ✓ messages éphémères, ✓ polls + vote tracking, ✓ reactions, ✓ business features, ✓ edit/revoke/forward, ✓ présence (typing/recording), ✓ archive/pin/mute, ✓ gestion groupes complète. ([repo](https://github.com/WhiskeySockets/Baileys) · [doc](https://baileys.wiki/docs/intro/))
- **Communauté Jeedom** : pas de plugin natif jusqu'à JeeWhatsApp — solutions actuelles = workarounds callmebot. ([forum](https://community.jeedom.com/t/jeedom-whatsapp/40014))

---

## Légende

| Symbole | Sens |
|---|---|
| 🟢 | Effort faible (< 2h) |
| 🟡 | Effort moyen (½ journée) |
| 🔴 | Effort important (1+ journée) |
| ⭐ | Valeur utilisateur élevée |
| 🛡️ | Touche la sécurité |
| 🧪 | Nécessite tests étendus |

---

## v0.2 — *« Compléter la chaîne média »* (cible 1 mois)

Objectif : ouvrir la réception, ajouter les briques manquantes pour traiter ce qui arrive.

| # | Feature | Effort | Notes |
|---|---|---|---|
| 1 | ✅ **Réception médias** (téléchargement local) ⭐ | 🟡 | `downloadMediaMessage()` → `data/jeewhatsapp/incoming/{eqId}/{date}/`, 4 cmds info `last_attachment_*`, cron daily cleanup 30j. |
| 2 | ✅ **Réactions emoji** (envoi + reception) ⭐ | 🟡 | Cmd action `react_last`, 3 cmds info `last_reaction*`, dispatcher event_type. |
| 3 | ✅ **Cmd action `send_location`** | 🟢 | Lat/long + nom optionnel, format Titre `lat\|long\|nom`. |
| 4 | ✅ **Cmd action `send_contact`** (vCard) | 🟢 | Numéro + nom, conversion 0X → 33X auto. |
| 5 | ✅ **Whitelist d'expéditeurs** 🛡️ | 🟡 | Numéros autorisés (config eqLogic), refus silencieux, helper `normalizePhone()`. |
| 6 | ✅ **Filtre par mot-clé** sur interactions | 🟢 | Préfixe configurable, retiré du message avant interactQuery. |
| 7 | ✅ **Cmd info `messages_today`** | 🟢 | Compteur reçus aujourd'hui (reset cron 00:02). |
| 8 | ✅ **Cmd info `connected_since`** | 🟢 | Date dernière connexion (refresh cron 5 min). |

**Sécurité bundlée v0.2** :
- ✅ F-004 — Secret partagé sur HTTP daemon (env var JEEDOM_DAEMON_SECRET + header `X-Daemon-Secret`) 🛡️
- ✅ F-007/F-008 — Échappement HTML systématique sur le desktop 🛡️
- ✅ F-009 — Masquage PII (messages, phones) dans logs debug 🛡️

---

## v0.3 — *« Interactif »* (cible 2-3 mois)

Objectif : sortir du paradigme « bot one-way » et offrir des interactions riches.

| # | Feature | Effort | Notes |
|---|---|---|---|
| 9 | ✅ **Polls / Sondages** ⭐ | 🔴 | Cmd action `send_poll` (title=question, message=options séparées par `\|`). Réception des votes via `messages.update` + `getAggregateVotesInPollMessage` → cmds info `poll_question`/`poll_results`/`poll_total`. |
| 10 | ✅ **Stickers personnalisés** | 🟡 | Cmd action `send_sticker` avec chemin .webp. Auto-conversion JPG/PNG → WebP via `sharp` (ajouté aux deps). |
| 11 | ✅ **Édition de message** | 🟡 | Cmd action `edit_last` — remplace le dernier message envoyé (Baileys `sendMessage(..., {edit: msgKey})`). |
| 12 | ✅ **Suppression (revoke)** | 🟡 | Cmd action `revoke_last` — équivalent "Supprimer pour tous". |
| 13 | ✅ **Transfert (forward)** ⭐ | 🟡 | Cmd action `forward_to` — title = destinataire, transfère le dernier message reçu. |
| 14 | ✅ **Présence typing/recording** | 🟢 | `sendPresenceUpdate('composing')` avant envoi auto. UX humanisée. Config eqLogic `presence_enabled`. |
| 15 | ✅ **Messages éphémères** | 🟡 | Option par eqLogic `ephemeral_duration` : tous les envois disparaissent après 24h/7j/90j (Baileys `ephemeralExpiration`). |
| 16 | ✅ **Multi-groupes par eqLogic** ⭐ | 🔴 | Config `extra_groups` (textarea `tag=Nom`). Écoute + ciblage de N groupes additionnels (couche `extraGroups[id]={tag:jid}`). Cmd action `send_group` (title=tag) + cmds info `last_group`/`last_group_name`. Rétrocompatible mono-groupe. |

---

## v0.4 — *« Vocal & assistant »* (cible 4-6 mois)

Objectif : transformer WhatsApp en interface vocale et command-line de Jeedom.

| # | Feature | Effort | Notes |
|---|---|---|---|
| 17 | ✅ **STT sur notes vocales reçues** ⭐ | 🔴🧪 | Vocal entrant → transcription **Vosk** (local, modèle fr) → cmd info `last_voice_text` + réinjection comme message (raccourcis/interactions). Pilotage vocal de Jeedom ; assistant vocal complet avec `tts_enabled`. Script `stt.py`, méthode `transcribe()`, config `stt_enabled`. Install auto non bloquante. |
| 18 | ✅ **TTS sur réponses** | 🟡 | Synthèse locale **Piper** (voix fr). Cmd action `send_voice` + méthode `speak()` (Piper → WAV → ffmpeg Opus `.ogg` PTT). Mode « vocal-first » par eqLogic (`tts_enabled`) via `sendReply()`, repli texte si échec. Voix configurable (`tts_voice`). Install auto non bloquante. |
| 19 | ✅ **Commandes shortcuts** (slash) | 🟡 | Config eqLogic `interaction_shortcuts` (`/déclencheur=cible`). Cible = commande `#id#` (action exécutée / info renvoyée) ou texte modèle avec tags `#id#` + placeholders `#args#`/`#1#`. Prioritaire sur le NLP, sans dépendance externe. Helpers `parseShortcuts()` / `handleShortcut()`. |
| 20 | ✅ **OCR sur images reçues** | 🔴 | Image entrante → Tesseract (local) → cmd info `last_ocr_text`. Activable par eqLogic (`ocr_enabled`), langue `ocr_lang` (défaut `fra`). Méthode `runOcr()` dans `updateFromAttachment()`. Install auto apt non bloquante. |
| 21 | ✅ **Reconnaissance utilisateur** | 🟡 | Config eqLogic `user_mapping` (`numéro=profil`). Expéditeur résolu en profil Jeedom (compatible plugin Profils), exposé en cmd info `last_sender_profile` et transmis à `interactQuery` via l'option `profile`. Fallback nom WhatsApp → numéro. Helpers `parseUserMapping()` / `resolveSenderProfile()`. |

---

## v0.5 — *« Gestion groupe & multi-instances »* (cible 6-9 mois)

| # | Feature | Effort | Notes |
|---|---|---|---|
| 22 | **Gestion groupe complète** | 🔴 | Cmds AJAX desktop : add/remove participant, promote admin, change subject/picture (✅ icône faite, bouton « Icône »), generate invite link, leave group. |
| 23 | ✅ **Lecture / accusés réception** | 🟢 | Cmd action `mark_read` (coches bleues, `sock.readMessages`) + cmd info `last_read_at` (callback `read_receipt` sur statut READ/PLAYED des messages envoyés). |
| 24 | **Archive / pin / mute** | 🟡 | Cmd action pour archiver/épingler une conversation. |
| 25 | **Statuts WhatsApp** (story-like) | 🟡 | Publier un statut éphémère 24h (texte ou image). |
| 26 | **Backup/restore session** 🛡️ | 🟡 | Export chiffré du dossier `auth/{id}/` vers backup Jeedom (via plugin backup). Restore en 1 clic après réinstall serveur. |
| 27 | **Sync contacts WhatsApp** | 🟡 | Pull du carnet et création eqLogics enfants (1 par contact) — permet de gérer envois directs par cmd dédiée. |

---

## v0.6 — *« Quality of life »* (cible 9-12 mois)

| # | Feature | Effort | Notes |
|---|---|---|---|
| 28 | **Widget dashboard** ⭐ | 🟡 | Tile Jeedom : dernier message + statut connexion + zone envoi rapide + bouton mute. |
| 29 | **Templates messages** | 🟢 | Pool extensible (cf donation_messages.json) pour messages utilisateurs récurrents (rappels, anniversaires, formulaires). |
| 30 | **Statistiques** | 🔴 | Graphique mensuel envoi/reception, top contacts, taux de réponse interactions, latence moyenne. |
| 31 | **Mode debug visuel** | 🟡 | Onglet « Live » dans la page eqLogic : flux temps réel des messages (WebSocket vers le daemon). |
| 32 | **Notification Manager bridge** | 🟢 | Intégration au [plugin Notification Manager](https://community.jeedom.com/t/notification-manager/67090) — JeeWhatsApp devient une "destination" sélectionnable globalement. |
| 33 | **Webhooks REST** | 🟡 | Exposer un webhook `/api/jeewhatsapp/send` (avec token) pour intégrations externes (n8n, Node-RED, scripts). |

---

## v1.0 — *« Production ready »* (cible 12+ mois)

| # | Feature | Effort | Notes |
|---|---|---|---|
| 34 | **i18n** (EN, DE, IT, ES) | 🟡 | Compléter `core/i18n/` — important pour publication store. |
| 35 | **Documentation complète** | 🔴 | Wiki GitHub : install, tutos vidéo, FAQ, troubleshooting, recettes scénarios. |
| 36 | **Tests automatisés** 🧪 | 🔴 | PHPUnit (core PHP) + Jest (daemon) + e2e (un compte WhatsApp test). |
| 37 | **Publication store Jeedom officiel** | 🟡 | Soumission, ligne éditoriale, screenshots. |
| 38 | **Mode WhatsApp Business API** (optionnel) | 🔴 | Alternative à Baileys pour utilisateurs avec compte WABA officiel (Meta Cloud API). Plus stable mais payant. |
| 39 | **Support officiel** | — | Forum dédié + GitHub Discussions + Discord. |
| 40 | **Plan donation** | 🟢 | Page Ko-fi améliorée + sponsoring GitHub + cagnotte Liberapay. |

---

## Tech debt & sécurité

À traiter en continu — pas une version dédiée mais visible dans chaque release :

| Item | Sévérité | Statut |
|---|---|---|
| F-001 API key via env | 🟠 HIGH | ✅ Corrigé v0.1 |
| F-002 Permissions auth/ | 🟠 HIGH | ✅ Corrigé v0.1 |
| F-003 IP filter callback | 🟡 MEDIUM | ✅ Corrigé v0.1 |
| F-004 Daemon HTTP auth | 🟡 MEDIUM | ⏳ Prévu v0.2 |
| F-005 Path traversal | 🟡 MEDIUM | ✅ Corrigé v0.1 |
| F-007/008 XSS desktop | 🟡 MEDIUM | ⏳ Prévu v0.2 |
| F-009 PII logs | 🔵 LOW | ⏳ Prévu v0.2 |
| F-011 package-lock | 🔵 LOW | ⏳ Prévu v0.2 |
| `npm audit` CI | — | ⏳ Prévu v1.0 |
| Refactor cleanup `jeewhatsapp/jeewhatsapp/` parasite | — | ⏳ À nettoyer v0.2 |

---

## Idées en réflexion (non priorisées)

- **IA générative** sur réponses : intégration LLM local (Ollama) ou cloud pour répondre intelligemment aux questions des membres du groupe.
- **Modération auto** : détection spam/contenu sensible avec actions auto (mute, kick).
- **Multi-comptes orchestrés** : 1 compte par membre famille, routing intelligent entre eux.
- **Channel WhatsApp** (≠ groupe) : support des Channels broadcast unidirectionnels.
- **Mode "fail-over"** : si WhatsApp down, basculer auto sur SMS/Telegram via Notification Manager.
- **App mobile compagnon** : reflet du dashboard widget en push natif Android.

---

## Métriques de succès

| Indicateur | Cible v0.5 | Cible v1.0 |
|---|---|---|
| Téléchargements store Jeedom | 100+ | 1 000+ |
| Note moyenne | ≥ 4.5/5 | ≥ 4.5/5 |
| Issues GitHub ouvertes | < 10 | < 20 |
| Couverture tests | 0 % | 60 % |
| Score sécurité (audit) | ≥ 80/100 | ≥ 90/100 |
| Contributeurs externes | 1-2 | 5+ |
| Documentation pages | 5 | 25 |

---

## Sources

- [Baileys repo (WhiskeySockets)](https://github.com/WhiskeySockets/Baileys) — 9.6k ⭐, lib de référence
- [Baileys documentation](https://baileys.wiki/docs/intro/)
- [Baileys DeepWiki](https://deepwiki.com/WhiskeySockets/Baileys) — API détaillée
- [Home Assistant : ha-whatsapp (FaserF)](https://faserf.github.io/ha-whatsapp/)
- [Home Assistant : homeassistant-whatsapp (cometrulz)](https://github.com/cometrulz/homeassistant-whatsapp)
- [Home Assistant : ha-wa-bridge (raulpetruta)](https://github.com/raulpetruta/ha-wa-bridge)
- [Communauté Jeedom — Notification Manager](https://community.jeedom.com/t/notification-manager/67090)
- [Communauté Jeedom — Envoi WhatsApp](https://community.jeedom.com/t/envoi-message-whatsapp/106224)
- [WhatsApp Business — nouveautés 2026](https://blog.omnichat.ai/whatsapp-features/)

---

*Ce roadmap est un document vivant. Les priorités peuvent évoluer selon les retours utilisateurs (issues GitHub, forum Jeedom).*
