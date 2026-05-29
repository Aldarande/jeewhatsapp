#!/bin/bash

PROGRESS_FILE=$1
DAEMON_DIR="$(dirname "$0")/jeewhatsappd"

echo 0 > "$PROGRESS_FILE"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"; }

# Vérification de Node.js
if ! command -v node &> /dev/null; then
  log "ERREUR : Node.js n'est pas installé"
  echo "error" > "$PROGRESS_FILE"
  exit 1
fi

NODE_VERSION=$(node --version | sed 's/v//' | cut -d. -f1)
log "Node.js : $(node --version)"
if [ "$NODE_VERSION" -lt 18 ]; then
  log "ERREUR : Node.js 18+ requis (version actuelle : $(node --version))"
  echo "error" > "$PROGRESS_FILE"
  exit 1
fi

echo 10 > "$PROGRESS_FILE"

# Nettoyage de l'ancienne installation (axios → baileys)
if [ -d "$DAEMON_DIR/node_modules/axios" ] && [ ! -d "$DAEMON_DIR/node_modules/@whiskeysockets" ]; then
  log "Migration détectée (axios → baileys) — nettoyage..."
  rm -rf "$DAEMON_DIR/node_modules"
fi

echo 20 > "$PROGRESS_FILE"

# Le package.json est fourni avec le plugin (type: module + baileys)
if [ ! -f "$DAEMON_DIR/package.json" ]; then
  log "ERREUR : package.json manquant dans $DAEMON_DIR"
  echo "error" > "$PROGRESS_FILE"
  exit 1
fi

echo 30 > "$PROGRESS_FILE"

# Installation des dépendances npm
log "Installation des dépendances npm (@whiskeysockets/baileys, qrcode, pino, sharp)..."
log "Cela peut prendre 2-5 minutes selon la connexion..."
cd "$DAEMON_DIR" && npm install 2>&1
if [ $? -ne 0 ]; then
  log "ERREUR : npm install a échoué"
  echo "error" > "$PROGRESS_FILE"
  exit 1
fi

echo 90 > "$PROGRESS_FILE"

# Vérification finale
if [ ! -d "$DAEMON_DIR/node_modules/@whiskeysockets/baileys" ]; then
  log "ERREUR : @whiskeysockets/baileys introuvable après installation"
  echo "error" > "$PROGRESS_FILE"
  exit 1
fi

if [ ! -d "$DAEMON_DIR/node_modules/qrcode" ]; then
  log "ERREUR : qrcode introuvable après installation"
  echo "error" > "$PROGRESS_FILE"
  exit 1
fi

echo 92 > "$PROGRESS_FILE"

# ---------------------------------------------------------------------------
# Piper TTS (v0.4 #18) — synthèse vocale française (optionnel, non bloquant)
# Binaire + voix installés dans resources/piper/ (gitignorés). ffmpeg requis
# pour la conversion WAV → Opus (.ogg PTT). Si l'installation échoue, le plugin
# continue de fonctionner : les réponses retombent en texte (sendReply).
# ---------------------------------------------------------------------------
PIPER_DIR="$(dirname "$0")/piper"
PIPER_REL="https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_linux_$(uname -m).tar.gz"
VOICE_BASE="https://huggingface.co/rhasspy/piper-voices/resolve/v1.0.0/fr/fr_FR/siwis/medium"
VOICE_NAME="fr_FR-siwis-medium"

if ! command -v ffmpeg &> /dev/null; then
  log "AVERTISSEMENT : ffmpeg introuvable — synthèse vocale (TTS) indisponible (les réponses resteront en texte)"
elif [ -x "$PIPER_DIR/piper/piper" ] && [ -f "$PIPER_DIR/voices/$VOICE_NAME.onnx" ]; then
  log "Piper TTS déjà installé — passage"
else
  log "Installation de Piper TTS (synthèse vocale, optionnel)..."
  mkdir -p "$PIPER_DIR/voices"
  if curl -fsSL -m 180 -o "$PIPER_DIR/piper.tar.gz" "$PIPER_REL" \
     && tar xzf "$PIPER_DIR/piper.tar.gz" -C "$PIPER_DIR" \
     && curl -fsSL -m 240 -o "$PIPER_DIR/voices/$VOICE_NAME.onnx"      "$VOICE_BASE/$VOICE_NAME.onnx?download=true" \
     && curl -fsSL -m 60  -o "$PIPER_DIR/voices/$VOICE_NAME.onnx.json" "$VOICE_BASE/$VOICE_NAME.onnx.json?download=true"; then
    rm -f "$PIPER_DIR/piper.tar.gz"
    chmod +x "$PIPER_DIR/tts.sh" "$PIPER_DIR/piper/piper" 2>/dev/null || true
    log "Piper TTS installé (voix $VOICE_NAME)"
  else
    log "AVERTISSEMENT : installation de Piper TTS échouée — synthèse vocale indisponible (réponses en texte)"
    rm -f "$PIPER_DIR/piper.tar.gz"
  fi
fi

echo 96 > "$PROGRESS_FILE"

# ---------------------------------------------------------------------------
# Tesseract OCR (v0.4 #20) — extraction de texte des images reçues (optionnel)
# Paquets apt tesseract-ocr + langue française. Non bloquant : si l'installation
# échoue, l'OCR est simplement indisponible (les médias restent traités).
# ---------------------------------------------------------------------------
if command -v tesseract &> /dev/null; then
  log "Tesseract OCR déjà installé ($(tesseract --version 2>&1 | head -1))"
elif command -v apt-get &> /dev/null; then
  log "Installation de Tesseract OCR (français, optionnel)..."
  SUDO=""
  [ "$(id -u)" -ne 0 ] && command -v sudo &> /dev/null && SUDO="sudo"
  if $SUDO apt-get update -qq >/dev/null 2>&1 \
     && $SUDO apt-get install -y -qq tesseract-ocr tesseract-ocr-fra >/dev/null 2>&1; then
    log "Tesseract OCR installé ($(tesseract --version 2>&1 | head -1))"
  else
    log "AVERTISSEMENT : installation de Tesseract échouée — OCR indisponible"
  fi
else
  log "AVERTISSEMENT : apt-get introuvable — Tesseract non installé (OCR indisponible)"
fi

log "Installation terminée avec succès"
log "Baileys : $(node -e "import('@whiskeysockets/baileys/package.json', {with:{type:'json'}}).then(m=>console.log(m.default.version))" 2>/dev/null || echo 'installé')"
echo 100 > "$PROGRESS_FILE"
rm -f "$PROGRESS_FILE"
