<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#611232">
    <title>Mi cita — Nexum</title>
    @livewireStyles
    <style>
        :root { --guinda:#611232; --guinda2:#7a1a40; --bg:#f5f5f7; --ok:#1a7f37; --danger:#b42318; --muted:#6b7280; --card:#ffffff; }
        * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
        html,body { margin:0; padding:0; }
        body {
            font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            background:var(--bg); color:#111827; min-height:100dvh;
        }
        .wrap { max-width:520px; margin:0 auto; min-height:100dvh; display:flex; flex-direction:column; padding:20px 18px calc(24px + env(safe-area-inset-bottom)); }
        .brand { display:flex; align-items:center; gap:10px; padding:6px 0 18px; }
        .brand .dot { width:34px; height:34px; border-radius:9px; background:var(--guinda); color:#fff; display:grid; place-items:center; font-weight:800; }
        .brand b { font-size:15px; letter-spacing:.2px; }
        .card { background:var(--card); border-radius:18px; padding:22px 20px; box-shadow:0 1px 3px rgba(0,0,0,.06),0 8px 24px rgba(0,0,0,.05); }
        h1 { font-size:22px; line-height:1.25; margin:0 0 6px; }
        .empresa { color:var(--guinda); font-weight:800; }
        p.sub { color:var(--muted); font-size:15px; margin:0 0 18px; }
        .btn { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; border:0; border-radius:14px; padding:17px 16px; font-size:17px; font-weight:700; cursor:pointer; margin-top:12px; }
        .btn-primary { background:var(--guinda); color:#fff; }
        .btn-ok { background:var(--ok); color:#fff; }
        .btn-danger { background:#fff; color:var(--danger); border:2px solid #f3c3bd; }
        .btn-muted { background:#eef0f3; color:#374151; }
        .btn-ghost { background:transparent; color:var(--muted); font-weight:600; box-shadow:none; }
        .btn:active { transform:scale(.985); }
        .spinner { width:44px; height:44px; border:4px solid #e5e7eb; border-top-color:var(--guinda); border-radius:50%; animation:spin .8s linear infinite; margin:8px auto 16px; }
        @keyframes spin { to { transform:rotate(360deg); } }
        .center { text-align:center; }
        label.field { display:block; font-size:13px; font-weight:700; color:#374151; margin:14px 0 6px; }
        input.rfc { width:100%; border:2px solid #d1d5db; border-radius:12px; padding:15px 14px; font-size:22px; letter-spacing:2px; text-transform:uppercase; font-weight:800; text-align:center; }
        input.rfc:focus { outline:none; border-color:var(--guinda); }
        .preview { width:100%; border-radius:12px; margin-top:8px; border:1px solid #e5e7eb; max-height:230px; object-fit:cover; }
        .filebtn { position:relative; overflow:hidden; }
        .filebtn input[type=file] { position:absolute; inset:0; opacity:0; font-size:0; }
        .note { font-size:13px; color:var(--muted); margin-top:12px; text-align:center; }
        .err { background:#fef2f2; color:var(--danger); border-radius:12px; padding:12px 14px; font-size:14px; margin-top:12px; }
        .ok-badge { width:64px; height:64px; border-radius:50%; background:#e7f6ec; color:var(--ok); display:grid; place-items:center; font-size:34px; margin:4px auto 14px; }
        .grow { flex:1; }
        .muted-foot { text-align:center; color:#9ca3af; font-size:12px; padding-top:18px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand"><span class="dot">N</span><b>Nexum · Mi cita</b></div>
        {{ $slot }}
        <div class="grow"></div>
        <div class="muted-foot">Backend Bridge · SAT</div>
    </div>
    @livewireScripts
</body>
</html>
