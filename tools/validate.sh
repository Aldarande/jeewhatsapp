#!/bin/bash
# =============================================================================
# JeeWhatsApp — Script de validation automatique
# Usage local Windows : docker exec jeedom-dev bash /var/www/html/plugins/jeewhatsapp/tools/validate.sh
# Usage Linux/macOS   : bash tools/validate.sh
#
# Code de sortie : 0 = tout OK, 1 = au moins un FAIL, 2 = au moins un WARN
# Sortie : lignes préfixées [PASS] / [WARN] / [FAIL] puis bloc SUMMARY final
# =============================================================================

set -u

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
JEEDOM_LOG="${JEEDOM_LOG:-/var/www/html/log/jeewhatsapp}"
DAEMON_PID_FILE="${DAEMON_PID_FILE:-/tmp/jeedom/jeewhatsapp/daemon.pid}"
DAEMON_PORT="${DAEMON_PORT:-55148}"

PASS=0; WARN=0; FAIL=0
pass() { echo "[PASS] $1"; PASS=$((PASS+1)); }
warn() { echo "[WARN] $1"; WARN=$((WARN+1)); }
fail() { echo "[FAIL] $1"; FAIL=$((FAIL+1)); }

echo "=== JeeWhatsApp — Validation automatique ==="
echo "Plugin : $PLUGIN_DIR"
echo "Date   : $(date '+%Y-%m-%d %H:%M:%S')"
echo

# -----------------------------------------------------------------------------
# 1. Syntaxe PHP
# -----------------------------------------------------------------------------
echo "── 1. Syntaxe PHP ──"
PHP_FILES=$(find "$PLUGIN_DIR" -name "*.php" -not -path "*/node_modules/*" -not -path "*/.git/*")
for f in $PHP_FILES; do
  if php -l "$f" >/dev/null 2>&1; then
    pass "PHP syntax: ${f#$PLUGIN_DIR/}"
  else
    fail "PHP syntax: ${f#$PLUGIN_DIR/}"
    php -l "$f" 2>&1 | sed 's/^/       /'
  fi
done

# -----------------------------------------------------------------------------
# 2. Syntaxe JS daemon
# -----------------------------------------------------------------------------
echo
echo "── 2. Syntaxe JS daemon ──"
if command -v node >/dev/null 2>&1; then
  if node --check "$PLUGIN_DIR/resources/jeewhatsappd/jeewhatsappd.js" 2>/dev/null; then
    pass "JS syntax: jeewhatsappd.js"
  else
    fail "JS syntax: jeewhatsappd.js"
    node --check "$PLUGIN_DIR/resources/jeewhatsappd/jeewhatsappd.js" 2>&1 | sed 's/^/       /'
  fi
else
  warn "node introuvable — saut du check JS"
fi

# -----------------------------------------------------------------------------
# 3. Dépendances npm
# -----------------------------------------------------------------------------
echo
echo "── 3. Dépendances Baileys ──"
if [ -d "$PLUGIN_DIR/resources/jeewhatsappd/node_modules/@whiskeysockets/baileys" ]; then
  BAILEYS_V=$(node -e "import('@whiskeysockets/baileys/package.json', {with:{type:'json'}}).then(m=>console.log(m.default.version))" 2>/dev/null \
              --experimental-vm-modules 2>/dev/null \
              || cat "$PLUGIN_DIR/resources/jeewhatsappd/node_modules/@whiskeysockets/baileys/package.json" 2>/dev/null | grep -oP '"version":\s*"\K[^"]+' | head -1)
  pass "Baileys installé (version: ${BAILEYS_V:-?})"
else
  fail "Baileys manquant — lancer install_dep.sh"
fi

# -----------------------------------------------------------------------------
# 3b. Dépendances média / audio (optionnelles, dégradation gracieuse)
# -----------------------------------------------------------------------------
echo
echo "── 3b. Dépendances média/audio (optionnelles) ──"
echo "      Plateforme : $(uname -m)"
if command -v ffmpeg &> /dev/null; then
  pass "ffmpeg présent (conversion audio : note vocale widget, TTS, STT)"
