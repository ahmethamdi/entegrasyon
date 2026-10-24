{{--
    Hata sayfaları (404, 403, 419, 429, 500, 503) — 24 Eki 2026 açılış
    denetiminde Laravel'in İngilizce "Not Found" sayfası görüldü.

    BİLEREK BAĞIMSIZ: site.layout'u genişletmez, Vite'a ve veritabanına
    dokunmaz. 500/503 tam da bunlar bozukken gösterilir; sayfa kendi
    hatasına düşmesin diye stil satır içi, logo düz <img>.
--}}
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · 34Pazar</title>
    <link rel="icon" href="/favicon.ico">
    <style>
        :root { --ink: #0b1b33; --muted: #5b6474; --line: #e5e7eb; --brand: #ce310d; --bg: #fafaf9; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
               background: var(--bg); color: var(--ink); font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 30rem; }
        img { height: 26px; width: auto; display: block; }
        .code { margin: 32px 0 0; font-size: 0.8125rem; font-weight: 700; letter-spacing: 0.06em; color: var(--brand); }
        h1 { margin: 6px 0 0; font-size: 1.625rem; line-height: 1.25; }
        p { margin: 12px 0 0; color: var(--muted); }
        .actions { margin-top: 28px; display: flex; flex-wrap: wrap; gap: 12px; }
        a.btn { display: inline-flex; align-items: center; min-height: 44px; padding: 0 18px; border-radius: 8px;
                background: var(--brand); color: #fff; font-weight: 600; text-decoration: none; }
        a.link { display: inline-flex; align-items: center; min-height: 44px; color: var(--ink); font-weight: 600; }
        .help { margin-top: 32px; padding-top: 16px; border-top: 1px solid var(--line); font-size: 0.875rem; }
        .help a { color: var(--ink); }
    </style>
</head>
<body>
    <main>
        <a href="/" aria-label="34Pazar ana sayfa"><img src="/images/34pazar-logo.png" alt="34Pazar" width="600" height="137"></a>
        <p class="code">@yield('code')</p>
        <h1>@yield('heading')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            @section('actions')
                <a class="btn" href="/">Ana sayfaya dön</a>
                <a class="link" href="/panel">Panele git</a>
            @show
        </div>
        <p class="help">Sorun sürerse yaz: <a href="https://wa.me/4917676798125">WhatsApp</a> · <a href="mailto:info@34devs.com">info@34devs.com</a> (her gün 09.00–18.00)</p>
    </main>
</body>
</html>
