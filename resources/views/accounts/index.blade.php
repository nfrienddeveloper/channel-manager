@extends('layouts.app')
@section('content')
<h1>Accounts</h1>
<p class="muted">Pages and profiles the app can post to. Tokens are stored encrypted on this computer only.</p>
<div class="card">
    @forelse ($accounts as $a)
        <div class="row spread" style="padding:6px 0;border-bottom:1px solid var(--line)">
            <div><strong>{{ $a->name }}</strong> <span class="muted">{{ ucfirst($a->platform) }} · {{ $a->channels_count }} channel(s) · {{ $a->token_expires_at ? 'token expires '.$a->token_expires_at->diffForHumans() : 'token does not expire' }}</span></div>
            <form class="inline" method="post" action="{{ route('accounts.destroy', $a) }}" onsubmit="return confirm('Remove {{ $a->name }}? Channels using it go back to dry run.')">@csrf @method('delete')<button>Remove</button></form>
        </div>
    @empty
        <div class="muted">Nothing connected yet.</div>
    @endforelse
</div>

<h2>Connect a Facebook Page</h2>
<div class="card" style="max-width:720px">
    <ol style="margin-top:0;padding-left:18px">
        <li>At <a href="https://developers.facebook.com/apps" target="_blank">developers.facebook.com/apps</a>, open your app (use case "Manage everything on your Page").</li>
        <li>Open the <a href="https://developers.facebook.com/tools/explorer" target="_blank">Graph API Explorer</a>, choose your app, and add the permissions <code>pages_show_list</code>, <code>pages_read_engagement</code>, <code>pages_manage_posts</code> and <code>read_insights</code>.</li>
        <li>Click Generate Access Token, approve, and pick your Page. Paste the token below.</li>
        <li>Add the App ID and App secret (App settings, Basic) so the Page token never expires.</li>
    </ol>
    <form method="post" action="{{ route('accounts.facebook.pages') }}" autocomplete="off">
        @csrf
        <label>User access token</label><input type="password" name="user_token" required>
        <div class="fields">
            <div><label>App ID</label><input type="text" name="app_id"></div>
            <div><label>App secret</label><input type="password" name="app_secret"></div>
        </div>
        <div style="margin-top:14px"><button class="primary">Find my Pages</button></div>
    </form>
</div>
@endsection
