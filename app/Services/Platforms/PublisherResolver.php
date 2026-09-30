<?php

namespace App\Services\Platforms;

use App\Models\Channel;
use App\Services\Platforms\Facebook\FacebookPagePublisher;
use RuntimeException;

class PublisherResolver
{
    public const SUPPORTED = ['facebook' => FacebookPagePublisher::class];

    public function for(Channel $channel): Publisher
    {
        if (! $channel->live) {
            return app(DryRunPublisher::class);
        }
        $class = self::SUPPORTED[$channel->platform] ?? throw new RuntimeException("Posting to {$channel->platformLabel()} is not built yet");

        return app($class);
    }
}
