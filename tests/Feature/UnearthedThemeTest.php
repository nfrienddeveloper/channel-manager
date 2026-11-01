<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Models\Channel;
use App\Services\Content\ScriptBuilder;
use App\Services\Content\Writers\ScriptPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnearthedThemeTest extends TestCase
{
    use RefreshDatabase;

    private array $research = [
        'notes' => [['title' => 'Tel Dan Stele', 'url' => 'https://www.imj.org.il/en/collections/tel-dan', 'source' => 'Israel Museum', 'text' => '...']],
        'images' => [['id' => 'img1', 'file' => 'assets/stele.jpg', 'description' => 'the Tel Dan Stele', 'credit' => 'Public domain', 'source_url' => 'https://example.com']],
    ];

    private function script(): array
    {
        return [
            'decision' => 'make', 'reason' => '', 'category' => 'culture', 'headline' => 'The stone that names King David',
            'scenes' => [
                ['type' => 'hook', 'text' => 'A stone that names *King David*', 'image' => 'img1', 'vo' => 'In 1993, archaeologists found a broken stone.'],
                ['type' => 'keyline', 'text' => 'House of David', 'translit' => 'b·y·t·d·w·d', 'vo' => 'One line reads, House of David.'],
                ['type' => 'artifact', 'title' => 'Tel Dan Stele', 'image' => 'img1', 'ref' => '2 Kings 9', 'vo' => 'An Aramean king carved it.',
                    'rows' => [['label' => 'Date', 'value' => 'c. 840 BC'], ['label' => 'Found', 'value' => '1993, Tel Dan'], ['label' => '', 'value' => 'dropped']]],
                ['type' => 'stat', 'value' => '130', 'label' => 'years after *David*', 'vo' => 'About a hundred and thirty years after David.'],
                ['type' => 'cta', 'vo' => 'Follow for the next find.'],
            ],
            'caption' => 'A stone from 840 BC names the House of David. Did you know?',
            'hashtags' => ['BibleHistory'],
            'sources' => ['https://www.imj.org.il/en/collections/tel-dan'],
        ];
    }

    private function channel(string $theme): Channel
    {
        return $this->makeChannel(settings: ['brand' => Channel::themePreset($theme)]);
    }

    public function test_unearthed_keeps_its_scene_types_emphasis_and_numbers_the_episode(): void
    {
        $channel = $this->channel('unearthed');
        $channel->items()->create(['status' => ContentStatus::Published, 'topic' => 'Earlier find', 'video_path' => 'a.mp4']);
        $channel->items()->create(['status' => ContentStatus::Skipped, 'topic' => 'Skipped find']);
        $item = $channel->items()->create(['status' => ContentStatus::Producing, 'topic' => 'Tel Dan Stele']);

        $sb = app(ScriptBuilder::class)->build($item, $this->script(), $this->research)['storyboard'];

        $this->assertSame(['hook', 'keyline', 'artifact', 'stat', 'cta'], array_column($sb['scenes'], 'type'));
        $this->assertSame('A stone that names *King David*', $sb['scenes'][0]['text']);
        $this->assertSame([['label' => 'Date', 'value' => 'c. 840 BC'], ['label' => 'Found', 'value' => '1993, Tel Dan']], $sb['scenes'][2]['rows']);
        $this->assertSame('assets/stele.jpg', $sb['scenes'][2]['image']);
        $this->assertSame(2, $sb['episode'], 'only videos actually made count toward the number');
        $this->assertSame('A new find, every day', $sb['scenes'][4]['text']);
    }

    public function test_the_classic_look_drops_scenes_it_cannot_draw_and_strips_emphasis(): void
    {
        $item = $this->channel('default')->items()->create(['status' => ContentStatus::Producing, 'topic' => 'Tel Dan Stele']);

        $sb = app(ScriptBuilder::class)->build($item, $this->script(), $this->research)['storyboard'];

        $this->assertSame(['hook', 'stat', 'cta'], array_column($sb['scenes'], 'type'));
        $this->assertSame('A stone that names King David', $sb['scenes'][0]['text']);
        $this->assertSame('years after David', $sb['scenes'][1]['label']);
        $this->assertArrayNotHasKey('episode', $sb);
    }

    public function test_the_prompt_describes_the_extra_scenes_only_for_unearthed(): void
    {
        $item = fn (string $theme) => $this->channel($theme)->items()->create(['status' => ContentStatus::Producing, 'topic' => 'Tel Dan Stele']);

        $this->assertStringContainsString('"artifact": a museum placard', ScriptPrompt::build($item('unearthed'), $this->research));
        $this->assertStringNotContainsString('"artifact"', ScriptPrompt::build($item('default'), $this->research));
    }

    public function test_switching_a_channel_to_unearthed_applies_the_preset(): void
    {
        $channel = $this->makeChannel();

        $this->put("/channels/{$channel->id}", [
            'name' => 'Unearthed', 'brand_name' => 'Unearthed', 'tagline' => $channel->setting('brand.tagline'), 'cta' => $channel->setting('brand.cta'),
            'tone' => 'calm, reverent and precise', 'posts_per_day' => 1, 'post_times' => '18:00', 'timezone' => 'UTC', 'region' => 'US',
            'voice' => 'Adam', 'voice_engine' => 'none', 'max_seconds' => 40,
            'color_primary' => '#1b2a4a', 'color_accent' => '#ff5a36', 'theme' => 'unearthed',
        ])->assertSessionHasNoErrors();

        $channel->refresh();
        $this->assertSame('unearthed', $channel->setting('brand.theme'));
        $this->assertSame('Cormorant Garamond', $channel->setting('brand.fonts.heading'));
        $this->assertSame('#c9a45c', $channel->setting('brand.colors.accent'));
        $this->assertSame('History, dug up. Scripture, examined.', $channel->setting('brand.tagline'));
        $this->assertSame('Artifact No.', $channel->setting('brand.series'));
        $this->assertSame('Unearthed', $channel->setting('brand.name'));
    }
}
