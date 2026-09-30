<?php

namespace App\Services\Content\Writers;

use App\Models\ContentItem;
use App\Services\Content\SafetyFilter;

/** The brief and output format shared by the AI script writers. */
class ScriptPrompt
{
    public const CATEGORIES = ['entertainment', 'sports', 'technology', 'science', 'business', 'lifestyle', 'weather', 'gaming', 'culture',
        'politics', 'crime', 'tragedy', 'health', 'adult', 'other'];

    public static function build(ContentItem $item, array $research): string
    {
        $channel = $item->channel;
        $brand = $channel->setting('brand');
        $avoid = collect($channel->setting('avoid', []))->map(fn ($c) => "- {$c}: ".(SafetyFilter::CATEGORIES[$c] ?? $c))->implode("\n");
        $maxSeconds = (int) $channel->setting('max_seconds', 35);
        $maxWords = (int) round($maxSeconds * 2.5);
        $elevenlabs = in_array($channel->setting('voice.engine'), ['auto', 'elevenlabs'], true);

        $notes = collect($research['notes'] ?? [])->map(function ($n, $i) {
            $text = $n['text'] ? "\n".mb_substr($n['text'], 0, 1800) : ' (headline only)';

            return '['.($i + 1)."] {$n['title']} | {$n['source']} | {$n['url']}{$text}";
        })->implode("\n\n");
        $images = collect($research['images'] ?? [])->map(fn ($img) => "- {$img['id']}: {$img['description']}")->implode("\n") ?: '(none: use scene types that need no image)';
        $learnings = $item->channel->learningsSummary();

        $tags = $elevenlabs ? <<<'TXT'
The voice is ElevenLabs v3. You may put one audio tag in square brackets right before the words it colours, where the mood shifts:
[excited], [curious], [confident], [warmly], [serious], [short pause]. At most one tag per scene; none is fine.
TXT : 'Do not use any [tags] in the voiceover.';

        return <<<PROMPT
You write short vertical videos (Facebook Reels) for the page "{$brand['name']}" ({$brand['tagline']}).
Tone: {$channel->setting('tone')}.

Today's trending topic: "{$item->topic}"

Research (the ONLY facts you may use; every claim must come from these, and cite the ones you use):
{$notes}

Photos you may use (freely licensed; reference by id; they are generic, so never claim a photo shows the actual event or person unless its description says so):
{$images}

What has worked on this page so far:
{$learnings}

First decide whether to make this video. Choose "skip" when:
- the topic is about any of these, which this page does not cover:
{$avoid}
- the research is too thin to say three accurate, interesting things, or the sources contradict each other
- it is a scheduled event with nothing to explain yet, or you can't tell what the trend is actually about.

If you make it, write {$maxSeconds} seconds or less (at most {$maxWords} spoken words in total), 4 to 6 scenes:
1. "hook" first: a scroll-stopping line (up to 40 characters on screen) that makes people want to know what is going on. No clickbait lies.
2. Then 2 to 4 scenes that explain what happened, why people care, and one surprising detail. Scene types:
   - "image": text (up to 45 chars) over a photo; needs "image" (a photo id)
   - "split": headline on brand colour with a photo; needs "image"; optional "sub"
   - "stat": one real number from the research: "value" is the number only, at most 6 characters (like "655", "10K+", "3x"; units and words go in the label), "label" (up to 50 chars), optional "kicker"
   - "list": "title" and 2 to 4 short "items" (up to 24 chars each)
   - "quote": a real quote from the research, "text" (up to 110 chars) and "author"
   - "hook" can also be used mid-video as a bold text card: "text", optional "kicker", optional "image"
3. "cta" last: "vo" only (the on-screen follow button is added for you), for example inviting people to follow for tomorrow's trends or to comment their take.
Every scene has "vo": the words spoken over it, natural and conversational, written to be heard, not read. The on-screen text should not simply repeat the voiceover.
{$tags}

Then write the Facebook caption: 1 to 3 short sentences that add context and end with a question that invites comments, no hashtags in it,
plus up to 3 relevant hashtags (without #). List the research URLs you actually used in "sources". Never invent numbers, quotes, dates or names.

Return JSON only, matching the schema.
PROMPT;
    }

    public static function schema(): array
    {
        $str = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'decision' => ['type' => 'string', 'enum' => ['make', 'skip']],
                'reason' => $str,
                'category' => ['type' => 'string', 'enum' => self::CATEGORIES],
                'headline' => $str,
                'scenes' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['hook', 'image', 'split', 'stat', 'list', 'quote', 'cta']],
                        'text' => $str, 'kicker' => $str, 'sub' => $str, 'image' => $str, 'value' => $str, 'label' => $str,
                        'title' => $str, 'items' => ['type' => 'array', 'items' => $str], 'author' => $str, 'vo' => $str,
                    ],
                    'required' => ['type', 'vo'],
                ]],
                'caption' => $str,
                'hashtags' => ['type' => 'array', 'items' => $str],
                'sources' => ['type' => 'array', 'items' => $str],
            ],
            'required' => ['decision', 'reason', 'category'],
        ];
    }
}
