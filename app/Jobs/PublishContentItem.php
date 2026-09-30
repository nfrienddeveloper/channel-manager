<?php

namespace App\Jobs;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Services\Platforms\PublisherResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PublishContentItem implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public ContentItem $item) {}

    public function uniqueId(): string
    {
        return (string) $this->item->id;
    }

    public function handle(PublisherResolver $publishers): void
    {
        $item = $this->item->refresh();
        $channel = $item->channel;
        if ($item->status !== ContentStatus::Publishing || $item->external_id) {
            return;
        }
        // Re-check at the last moment: the channel may have been switched to dry run meanwhile.
        $dryRun = ! $channel->live;
        try {
            $result = $publishers->for($channel)->publish($item);
            $item->update([
                'status' => ContentStatus::Published,
                'external_id' => $result['external_id'],
                'external_url' => $result['url'],
                'published_at' => now(),
                'dry_run' => $dryRun,
                'error' => null,
            ]);
            $channel->log(($dryRun ? 'Dry run: saved to outbox' : 'Posted to '.$channel->platformLabel()).": \"{$item->topic}\"", item: $item, context: array_filter(['url' => $result['url']]));
        } catch (Throwable $e) {
            $item->update(['attempts' => $item->attempts + 1, 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            $final = $item->attempts >= 3;
            $item->update(['status' => $final ? ContentStatus::Failed : ContentStatus::Ready]);
            $channel->log(($final ? 'Gave up posting' : 'Posting failed, will retry')." \"{$item->topic}\": {$e->getMessage()}", 'error', $item);
        }
    }
}