else
  warn "ffmpeg absent — enregistrement vocal/TTS/STT indisponibles (apt-get install ffmpeg)"
fi
if [ -x "$PLUGIN_DIR/resources/piper/piper/piper" ]; then
  pass "Piper TTS installé"
else
  warn "Piper TTS absent — réponses vocales en repli texte (optionnel)"
fi
if command -v tesseract &> /dev/null; then
  pass "Tesseract OCR présent"
else
  warn "Tesseract OCR absent — OCR images désactivé (optionnel)"
fi
if command -v python3 &> /dev/null && python3 -c "import vosk" >/dev/null 2>&1 && [ -d "$PLUGIN_DIR/resources/stt/model-fr" ]; then
  pass "Vosk STT installé"
else
  warn "Vosk STT absent — transcription notes vocales désactivée (optionnel)"
fi

# -----------------------------------------------------------------------------
# 4. Daemon vivant
# -----------------------------------------------------------------------------
echo
echo "── 4. État daemon ──"
DAEMON_PID=""
if [ -f "$DAEMON_PID_FILE" ]; then
  DAEMON_PID=$(cat "$DAEMON_PID_FILE" 2>/dev/null)
  if [ -n "$DAEMON_PID" ] && kill -0 "$DAEMON_PID" 2>/dev/null; then
    pass "Daemon vivant (PID=$DAEMON_PID)"
  else
    fail "PID file présent ($DAEMON_PID) mais process mort"
  fi
else
  warn "Pas de PID file — daemon non démarré ?"
  # cherche un node jeewhatsappd dans la table des process
  if pgrep -af "jeewhatsappd.js" >/dev/null 2>&1; then
    PIDS=$(pgrep -af "jeewhatsappd.js" | awk '{print $1}' | tr '\n' ' ')
    warn "Process daemon trouvé sans PID file : $PIDS"
  fi
fi

# -----------------------------------------------------------------------------
# 5. SECURITY — F-001 : API key absente de ps aux
# -----------------------------------------------------------------------------
echo
echo "── 5. Sécurité (F-001) — API key hors process list ──"
if pgrep -af "jeewhatsappd.js" >/dev/null 2>&1; then
  if pgrep -af "jeewhatsappd.js" | grep -qE 'apikey=|--callback[^ ]*apikey'; then
    fail "API key visible dans ps aux (F-001 non corrigé)"
    pgrep -af "jeewhatsappd.js" | sed 's/^/       /'
  else
    pass "API key absente des arguments CLI"
  fi
else
  warn "Daemon non lancé — check F-001 ignoré"
fi

