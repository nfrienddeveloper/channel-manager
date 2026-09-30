<?php

namespace App\Services\Content\Writers;

use App\Models\ContentItem;
use Illuminate\Support\Str;

/** No-AI writer for tests and offline runs: reads out the headlines it found. Not meant for a real channel. */
class TemplateWriter implements ScriptWriter
{
    public function write(ContentItem $item, array $research): array
    {
        $notes = collect($research['notes'] ?? [])->filter(fn ($n) => $n['title'] && $n['source'] !== 'Wikipedia')->take(3)->values();
        $image = $research['images'][0]['id'] ?? null;
        $scenes = [['type' => 'hook', 'kicker' => 'Trending now', 'text' => Str::limit($item->topic, 38), 'image' => $image, 'vo' => "Everyone is talking about {$item->topic}. Here's the story."]];
        foreach ($notes as $i => $n) {
            $scenes[] = ['type' => 'hook', 'kicker' => $n['source'] ?: 'Headline', 'text' => Str::limit($n['title'], 40), 'vo' => Str::limit($n['title'], 140, '').'.'];
        }
        $scenes[] = ['type' => 'cta', 'vo' => 'Follow for tomorrow\'s trends.'];

        return [
            'decision' => $notes->isEmpty() ? 'skip' : 'make',
            'reason' => $notes->isEmpty() ? 'No headlines to report' : 'Template script',
            'category' => 'other',
            'headline' => $item->topic,
            'scenes' => $scenes,
            'caption' => "{$item->topic} is trending today. What do you make of it?",
            'hashtags' => [Str::studly($item->topic)],
            'sources' => $notes->pluck('url')->all(),
        ];
    }
}
