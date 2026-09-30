<?php

namespace App\Jobs;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Services\Content\ContentProducer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProduceContentItem implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public ContentItem $item)
    {
        $this->onQueue('media');
    }

    public function uniqueId(): string
    {
        return (string) $this->item->id;
    }

    public function handle(ContentProducer $producer): void
    {
        $this->item->refresh();
        if ($this->item->status !== ContentStatus::Planned) {
            return;
        }
        $producer->produce($this->item);
    }
}
