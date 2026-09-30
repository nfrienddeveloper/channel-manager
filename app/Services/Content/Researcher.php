<?php

namespace App\Services\Content;

use App\Models\ContentItem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Gathers what the script may say (article text and a Wikipedia summary, each with its URL)
 * and freely licensed images for the video (Openverse, commercial-use licences only).
 */
class Researcher
{
    public function research(ContentItem $item): array
    {
        $trend = $item->trend;
        $articles = collect($trend?->articles ?? [])->take(6);
        $notes = [];

        foreach ($articles as $a) {
            // Google News links are redirects we can't follow without a browser; their headline still helps.
            $direct = ! str_contains($a['url'], 'news.google.com');
            $text = $direct && count(array_filter($notes, fn ($n) => ! empty($n['text']))) < 3 ? $this->readArticle($a['url']) : null;
            $notes[] = ['title' => $a['title'], 'url' => $a['url'], 'source' => $a['source'] ?? null, 'text' => $text];
        }

        if ($wiki = $this->wikipedia($item->topic)) {
            $notes[] = $wiki;
        }

        $images = $this->images($item, $item->topic);

        return ['topic' => $item->topic, 'summary' => $trend?->summary, 'notes' => $notes, 'images' => $images, 'researched_at' => now()->toIso8601String()];
    }

    private function http()
    {
        return Http::withUserAgent(config('channels.http.user_agent'))->timeout(15)->connectTimeout(8);
    }

    public function readArticle(string $url): ?string
    {
        try {
            $res = $this->http()->withHeaders(['Accept' => 'text/html'])->get($url);
            if (! $res->successful() || ! str_contains($res->header('Content-Type'), 'html')) {
                return null;
            }

            return self::extractText($res->body());
        } catch (Throwable) {
            return null;
        }
    }

    /** Description meta tags plus the first substantial paragraphs, capped to keep prompts small. */
    public static function extractText(string $html): ?string
    {
        $parts = [];
        if (preg_match('/<meta[^>]+(?:property|name)=["\'](?:og:description|description)["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) {
            $parts[] = $m[1];
        }
        $html = preg_replace('#<(script|style|nav|header|footer|aside|figure)\b.*?</\1>#is', ' ', $html);
        if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $html, $m)) {
            foreach ($m[1] as $p) {
                $p = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_HTML5)));
                if (mb_strlen($p) >= 60) {
                    $parts[] = $p;
                }
                if (mb_strlen(implode(' ', $parts)) > 2500) {
                    break;
                }
            }
        }
        $text = trim(implode("\n", array_unique($parts)));

        return $text === '' ? null : mb_substr($text, 0, 3000);
    }

    private function wikipedia(string $topic): ?array
    {
        try {
            $search = $this->http()->get('https://en.wikipedia.org/w/api.php', [
                'action' => 'query', 'list' => 'search', 'srsearch' => $topic, 'srlimit' => 1, 'format' => 'json',
            ])->json('query.search.0.title');
            if (! $search) {
                return null;
            }
            $s = $this->http()->get('https://en.wikipedia.org/api/rest_v1/page/summary/'.rawurlencode(str_replace(' ', '_', $search)))->json();
            if (empty($s['extract'])) {
                return null;
            }

            return ['title' => $s['title'].' (Wikipedia)', 'url' => $s['content_urls']['desktop']['page'] ?? 'https://en.wikipedia.org/wiki/'.rawurlencode($search), 'source' => 'Wikipedia', 'text' => $s['extract']];
        } catch (Throwable) {
            return null;
        }
    }

    /** Downloads up to 3 photos into the item's assets folder. */
    public function images(ContentItem $item, string $query, int $max = 3): array
    {
        try {
            $results = $this->http()->get('https://api.openverse.org/v1/images/', [
                'q' => Str::limit($query, 80, ''), 'license_type' => 'commercial', 'mature' => 'false', 'page_size' => 12,
            ])->json('results') ?? [];
        } catch (Throwable) {
            return [];
        }

        $dir = $item->workDir('assets');
        File::ensureDirectoryExists($dir);
        $out = [];
        foreach ($results as $r) {
            if (count($out) >= $max) {
                break;
            }
            if (($r['width'] ?? 0) && $r['width'] < 700) {
                continue;
            }
            try {
                $img = $this->http()->get($r['url']);
                $type = $img->header('Content-Type');
                if (! $img->successful() || ! preg_match('#image/(jpeg|png|webp)#', $type, $m) || strlen($img->body()) < 20000) {
                    continue;
                }
                $id = 'img'.(count($out) + 1);
                $file = "assets/{$id}.".($m[1] === 'jpeg' ? 'jpg' : $m[1]);
                File::put($item->workDir($file), $img->body());
                $out[] = [
                    'id' => $id,
                    'file' => $file,
                    'description' => trim(($r['title'] ?? '').' '.collect($r['tags'] ?? [])->pluck('name')->take(8)->implode(', ')),
                    'credit' => sprintf('"%s" by %s (%s %s)', Str::limit($r['title'] ?? 'Photo', 60), $r['creator'] ?? 'unknown', strtoupper($r['license'] ?? ''), $r['license_version'] ?? ''),
                    'source_url' => $r['foreign_landing_url'] ?? $r['url'],
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return $out;
    }
}
