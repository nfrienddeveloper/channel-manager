<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Models\Trend;
use App\Services\Content\TopicPicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicPickerTest extends TestCase
{
    use RefreshDatabase;

    private function trend(string $title, float $score, array $headlines = []): Trend
    {
        return Trend::create([
            'region' => 'US', 'fingerprint' => md5($title), 'title' => $title, 'sources' => ['google_trends'],
            'articles' => array_map(fn ($h) => ['title' => $h, 'url' => 'https://example.com/'.md5($h), 'source' => 'Example'], $headlines),
            'score' => $score, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_it_picks_the_top_safe_story_the_channel_has_not_covered(): void
    {
        $channel = $this->makeChannel();
        $this->trend('Manassas Shooting', 9, ['Manhunt for suspect who shot 3 coworkers']);
        $this->trend('Strait Of Hormuz News', 8, ['Iran war live: US response under review']);
        $covered = $this->trend("D'Angelo Russell", 7, ['Russell joins Shanghai Sharks']);
        $this->trend('Zach Braff', 5, ["'Scrubs' Zach Braff gives Ken Jenkins update"]);
        $channel->items()->create(['trend_id' => $covered->id, 'status' => ContentStatus::Published, 'topic' => $covered->title]);

        $this->assertSame('Zach Braff', app(TopicPicker::class)->pick($channel)?->title);
    }

    public function test_it_skips_a_story_already_covered_under_another_headline(): void
    {
        $channel = $this->makeChannel();
        $channel->items()->create(['status' => ContentStatus::Published, 'topic' => "D'Angelo Russell joins Shanghai Sharks"]);
        $this->trend('Russell Shanghai Sharks', 9);
        $this->trend('Moon Rover', 3);

        $this->assertSame('Moon Rover', app(TopicPicker::class)->pick($channel)?->title);
    }

    public function test_include_keywords_narrow_the_channel_to_a_niche(): void
    {
        $channel = $this->makeChannel(settings: ['include_keywords' => ['nba']]);
        $this->trend('Zach Braff', 9, ['Scrubs season 2']);
        $this->trend("D'Angelo Russell", 5, ['Former NBA guard joins CBA team']);

        $this->assertSame("D'Angelo Russell", app(TopicPicker::class)->pick($channel)?->title);
    }

    public function test_stale_trends_are_ignored(): void
    {
        $channel = $this->makeChannel();
        $this->trend('Old News', 9)->update(['last_seen_at' => now()->subDays(2)]);

        $this->assertNull(app(TopicPicker::class)->pick($channel));
    }
}
