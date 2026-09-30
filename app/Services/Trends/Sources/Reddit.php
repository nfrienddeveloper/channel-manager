<?php

namespace App\Services\Trends\Sources;

use App\Services\Trends\TrendItem;
use RuntimeException;

/** Top posts of the day on r/popular, NSFW excluded. Reddit may refuse anonymous requests; that source is then skipped. */
class Reddit implements TrendSource
{
    use Http;

    public function key(): string
    {
        return 'reddit';
    }

    public function fetch(string $region): array
    {
        $res = $this->http()->get('https://www.reddit.com/r/popular/top.json', ['t' => 'day', 'limit' => 50, 'geo_filter' => strtoupper($region)]);
        if (! $res->successful()) {
            throw new RuntimeException("Reddit HTTP {$res->status()}");
        }

        return $this->parse($res->json() ?? []);
    }

    /** @return array<int, TrendItem> */
    public function parse(array $json): array
    {
        $items = [];
        foreach ($json['data']['children'] ?? [] as $child) {
            $p = $child['data'] ?? [];
            if (! empty($p['over_18']) || ! empty($p['stickied']) || empty($p['title'])) {
                continue;
            }
            $ups = (int) ($p['ups'] ?? 0);
            $permalink = 'https://www.reddit.com'.($p['permalink'] ?? '');
            $link = $p['url_overridden_by_dest'] ?? null;
            $articles = [['title' => self::clean($p['title']), 'url' => $permalink, 'source' => 'r/'.($p['subreddit'] ?? 'popular')]];
            if ($link && ! str_contains($link, 'reddit.com') && ! str_contains($link, 'redd.it')) {
                $articles[] = ['title' => self::clean($p['title']), 'url' => $link, 'source' => parse_url($link, PHP_URL_HOST) ?: 'link'];
            }
            $items[] = new TrendItem(
                source: $this->key(),
                title: self::clean($p['title']),
                weight: 0.3 + log10(max(10, $ups)) / 8,
                traffic: $ups,
                url: $permalink,
                summary: isset($p['selftext']) ? mb_substr(self::clean($p['selftext']), 0, 400) : null,
                articles: $articles,
            );
        }

        return $items;
    }
}