# -----------------------------------------------------------------------------
# 6. SECURITY — F-002 : permissions auth/
# -----------------------------------------------------------------------------
echo
echo "── 6. Sécurité (F-002) — permissions auth/ ──"
AUTH_BASE="$PLUGIN_DIR/resources/jeewhatsappd/auth"
if [ -d "$AUTH_BASE" ]; then
  PERMS=$(stat -c '%a' "$AUTH_BASE" 2>/dev/null)
  if [ "$PERMS" = "700" ]; then
    pass "auth/ permissions: 700"
  else
    warn "auth/ permissions: $PERMS (attendu 700)"
  fi
  for d in "$AUTH_BASE"/*/; do
    [ -d "$d" ] || continue
    P=$(stat -c '%a' "$d" 2>/dev/null)
    if [ "$P" = "700" ]; then
      pass "auth/$(basename "$d")/ permissions: 700"
    else
      warn "auth/$(basename "$d")/ permissions: $P (attendu 700)"
    fi
  done
else
  warn "auth/ inexistant — aucune session WhatsApp encore créée"
fi

# -----------------------------------------------------------------------------
# 7. Port daemon en écoute
# -----------------------------------------------------------------------------
echo
echo "── 7. Port daemon ──"
if command -v ss >/dev/null 2>&1; then
  LISTEN=$(ss -tlnp 2>/dev/null | grep ":$DAEMON_PORT ")
elif command -v netstat >/dev/null 2>&1; then
  LISTEN=$(netstat -tlnp 2>/dev/null | grep ":$DAEMON_PORT ")
else
  LISTEN=""
fi
if [ -n "$LISTEN" ]; then
  if echo "$LISTEN" | grep -q "127.0.0.1:$DAEMON_PORT"; then
    pass "Port $DAEMON_PORT en écoute sur 127.0.0.1 (bind sécurisé)"
  else
    warn "Port $DAEMON_PORT en écoute mais pas restreint à 127.0.0.1"
    echo "$LISTEN" | sed 's/^/       /'
  fi
else
  warn "Port $DAEMON_PORT pas en écoute"
fi

# -----------------------------------------------------------------------------
# 8. Test ping daemon HTTP
# -----------------------------------------------------------------------------
echo
echo "── 8. Connectivité daemon HTTP ──"
if command -v curl >/dev/null 2>&1; then
  HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
               -H "Content-Type: application/json" \
               -d '{"action":"getStatus","instance_id":0}' \
               --max-time 5 \
               "http://127.0.0.1:$DAEMON_PORT/action" 2>/dev/null)
  case "$HTTP_CODE" in
    200) pass "Daemon HTTP répond 200 sur /action" ;;
    500) pass "Daemon HTTP répond (500 sur instance_id=0 attendu)" ;;
    000) warn "Daemon HTTP injoignable" ;;
    *)   warn "Daemon HTTP répond $HTTP_CODE (inhabituel)" ;;
  esac
else
  warn "curl introuvable — saut du test HTTP"
fi

# -----------------------------------------------------------------------------
# 9. Test callback.php — IP locale acceptée, IP externe rejetée
# -----------------------------------------------------------------------------
echo
echo "── 9. Callback.php — sécurité (F-003) ──"
if command -v curl >/dev/null 2>&1; then
  # Test depuis 127.0.0.1 sans Content-Type → 415 attendu (F-010)
  CB=$(curl -s -o /dev/null -w "%{http_code}" -X POST --max-time 3 \
       "http://127.0.0.1/plugins/jeewhatsapp/core/php/callback.php" 2>/dev/null)
  case "$CB" in
    415) pass "callback.php exige Content-Type JSON (F-010 OK)" ;;
    403) pass "callback.php refuse sans clé (403)" ;;
    000) warn "callback.php injoignable — Jeedom HTTP down ?" ;;
    *)   warn "callback.php répond $CB (attendu 415 ou 403)" ;;
  esac
else
  warn "curl introuvable — saut test callback"
fi

# -----------------------------------------------------------------------------
# 10. Erreurs récentes dans le log
# -----------------------------------------------------------------------------
echo
echo "── 10. Log Jeedom — erreurs récentes ──"
if [ -f "$JEEDOM_LOG" ]; then
  ERRORS=$(tail -n 200 "$JEEDOM_LOG" 2>/dev/null | grep -cE '\[ERROR\]|Erreur')
  WARNINGS=$(tail -n 200 "$JEEDOM_LOG" 2>/dev/null | grep -cE '\[WARN(ING)?\]')
  BADMAC=$(tail -n 200 "$JEEDOM_LOG" 2>/dev/null | grep -c 'Bad MAC')
  if [ "$ERRORS" -eq 0 ]; then
    pass "Pas d'erreur dans les 200 dernières lignes du log"
  else
    warn "$ERRORS erreur(s) dans les 200 dernières lignes du log"
    tail -n 200 "$JEEDOM_LOG" 2>/dev/null | grep -E '\[ERROR\]|Erreur' | tail -3 | sed 's/^/       /'
  fi
  if [ "$BADMAC" -gt 0 ]; then
    warn "$BADMAC 'Bad MAC' (libsignal) — normal après reconnexion, à surveiller si excessif"
  fi
  echo "       (warnings : $WARNINGS)"
else
  warn "Log $JEEDOM_LOG inexistant"
fi

# -----------------------------------------------------------------------------
# Récap
# -----------------------------------------------------------------------------
echo
echo "=== SUMMARY ==="
echo "PASS=$PASS  WARN=$WARN  FAIL=$FAIL"

if [ "$FAIL" -gt 0 ]; then exit 1
elif [ "$WARN" -gt 0 ]; then exit 2
else exit 0
fi
