<?php

namespace App\Http\Controllers;

use App\Enums\ContentStatus;
use App\Models\ContentItem;

class ContentItemController extends Controller
{
    public function media(ContentItem $item, string $kind)
    {
        $path = $kind === 'video' ? $item->video_path : $item->thumb_path;
        abort_unless($path && is_file($item->workDir($path)), 404);

        return response()->file($item->workDir($path));
    }

    public function skip(ContentItem $item)
    {
        if ($item->status->isOpen()) {
            $item->update(['status' => ContentStatus::Skipped, 'error' => 'Skipped from the app']);
            $item->channel->log("Skipped \"{$item->topic}\" (from the app)", item: $item);
        }

        return back();
    }
}
