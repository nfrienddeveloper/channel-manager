<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Services\Content\ScriptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ScriptBuilderTest extends TestCase
{
    use RefreshDatabase;

    private array $research = [
        'notes' => [
            ['title' => 'Russell joins Sharks', 'url' => 'https://bleacherreport.com/a', 'source' => 'Bleacher Report', 'text' => '...'],
            ['title' => 'Russell to China', 'url' => 'https://news.google.com/rss/articles/x', 'source' => 'ESPN', 'text' => null],
        ],
        'images' => [['id' => 'img1', 'file' => 'assets/img1.jpg', 'description' => 'basketball court', 'credit' => '"Court" by Jo (CC BY 2.0)', 'source_url' => 'https://example.com']],
    ];

    private function script(array $overrides = []): array
    {
        return $overrides + [
            'decision' => 'make', 'reason' => '', 'category' => 'sports', 'headline' => 'Russell heads to China',
            'scenes' => [
                ['type' => 'hook', 'text' => 'An All-Star is going to China', 'image' => 'img1', 'vo' => 'An All-Star just picked a new league.'],
                ['type' => 'split', 'text' => 'Waived last week', 'image' => 'img9', 'vo' => 'The Grizzlies waived him last week.'],
                ['type' => 'stat', 'value' => '655', 'label' => 'NBA games', 'vo' => 'Six hundred fifty-five games.'],
                ['type' => 'list', 'title' => 'Only one item', 'items' => ['one'], 'vo' => 'Dropped.'],
                ['type' => 'cta', 'vo' => 'Follow for more, and tell us what you think.'],
            ],
            'caption' => 'Russell is off to Shanghai. Would you go?',
            'hashtags' => ['NBA', '#Basketball'],
            'sources' => ['https://bleacherreport.com/a', 'https://made-up.example/'],
        ];
    }

    private function item()
    {
        $channel = $this->makeChannel();

        return $channel->items()->create(['status' => ContentStatus::Producing, 'topic' => "D'Angelo Russell"]);
    }

    public function test_it_builds_a_storyboard_and_caption_from_a_good_script(): void
    {
        $out = app(ScriptBuilder::class)->build($this->item(), $this->script(), $this->research);

        $this->assertNull($out['skip']);
        $types = array_column($out['storyboard']['scenes'], 'type');
        $this->assertSame(['hook', 'hook', 'stat', 'cta'], $types, 'photo-less split becomes a text card; a one-item list is dropped; CTA is last');
        $this->assertSame('assets/img1.jpg', $out['storyboard']['scenes'][0]['image']);
        $this->assertSame('Follow for the daily trends', $out['storyboard']['scenes'][3]['text']);
        $this->assertSame(['https://bleacherreport.com/a'], $out['sources'], 'sources not in the research are dropped');
        $this->assertStringContainsString('#NBA #Basketball #TrendingNow', $out['caption']);
        $this->assertStringContainsString('Photos: "Court" by Jo (CC BY 2.0)', $out['caption']);
        $this->assertStringContainsString('Sources: Bleacher Report', $out['caption']);
        $this->assertSame(['9x16'], $out['storyboard']['aspects']);
        $this->assertSame('Adam', $out['storyboard']['voice']['voice']);
    }

    public function test_it_skips_what_the_writer_or_the_channel_rules_out(): void
    {
        $builder = app(ScriptBuilder::class);

        $this->assertSame('Too thin', $builder->build($this->item(), $this->script(['decision' => 'skip', 'reason' => 'Too thin']), $this->research)['skip']);
        $this->assertStringContainsString('politics', $builder->build($this->item(), $this->script(['category' => 'politics']), $this->research)['skip']);

        $violent = $this->script();
        $violent['scenes'][0]['vo'] = 'Police say the suspect was arrested.';
        $this->assertStringContainsString('crime', $builder->build($this->item(), $violent, $this->research)['skip']);
    }

    public function test_it_rejects_a_script_that_cites_nothing_from_the_research(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ScriptBuilder::class)->build($this->item(), $this->script(['sources' => ['https://made-up.example/']]), $this->research);
    }
}
