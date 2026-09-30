# Channel Manager

A Laravel 13 app packaged as a desktop app with NativePHP (Electron). It plans, produces and runs social video channels (Facebook first; TikTok, Instagram, YouTube, X and others later) on autopilot, from the user's own computer. See README.md for the pipeline.

- PHP 8.4, SQLite, database queue (`media` queue for research/script/render, `default` for publishing), Laravel scheduler runs `channels:tick` every 5 minutes. NativePHP starts the scheduler and both queue workers inside the desktop app.
- Dev: `composer autopilot` (browser) or `composer native:dev` (desktop). Tests: `php artisan test`. Style: `vendor/bin/pint`.
- `media-engine/` is a copy of the social-video-maker renderer (Node + Playwright + FFmpeg + TTS). Keep changes to it in step with that repo.
- Channels start in dry run. Nothing may post to a real platform unless the channel's `live` flag is on; keep that check in `PublisherResolver` and `PublishContentItem`.
- Scripts may only state facts from the item's research and must cite research URLs; `ScriptBuilder` enforces this. Don't loosen it.
- Secrets (platform tokens, API keys) are stored with the `encrypted` cast or read from the OS keychain. Never commit them, log them, or put them in `.env.example`.
