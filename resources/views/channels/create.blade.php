@extends('layouts.app')
@section('content')
<h1>New trending channel</h1>
<p class="muted">Picks the day's trending stories, writes and voices a short video about each, and posts it at set times. It starts in dry run: videos are saved to an outbox until you switch it live.</p>
<form class="card" method="post" action="{{ route('channels.store') }}" style="max-width:520px">
    @csrf
    <label>Name (also the brand shown in the videos)</label>
    <input type="text" name="name" value="{{ old('name', 'Trend Brief') }}" required>
    <label>Platform</label>
    <select name="platform">
        @foreach (\App\Models\Channel::PLATFORMS as $key => $label)
            <option value="{{ $key }}" @disabled(! array_key_exists($key, \App\Services\Platforms\PublisherResolver::SUPPORTED))>{{ $label }}{{ array_key_exists($key, \App\Services\Platforms\PublisherResolver::SUPPORTED) ? '' : ' (coming later)' }}</option>
        @endforeach
    </select>
    <label>Account</label>
    <select name="platform_account_id">
        <option value="">Connect one later</option>
        @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }} ({{ $a->platform }})</option>@endforeach
    </select>
    <div style="margin-top:16px"><button class="primary">Create channel</button></div>
</form>
@endsection
