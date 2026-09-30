@extends('layouts.app')
@section('title', $channel->name.' · Channel Manager')
@section('content')
@php
    $tz = $channel->setting('timezone');
    $badge = fn ($s) => match ($s) { \App\Enums\ContentStatus::Published => 'ok', \App\Enums\ContentStatus::Failed => 'bad', \App\Enums\ContentStatus::Skipped => 'warn', default => '' };
@endphp
<div class="row spread">
    <div>
        <h1>{{ $channel->name }}</h1>
        <div class="muted">{{ $channel->platformLabel() }} · {{ $channel->account?->name ?? 'no account connected' }} · last run {{ $channel->last_tick_at?->diffForHumans() ?? 'never' }}</div>
    </div>
    <div class="row">
        <form class="inline" method="post" action="{{ route('channels.toggle', $channel) }}">@csrf<input type="hidden" name="what" value="active">
            <button class="{{ $channel->active ? '' : 'primary' }}">{{ $channel->active ? 'Pause autopilot' : 'Start autopilot' }}</button></form>
        <form class="inline" method="post" action="{{ route('channels.toggle', $channel) }}" onsubmit="return {{ $channel->live ? 'true' : "confirm('Go live? From now on videos are posted to ".e(addslashes($channel->account?->name ?? 'the Page'))." with no review.')" }}">@csrf<input type="hidden" name="what" value="live">
            <button class="{{ $channel->live ? '' : 'danger' }}">{{ $channel->live ? 'Back to dry run' : 'Go live' }}</button></form>
        <form class="inline" method="post" action="{{ route('channels.make-now', $channel) }}">@csrf<button>Make one now</button></form>
    </div>
</div>
<div class="row" style="margin-top:10px">
    @if ($channel->active)<span class="badge ok">Autopilot on</span>@else<span class="badge warn">Paused</span>@endif
    @if ($channel->live)<span class="badge live">Live: posting to {{ $channel->account?->name }}</span>@else<span class="badge">Dry run: videos go to the outbox, nothing is posted</span>@endif
    <span class="muted">Posts {{ $channel->setting('posts_per_day') }} a day at {{ implode(', ', array_slice($channel->setting('post_times'), 0, $channel->setting('posts_per_day'))) }} ({{ $tz }})</span>
</div>

<h2>Videos</h2>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(360px,1fr))">
    @forelse ($items as $item)
        <div class="card item">
            <div class="media">
                @if ($item->video_path)
                    <video src="{{ route('items.media', [$item, 'video']) }}" @if ($item->thumb_path) poster="{{ route('items.media', [$item, 'thumb']) }}" @endif controls preload="none"></video>
                @endif
            </div>
            <div class="body">
                <div class="row spread"><strong>{{ $item->topic }}</strong><span class="badge {{ $badge($item->status) }}">{{ $item->status === \App\Enums\ContentStatus::Published && $item->dry_run ? 'Dry run' : $item->status->label() }}</span></div>
                <div class="muted">
                    {{ $item->published_at ? 'Posted '.$item->published_at->setTimezone($tz)->format('D M j, g:ia') : ($item->scheduled_for ? 'For '.$item->scheduled_for->setTimezone($tz)->format('D M j, g:ia') : '') }}
                    {{ $item->category ? '· '.$item->category : '' }} {{ $item->seconds ? '· '.round($item->seconds).' s' : '' }} {{ $item->voice_engine ? '· voice '.$item->voice_engine : '' }}
                </div>
                @if ($item->metrics)<div>{{ collect($item->metrics)->only(['views', 'likes', 'comments'])->map(fn ($v, $k) => number_format($v).' '.$k)->implode(' · ') }}</div>@endif
                @if ($item->external_url)<a href="{{ $item->external_url }}" target="_blank">Open on {{ $channel->platformLabel() }}</a>@endif
                @if ($item->error)<div class="{{ $item->status === \App\Enums\ContentStatus::Failed ? 'lvl-error' : 'muted' }}">{{ \Illuminate\Support\Str::limit($item->error, 200) }}</div>@endif
                @if ($item->caption)<pre>{{ $item->caption }}</pre>@endif
                @if ($item->status->isOpen() && $item->status !== \App\Enums\ContentStatus::Publishing)
                    <form class="inline" method="post" action="{{ route('items.skip', $item) }}">@csrf<button style="margin-top:6px">Skip</button></form>
                @endif
            </div>
        </div>
    @empty
        <div class="card muted">Nothing yet. Start the autopilot, or press "Make one now".</div>
    @endforelse
</div>

