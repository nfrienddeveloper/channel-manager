<?php

namespace App\Services\Platforms;

use App\Models\ContentItem;
use Illuminate\Support\Facades\File;

/** Stands in for a platform while a channel is not live: writes the video and caption to the channel's outbox folder. */
class DryRunPublisher implements Publisher
{
    public function publish(ContentItem $item): array
    {
        $dir = $item->channel->storagePath('outbox');
        File::ensureDirectoryExists($dir);
        $stem = $item->scheduled_for?->format('Y-m-d_Hi') ?? now()->format('Y-m-d_Hi');
        $stem .= '_'.$item->id;
        File::copy($item->workDir($item->video_path), "{$dir}/{$stem}.mp4");
        File::put("{$dir}/{$stem}.txt", $item->caption."\n");

        return ['external_id' => 'dry-run-'.$item->id, 'url' => null];
    }

    public function metrics(ContentItem $item): array
    {
        return [];
    }
}
