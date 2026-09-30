# Channel Manager

A desktop app (Laravel 13 + NativePHP/Electron) that plans, produces and runs social video channels on autopilot, from your own computer: it finds what to post, writes and voices the videos, publishes them, and learns from how they perform.

**First channel type: trending topics on a Facebook Page.** Every few minutes the autopilot:

1. **Collects trends** from free sources: Google Trends (US "trending now"), Google News top stories, Reddit r/popular and Wikipedia's most-read pages. Stories reported by several sources are merged and ranked higher.
2. **Picks a topic** for each upcoming post time (3 a day by default) that the channel has not covered and that passes its safety rules. By default it never covers deaths and violence, crime, politics, health claims or adult content; each channel can change that, and can narrow itself to a niche with keywords.
3. **Researches** the story: reads the linked articles and the Wikipedia summary, and finds freely licensed photos (Openverse, commercial-use licences, credited in the caption).
4. **Writes the script** with Claude: a 20 to 35 second vertical video (hook, 2 to 4 explainer scenes, follow call to action) plus the post caption. Claude may only use facts from the research, must cite the sources it used, and can decide to skip a topic. The app checks the script again before rendering.
5. **Renders** the video with the bundled media engine: ElevenLabs voice (the "Adam" voice by default, with emotion tags) or the free Kokoro voice, word-timed captions, branded motion graphics, 1080x1920 MP4.
6. **Publishes** it as a Facebook Reel at its time slot. Until the channel is switched live, it runs in **dry run** and saves the video and caption to `storage/app/channels/<id>/outbox/` instead.
7. **Reads the numbers** (plays, likes, comments) for a week after posting and feeds the best and weakest performers back into the script writer.

Missed slots (the computer was asleep) are dropped after 6 hours rather than posting stale news, failed steps retry up to 3 times, and everything the autopilot does is listed in the channel's Activity log.

## Setup (macOS)

Requires PHP 8.4+, Composer, Node 20+, Python 3.10+, and [Claude Code](https://claude.com/claude-code) signed in (the default script writer runs `claude -p` on your subscription).

```bash
composer setup          # PHP and JS deps, .env, SQLite database, and the media engine (Chromium, Kokoro voice, ffmpeg)
```

ElevenLabs voice: the media engine reads the key from the macOS Keychain item `svm-elevenlabs` (same as social-video-maker), or `ELEVENLABS_API_KEY`. `cd media-engine && npm run check` shows whether it works.

## Run

```bash
composer native:dev     # desktop app; the autopilot and queue workers run inside it
# or in the browser at http://127.0.0.1:8000:
composer autopilot      # web server + queue worker + scheduler
```

The autopilot only runs while the app (or `composer autopilot`) is running and the computer is awake.

### Connect the Facebook Page

1. Create a Meta app at [developers.facebook.com/apps](https://developers.facebook.com/apps) with the use case "Manage everything on your Page". You are its admin, so it can post to your own Pages without App Review.
2. In the [Graph API Explorer](https://developers.facebook.com/tools/explorer), pick the app, add `pages_show_list`, `pages_read_engagement`, `pages_manage_posts` and `read_insights`, click Generate Access Token and choose your Page.
3. In the app, open **Accounts**, paste the token plus the App ID and App secret (so the Page token never expires), and choose the Page. Or run `php artisan facebook:connect`.

Tokens are stored encrypted in the local database with the app key. They are never logged.

### Start a channel

In the app: **New channel**, then **Start autopilot**. It runs in dry run: check the videos in the channel page (or the outbox folder). **Make one now** produces a video immediately. When you are happy, link the Page in Settings and press **Go live**.

From the command line:

```bash
php artisan trends:collect                  # see what is trending and how it ranks
php artisan channels:create "Trend Brief" --active
php artisan channels:make-now 1             # make one video right now (add --publish to post or save to the outbox)
php artisan channels:tick                   # one autopilot pass (the scheduler runs this every 5 minutes)
```

## Settings worth knowing

`.env`: `CHANNELS_TIMEZONE` (default for new channels), `CHANNELS_WRITER` (`claude_cli`, `anthropic` with `ANTHROPIC_API_KEY`, or `template` for tests), `CHANNELS_WRITER_MODEL`, `CLAUDE_BIN` and `NODE_BIN` if they are not on the PATH the app sees, `FACEBOOK_APP_ID`/`FACEBOOK_APP_SECRET`.

Per channel (Settings on the channel page): posts per day and times, timezone, trend country, topics to avoid, keyword include/exclude lists, tone, brand name, colours, closing call to action, hashtags, voice and maximum length.

## Layout

- `app/Services/Trends`: trend sources and the collector that merges and scores them.
- `app/Services/Content`: topic picker, safety filter, researcher, script writers and the script checker, and the producer that runs them in order.
- `app/Services/Media/VideoRenderer.php` and `media-engine/`: the renderer (from [social-video-maker](https://github.com/nfrienddeveloper/social-video-maker)).
- `app/Services/Platforms`: publishers. Facebook Pages today; the channel model already has TikTok, Instagram, YouTube, X and LinkedIn slots for the next publishers.
- `app/Services/Channels/Autopilot.php`: the loop described above. `routes/console.php` schedules it.

## Limits today

- Facebook allows 30 API-published Reels per Page per 24 hours.
- Openverse photos are generic (a basketball court, not the actual player), so videos lean on bold text scenes; the script writer is told never to claim a photo shows the real event. Licensed news imagery or generated footage would be a paid upgrade.
- Reddit sometimes refuses anonymous requests; the other sources carry on without it.
- Packaging with `php artisan native:build` does not yet bundle the media engine's Node modules and Chromium, so run it from source for now.

Tests: `php artisan test` (no network, Chromium or AI needed).
