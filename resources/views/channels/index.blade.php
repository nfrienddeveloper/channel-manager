@extends('layouts.app')
@section('content')
<div class="row spread">
    <div><h1>Channels</h1><div class="muted">Each channel finds what to post, makes the videos and posts them on its own.</div></div>
    <a class="btn primary" href="{{ route('channels.create') }}">New channel</a>
</div>
<h2></h2>
<div class="grid">
    @forelse ($channels as $channel)
        <a class="card" href="{{ route('channels.show', $channel) }}" style="text-decoration:none;color:inherit">
            <div class="row spread"><strong style="font-size:16px">{{ $channel->name }}</strong>
                @if ($channel->live)<span class="badge live">Live</span>@else<span class="badge">Dry run</span>@endif
            </div>
            <div class="muted">{{ $channel->platformLabel() }} · {{ \App\Models\Channel::STRATEGIES[$channel->strategy] ?? $channel->strategy }}</div>
            <div class="row" style="margin-top:10px">
                @if ($channel->active)<span class="badge ok">Autopilot on</span>@else<span class="badge warn">Paused</span>@endif
                <span class="muted">{{ $channel->published_count }} posted{{ $channel->account ? ' · '.$channel->account->name : '' }}</span>
            </div>
        </a>
    @empty
        <div class="card">No channels yet. Create one to start.</div>
    @endforelse
</div>
@endsection
