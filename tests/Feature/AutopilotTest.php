<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Services\Channels\Autopilot;
use App\Services\Content\Writers\ScriptWriter;
use App\Services\Content\Writers\TemplateWriter;
use App\Services\Media\VideoRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutopilotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'trends.google.com/*' => Http::response($this->fixture('google-trends-us.xml')),
            'news.google.com/*' => Http::response($this->fixture('google-news-us.xml')),
            'www.reddit.com/*' => Http::response('', 403),
            'wikimedia.org/*' => Http::response(['items' => []]),
            'bleacherreport.com/*' => Http::response('<html><head><meta property="og:description" content="Russell joins the Shanghai Sharks."></head><body><p>'.str_repeat('D\'Angelo Russell is headed to China after his release. ', 3).'</p></body></html>', 200, ['Content-Type' => 'text/html']),
            'en.wikipedia.org/*' => Http::response([], 404),
            'api.openverse.org/*' => Http::response(['results' => []]),
            '*' => Http::response('', 404),
        ]);
        $this->app->bind(ScriptWriter::class, TemplateWriter::class);
        // Rendering needs Chromium and FFmpeg; here it just writes a placeholder file.
        $this->app->instance(VideoRenderer::class, new class extends VideoRenderer
        {
            public function render(ContentItem $item): array
            {
                File::ensureDirectoryExists($item->workDir('render'));
                File::put($item->workDir('render/v.mp4'), 'mp4');

                return ['video' => 'render/v.mp4', 'thumb' => null, 'seconds' => 20.5, 'voice_engine' => 'none'];
            }
        });
    }

    public function test_a_day_on_autopilot_plans_makes_and_publishes_to_the_outbox_in_dry_run(): void
    {
        Carbon::setTestNow('2026-10-01 07:30:00');
        $channel = $this->makeChannel(settings: ['posts_per_day' => 2, 'post_times' => ['09:00', '13:00']]);
        $autopilot = app(Autopilot::class);

        $report = $autopilot->tick($channel);

        $this->assertSame(1, $report['planned'], 'only the 9:00 slot is inside the lead time');
        $item = $channel->items()->first();
        $this->assertSame("D'Angelo Russell", $item->topic, 'the unsafe stories ranked above it are passed over');
        $this->assertSame(ContentStatus::Ready, $item->status, 'produced by the (sync) queue');
        $this->assertSame('2026-10-01 09:00:00', $item->scheduled_for->toDateTimeString());
        $this->assertStringContainsString('#TrendingNow', $item->caption);

        Carbon::setTestNow('2026-10-01 09:01:00');
        $autopilot->tick($channel);

        $item->refresh();
        $this->assertSame(ContentStatus::Published, $item->status);
        $this->assertTrue($item->dry_run);
        $outbox = File::files($channel->storagePath('outbox'));
        $this->assertCount(2, $outbox, 'video and caption');

        Carbon::setTestNow('2026-10-01 11:05:00');
        $autopilot->tick($channel);
        $this->assertSame(2, $channel->items()->count(), 'the 13:00 slot is planned once inside the lead time');
        $this->assertNotSame($item->topic, $channel->items()->latest('id')->first()->topic, 'with a different story');
    }

    public function test_a_paused_channel_does_nothing(): void
    {
        $channel = $this->makeChannel(['active' => false]);

        app(Autopilot::class)->tick($channel);

        $this->assertSame(0, $channel->items()->count());
        Http::assertNothingSent();
    }

    public function test_a_video_that_missed_its_slot_by_hours_is_dropped_not_posted(): void
    {
        Carbon::setTestNow('2026-10-01 20:00:00');
        $channel = $this->makeChannel(settings: ['post_times' => ['09:00']]);
        $item = $channel->items()->create(['status' => ContentStatus::Ready, 'topic' => 'Old', 'scheduled_for' => '2026-10-01 09:00:00', 'video_path' => 'x.mp4']);

        app(Autopilot::class)->tick($channel);

        $this->assertSame(ContentStatus::Skipped, $item->refresh()->status);
    }

    public function test_slots_follow_the_channel_timezone(): void
    {
        $channel = $this->makeChannel(settings: ['timezone' => 'America/New_York', 'post_times' => ['09:00']]);

        $slots = app(Autopilot::class)->upcomingSlots($channel, Carbon::parse('2026-10-01 12:00:00', 'UTC'));

        $this->assertSame(['2026-10-01 13:00:00'], $slots->map->toDateTimeString()->all(), '9:00 in New York is 13:00 UTC in October');
    }
}
