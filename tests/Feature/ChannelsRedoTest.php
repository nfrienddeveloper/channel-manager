<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Jobs\ProduceContentItem;
use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChannelsRedoTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-11-01 06:00:00', 'UTC'));
        $this->channel = $this->makeChannel(settings: ['brand' => Channel::themePreset('unearthed')]);
    }

    private function item(array $attrs)
    {
        return $this->channel->items()->create($attrs + [
            'topic' => 'Tel Dan Stele', 'research' => ['notes' => [], 'images' => []],
            'storyboard' => ['id' => 'old', 'scenes' => [['type' => 'hook', 'text' => 'Old', 'vo' => 'Old.']]],
            'caption' => 'Old caption', 'video_path' => 'ads/old/render/old.mp4',
        ]);
    }

    public function test_unpublished_videos_are_rewritten_and_published_ones_left_alone(): void
    {
        $published = $this->item(['status' => ContentStatus::Published, 'published_at' => now()->subDay(), 'metrics' => ['views' => 900]]);
        $ready = $this->item(['status' => ContentStatus::Ready, 'scheduled_for' => now()->addHours(3)]);
        $failed = $this->item(['status' => ContentStatus::Failed, 'scheduled_for' => now()->addHours(7), 'attempts' => 3, 'error' => 'boom']);
        $stale = $this->item(['status' => ContentStatus::Ready, 'scheduled_for' => now()->subDays(2)]);
        $busy = $this->item(['status' => ContentStatus::Producing]);

        $this->artisan('channels:redo', ['channel' => $this->channel->id])->assertSuccessful();

        foreach ([$ready, $failed] as $item) {
            $item->refresh();
            $this->assertSame(ContentStatus::Planned, $item->status);
            $this->assertNull($item->storyboard, 'the script is rewritten');
            $this->assertNull($item->video_path);
            $this->assertSame(0, $item->attempts);
            $this->assertNotNull($item->research, 'research is kept');
        }
        $this->assertSame(ContentStatus::Published, $published->refresh()->status);
        $this->assertSame('ads/old/render/old.mp4', $published->video_path);
        $this->assertSame(ContentStatus::Ready, $stale->refresh()->status, 'a video past its time would only be dropped');
        $this->assertSame(ContentStatus::Producing, $busy->refresh()->status);
        Queue::assertPushed(ProduceContentItem::class, 2);
        $this->assertSame(5, $this->channel->items()->count(), 'nothing is reposted unless asked');
    }

    public function test_restyle_only_keeps_the_script_and_numbers_it(): void
    {
        $this->item(['status' => ContentStatus::Published]);
        $ready = $this->item(['status' => ContentStatus::Ready, 'scheduled_for' => now()->addHours(3)]);

        $this->artisan('channels:redo', ['channel' => $this->channel->id, '--restyle-only' => true])->assertSuccessful();

        $ready->refresh();
        $this->assertSame('Old', $ready->storyboard['scenes'][0]['text']);
        $this->assertSame(2, $ready->storyboard['episode']);
        $this->assertNull($ready->video_path);
    }

    public function test_repost_remakes_the_most_viewed_as_new_videos_in_free_slots(): void
    {
        $low = $this->item(['status' => ContentStatus::Published, 'metrics' => ['views' => 100]]);
        $top = $this->item(['status' => ContentStatus::Published, 'topic' => 'Pilate Stone', 'metrics' => ['views' => 5000]]);
        $mid = $this->item(['status' => ContentStatus::Published, 'topic' => 'Dead Sea Scrolls', 'metrics' => ['views' => 800]]);
        File::ensureDirectoryExists($top->workDir('assets'));
        File::put($top->workDir('assets/photo.jpg'), 'jpg');
        // Production needs two hours, so slots start at 08:00. 09:00 today is already taken.
        $this->item(['status' => ContentStatus::Planned, 'scheduled_for' => Carbon::parse('2026-11-01 09:00:00', 'UTC')]);

        $this->artisan('channels:redo', ['channel' => $this->channel->id, '--repost' => 2])->assertSuccessful();

        $new = $this->channel->items()->where('id', '>', $mid->id + 1)->orderBy('id')->get();
        $this->assertSame(['Pilate Stone', 'Dead Sea Scrolls'], $new->pluck('topic')->all(), 'most viewed first');
        $this->assertSame(['2026-11-01 13:00:00', '2026-11-02 09:00:00'], $new->map(fn ($i) => $i->scheduled_for->utc()->toDateTimeString())->all(), 'one a day, in free slots');
        $this->assertTrue($new->every(fn ($i) => $i->status === ContentStatus::Planned && $i->research !== null && $i->storyboard === null));
        $this->assertFileExists($new[0]->workDir('assets/photo.jpg'));
        $this->assertSame(ContentStatus::Published, $top->refresh()->status, 'the old post stays as it is');
        $this->assertSame(ContentStatus::Published, $low->refresh()->status);
        File::deleteDirectory($this->channel->storagePath());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $ready = $this->item(['status' => ContentStatus::Ready, 'scheduled_for' => now()->addHours(3)]);
        $this->item(['status' => ContentStatus::Published, 'metrics' => ['views' => 10]]);

        $this->artisan('channels:redo', ['channel' => $this->channel->id, '--repost' => 1, '--dry-run' => true])
            ->expectsOutputToContain('2 videos would be remade')->assertSuccessful();

        $this->assertSame(ContentStatus::Ready, $ready->refresh()->status);
        $this->assertSame(2, $this->channel->items()->count());
        Queue::assertNothingPushed();
    }
}
