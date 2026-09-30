#!/usr/bin/env python3
"""Voiceover. Reads a JSON job list and writes one WAV per line.

Usage: python3 tts.py jobs.json
jobs.json: {"engine": "auto|elevenlabs|kokoro|espeak|none", "voice": "...", "speed": 1.0,
            "model": "eleven_multilingual_v2", "lines": [{"id": "s1", "text": "...", "out": "/abs/path.wav"}]}
Prints JSON: {"engine": "...", "durations": {"s1": 2.4}, "words": {"s1": [{"text": "Hi", "start": 0.0, "end": 0.3}]}}

Engines, best first:
- ElevenLabs (paid, natural voices, exact word timings for captions). Used when an API key is set:
  ELEVENLABS_API_KEY, the macOS Keychain item "svm-elevenlabs", or the file ~/.social-video-maker/elevenlabs.key.
- Kokoro (free open-source model, runs locally on CPU).
- espeak-ng (robotic, last resort).
- none (silent; captions still work).
If ElevenLabs fails (no credits, network), the job falls back to the next engine and says so on stderr.
"""
import base64
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import wave

HOME = os.environ.get("SVM_HOME", os.path.join(os.path.expanduser("~"), ".social-video-maker"))
MODEL = os.path.join(HOME, "models", "kokoro-v1.0.onnx")
VOICES = os.path.join(HOME, "models", "voices-v1.0.bin")
KEY_FILE = os.path.join(HOME, "elevenlabs.key")
CACHE = os.path.join(HOME, "tts-cache")
EL_API = os.environ.get("ELEVENLABS_API_URL", "https://api.elevenlabs.io")
EL_DEFAULT_VOICE = "EXAVITQu4vr4xnSDxMaL"  # "Sarah", a premade ElevenLabs voice
EL_DEFAULT_MODEL = "eleven_multilingual_v2"
# Premade voices by name, so keys without Voices read access can still pick one.
EL_PREMADE = {"sarah": "EXAVITQu4vr4xnSDxMaL", "rachel": "21m00Tcm4TlvDq8ikWAM", "adam": "pNInz6obpgDQGcFmaJgB",
              "george": "JBFqnCBsd6RMkjVDRZzb", "charlotte": "XB0fDUnXU5powFXDhCwa", "brian": "nPczCjzI2devNBz1zQrb",
              "alice": "Xb7hH8MSUJpSbSDYk0k2", "daniel": "onwK4e9ZLuTAKqWW03F9", "lily": "pFZP5JQG7iQjIQuC4Bku"}
TAG = re.compile(r"\[[^\]]*\]")          # ElevenLabs v3 audio tags, e.g. [excited], [whispers]
BREAK = re.compile(r"<break[^>]*/?>", re.I)  # SSML pauses, e.g. <break time="0.5s" />
KOKORO_VOICES = {"af_heart", "af_bella", "af_nicole", "am_michael", "am_fenrir", "bf_emma", "bm_george"}


def log(*a):
    print("[tts]", *a, file=sys.stderr)


def wav_seconds(path):
    with wave.open(path) as w:
        return w.getnframes() / float(w.getframerate())


def spoken(text):
    """Text with audio tags and pause markup removed: what a plain TTS engine should read and captions show."""
    return re.sub(r"\s+", " ", BREAK.sub(" ", TAG.sub(" ", text))).strip()


def ffmpeg():
    exe = os.environ.get("FFMPEG") or shutil.which("ffmpeg")
    if exe:
        return exe
    import imageio_ffmpeg
    return imageio_ffmpeg.get_ffmpeg_exe()


# ---------- ElevenLabs ----------

def elevenlabs_key():
    key = os.environ.get("ELEVENLABS_API_KEY", "").strip()
    if not key and sys.platform == "darwin" and shutil.which("security"):
        # Keychain: security add-generic-password -a "$USER" -s svm-elevenlabs -w
        r = subprocess.run(["security", "find-generic-password", "-s", "svm-elevenlabs", "-w"], capture_output=True, text=True)
        key = r.stdout.strip() if r.returncode == 0 else ""
    if not key and os.path.exists(KEY_FILE):
        key = open(KEY_FILE).read().strip()
    return key or None


