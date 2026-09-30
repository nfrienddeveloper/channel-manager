<?php

namespace App\Services\Trends\Sources;

use App\Services\Trends\TrendItem;
use RuntimeException;

/** Google Trends "trending now" RSS: search terms with rising traffic, each with a few news links. */
class GoogleTrends implements TrendSource
{
    use Http;

    public function key(): string
    {
        return 'google_trends';
    }

    public function fetch(string $region): array
    {
        $res = $this->http()->get('https://trends.google.com/trending/rss', ['geo' => strtoupper($region)]);
        if (! $res->successful()) {
            throw new RuntimeException("Google Trends HTTP {$res->status()}");
        }

        return $this->parse($res->body());
    }

    /** @return array<int, TrendItem> */
    public function parse(string $xml): array
    {
        $doc = simplexml_load_string($xml, options: LIBXML_NOCDATA);
        if (! $doc) {
            throw new RuntimeException('Google Trends returned invalid XML');
        }
        $ns = $doc->getDocNamespaces(true)['ht'] ?? 'https://trends.google.com/trending/rss';
        $items = [];
        foreach ($doc->channel->item as $item) {
            $ht = $item->children($ns);
            $traffic = (int) preg_replace('/\D/', '', (string) $ht->approx_traffic);
            $articles = [];
            foreach ($ht->news_item as $news) {
                $articles[] = [
                    'title' => self::clean((string) $news->news_item_title),
                    'url' => (string) $news->news_item_url,
                    'source' => self::clean((string) $news->news_item_source),
                ];
            }
            $items[] = new TrendItem(
                source: $this->key(),
                title: self::clean((string) $item->title),
                weight: 1.0 + log10(max(100, $traffic)) / 2,
                traffic: $traffic,
                articles: $articles,
                imageUrl: ((string) $ht->picture) ?: null,
            );
        }

        return $items;
    }
}
