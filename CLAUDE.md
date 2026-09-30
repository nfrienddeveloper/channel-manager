# Channel Manager

A Laravel 13 app packaged as a desktop app with NativePHP (Electron). It plans, produces and runs social video channels (Facebook, TikTok, Instagram, YouTube, X and others) on autopilot, from the user's own computer.

- PHP 8.4, SQLite, database queue, Laravel scheduler (NativePHP runs `schedule:run` and the queue workers inside the desktop app).
- Run in the browser for development: `composer setup`, then `php artisan serve` and `php artisan queue:work`. Run as a desktop app: `composer native:dev`.
- Tests: `php artisan test`. Style: `vendor/bin/pint`.
- Secrets (platform tokens, API keys) are stored encrypted in the database or read from the OS keychain. Never commit them, log them, or put them in `.env.example`.
