@extends('layouts.app')
@section('content')
<h1>Choose a Page</h1>
<form class="card" method="post" action="{{ route('accounts.facebook.store') }}" style="max-width:520px">
    @csrf
    @foreach ($pages as $p)
        <label style="font-weight:400"><input type="radio" name="page_id" value="{{ $p['id'] }}" @checked($loop->first)> {{ $p['name'] }}</label>
    @endforeach
    <div style="margin-top:14px"><button class="primary">Connect</button></div>
</form>
@endsection
