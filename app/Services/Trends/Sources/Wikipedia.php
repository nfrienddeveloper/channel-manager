<?php

namespace App\Services\Trends\Sources;

use App\Services\Trends\TrendItem;
use Illuminate\Support\Carbon;
use RuntimeException;

/** Yesterday's most-read English Wikipedia articles: a good signal for people and events in the news. */
class Wikipedia implements TrendSource
{
    use Http;

    // Pages that are always near the top and are not news.
    private const EVERGREEN = ['Main_Page', 'Cleopatra', 'XXXX', 'Undefined', 'Deaths_in_2026', 'Google', 'YouTube', 'Facebook', 'ChatGPT', 'Wikipedia'];

    public function key(): string
    {
        return 'wikipedia';
    }

    public function fetch(string $region): array
    {
        $day = Carbon::now('UTC')->subDay();
        $url = sprintf('https://wikimedia.org/api/rest_v1/metrics/pageviews/top/en.wikipedia/all-access/%s', $day->format('Y/m/d'));
        $res = $this->http()->get($url);
        if (! $res->successful()) {
            throw new RuntimeException("Wikipedia HTTP {$res->status()}");
        }

        return $this->parse($res->json() ?? []);
    }

    /** @return array<int, TrendItem> */
    public function parse(array $json): array
    {
        $items = [];
        foreach (array_slice($json['items'][0]['articles'] ?? [], 0, 60) as $a) {
            $page = $a['article'] ?? '';
            if ($page === '' || str_contains($page, ':') || in_array($page, self::EVERGREEN, true) || str_starts_with($page, 'Deaths_in')) {
                continue;
            }
            $views = (int) ($a['views'] ?? 0);
            $title = str_replace('_', ' ', $page);
            $url = 'https://en.wikipedia.org/wiki/'.rawurlencode($page);
            $items[] = new TrendItem(
                source: $this->key(),
                title: $title,
                weight: 0.2 + log10(max(1000, $views)) / 10,
                traffic: $views,
                url: $url,
                articles: [['title' => $title.' (Wikipedia)', 'url' => $url, 'source' => 'Wikipedia']],
            );
            if (count($items) >= 30) {
                break;
            }
        }

        return $items;
    }
}
