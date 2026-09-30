<?php

namespace App\Services\Platforms\Facebook;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Thin Graph API client. Tokens are passed per call and never logged. */
class Graph
{
    public function url(string $path, string $host = 'graph.facebook.com'): string
    {
        return "https://{$host}/".config('channels.facebook.graph_version').'/'.ltrim($path, '/');
    }

    public function get(string $path, string $token, array $query = []): array
    {
        return $this->check($this->client()->get($this->url($path), $query + ['access_token' => $token]));
    }

    public function post(string $path, string $token, array $data = []): array
    {
        return $this->check($this->client()->asForm()->post($this->url($path), $data + ['access_token' => $token]));
    }

    public function client(): PendingRequest
    {
        return Http::timeout(120)->connectTimeout(15)->acceptJson();
    }

    public function check(Response $res): array
    {
        if ($res->successful()) {
            return $res->json() ?? [];
        }
        $err = $res->json('error') ?? [];
        $msg = $err['message'] ?? mb_substr($res->body(), 0, 300);
        throw new RuntimeException("Facebook: {$msg}".(isset($err['code']) ? " (code {$err['code']})" : ''), (int) ($err['code'] ?? 0));
    }

    /** Swaps a short-lived user token (1-2 hours) for a long-lived one (about 60 days). */
    public function exchangeForLongLived(string $userToken, string $appId, string $appSecret): array
    {
        return $this->check($this->client()->get($this->url('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $userToken,
        ]));
    }

    /** Pages the user manages, each with its own Page token. Page tokens from a long-lived user token do not expire. */
    public function pages(string $userToken): array
    {
        return $this->get('me/accounts', $userToken, ['fields' => 'id,name,access_token,tasks', 'limit' => 100])['data'] ?? [];
    }
}
