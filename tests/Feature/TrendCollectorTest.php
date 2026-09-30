<?php

namespace Tests\Feature;

use App\Models\Trend;
use App\Services\Trends\TrendCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrendCollectorTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSources(): void
    {
        Http::fake([
            'trends.google.com/*' => Http::response($this->fixture('google-trends-us.xml')),
            'news.google.com/*' => Http::response($this->fixture('google-news-us.xml')),
            'www.reddit.com/*' => Http::response('blocked', 403),
            'wikimedia.org/*' => Http::response(['items' => [['articles' => [['article' => 'Zach_Braff', 'views' => 90000]]]]]),
        ]);
    }

    public function test_it_merges_the_same_story_across_sources_and_ranks_it_first(): void
    {
        $this->fakeSources();

        $result = app(TrendCollector::class)->collect('US');

        $this->assertArrayHasKey('reddit', $result['errors'], 'a failing source is reported, not fatal');
        $top = Trend::orderByDesc('score')->first();
        $this->assertSame("D'Angelo Russell", $top->title);
        $this->assertEqualsCanonicalizing(['google_trends', 'google_news'], $top->sources);
        $braff = Trend::where('title', 'like', '%Braff%')->get();
        $this->assertCount(1, $braff);
        $this->assertEqualsCanonicalizing(['google_trends', 'wikipedia'], $braff->first()->sources);
    }

    public function test_collecting_again_updates_stories_instead_of_duplicating_them(): void
    {
        $this->fakeSources();
        $collector = app(TrendCollector::class);

        $collector->collect('US');
        $count = Trend::count();
        $this->travel(30)->minutes();
        $collector->collect('US');

        $this->assertSame($count, Trend::count());
        $this->assertNotNull($collector->lastCollectedAt('US'));
    }
}
