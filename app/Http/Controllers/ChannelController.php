<?php

namespace App\Http\Controllers;

use App\Enums\ContentStatus;
use App\Jobs\ProduceContentItem;
use App\Models\Channel;
use App\Models\PlatformAccount;
use App\Services\Channels\Autopilot;
use App\Services\Content\SafetyFilter;
use App\Services\Content\TopicPicker;
use App\Services\Platforms\PublisherResolver;
use App\Services\Trends\TrendCollector;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ChannelController extends Controller
{
    public function index()
    {
        $channels = Channel::with('account')->withCount(['items as published_count' => fn ($q) => $q->where('status', ContentStatus::Published)])->get();

        return view('channels.index', compact('channels'));
    }

    public function create()
    {
        return view('channels.create', ['accounts' => PlatformAccount::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'platform' => 'required|in:'.implode(',', array_keys(PublisherResolver::SUPPORTED)),
            'platform_account_id' => 'nullable|exists:platform_accounts,id',
        ]);
        $settings = Channel::defaultSettings();
        $settings['brand']['name'] = $data['name'];
        $channel = Channel::create($data + ['strategy' => 'trending', 'active' => false, 'live' => false, 'settings' => $settings]);

        return redirect()->route('channels.show', $channel)->with('status', 'Channel created. It runs in dry run until you switch it live.');
    }

    public function show(Channel $channel, TopicPicker $picker, Autopilot $autopilot)
    {
        $items = $channel->items()->with('trend')->orderByRaw('scheduled_for is null')->orderByDesc('scheduled_for')->orderByDesc('id')->limit(40)->get();
        $events = $channel->events()->latest('id')->limit(60)->get();
        $candidates = $picker->candidates($channel)->take(10);
        $accounts = PlatformAccount::where('platform', $channel->platform)->orderBy('name')->get();
        $categories = SafetyFilter::CATEGORIES;
        $nextSlots = $autopilot->upcomingSlots($channel, now()->subMinutes(config('channels.lead_minutes')))->take(3);

        return view('channels.show', compact('channel', 'items', 'events', 'candidates', 'accounts', 'categories', 'nextSlots'));
    }

    public function update(Request $request, Channel $channel)
    {
        $v = $request->validate([
            'name' => 'required|string|max:80',
            'platform_account_id' => 'nullable|exists:platform_accounts,id',
            'brand_name' => 'required|string|max:60',
            'tagline' => 'nullable|string|max:120',
            'cta' => 'required|string|max:40',
            'tone' => 'required|string|max:300',
            'posts_per_day' => 'required|integer|min:1|max:10',
            'post_times' => ['required', 'string', 'regex:/^\s*\d{1,2}:\d{2}(\s*,\s*\d{1,2}:\d{2})*\s*$/'],
            'timezone' => 'required|timezone',
            'region' => 'required|string|size:2',
            'avoid' => 'array',
            'avoid.*' => 'in:'.implode(',', array_keys(SafetyFilter::CATEGORIES)),
            'include_keywords' => 'nullable|string|max:500',
            'exclude_keywords' => 'nullable|string|max:500',
            'hashtags' => 'nullable|string|max:200',
            'voice' => 'required|string|max:60',
            'voice_engine' => 'required|in:auto,elevenlabs,kokoro,none',
            'max_seconds' => 'required|integer|min:10|max:90',
            'color_primary' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
            'color_accent' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
        ]);
        $list = fn (?string $s) => array_values(array_filter(array_map('trim', explode(',', (string) $s))));
        $times = collect($list($v['post_times']))->map(fn ($t) => sprintf('%02d:%02d', ...array_map('intval', explode(':', $t))))->sort()->values()->all();

        $s = $channel->settings;
        Arr::set($s, 'brand.name', $v['brand_name']);
        Arr::set($s, 'brand.tagline', $v['tagline']);
        Arr::set($s, 'brand.cta', $v['cta']);
        Arr::set($s, 'brand.colors.primary', $v['color_primary']);
        Arr::set($s, 'brand.colors.accent', $v['color_accent']);
        $s['tone'] = $v['tone'];
        $s['posts_per_day'] = (int) $v['posts_per_day'];
        $s['post_times'] = $times;
        $s['timezone'] = $v['timezone'];
        $s['region'] = strtoupper($v['region']);
        $s['avoid'] = $v['avoid'] ?? [];
        $s['include_keywords'] = $list($v['include_keywords'] ?? '');
        $s['exclude_keywords'] = $list($v['exclude_keywords'] ?? '');
        $s['hashtags'] = array_map(fn ($h) => ltrim($h, '#'), $list($v['hashtags'] ?? ''));
        $s['voice'] = ['engine' => $v['voice_engine'], 'voice' => $v['voice'], 'fallback' => $s['voice']['fallback'] ?? 'am_michael'];
        $s['max_seconds'] = (int) $v['max_seconds'];

        $channel->update(['name' => $v['name'], 'platform_account_id' => $v['platform_account_id'] ?? null, 'settings' => $s]);

        return back()->with('status', 'Settings saved.');
    }

    public function toggle(Request $request, Channel $channel)
    {
        $what = $request->validate(['what' => 'required|in:active,live'])['what'];
        if ($what === 'live' && ! $channel->live && ! $channel->account) {
            return back()->withErrors(['live' => 'Connect a '.$channel->platformLabel().' account to this channel before going live.']);
        }
        $channel->update([$what => ! $channel->$what]);
        $channel->log(match ($what) {
            'active' => $channel->active ? 'Autopilot started' : 'Autopilot paused',
            'live' => $channel->live ? 'Switched to LIVE: videos will be posted to '.$channel->account->name : 'Switched to dry run: videos go to the outbox',
        });

        return back();
    }

    /** Plans the top trend right now and queues its production; it posts as soon as it is ready. */
    public function makeNow(Channel $channel, TrendCollector $collector, TopicPicker $picker)
    {
        if (! $collector->lastCollectedAt($channel->setting('region'))) {
            $collector->collect($channel->setting('region'), $channel->setting('sources'));
        }
        $trend = $picker->pick($channel);
        if (! $trend) {
            return back()->withErrors(['make' => 'No suitable trending topic right now.']);
        }
        $item = $channel->items()->create([
            'trend_id' => $trend->id, 'status' => ContentStatus::Planned, 'topic' => $trend->title,
            'scheduled_for' => now(), 'dry_run' => ! $channel->live,
        ]);
        $channel->log("Making \"{$trend->title}\" now (requested from the app)", item: $item);
        ProduceContentItem::dispatch($item);

        return back()->with('status', "Making a video about \"{$trend->title}\". It will ".($channel->live ? 'post' : 'go to the outbox').' when ready (if the autopilot is on).');
    }

    public function destroy(Channel $channel)
    {
        $channel->delete();

        return redirect()->route('channels.index')->with('status', 'Channel deleted.');
    }
}
