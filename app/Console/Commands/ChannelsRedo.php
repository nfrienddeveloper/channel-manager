<?php

namespace App\Console\Commands;

use App\Enums\ContentStatus;
use App\Jobs\ProduceContentItem;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Services\Channels\Autopilot;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Remakes a channel's videos in its current look (for example after switching it to Unearthed).
 * Unpublished videos are rewritten and re-rendered from their saved research before they post.
 * Published videos are never touched: with --repost, the most-viewed ones come back as new videos
 * in free post slots, made from the same research.
 */
class ChannelsRedo extends Command
{
    protected $signature = 'channels:redo {channel : Channel id}
        {--restyle-only : Keep the existing scripts and only re-render them}
        {--repost=0 : Also remake this many of the most-viewed published videos as new posts}
        {--repost-items= : Remake these published items as new posts instead (comma-separated ids)}
        {--per-day=1 : Reposts to put in each day\'s post slots}
        {--dry-run : List what would change and change nothing}';

    protected $description = 'Remake a channel\'s unpublished videos in its current look, and optionally repost its best published ones';

    public function handle(Autopilot $autopilot): int
    {
        $channel = Channel::findOrFail($this->argument('channel'));
        $dry = (bool) $this->option('dry-run');
        $rewrite = ! $this->option('restyle-only');
        $this->line("Channel \"{$channel->name}\", look: ".(Channel::THEMES[$channel->setting('brand.theme', 'default')] ?? 'Classic')
            .($rewrite ? ', rewriting scripts' : ', keeping scripts').($dry ? ' (dry run: nothing changes)' : ''));

        $redo = $this->unpublished($channel);
        foreach ($redo as $item) {
            $this->line("  redo   #{$item->id} \"{$item->topic}\" ({$item->status->value})");
            if (! $dry) {
                $this->reset($item, $rewrite);
                ProduceContentItem::dispatch($item);
            }
        }

        $best = $this->toRepost($channel);
        $slots = $autopilot->freeSlots($channel, now()->addMinutes(config('channels.lead_minutes')), $best->count(), max(1, (int) $this->option('per-day')));
        if ($slots->count() < $best->count()) {
            $this->warn('Only '.$slots->count().' free post slots found; set post times on the channel to repost more.');
        }
        foreach ($best->take($slots->count())->values() as $k => $old) {
            $slot = $slots[$k];
            $views = $old->views() !== null ? number_format($old->views()).' views' : 'no view count';
            $when = $slot->copy()->setTimezone($channel->setting('timezone'))->format('D j M g:ia');
            $this->line("  repost #{$old->id} \"{$old->topic}\" ({$views}) as a new video for {$when}");
            if (! $dry) {
                $new = $this->repost($old, $slot, $rewrite);
                ProduceContentItem::dispatch($new);
            }
        }

        $total = $redo->count() + min($best->count(), $slots->count());
        $this->info($dry ? "{$total} videos would be remade." : "{$total} videos queued. They render in the background and post at their times".($channel->live ? '.' : ' (dry run: to the outbox).'));
        if (! $dry && $total) {
            $channel->log("Remaking {$total} videos in the current look", context: ['redo' => $redo->pluck('id'), 'repost' => $best->take($slots->count())->pluck('id')]);
        }

        return self::SUCCESS;
    }

    /** Videos not yet posted whose post time hasn't passed (a stale one would only be dropped). In-flight ones are left alone. */
    private function unpublished(Channel $channel): Collection
    {
        $items = $channel->items()->whereIn('status', [ContentStatus::Planned, ContentStatus::Ready, ContentStatus::Failed])
            ->where(fn ($q) => $q->whereNull('scheduled_for')->orWhere('scheduled_for', '>=', now()->subMinutes(Autopilot::MAX_LATE_MINUTES)))
            ->orderBy('id')->get();
        $busy = $channel->items()->whereIn('status', [ContentStatus::Producing, ContentStatus::Publishing])->count();
        if ($busy) {
            $this->warn("{$busy} videos are being made or posted right now and were left alone; run this again once they finish.");
        }

        return $items;
    }

    private function toRepost(Channel $channel): Collection
    {
        $published = $channel->items()->where('status', ContentStatus::Published)->whereNotNull('research');
        if ($ids = array_filter(array_map('intval', explode(',', (string) $this->option('repost-items'))))) {
            return $published->whereIn('id', $ids)->orderBy('id')->get();
        }
        $n = (int) $this->option('repost');
        if ($n < 1) {
            return collect();
        }
        $ranked = $published->get()->filter(fn (ContentItem $i) => $i->views() !== null)->sortByDesc(fn (ContentItem $i) => $i->views())->values();
        if ($ranked->isEmpty()) {
            $this->warn('No published video has view counts yet, so there is nothing to rank. Name them with --repost-items instead.');
        }

        return $ranked->take($n);
    }

    private function reset(ContentItem $item, bool $rewrite): void
    {
        $changes = ['status' => ContentStatus::Planned, 'attempts' => 0, 'error' => null, 'video_path' => null, 'thumb_path' => null, 'seconds' => null];
        if ($rewrite) {
            $changes += ['storyboard' => null, 'caption' => null];
        } elseif ($item->storyboard) {
            $sb = $item->storyboard;
            unset($sb['episode']);
            if ($item->channel->setting('brand.series')) {
                $sb['episode'] = $item->channel->nextEpisode($item);
            }
            $changes['storyboard'] = $sb;
        }
        $item->update($changes);
    }

    /** A new item for the same story, with the old one's research and photos. The old post stays as it is. */
    private function repost(ContentItem $old, $slot, bool $rewrite): ContentItem
    {
        $channel = $old->channel;
        $new = $channel->items()->create([
            'trend_id' => $old->trend_id, 'status' => ContentStatus::Planned, 'topic' => $old->topic, 'category' => $old->category,
            'research' => $old->research, 'scheduled_for' => $slot, 'dry_run' => ! $channel->live,
        ]);
        if (File::isDirectory($old->workDir('assets'))) {
            File::copyDirectory($old->workDir('assets'), $new->workDir('assets'));
        }
        if (! $rewrite && $old->storyboard) {
            $sb = $old->storyboard;
            $sb['id'] = 'v'.$new->id;
            unset($sb['episode']);
            if ($channel->setting('brand.series')) {
                $sb['episode'] = $channel->nextEpisode($new);
            }
            $new->update(['storyboard' => $sb, 'caption' => $old->caption, 'sources' => $old->sources, 'credits' => $old->credits]);
        }
        $channel->log("Reposting \"{$old->topic}\" (from #{$old->id}) in the current look", item: $new);

        return $new;
    }
}
