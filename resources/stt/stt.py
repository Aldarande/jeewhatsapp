#!/usr/bin/env python3
# stt.py — transcription d'une note vocale via Vosk (offline, local).
# Usage : stt.py <fichier_audio> [chemin_modele]
#   - convertit l'audio en PCM 16 kHz mono via ffmpeg (pipe)
#   - transcrit avec le modèle Vosk
#   - imprime le texte reconnu sur stdout (chaîne vide si rien)
# Variables d'env : STT_MODEL (chemin du modèle, défaut ./model-fr)
import sys
import os
import json
import subprocess

def log(msg):
    print(msg, file=sys.stderr)

def main():
    if len(sys.argv) < 2:
        log("usage: stt.py <audio> [model]")
        return 2
    audio = sys.argv[1]
    here = os.path.dirname(os.path.abspath(__file__))
    model_path = sys.argv[2] if len(sys.argv) > 2 else os.environ.get(
        "STT_MODEL", os.path.join(here, "model-fr"))

    if not os.path.isfile(audio):
        log("audio introuvable: %s" % audio)
        return 3
    if not os.path.isdir(model_path):
        log("modele introuvable: %s" % model_path)
        return 4

    try:
        from vosk import Model, KaldiRecognizer, SetLogLevel
    except Exception as e:
        log("vosk indisponible: %s" % e)
        return 5

    SetLogLevel(-1)  # silence vosk/kaldi

    # ffmpeg : audio quelconque -> PCM s16le 16kHz mono sur stdout
    cmd = ["ffmpeg", "-nostdin", "-loglevel", "quiet", "-i", audio,
           "-ar", "16000", "-ac", "1", "-f", "s16le", "-"]
    try:
        proc = subprocess.Popen(cmd, stdout=subprocess.PIPE)
    except FileNotFoundError:
        log("ffmpeg introuvable")
        return 6

    model = Model(model_path)
    rec = KaldiRecognizer(model, 16000)
    rec.SetWords(False)

    results = []
    while True:
        data = proc.stdout.read(4000)
        if len(data) == 0:
            break
        if rec.AcceptWaveform(data):
            results.append(json.loads(rec.Result()).get("text", ""))
    results.append(json.loads(rec.FinalResult()).get("text", ""))
    proc.wait()

    text = " ".join(p for p in results if p).strip()
    print(text)
    return 0

if __name__ == "__main__":
    sys.exit(main())
