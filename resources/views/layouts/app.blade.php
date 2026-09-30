<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Channel Manager')</title>
    <style>
        :root { --bg:#f6f5f2; --card:#fff; --ink:#1b1f29; --muted:#6b7080; --line:#e4e2dc; --brand:#1b2a4a; --accent:#ff5a36; --ok:#1f8a4c; --warn:#b7791f; --bad:#c0392b; }
        @media (prefers-color-scheme: dark) { :root { --bg:#12151c; --card:#1b1f29; --ink:#eceae4; --muted:#9aa0ad; --line:#2c3140; --brand:#9db4ff; } }
        * { box-sizing: border-box; }
        body { margin:0; font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Inter, sans-serif; background:var(--bg); color:var(--ink); }
        a { color: var(--brand); }
        header.top { display:flex; align-items:center; gap:24px; padding:14px 28px; border-bottom:1px solid var(--line); background:var(--card); }
        header.top .logo { font-weight:800; letter-spacing:-.02em; font-size:16px; }
        header.top nav a { margin-right:16px; text-decoration:none; color:var(--muted); font-weight:600; }
        header.top nav a.on { color:var(--ink); }
        main { max-width:1200px; margin:0 auto; padding:24px 28px 60px; }
        h1 { font-size:24px; margin:0 0 4px; letter-spacing:-.02em; }
        h2 { font-size:16px; margin:28px 0 12px; }
        .muted { color:var(--muted); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:18px; }
        .grid { display:grid; gap:16px; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); }
        .row { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
        .spread { justify-content:space-between; }
        .badge { display:inline-block; padding:2px 9px; border-radius:99px; font-size:12px; font-weight:600; background:var(--line); color:var(--ink); }
        .badge.ok { background:#dff3e6; color:var(--ok); } .badge.warn { background:#fbefd9; color:var(--warn); } .badge.bad { background:#f8dfdc; color:var(--bad); } .badge.live { background:var(--accent); color:#fff; }
        button, .btn { font:inherit; font-weight:600; border:1px solid var(--line); background:var(--card); color:var(--ink); padding:7px 14px; border-radius:8px; cursor:pointer; text-decoration:none; display:inline-block; }
        button.primary, .btn.primary { background:var(--brand); border-color:var(--brand); color:#fff; }
        button.danger { background:var(--bad); border-color:var(--bad); color:#fff; }
        form.inline { display:inline; }
        label { display:block; font-weight:600; margin:10px 0 4px; font-size:13px; }
        input[type=text], input[type=number], input[type=password], select, textarea { width:100%; font:inherit; padding:7px 10px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--ink); }
        .fields { display:grid; gap:0 16px; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); }
        .flash { padding:10px 14px; border-radius:8px; margin-bottom:16px; background:#dff3e6; color:var(--ok); }
        .flash.err { background:#f8dfdc; color:var(--bad); }
        .item { display:flex; gap:14px; }
        .item .media { width:110px; flex:none; aspect-ratio:9/16; background:#000; border-radius:8px; overflow:hidden; }
        .item .media img, .item .media video { width:100%; height:100%; object-fit:cover; display:block; }
        .item .body { min-width:0; flex:1; }
        .item pre { white-space:pre-wrap; font:12px/1.45 inherit; font-family:inherit; background:var(--bg); padding:8px; border-radius:6px; margin:6px 0 0; max-height:140px; overflow:auto; }
        table { width:100%; border-collapse:collapse; } td, th { text-align:left; padding:6px 8px; border-bottom:1px solid var(--line); vertical-align:top; font-size:13px; }
        .log td:first-child { white-space:nowrap; color:var(--muted); }
        .lvl-error { color:var(--bad); } .lvl-warning { color:var(--warn); }
        details summary { cursor:pointer; font-weight:600; }
    </style>
</head>
<body>
<header class="top">
    <div class="logo">Channel Manager</div>
    <nav>
        <a href="{{ route('channels.index') }}" class="{{ request()->routeIs('channels.*') ? 'on' : '' }}">Channels</a>
        <a href="{{ route('accounts.index') }}" class="{{ request()->routeIs('accounts.*') ? 'on' : '' }}">Accounts</a>
    </nav>
</header>
<main>
    @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="flash err">{{ $errors->first() }}</div>@endif
    @yield('content')
</main>
</body>
</html>
