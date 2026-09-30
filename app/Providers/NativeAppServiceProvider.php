<?php

namespace App\Providers;

use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * The scheduler (the channel autopilot) and the queue workers start with the app.
     */
    public function boot(): void
    {
        Window::open()
            ->title('Channel Manager')
            ->width(1280)
            ->height(860)
            ->minWidth(900)
            ->minHeight(600);
    }

    public function phpIni(): array
    {
        return [
            'memory_limit' => '512M',
            'max_execution_time' => '0',
        ];
    }
}
