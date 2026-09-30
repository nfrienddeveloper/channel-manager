# Channel Manager

A desktop app (Laravel + NativePHP/Electron) that plans, produces and runs social video channels on autopilot: it finds what to post, makes the videos, publishes them, and learns from how they perform.

## Develop

Requires PHP 8.4+, Composer and Node 20+.

```bash
composer setup           # install, create .env and the SQLite database, migrate
composer native:dev      # run as a desktop app
# or in the browser:
php artisan serve & php artisan queue:work
```

Tests: `php artisan test`.
