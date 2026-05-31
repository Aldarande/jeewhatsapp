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
if ! cd "$DAEMON_DIR"; then
  log "ERREUR : impossible d'accéder à $DAEMON_DIR"
  echo "error" > "$PROGRESS_FILE"
  exit 1
fi
# --omit=dev : ignore les devDependencies (build local plus rapide, moins de Mo)
# --no-audit --no-fund : silencieux et plus rapide en CI/box Jeedom
if ! npm install --omit=dev --no-audit --no-fund 2>&1; then
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
# ffmpeg — requis par la conversion audio (note vocale du widget, TTS, STT).
# Paquet apt disponible sur toutes les plateformes Jeedom (x86_64 et ARM).
# Non bloquant : si absent et non installable, les fonctions audio se
# désactivent d'elles-mêmes (le reste du plugin fonctionne).
# ---------------------------------------------------------------------------
if command -v ffmpeg &> /dev/null; then
  log "ffmpeg déjà présent ($(ffmpeg -version 2>/dev/null | head -1))"
elif command -v apt-get &> /dev/null; then
  log "Installation de ffmpeg (conversion audio)..."
  SUDO=""
  [ "$(id -u)" -ne 0 ] && command -v sudo &> /dev/null && SUDO="sudo"
  if $SUDO apt-get update -qq >/dev/null 2>&1 \
     && $SUDO apt-get install -y -qq ffmpeg >/dev/null 2>&1; then
    log "ffmpeg installé"
  else
    log "AVERTISSEMENT : installation de ffmpeg échouée — fonctions audio (note vocale/TTS/STT) indisponibles"
  fi
else
  log "AVERTISSEMENT : apt-get introuvable — ffmpeg non installé (fonctions audio indisponibles)"
fi

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

echo 98 > "$PROGRESS_FILE"

# ---------------------------------------------------------------------------
# Vosk STT (v0.4 #17) — transcription des notes vocales reçues (optionnel)
# Module python `vosk` + modèle français léger dans resources/stt/model-fr/.
# Non bloquant : si l'installation échoue, la transcription est indisponible.
# ---------------------------------------------------------------------------
STT_DIR="$(dirname "$0")/stt"
VOSK_MODEL_URL="https://alphacephei.com/vosk/models/vosk-model-small-fr-0.22.zip"

if ! command -v python3 &> /dev/null; then
  log "AVERTISSEMENT : python3 introuvable — transcription vocale (STT) indisponible"
elif python3 -c "import vosk" >/dev/null 2>&1 && [ -d "$STT_DIR/model-fr" ]; then
  log "Vosk STT déjà installé"
else
  log "Installation de Vosk STT (transcription vocale, optionnel)..."
  PIP_FLAGS="--quiet"
  pip3 install $PIP_FLAGS --break-system-packages vosk >/dev/null 2>&1 \
    || pip3 install $PIP_FLAGS vosk >/dev/null 2>&1 || true
  if python3 -c "import vosk" >/dev/null 2>&1; then
    if [ ! -d "$STT_DIR/model-fr" ]; then
      mkdir -p "$STT_DIR"
      command -v unzip >/dev/null 2>&1 || { [ "$(id -u)" -eq 0 ] && apt-get install -y -qq unzip >/dev/null 2>&1; }
      if curl -fsSL -m 240 -o "$STT_DIR/model-fr.zip" "$VOSK_MODEL_URL" \
         && unzip -q -o "$STT_DIR/model-fr.zip" -d "$STT_DIR" \
         && mv "$STT_DIR"/vosk-model-small-fr-* "$STT_DIR/model-fr" 2>/dev/null; then
        rm -f "$STT_DIR/model-fr.zip"
        chmod +x "$STT_DIR/stt.py" 2>/dev/null || true
        log "Vosk STT installé (modèle fr small)"
      else
        log "AVERTISSEMENT : téléchargement du modèle Vosk échoué — STT indisponible"
        rm -f "$STT_DIR/model-fr.zip"
      fi
    else
      log "Vosk STT installé (modèle déjà présent)"
    fi
  else
    log "AVERTISSEMENT : installation du module python vosk échouée — STT indisponible"
  fi
fi

log "Installation terminée avec succès"
log "Baileys : $(node -e "import('@whiskeysockets/baileys/package.json', {with:{type:'json'}}).then(m=>console.log(m.default.version))" 2>/dev/null || echo 'installé')"
echo 100 > "$PROGRESS_FILE"
rm -f "$PROGRESS_FILE"
