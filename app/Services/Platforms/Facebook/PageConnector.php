<?php

namespace App\Services\Platforms\Facebook;

use App\Models\PlatformAccount;

/** Turns a user token from Meta's Graph API Explorer into stored, non-expiring Page tokens. */
class PageConnector
{
    public function __construct(private Graph $graph) {}

    /**
     * @return array<int, array{id: string, name: string, access_token: string, tasks?: array}> pages the token can post to
     */
    public function pages(string $userToken, ?string $appId = null, ?string $appSecret = null): array
    {
        $appId ??= config('channels.facebook.app_id');
        $appSecret ??= config('channels.facebook.app_secret');
        if ($appId && $appSecret) {
            // Page tokens derived from a long-lived user token never expire.
            $userToken = $this->graph->exchangeForLongLived($userToken, $appId, $appSecret)['access_token'] ?? $userToken;
        }

        return collect($this->graph->pages($userToken))
            ->filter(fn ($p) => empty($p['tasks']) || array_intersect(['CREATE_CONTENT', 'MANAGE'], $p['tasks']))
            ->values()->all();
    }

    public function store(array $page, bool $longLived): PlatformAccount
    {
        return PlatformAccount::updateOrCreate(
            ['platform' => 'facebook', 'external_id' => $page['id']],
            [
                'name' => $page['name'],
                'credentials' => ['access_token' => $page['access_token']],
                'token_expires_at' => $longLived ? null : now()->addHour(),
                'meta' => ['tasks' => $page['tasks'] ?? [], 'long_lived' => $longLived, 'connected_at' => now()->toIso8601String()],
            ],
        );
    }
}
