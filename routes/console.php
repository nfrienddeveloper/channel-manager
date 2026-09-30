<?php

use Illuminate\Support\Facades\Schedule;

// The autopilot. Inside the desktop app NativePHP runs the scheduler every minute.
Schedule::command('channels:tick')->everyFiveMinutes()->withoutOverlapping(30);
Schedule::command('queue:prune-failed --hours=168')->daily();
