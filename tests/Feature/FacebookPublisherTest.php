<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Models\PlatformAccount;
use App\Services\Platforms\DryRunPublisher;
use App\Services\Platforms\Facebook\FacebookPagePublisher;
use App\Services\Platforms\PublisherResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookPublisherTest extends TestCase
{
    use RefreshDatabase;

    private function liveItem()
    {
        $account = PlatformAccount::create(['platform' => 'facebook', 'name' => 'Trend Brief', 'external_id' => '1234', 'credentials' => ['access_token' => 'PAGE_TOKEN']]);
        $channel = $this->makeChannel(['live' => true, 'platform_account_id' => $account->id]);
        $item = $channel->items()->create(['status' => ContentStatus::Publishing, 'topic' => 'Moon rover', 'caption' => "Caption\n\n#Space", 'video_path' => 'v.mp4', 'storyboard' => ['title' => 'Moon rover passes test']]);
        File::ensureDirectoryExists($item->workDir());
        File::put($item->workDir('v.mp4'), str_repeat('x', 2048));

        return $item;
    }

    public function test_it_publishes_a_reel_in_three_steps(): void
    {
        $item = $this->liveItem();
        Http::fake([
            'graph.facebook.com/*/1234/video_reels' => Http::sequence()
                ->push(['video_id' => '999', 'upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/999'])
                ->push(['success' => true]),
            'rupload.facebook.com/*' => Http::response(['success' => true]),
        ]);

        $result = app(PublisherResolver::class)->for($item->channel)->publish($item);

        $this->assertSame(['external_id' => '999', 'url' => 'https://www.facebook.com/reel/999'], $result);
        $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertSame('start', $sent[0]['upload_phase']);
        $this->assertSame('PAGE_TOKEN', $sent[0]['access_token']);
        $this->assertSame('OAuth PAGE_TOKEN', $sent[1]->header('Authorization')[0]);
        $this->assertSame('2048', $sent[1]->header('file_size')[0]);
        $this->assertSame('finish', $sent[2]['upload_phase']);
        $this->assertSame('PUBLISHED', $sent[2]['video_state']);
        $this->assertSame("Caption\n\n#Space", $sent[2]['description']);
    }

    public function test_graph_errors_surface_with_their_message(): void
    {
        $item = $this->liveItem();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 400)]);

        $this->expectExceptionMessage('Facebook: Invalid OAuth access token. (code 190)');
        app(FacebookPagePublisher::class)->publish($item);
    }

    public function test_metrics_use_reel_plays_as_views(): void
    {
        $item = $this->liveItem();
        $item->update(['external_id' => '999']);
        Http::fake([
            'graph.facebook.com/*/999/video_insights*' => Http::response(['data' => [
                ['name' => 'blue_reels_play_count', 'values' => [['value' => 4210]]],
                ['name' => 'post_video_likes_by_reaction_type', 'values' => [['value' => ['REACTION_LIKE' => 30, 'REACTION_LOVE' => 5]]]],
            ]]),
            'graph.facebook.com/*/999*' => Http::response(['likes' => ['summary' => ['total_count' => 35]], 'comments' => ['summary' => ['total_count' => 7]]]),
        ]);

        $m = app(FacebookPagePublisher::class)->metrics($item);

        $this->assertSame(4210, $m['views']);
        $this->assertSame(35, $m['post_video_likes_by_reaction_type']);
        $this->assertSame(7, $m['comments']);
    }

    public function test_a_channel_in_dry_run_never_reaches_facebook(): void
    {
        $item = $this->liveItem();
        $item->channel->update(['live' => false]);

        $this->assertInstanceOf(DryRunPublisher::class, app(PublisherResolver::class)->for($item->channel->refresh()));
    }
}
