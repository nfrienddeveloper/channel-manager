<?php

namespace Tests\Feature;

use App\Models\PlatformAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannelPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_render(): void
    {
        $channel = $this->makeChannel();

        $this->get('/')->assertOk()->assertSee('Trend Brief');
        $this->get('/channels/create')->assertOk();
        $this->get("/channels/{$channel->id}")->assertOk()->assertSee('Dry run');
        $this->get('/accounts')->assertOk()->assertSee('Connect a Facebook Page');
    }

    public function test_a_channel_cannot_go_live_without_an_account(): void
    {
        $channel = $this->makeChannel();

        $this->post("/channels/{$channel->id}/toggle", ['what' => 'live'])->assertSessionHasErrors('live');
        $this->assertFalse($channel->refresh()->live);
    }

    public function test_settings_save(): void
    {
        $channel = $this->makeChannel();

        $this->put("/channels/{$channel->id}", [
            'name' => 'Sports Now', 'brand_name' => 'Sports Now', 'tagline' => '', 'cta' => 'Follow for more', 'tone' => 'hype',
            'posts_per_day' => 2, 'post_times' => '18:30, 8:00', 'timezone' => 'America/Chicago', 'region' => 'us',
            'avoid' => ['tragedy', 'adult'], 'include_keywords' => 'nba, nfl', 'exclude_keywords' => '', 'hashtags' => '#Sports',
            'voice' => 'Adam', 'voice_engine' => 'elevenlabs', 'max_seconds' => 30, 'color_primary' => '#112233', 'color_accent' => '#ff0000',
        ])->assertSessionHasNoErrors();

        $channel->refresh();
        $this->assertSame(['08:00', '18:30'], $channel->setting('post_times'));
        $this->assertSame(['nba', 'nfl'], $channel->setting('include_keywords'));
        $this->assertSame(['Sports'], $channel->setting('hashtags'));
        $this->assertSame('US', $channel->setting('region'));
        $this->assertSame('#112233', $channel->setting('brand.colors.primary'));
    }

    public function test_connecting_a_facebook_page_stores_a_long_lived_page_token_encrypted(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'LONG_USER']),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
                ['id' => '1234', 'name' => 'Trend Brief', 'access_token' => 'PAGE_TOKEN', 'tasks' => ['MANAGE', 'CREATE_CONTENT']],
                ['id' => '5678', 'name' => 'Read Only', 'access_token' => 'X', 'tasks' => ['ANALYZE']],
            ]]),
        ]);

        $this->post('/accounts/facebook', ['user_token' => 'SHORT', 'app_id' => '1', 'app_secret' => 's'])
            ->assertOk()->assertSee('Trend Brief')->assertDontSee('Read Only');
        $this->post('/accounts/facebook/store', ['page_id' => '1234'])->assertRedirect('/accounts');

        $account = PlatformAccount::firstOrFail();
        $this->assertSame('PAGE_TOKEN', $account->token());
        $this->assertNull($account->token_expires_at);
        $this->assertStringNotContainsString('PAGE_TOKEN', $account->getRawOriginal('credentials'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'me/accounts') && $r['access_token'] === 'LONG_USER');
    }
}
