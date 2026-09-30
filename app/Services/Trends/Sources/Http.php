<?php

namespace App\Services\Trends\Sources;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http as HttpClient;

trait Http
{
    protected function http(): PendingRequest
    {
        return HttpClient::withUserAgent(config('channels.http.user_agent'))->timeout(20)->retry(2, 500, throw: false);
    }

    protected static function clean(?string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5)));
    }
}