def el_request(path, key, body=None):
    req = urllib.request.Request(EL_API + path, method="POST" if body is not None else "GET",
                                 headers={"xi-api-key": key, "content-type": "application/json", "accept": "application/json"},
                                 data=json.dumps(body).encode() if body is not None else None)
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        detail = e.read().decode(errors="replace")[:300]
        raise RuntimeError(f"ElevenLabs HTTP {e.code}: {detail}") from None


def el_voice_id(voice, key):
    """Accepts a voice ID or a voice name from the account's voice list."""
    if not voice or voice in KOKORO_VOICES:
        return EL_DEFAULT_VOICE
    if len(voice) >= 20 and voice.isalnum():
        return voice
    if voice.lower() in EL_PREMADE:
        return EL_PREMADE[voice.lower()]
    voices = el_request("/v1/voices", key).get("voices", [])
    for v in voices:
        if v.get("name", "").lower() == voice.lower() or v.get("name", "").lower().startswith(voice.lower() + " "):
            return v["voice_id"]
    names = ", ".join(sorted(v.get("name", "") for v in voices))
    raise RuntimeError(f'ElevenLabs voice "{voice}" not found. Available: {names}')


def words_from_alignment(al):
    """Turn per-character timings into per-word timings, leaving out [tags] and <break/> markup."""
    chars = al.get("characters") or []
    starts = al.get("character_start_times_seconds") or []
    ends = al.get("character_end_times_seconds") or []
    words, cur, skip = [], None, None
    for c, s, e in zip(chars, starts, ends):
        if skip:
            if c == skip:
                skip = None
            continue
        if c in "[<":
            skip = "]" if c == "[" else ">"
            c = " "
        if c.isspace():
            if cur:
                words.append(cur)
            cur = None
        elif cur is None:
            cur = {"text": c, "start": s, "end": e}
        else:
            cur["text"] += c
            cur["end"] = e
    if cur:
        words.append(cur)
    return [w for w in words if re.search(r"\w", w["text"])]  # drop bare "..." pauses


def run_elevenlabs(job, key):
    voice_id = el_voice_id(job.get("voice"), key)
    tagged = any(TAG.search(l["text"]) for l in job["lines"])
    # Audio tags ([excited], [warmly], [pause]) only work on Eleven v3, so tagged scripts default to it.
    model = job.get("model") or ("eleven_v3" if tagged else EL_DEFAULT_MODEL)
    v3 = model.startswith("eleven_v3")
    if v3:
        # v3 stability is one of 0.0 (creative, most expressive), 0.5 (natural) or 1.0 (robust).
        settings = {"stability": 0.5}
    else:
        settings = {"stability": 0.45, "similarity_boost": 0.8, "style": 0.15, "use_speaker_boost": True}
        speed = float(job.get("speed") or 1.0)
        if speed != 1.0:
            settings["speed"] = max(0.7, min(1.2, speed))
    settings.update(job.get("voiceSettings") or {})
    if v3:
        settings["stability"] = min((0.0, 0.5, 1.0), key=lambda x: abs(x - float(settings["stability"])))
    fmt = job.get("outputFormat") or "mp3_44100_128"
    os.makedirs(CACHE, exist_ok=True)
    durations, words = {}, {}
    for line in job["lines"]:
        # v3 reads tags but not <break/>; v2 reads <break/> but would speak tags aloud.
        text = BREAK.sub(" ... ", line["text"]) if v3 else TAG.sub(" ", line["text"])
        text = re.sub(r"\s+", " ", text).strip()
        # Cache by everything that changes the audio, so re-renders don't spend credits.
        h = hashlib.sha256(json.dumps([text, voice_id, model, settings, fmt], sort_keys=True).encode()).hexdigest()[:24]
        cached = os.path.join(CACHE, h + ".json")
        if os.path.exists(cached):
            data = json.load(open(cached))
        else:
            body = {"text": text, "model_id": model, "voice_settings": settings}
            try:
                data = el_request(f"/v1/text-to-speech/{voice_id}/with-timestamps?output_format={fmt}", key, body)
            except RuntimeError as e:
                if "output_format" not in str(e) and "subscription" not in str(e).lower():
                    raise
                # Some plans don't allow 44.1 kHz; 24 kHz is still clear for social video.
                log(f"{fmt} not allowed on this plan, using mp3_24000_48")
                fmt = "mp3_24000_48"
                data = el_request(f"/v1/text-to-speech/{voice_id}/with-timestamps?output_format={fmt}", key, body)
            json.dump(data, open(cached, "w"))
        mp3 = line["out"] + ".mp3"
        with open(mp3, "wb") as f:
            f.write(base64.b64decode(data["audio_base64"]))
        subprocess.run([ffmpeg(), "-y", "-loglevel", "error", "-i", mp3, "-ar", "48000", "-ac", "1", line["out"]], check=True)
        os.remove(mp3)
        durations[line["id"]] = wav_seconds(line["out"])
        words[line["id"]] = words_from_alignment(data.get("alignment") or data.get("normalized_alignment") or {})
    return durations, words


