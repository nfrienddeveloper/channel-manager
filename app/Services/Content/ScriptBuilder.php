<?php

namespace App\Services\Content;

use App\Models\ContentItem;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Checks a writer's script and turns it into a media-engine storyboard.
 * Fixes what can be fixed (lengths, missing photos, the CTA) and rejects the rest.
 */
class ScriptBuilder
{
    private const LIMITS = ['text' => 60, 'kicker' => 28, 'sub' => 70, 'label' => 60, 'title' => 40, 'author' => 40, 'value' => 8];

    public function __construct(private SafetyFilter $safety) {}

    /**
     * @return array{skip: ?string, category: string, storyboard: array, caption: string, sources: array, credits: array}
     */
    public function build(ContentItem $item, array $script, array $research): array
    {
        $channel = $item->channel;
        $category = $script['category'] ?? 'other';
        $avoid = $channel->setting('avoid', []);

        if (($script['decision'] ?? 'skip') !== 'make') {
            return $this->skipped($category, $script['reason'] ?? 'Writer chose to skip');
        }
        if (in_array($category, $avoid, true)) {
            return $this->skipped($category, "Topic is {$category}, which this channel avoids");
        }
        $spoken = collect($script['scenes'] ?? [])->pluck('vo')->implode(' ');
        if ($flags = $this->safety->flags($spoken.' '.($script['caption'] ?? ''), $avoid)) {
            return $this->skipped($category, 'Script touches '.implode(', ', $flags));
        }

        $images = collect($research['images'] ?? [])->keyBy('id');
        $known = collect($research['notes'] ?? [])->pluck('url')->all();
        $sources = array_values(array_intersect($script['sources'] ?? [], $known));
        if (! $sources) {
            throw new InvalidArgumentException('Script cites none of the research sources');
        }

        $scenes = [];
        $used = [];
        foreach ($script['scenes'] ?? [] as $s) {
            $type = $s['type'] ?? null;
            if ($type === 'cta') {
                continue;
            }
            $scene = array_filter(array_intersect_key($s, array_flip(['type', 'text', 'kicker', 'sub', 'value', 'label', 'title', 'items', 'author', 'vo'])), fn ($v) => $v !== '' && $v !== null);
            foreach (self::LIMITS as $k => $max) {
                if (isset($scene[$k])) {
                    $scene[$k] = Str::limit(trim($scene[$k]), $max);
                }
            }
            $image = $s['image'] ?? null;
            if ($image && $images->has($image)) {
                $scene['image'] = $images[$image]['file'];
                $used[$image] = true;
            } elseif (in_array($type, ['image', 'split'], true)) {
                // No usable photo: show the line as a bold text card instead.
                $scene['type'] = 'hook';
            }
            if ($scene['type'] === 'list') {
                $scene['items'] = collect($scene['items'] ?? [])->map(fn ($i) => Str::limit($i, 26))->take(4)->values()->all();
                if (count($scene['items']) < 2 || empty($scene['title'])) {
                    continue;
                }
            }
            if ($scene['type'] === 'stat' && (empty($scene['value']) || empty($scene['label']))) {
                continue;
            }
            if ($scene['type'] === 'quote' && empty($scene['text'])) {
                continue;
            }
            if (in_array($scene['type'], ['hook', 'image', 'split'], true) && empty($scene['text'])) {
                continue;
            }
            $scenes[] = $scene;
        }
        if (count($scenes) < 2) {
            throw new InvalidArgumentException('Script has fewer than 2 usable scenes');
        }
        $scenes[0]['type'] = in_array($scenes[0]['type'], ['hook', 'image', 'split'], true) ? 'hook' : $scenes[0]['type'];

        $brand = $channel->setting('brand');
        $ctaVo = collect($script['scenes'] ?? [])->firstWhere('type', 'cta')['vo'] ?? $brand['cta'];
        $scenes[] = ['type' => 'cta', 'text' => Str::limit($brand['cta'], 40), 'button' => 'Follow', 'url' => $brand['name'], 'vo' => $ctaVo];

        $voice = $channel->setting('voice');
        $storyboard = [
            'id' => 'v'.$item->id,
            'title' => Str::limit($script['headline'] ?? $item->topic, 80),
            'maxSeconds' => (int) $channel->setting('max_seconds', 35),
            'aspects' => [$channel->setting('aspect', '9x16')],
            'captions' => true,
            'voice' => array_filter(['engine' => $voice['engine'] ?? 'auto', 'voice' => $voice['voice'] ?? null, 'fallbackVoice' => $voice['fallback'] ?? null]),
            'scenes' => $scenes,
        ];

        $hashtags = collect([...($script['hashtags'] ?? []), ...$channel->setting('hashtags', [])])
            ->map(fn ($h) => '#'.preg_replace('/[^\p{L}\p{N}_]/u', '', ltrim($h, '#')))->filter(fn ($h) => strlen($h) > 1)->unique()->take(4);
        $credits = collect($research['images'] ?? [])->filter(fn ($i) => isset($used[$i['id']]))->map(fn ($i) => $i['credit'])->values()->all();
        $caption = trim(Str::limit(trim($script['caption'] ?? $item->topic), 1500))
            ."\n\n".$hashtags->implode(' ')
            .($credits ? "\n\nPhotos: ".implode('; ', $credits) : '')
            ."\nSources: ".collect($research['notes'])->whereIn('url', $sources)->map(fn ($n) => $n['source'] ?: parse_url($n['url'], PHP_URL_HOST))->unique()->take(4)->implode(', ');

        return ['skip' => null, 'category' => $category, 'storyboard' => $storyboard, 'caption' => $caption, 'sources' => $sources, 'credits' => $credits];
    }

    private function skipped(string $category, string $reason): array
    {
        return ['skip' => $reason, 'category' => $category, 'storyboard' => [], 'caption' => '', 'sources' => [], 'credits' => []];
    }
}
