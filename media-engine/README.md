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

## Themes

`brand.json` can name a `theme` from `config/themes.json`. The theme picks the HTML template and supplies default colours and fonts; anything set in `brand.json` wins.

- `default`: the original look (`templates/player.html`).
- `unearthed`: museum documentary for the Bible evidence channel (`templates/unearthed.html`). Obsidian and gold, Cormorant Garamond headlines, catalogue labels in IBM Plex Mono, film grain, slow push-ins, dips through black, a 0.9 s intro sting and a numbered end card. Captions have no box; the current word is gold.

Unearthed adds two scene types and one bit of markup:

- `artifact`: a museum placard. `title`, `rows` (2 to 4 `{label, value}`), optional `ref` (a Bible passage), `kicker`, `image`.
- `keyline`: one inscription line, enlarged. `text` (its meaning), optional `translit`, `script` (original letters, drawn in the theme's `script` font), `kicker`, `image`.
- `*words*` in a headline, stat label or quote are set in gold italics with a light sweep.

Set `episode` on the storyboard and `series` in `brand.json` (for example `"Artifact No."`) to number the videos on screen.
