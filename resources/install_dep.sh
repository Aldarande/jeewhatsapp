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

log "Installation terminée avec succès"
log "Baileys : $(node -e "import('@whiskeysockets/baileys/package.json', {with:{type:'json'}}).then(m=>console.log(m.default.version))" 2>/dev/null || echo 'installé')"
echo 100 > "$PROGRESS_FILE"
rm -f "$PROGRESS_FILE"
