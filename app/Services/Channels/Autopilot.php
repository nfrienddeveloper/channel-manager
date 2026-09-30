<?php

namespace App\Services\Channels;

use App\Enums\ContentStatus;
use App\Jobs\ProduceContentItem;
use App\Jobs\PublishContentItem;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Services\Content\TopicPicker;
use App\Services\Platforms\PublisherResolver;
use App\Services\Trends\TrendCollector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Runs a channel with no one at the wheel. Each tick (every few minutes, from the scheduler):
 * refresh trends, plan a topic for each post slot coming up, start production, publish what is due,
 * and read back numbers for recent posts so the writer can learn what works.
 */
class Autopilot
{
    public const MAX_TRIES_PER_SLOT = 3;

    // A trending video posted this late is stale, so a missed slot (computer asleep) is dropped instead.
    public const MAX_LATE_MINUTES = 360;

    public function __construct(
        private TrendCollector $trends,
        private TopicPicker $picker,
        private PublisherResolver $publishers,
    ) {}

    public function tick(Channel $channel): array
    {
        $report = ['planned' => 0, 'producing' => 0, 'publishing' => 0, 'metrics' => 0, 'dropped' => 0];
        if (! $channel->active) {
            return $report;
        }
        $this->refreshTrends($channel);
        $this->recoverStuck($channel);
        $report['dropped'] = $this->dropMissed($channel);
        $report['planned'] = $this->planSlots($channel);

        foreach ($channel->items()->where('status', ContentStatus::Planned)->get() as $item) {
            ProduceContentItem::dispatch($item);
            $report['producing']++;
        }
        foreach ($channel->items()->where('status', ContentStatus::Ready)->where('scheduled_for', '<=', now())->get() as $item) {
            $item->update(['status' => ContentStatus::Publishing]);
            PublishContentItem::dispatch($item);
            $report['publishing']++;
        }
        $report['metrics'] = $this->refreshMetrics($channel);
        $channel->update(['last_tick_at' => now()]);

        return $report;
    }

    public function refreshTrends(Channel $channel): void
    {
        $region = $channel->setting('region', 'US');
        $last = $this->trends->lastCollectedAt($region);
        if ($last && $last->gt(now()->subMinutes(config('channels.trend_refresh_minutes')))) {
            return;
        }
        $result = $this->trends->collect($region, $channel->setting('sources'));
        $msg = "Collected trends: {$result['stored']} stories";
        if ($result['errors']) {
            $msg .= '; unavailable: '.implode(', ', array_keys($result['errors']));
        }
        $channel->log($msg, $result['stored'] ? 'info' : 'warning', context: $result);
    }

    /** Post times from now until the production lead time runs out, in UTC. */
    public function upcomingSlots(Channel $channel, ?Carbon $now = null): Collection
    {
        $now ??= now();
        $tz = $channel->setting('timezone', config('channels.timezone'));
        $times = array_slice($channel->setting('post_times', []), 0, max(0, (int) $channel->setting('posts_per_day', 3)));
        $horizon = $now->copy()->addMinutes(config('channels.lead_minutes'));
        $slots = collect();
        foreach ([0, 1] as $dayOffset) {
            $day = $now->copy()->setTimezone($tz)->startOfDay()->addDays($dayOffset);
            foreach ($times as $time) {
                [$h, $m] = array_map('intval', explode(':', $time) + [1 => 0]);
                $slot = $day->copy()->setTime($h, $m)->utc();
                if ($slot->gt($now) && $slot->lte($horizon)) {
                    $slots->push($slot);
                }
            }
        }

        return $slots->sort()->values();
    }

    private function planSlots(Channel $channel): int
    {
        $planned = 0;
        foreach ($this->upcomingSlots($channel) as $slot) {
            $items = $channel->items()->where('scheduled_for', $slot)->get();
            if ($items->contains(fn (ContentItem $i) => $i->status->isOpen() || $i->status === ContentStatus::Published)) {
                continue;
            }
            if ($items->count() >= self::MAX_TRIES_PER_SLOT) {
                continue;
            }
            $trend = $this->picker->pick($channel);
            if (! $trend) {
                $channel->log('No suitable trending topic for the '.$slot->copy()->setTimezone($channel->setting('timezone'))->format('D g:ia').' post', 'warning');

                continue;
            }
            $item = $channel->items()->create([
                'trend_id' => $trend->id,
                'status' => ContentStatus::Planned,
                'topic' => $trend->title,
                'scheduled_for' => $slot,
                'dry_run' => ! $channel->live,
            ]);
            $channel->log("Planned \"{$trend->title}\" for ".$slot->copy()->setTimezone($channel->setting('timezone'))->format('D g:ia'), item: $item);
            $planned++;
        }

        return $planned;
    }

    /** Items left "producing" or "publishing" by a crash or a closed app go back in the queue. */
    private function recoverStuck(Channel $channel): void
    {
        $channel->items()->where('status', ContentStatus::Producing)->where('updated_at', '<', now()->subMinutes(45))
            ->update(['status' => ContentStatus::Planned]);
        $channel->items()->where('status', ContentStatus::Publishing)->whereNull('external_id')->where('updated_at', '<', now()->subMinutes(30))
            ->update(['status' => ContentStatus::Ready]);
    }

    private function dropMissed(Channel $channel): int
    {
        $late = $channel->items()->whereIn('status', [ContentStatus::Planned, ContentStatus::Ready])
            ->where('scheduled_for', '<', now()->subMinutes(self::MAX_LATE_MINUTES))->get();
        foreach ($late as $item) {
            $item->update(['status' => ContentStatus::Skipped, 'error' => 'Missed its post time; the trend is stale now']);
            $channel->log("Dropped \"{$item->topic}\": missed its post time", 'warning', $item);
        }

        return $late->count();
    }

    private function refreshMetrics(Channel $channel): int
    {
        if (! $channel->live) {
            return 0;
        }
        $items = $channel->items()->where('status', ContentStatus::Published)->where('dry_run', false)
            ->where('published_at', '>=', now()->subDays(7))
            ->where(fn ($q) => $q->whereNull('metrics_at')->orWhere('metrics_at', '<', now()->subHours(6)))
            ->where('published_at', '<', now()->subHour())
            ->limit(10)->get();
        $publisher = $this->publishers->for($channel);
        foreach ($items as $item) {
            try {
                $item->update(['metrics' => $publisher->metrics($item) ?: $item->metrics, 'metrics_at' => now()]);
            } catch (Throwable $e) {
                $channel->log("Could not read numbers for \"{$item->topic}\": {$e->getMessage()}", 'warning', $item);
                $item->update(['metrics_at' => now()]);
            }
        }

        return $items->count();
    }
}
