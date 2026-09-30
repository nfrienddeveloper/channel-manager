<?php

namespace Tests\Unit;

use App\Services\Trends\Sources\GoogleNews;
use App\Services\Trends\Sources\GoogleTrends;
use App\Services\Trends\Sources\Reddit;
use App\Services\Trends\Sources\Wikipedia;
use Tests\TestCase;

class TrendSourcesTest extends TestCase
{
    public function test_google_trends_feed_is_parsed_with_traffic_and_news_links(): void
    {
        $items = (new GoogleTrends)->parse($this->fixture('google-trends-us.xml'));

        $this->assertCount(4, $items);
        $this->assertSame("d'angelo russell", $items[0]->title);
        $this->assertSame(2000, $items[0]->traffic);
        $this->assertCount(2, $items[0]->articles);
        $this->assertSame('Bleacher Report', $items[0]->articles[0]['source']);
        $this->assertStringStartsWith('https://bleacherreport.com/', $items[0]->articles[0]['url']);
        $this->assertGreaterThan($items[1]->weight, $items[2]->weight, 'more traffic weighs more');
    }

    public function test_google_news_strips_the_publisher_and_keeps_related_coverage(): void
    {
        $items = (new GoogleNews)->parse($this->fixture('google-news-us.xml'));

        $this->assertSame("D'Angelo Russell signs with Shanghai Sharks after Grizzlies release", $items[0]->title);
        $this->assertSame('ESPN', $items[0]->articles[0]['source']);
        $this->assertSame('The Athletic', $items[0]->articles[1]['source']);
        $this->assertGreaterThan($items[1]->weight, $items[0]->weight, 'higher in the feed weighs more');
    }

    public function test_reddit_skips_nsfw_and_stickied_posts(): void
    {
        $items = (new Reddit)->parse(['data' => ['children' => [
            ['data' => ['title' => 'Safe post', 'ups' => 5000, 'permalink' => '/r/pics/1', 'subreddit' => 'pics']],
            ['data' => ['title' => 'NSFW post', 'ups' => 9000, 'over_18' => true, 'permalink' => '/r/x/2']],
            ['data' => ['title' => 'Mod post', 'ups' => 10, 'stickied' => true, 'permalink' => '/r/x/3']],
        ]]]);

        $this->assertCount(1, $items);
        $this->assertSame('Safe post', $items[0]->title);
    }

    public function test_wikipedia_skips_the_main_page_and_special_pages(): void
    {
        $items = (new Wikipedia)->parse(['items' => [['articles' => [
            ['article' => 'Main_Page', 'views' => 5000000],
            ['article' => 'Special:Search', 'views' => 900000],
            ['article' => "D'Angelo_Russell", 'views' => 120000],
        ]]]]);

        $this->assertCount(1, $items);
        $this->assertSame("D'Angelo Russell", $items[0]->title);
    }
}
