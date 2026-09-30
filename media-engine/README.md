# Media engine

Renders storyboard JSON into finished MP4s: voiceover (ElevenLabs, or the free Kokoro voice), word-timed captions, HTML motion graphics rendered frame by frame in Chromium, and an FFmpeg encode with loudness normalisation.

It comes from [social-video-maker](https://github.com/nfrienddeveloper/social-video-maker) (`elevenlabs-voice` branch at `faaa45a`). The storyboard format is documented there in `skills/storyboard/reference.md`.

```bash
cd media-engine
npm run setup     # Node deps, Chromium, Kokoro voice model, bundled ffmpeg
npm run check
node scripts/render.mjs <dir>/ads/<id>/storyboard.json --aspects 9x16
```

The ElevenLabs key is read from `ELEVENLABS_API_KEY`, the macOS Keychain item `svm-elevenlabs`, or `~/.social-video-maker/elevenlabs.key`. Models and the voice cache live in `~/.social-video-maker/` (override with `SVM_HOME`), so an existing social-video-maker install is reused.
