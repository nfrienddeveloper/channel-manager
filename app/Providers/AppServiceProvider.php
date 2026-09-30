<?php

namespace App\Providers;

use App\Services\Content\Writers\AnthropicApiWriter;
use App\Services\Content\Writers\ClaudeCliWriter;
use App\Services\Content\Writers\ScriptWriter;
use App\Services\Content\Writers\TemplateWriter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ScriptWriter::class, fn () => match (config('channels.writer.driver')) {
            'anthropic' => new AnthropicApiWriter,
            'template' => new TemplateWriter,
            default => new ClaudeCliWriter,
        });
    }

    public function boot(): void
    {
        //
    }
}
