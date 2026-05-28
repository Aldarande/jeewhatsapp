#!/bin/bash
# Test rapide envoi location + contact via daemon
# Usage : docker exec jeedom-dev bash /var/www/html/plugins/jeewhatsapp/tools/test-location-contact.sh [instance_id]

INSTANCE_ID="${1:-30}"

echo "=== Test sendLocation (Tour Eiffel) ==="
curl -s -X POST -H "Content-Type: application/json" \
  -d "{\"action\":\"sendLocation\",\"instance_id\":${INSTANCE_ID},\"latitude\":48.8566,\"longitude\":2.3522,\"location_name\":\"Tour Eiffel (test)\"}" \
  --max-time 15 http://127.0.0.1:55148/action
echo

echo "=== Test sendContact (33612345678 / Jean Test) ==="
curl -s -X POST -H "Content-Type: application/json" \
  -d "{\"action\":\"sendContact\",\"instance_id\":${INSTANCE_ID},\"contact_phone\":\"33612345678\",\"contact_name\":\"Jean Test\"}" \
  --max-time 15 http://127.0.0.1:55148/action
echo