# ---------- Kokoro / espeak ----------

def kokoro_available():
    if not (os.path.exists(MODEL) and os.path.exists(VOICES)):
        return False
    try:
        import kokoro_onnx  # noqa: F401
        import soundfile  # noqa: F401
        return True
    except ImportError:
        return False


def run_kokoro(job):
    from kokoro_onnx import Kokoro
    import soundfile as sf

    k = Kokoro(MODEL, VOICES)
    voice = job.get("voice") if job.get("voice") in KOKORO_VOICES else (job.get("fallbackVoice") or "af_heart")
    out = {}
    for line in job["lines"]:
        samples, sr = k.create(spoken(line["text"]), voice=voice, speed=float(job.get("speed") or 1.0), lang=job.get("lang") or "en-us")
        sf.write(line["out"], samples, sr)
        out[line["id"]] = len(samples) / float(sr)
    return out


def run_espeak(job):
    exe = shutil.which("espeak-ng") or shutil.which("espeak")
    out = {}
    for line in job["lines"]:
        subprocess.run([exe, "-v", "en-us", "-s", "165", "-w", line["out"], spoken(line["text"])], check=True)
        out[line["id"]] = wav_seconds(line["out"])
    return out


def main():
    job = json.load(open(sys.argv[1]))
    engine = job.get("engine") or "auto"
    words = {}
    if engine in ("auto", "elevenlabs"):
        key = elevenlabs_key()
        if key:
            try:
                durations, words = run_elevenlabs(job, key)
                print(json.dumps({"engine": "elevenlabs", "durations": durations, "words": words}))
                return
            except Exception as e:  # fall back to the free voice rather than fail the render
                log(f"ElevenLabs failed, falling back to a free voice: {e}")
        elif engine == "elevenlabs":
            log("No ElevenLabs API key found (ELEVENLABS_API_KEY, Keychain item svm-elevenlabs, or ~/.social-video-maker/elevenlabs.key); using a free voice.")
        engine = "auto"
    if engine == "auto":
        if kokoro_available():
            engine = "kokoro"
        elif shutil.which("espeak-ng") or shutil.which("espeak"):
            engine = "espeak"
        else:
            engine = "none"
    if engine == "kokoro":
        durations = run_kokoro(job)
    elif engine == "espeak":
        durations = run_espeak(job)
    else:
        durations = {}
    print(json.dumps({"engine": engine, "durations": durations, "words": words}))


if __name__ == "__main__":
    main()