<div class="grid" style="grid-template-columns:1fr 1fr;margin-top:8px">
    <div>
        <h2>Next up: trending topics this channel would pick</h2>
        <div class="card">
            <table>
                @forelse ($candidates as $t)
                    <tr><td>{{ $t->title }}<div class="muted">{{ collect($t->articles)->take(1)->pluck('title')->first() }}</div></td><td class="muted">{{ implode(', ', $t->sources) }}</td></tr>
                @empty
                    <tr><td class="muted">No trends collected yet.</td></tr>
                @endforelse
            </table>
        </div>
    </div>
    <div>
        <h2>Activity</h2>
        <div class="card" style="max-height:420px;overflow:auto">
            <table class="log">
                @forelse ($events as $e)
                    <tr><td>{{ $e->created_at->setTimezone($tz)->format('M j g:ia') }}</td><td class="lvl-{{ $e->level }}">{{ $e->message }}</td></tr>
                @empty
                    <tr><td class="muted">No activity yet.</td></tr>
                @endforelse
            </table>
        </div>
    </div>
</div>

<h2>Settings</h2>
<form class="card" method="post" action="{{ route('channels.update', $channel) }}">
    @csrf @method('put')
    <div class="fields">
        <div><label>Channel name</label><input type="text" name="name" value="{{ $channel->name }}"></div>
        <div><label>Account</label><select name="platform_account_id"><option value="">None</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected($channel->platform_account_id === $a->id)>{{ $a->name }}</option>@endforeach</select></div>
        <div><label>Brand name in videos</label><input type="text" name="brand_name" value="{{ $channel->setting('brand.name') }}"></div>
        <div><label>Tagline</label><input type="text" name="tagline" value="{{ $channel->setting('brand.tagline') }}"></div>
        <div><label>Closing call to action</label><input type="text" name="cta" value="{{ $channel->setting('brand.cta') }}"></div>
        <div><label>Brand colours (main, accent)</label><div class="row"><input type="color" name="color_primary" value="{{ $channel->setting('brand.colors.primary') }}"><input type="color" name="color_accent" value="{{ $channel->setting('brand.colors.accent') }}"></div></div>
        <div><label>Posts per day</label><input type="number" name="posts_per_day" min="1" max="10" value="{{ $channel->setting('posts_per_day') }}"></div>
        <div><label>Post times (24h, comma separated)</label><input type="text" name="post_times" value="{{ implode(', ', $channel->setting('post_times')) }}"></div>
        <div><label>Timezone</label><input type="text" name="timezone" value="{{ $tz }}"></div>
        <div><label>Trends from country (2 letters)</label><input type="text" name="region" value="{{ $channel->setting('region') }}"></div>
        <div><label>Only topics mentioning (optional)</label><input type="text" name="include_keywords" value="{{ implode(', ', $channel->setting('include_keywords')) }}" placeholder="e.g. nba, nfl, movie"></div>
        <div><label>Never topics mentioning</label><input type="text" name="exclude_keywords" value="{{ implode(', ', $channel->setting('exclude_keywords')) }}"></div>
        <div><label>Always add hashtags</label><input type="text" name="hashtags" value="{{ implode(', ', $channel->setting('hashtags')) }}"></div>
        <div><label>Voice engine</label><select name="voice_engine">@foreach (['auto' => 'ElevenLabs if set up, else free voice', 'elevenlabs' => 'ElevenLabs', 'kokoro' => 'Kokoro (free, local)', 'none' => 'No voice, captions only'] as $k => $l)<option value="{{ $k }}" @selected($channel->setting('voice.engine') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div><label>Voice</label><input type="text" name="voice" value="{{ $channel->setting('voice.voice') }}"></div>
        <div><label>Longest video (seconds)</label><input type="number" name="max_seconds" min="10" max="90" value="{{ $channel->setting('max_seconds') }}"></div>
    </div>
    <label>Tone</label><textarea name="tone" rows="2">{{ $channel->setting('tone') }}</textarea>
    <label>Topics this channel never covers</label>
    <div class="row">
        @foreach ($categories as $key => $label)
            <label style="font-weight:400;margin:0"><input type="checkbox" name="avoid[]" value="{{ $key }}" @checked(in_array($key, $channel->setting('avoid')))> {{ $label }}</label>
        @endforeach
    </div>
    <div style="margin-top:16px" class="row spread">
        <button class="primary">Save settings</button>
    </div>
</form>
<form method="post" action="{{ route('channels.destroy', $channel) }}" onsubmit="return confirm('Delete this channel and its videos list?')" style="margin-top:12px">@csrf @method('delete')<button>Delete channel</button></form>
@endsection
