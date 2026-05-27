#!/bin/bash
# Test rapide envoi média via daemon
# Usage : docker exec jeedom-dev bash /var/www/html/plugins/jeewhatsapp/tools/test-media.sh [instance_id] [filepath]

INSTANCE_ID="${1:-30}"
FILEPATH="${2:-/tmp/test_jwa.png}"

# Crée un PNG 1x1 si pas fourni
if [ ! -f "$FILEPATH" ]; then
  echo "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=" \
    | base64 -d > "$FILEPATH"
fi

PAYLOAD=$(printf '{"action":"sendMedia","instance_id":%s,"media_path":"%s","message":"Test JWA media"}' \
  "$INSTANCE_ID" "$FILEPATH")

echo "Payload : $PAYLOAD"
echo "---"
curl -s -X POST -H "Content-Type: application/json" -d "$PAYLOAD" --max-time 60 http://127.0.0.1:55148/action
echo
