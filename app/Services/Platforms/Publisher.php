<?php

namespace App\Services\Platforms;

use App\Models\ContentItem;

interface Publisher
{
    /** @return array{external_id: string, url: ?string} */
    public function publish(ContentItem $item): array;

    /** Fresh engagement numbers for a published item; "views" is the one the autopilot learns from. */
    public function metrics(ContentItem $item): array;
}
