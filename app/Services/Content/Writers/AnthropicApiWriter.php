<?php

namespace App\Services\Content\Writers;

use App\Models\ContentItem;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Writes scripts with the Claude API (needs ANTHROPIC_API_KEY; billed per use). */
class AnthropicApiWriter implements ScriptWriter
{
    public function write(ContentItem $item, array $research): array
    {
        $cfg = config('channels.writer');
        if (! $cfg['anthropic_key']) {
            throw new RuntimeException('ANTHROPIC_API_KEY is not set');
        }
        $res = Http::withHeaders(['x-api-key' => $cfg['anthropic_key'], 'anthropic-version' => '2023-06-01'])
            ->timeout($cfg['timeout'])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $cfg['model'],
                'max_tokens' => 4000,
                'tools' => [['name' => 'submit_script', 'description' => 'Submit the video script', 'input_schema' => ScriptPrompt::schema()]],
                'tool_choice' => ['type' => 'tool', 'name' => 'submit_script'],
                'messages' => [['role' => 'user', 'content' => ScriptPrompt::build($item, $research)]],
            ]);
        if (! $res->successful()) {
            throw new RuntimeException("Claude API HTTP {$res->status()}: ".mb_substr($res->body(), 0, 300));
        }
        $block = collect($res->json('content'))->firstWhere('type', 'tool_use');
        if (! $block) {
            throw new RuntimeException('Claude API returned no script');
        }

        return $block['input'];
    }
}
