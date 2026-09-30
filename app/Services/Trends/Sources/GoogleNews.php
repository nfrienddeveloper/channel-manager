<?php

namespace App\Services\Trends\Sources;

use App\Services\Trends\TrendItem;
use RuntimeException;

/** Google News top stories RSS. Higher in the feed counts for more. */
class GoogleNews implements TrendSource
{
    use Http;

    public function key(): string
    {
        return 'google_news';
    }

    public function fetch(string $region): array
    {
        $region = strtoupper($region);
        $res = $this->http()->get('https://news.google.com/rss', ['hl' => 'en-'.$region, 'gl' => $region, 'ceid' => $region.':en']);
        if (! $res->successful()) {
            throw new RuntimeException("Google News HTTP {$res->status()}");
        }

        return $this->parse($res->body());
    }

    /** @return array<int, TrendItem> */
    public function parse(string $xml): array
    {
        $doc = simplexml_load_string($xml, options: LIBXML_NOCDATA);
        if (! $doc) {
            throw new RuntimeException('Google News returned invalid XML');
        }
        $items = [];
        $rank = 0;
        foreach ($doc->channel->item as $item) {
            $publisher = self::clean((string) $item->source);
            $title = self::clean((string) $item->title);
            // Headlines end with " - Publisher".
            if ($publisher && str_ends_with($title, ' - '.$publisher)) {
                $title = substr($title, 0, -strlen(' - '.$publisher));
            }
            $articles = [['title' => $title, 'url' => (string) $item->link, 'source' => $publisher]];
            // The description lists related coverage as links.
            if (preg_match_all('/<a href="([^"]+)"[^>]*>(.*?)<\/a>(?:&nbsp;|\s)*<font[^>]*>(.*?)<\/font>/s', (string) $item->description, $m, PREG_SET_ORDER)) {
                foreach (array_slice($m, 1, 3) as $a) {
                    $articles[] = ['title' => self::clean($a[2]), 'url' => html_entity_decode($a[1]), 'source' => self::clean($a[3])];
                }
            }
            $items[] = new TrendItem(
                source: $this->key(),
                title: $title,
                weight: max(0.3, 1.2 - 0.03 * $rank++),
                url: (string) $item->link,
                articles: $articles,
            );
        }

        return $items;
    }
}
