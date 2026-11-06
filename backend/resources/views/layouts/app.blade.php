<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Alarme de Pedidos')</title>
    <style>
        :root { --brand:#A543FA; --brand-dark:#8a2fe0; --text:#1f2330; --muted:#6b7280; --line:#e5e7eb; --bg:#f7f5fb; }
        * { box-sizing: border-box; }
        body { margin:0; font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; color:var(--text); background:var(--bg); }
        .wrap { max-width:720px; margin:0 auto; padding:32px 20px 64px; }
        header { display:flex; align-items:center; gap:12px; margin-bottom:28px; }
        .logo { width:40px; height:40px; border-radius:12px; background:var(--brand); display:grid; place-items:center; color:#fff; font-size:22px; }
        h1 { font-size:22px; margin:0; }
        .sub { color:var(--muted); font-size:14px; margin:0; }
        .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:22px; margin-bottom:18px; }
        .card h2 { font-size:17px; margin:0 0 6px; }
        .card p { margin:0 0 12px; color:var(--muted); }
        .btn { display:inline-block; border:0; border-radius:10px; padding:12px 18px; font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
        .btn-primary { background:var(--brand); color:#fff; }
        .btn-primary:hover { background:var(--brand-dark); }
        .btn-ghost { background:transparent; color:var(--muted); border:1px solid var(--line); }
        .btn-danger { background:transparent; color:#b91c1c; border:1px solid #fecaca; padding:6px 12px; font-size:14px; }
        .code { font:700 44px/1 ui-monospace,Menlo,monospace; letter-spacing:8px; color:var(--brand); margin:6px 0 10px; }
        .pair { display:flex; gap:24px; align-items:center; flex-wrap:wrap; }
        #qr { background:#fff; padding:8px; border:1px solid var(--line); border-radius:12px; }
        .flash { background:#f3e8ff; border:1px solid #e9d5ff; color:#6b21a8; border-radius:12px; padding:12px 16px; margin-bottom:18px; }
        table { width:100%; border-collapse:collapse; font-size:15px; }
        td, th { text-align:left; padding:10px 6px; border-bottom:1px solid var(--line); vertical-align:middle; }
        th { color:var(--muted); font-weight:600; font-size:13px; }
        .ok { color:#15803d; } .warn { color:#b45309; }
        ol { padding-left:20px; color:var(--muted); } ol li { margin-bottom:6px; }
    </style>
    @stack('head')
</head>
<body>
<div class="wrap">
    <header>
        <div class="logo">🔔</div>
        <div>
            <h1>Alarme de Pedidos</h1>
            <p class="sub">@yield('subtitle', 'Para lojas Cardápio Web')</p>
        </div>
    </header>
    @yield('content')
</div>
</body>
</html>
