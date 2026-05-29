#!/bin/bash
# tts.sh — synthèse vocale Piper → note vocale Opus (.ogg) pour WhatsApp PTT
# Usage : tts.sh "<texte>" "<chemin_sortie.ogg>" [modele.onnx]
# Variables d'env : PIPER_DIR (racine resources/piper), TTS_VOICE (chemin .onnx)
set -e
PIPER_DIR="${PIPER_DIR:-$(dirname "$0")}"
TEXT="$1"
OUT="$2"
MODEL="${3:-${TTS_VOICE:-$PIPER_DIR/voices/fr_FR-siwis-medium.onnx}}"

if [ -z "$TEXT" ] || [ -z "$OUT" ]; then
  echo "usage: tts.sh <texte> <sortie.ogg> [modele.onnx]" >&2
  exit 2
fi
if [ ! -f "$MODEL" ]; then
  echo "modele introuvable: $MODEL" >&2
  exit 3
fi

WAV="$(mktemp --suffix=.wav)"
trap 'rm -f "$WAV"' EXIT

printf '%s' "$TEXT" | LD_LIBRARY_PATH="$PIPER_DIR/piper" "$PIPER_DIR/piper/piper" \
  --model "$MODEL" --output_file "$WAV" >/dev/null 2>&1

ffmpeg -y -i "$WAV" -c:a libopus -b:a 32k -ar 48000 -ac 1 "$OUT" >/dev/null 2>&1

[ -s "$OUT" ] || { echo "sortie vide" >&2; exit 4; }
echo "ok"
