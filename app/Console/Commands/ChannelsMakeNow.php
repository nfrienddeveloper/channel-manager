<?php

namespace App\Console\Commands;

use App\Enums\ContentStatus;
use App\Jobs\PublishContentItem;
use App\Models\Channel;
use App\Models\Trend;
use App\Services\Content\ContentProducer;
use App\Services\Content\TopicPicker;
use App\Services\Trends\TrendCollector;
use Illuminate\Console\Command;

class ChannelsMakeNow extends Command
{
    protected $signature = 'channels:make-now {channel : Channel id} {--topic= : Use this topic instead of the top trend} {--publish : Publish right away (dry run unless the channel is live)}';

    protected $description = 'Make one video for a channel right now, for testing';

    public function handle(TrendCollector $collector, TopicPicker $picker, ContentProducer $producer): int
    {
        $channel = Channel::findOrFail($this->argument('channel'));
        if ($topic = $this->option('topic')) {
            $trend = Trend::firstOrCreate(
                ['region' => $channel->setting('region'), 'fingerprint' => 'manual-'.md5($topic)],
                ['title' => $topic, 'sources' => ['manual'], 'articles' => [], 'first_seen_at' => now(), 'last_seen_at' => now()],
            );
        } else {
            $this->line('Collecting trends…');
            $collector->collect($channel->setting('region'), $channel->setting('sources'));
            $trend = $picker->pick($channel) ?? throw new \RuntimeException('No suitable trend found');
        }
        $item = $channel->items()->create([
            'trend_id' => $trend->id, 'status' => ContentStatus::Planned, 'topic' => $trend->title,
            'scheduled_for' => now(), 'dry_run' => ! $channel->live,
        ]);
        $this->line("Making \"{$item->topic}\"…");
        $item = $producer->produce($item);
        $this->line("Status: {$item->status->value}".($item->error ? " ({$item->error})" : ''));
        if ($item->status === ContentStatus::Ready) {
            $this->line('Video: '.$item->workDir($item->video_path));
            $this->line("Caption:\n{$item->caption}");
            if ($this->option('publish')) {
                $item->update(['status' => ContentStatus::Publishing]);
                PublishContentItem::dispatchSync($item);
                $item->refresh();
                $this->line("Published: {$item->status->value} ".($item->external_url ?? $item->external_id ?? $item->error));
            }
        }

        return $item->status === ContentStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}
