<?php

namespace App\Services\Trends;

use App\Models\Trend;
use App\Services\Trends\Sources\GoogleNews;
use App\Services\Trends\Sources\GoogleTrends;
use App\Services\Trends\Sources\Reddit;
use App\Services\Trends\Sources\TrendSource;
use App\Services\Trends\Sources\Wikipedia;
use App\Support\Text;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls trending items from every source, merges items about the same story,
 * scores them, and stores them as Trend rows (updating stories seen before).
 */
class TrendCollector
{
    public const SOURCES = [
        'google_trends' => GoogleTrends::class,
        'google_news' => GoogleNews::class,
        'reddit' => Reddit::class,
        'wikipedia' => Wikipedia::class,
    ];

    private const MATCH = 0.5;

    /**
     * @param  array<int, string>  $sources
     * @return array{stored: int, errors: array<string, string>, counts: array<string, int>}
     */
    public function collect(string $region = 'US', ?array $sources = null): array
    {
        $items = [];
        $errors = [];
        $counts = [];
        foreach ($sources ?? array_keys(self::SOURCES) as $key) {
            if (! isset(self::SOURCES[$key])) {
                continue;
            }
            /** @var TrendSource $source */
            $source = app(self::SOURCES[$key]);
            try {
                $found = $source->fetch($region);
                $counts[$key] = count($found);
                array_push($items, ...$found);
            } catch (Throwable $e) {
                $errors[$key] = $e->getMessage();
                Log::warning("Trend source {$key} failed: {$e->getMessage()}");
            }
        }

        $stored = $this->store($region, $this->cluster($items));
        Cache::put("trends:collected:{$region}", now()->toIso8601String(), now()->addDay());

        return ['stored' => $stored, 'errors' => $errors, 'counts' => $counts];
    }

    public function lastCollectedAt(string $region): ?Carbon
    {
        $at = Cache::get("trends:collected:{$region}");

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * Groups items that are about the same story.
     *
     * @param  array<int, TrendItem>  $items
     * @return array<int, array{tokens: array, items: array<int, TrendItem>}>
     */
    public function cluster(array $items): array
    {
        usort($items, fn ($a, $b) => $b->weight <=> $a->weight);
        $clusters = [];
        foreach ($items as $item) {
            $tokens = Text::tokens($item->title.' '.collect($item->articles)->take(2)->pluck('title')->implode(' '));
            $titleTokens = Text::tokens($item->title);
            $best = null;
            $bestScore = 0;
            foreach ($clusters as $i => $c) {
                $s = max(Text::similarity($titleTokens, $c['title_tokens']), Text::similarity($tokens, $c['tokens']) * 0.9);
                if ($s > $bestScore) {
                    [$best, $bestScore] = [$i, $s];
                }
            }
            if ($best !== null && $bestScore >= self::MATCH) {
                $clusters[$best]['items'][] = $item;
            } else {
                $clusters[] = ['tokens' => $tokens, 'title_tokens' => $titleTokens, 'items' => [$item]];
            }
        }

        return $clusters;
    }

    /**
     * Google Trends titles are lowercase search terms. Borrow the casing a headline uses
     * ("d'angelo russell" -> "D'Angelo Russell"), else capitalise each word.
     */
    public static function displayTitle(string $query, array $articles): string
    {
        foreach ($articles as $a) {
            $pos = mb_stripos($a['title'] ?? '', $query);
            if ($pos !== false) {
                return mb_substr($a['title'], $pos, mb_strlen($query));
            }
        }

        return preg_replace_callback('/(^|\s)(\p{Ll})/u', fn ($m) => $m[1].mb_strtoupper($m[2]), $query);
    }

    /** @param array<int, array{tokens: array, items: array<int, TrendItem>}> $clusters */
    private function store(string $region, array $clusters): int
    {
        $now = now();
        $recent = Trend::where('region', $region)->where('last_seen_at', '>=', $now->copy()->subHours(48))->get()
            ->map(fn (Trend $t) => ['trend' => $t, 'tokens' => Text::tokens($t->title)]);
        $stored = 0;

        foreach ($clusters as $c) {
            /** @var array<int, TrendItem> $items */
            $items = $c['items'];
            $lead = $items[0];
            $sources = collect($items)->map(fn ($i) => $i->source)->unique()->values()->all();
            // Stories on several sources are the real trends.
            $score = collect($items)->sum(fn ($i) => $i->weight) * (1 + 0.5 * (count($sources) - 1));
            $articles = collect($items)->flatMap(fn ($i) => $i->articles)->unique('url')->take(8)->values()->all();
            $title = $lead->source === 'google_trends' ? self::displayTitle($lead->title, $articles) : $lead->title;
            $data = [
                'title' => mb_substr($title, 0, 250),
                'summary' => collect($items)->pluck('summary')->filter()->first(),
                'sources' => $sources,
                'articles' => $articles,
                'image_url' => collect($items)->pluck('imageUrl')->filter()->first(),
                'traffic' => (int) collect($items)->max('traffic'),
                'score' => round($score, 3),
                'last_seen_at' => $now,
            ];

            $match = $recent->first(fn ($r) => Text::similarity(Text::tokens($title), $r['tokens']) >= self::MATCH);
            if ($match) {
                $trend = $match['trend'];
                // Keep the higher score while a story stays hot; merge sources and articles.
                $data['score'] = max($data['score'], $trend->score * 0.9);
                $data['sources'] = array_values(array_unique([...$trend->sources, ...$sources]));
                $data['articles'] = collect([...$articles, ...$trend->articles])->unique('url')->take(8)->values()->all();
                $data['title'] = $trend->title;
                $trend->update($data);
            } else {
                $fingerprint = Text::fingerprint($c['title_tokens'] ?: $c['tokens']);
                if ($fingerprint === '') {
                    continue;
                }
                $trend = Trend::updateOrCreate(
                    ['region' => $region, 'fingerprint' => $fingerprint],
                    $data + ['first_seen_at' => $now],
                );
                $recent->push(['trend' => $trend, 'tokens' => Text::tokens($trend->title)]);
            }
            $stored++;
        }

        return $stored;
    }
}
