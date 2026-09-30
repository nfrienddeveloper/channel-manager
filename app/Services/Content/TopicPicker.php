<?php

namespace App\Services\Content;

use App\Enums\ContentStatus;
use App\Models\Channel;
use App\Models\Trend;
use App\Support\Text;
use Illuminate\Support\Collection;

/** Chooses the next trend a channel should cover. */
class TopicPicker
{
    public function __construct(private SafetyFilter $safety) {}

    public function pick(Channel $channel): ?Trend
    {
        return $this->candidates($channel)->first();
    }

    /** @return Collection<int, Trend> best first */
    public function candidates(Channel $channel, int $hours = 18): Collection
    {
        $recent = $channel->items()->where('created_at', '>=', now()->subDays(7))->get(['trend_id', 'topic', 'status']);
        $usedTrendIds = $recent->pluck('trend_id')->filter()->all();
        $usedTokens = $recent->map(fn ($i) => Text::tokens($i->topic));
        $include = array_map('mb_strtolower', array_filter($channel->setting('include_keywords', [])));
        $exclude = array_map('mb_strtolower', array_filter($channel->setting('exclude_keywords', [])));
        $avoid = $channel->setting('avoid', []);

        return Trend::query()
            ->where('region', $channel->setting('region', 'US'))
            ->where('last_seen_at', '>=', now()->subHours($hours))
            ->whereNotIn('id', $usedTrendIds)
            ->orderByDesc('score')
            ->limit(200)
            ->get()
            ->filter(function (Trend $t) use ($usedTokens, $include, $exclude, $avoid) {
                $text = $t->searchText();
                if ($this->safety->flags($text, $avoid)) {
                    return false;
                }
                if ($include && ! collect($include)->contains(fn ($k) => str_contains($text, $k))) {
                    return false;
                }
                if ($exclude && collect($exclude)->contains(fn ($k) => str_contains($text, $k))) {
                    return false;
                }
                $tokens = Text::tokens($t->title);

                // Skip stories the channel already covered under a different headline.
                return ! $usedTokens->contains(fn ($u) => Text::similarity($tokens, $u) >= 0.5);
            })
            // Newer stories get a lift; a story first seen days ago is old news even if still listed.
            ->sortByDesc(fn (Trend $t) => $t->score * ($t->first_seen_at->gt(now()->subHours(12)) ? 1.2 : 1.0))
            ->values();
    }

    /** Statuses that count as "the channel covered this". */
    public static function coveredStatuses(): array
    {
        return [ContentStatus::Planned, ContentStatus::Producing, ContentStatus::Ready, ContentStatus::Publishing, ContentStatus::Published];
    }
}
