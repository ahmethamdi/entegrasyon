{{--
    Tanıtım sitesi ana şablonu — SÖZLEŞME (tasarım değişir, bölümler değişmez):
      @section('title')        sayfa başlığı (" · 34Pazar" şablon ekler)
      @section('description')  meta açıklama, 150–160 karakter
      @section('canonical')    isteğe bağlı; yoksa geçerli adres
      @section('og_image')     isteğe bağlı mutlak URL
      @push('jsonld')          bir veya daha çok <script type="application/ld+json">
      @section('content')      sayfa gövdesi

    Panelin CSS/JS'i (app.css, app.js, Vue) burada YÜKLENMEZ: tanıtım
    sayfası yalnız kendi küçük dosyalarını indirir, ilk boyama hızlı olur.
--}}
<!DOCTYPE html>
<html lang="@yield('lang', 'tr')">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · 34Pazar</title>
    <meta name="description" content="@yield('description')">
    <link rel="canonical" href="@hasSection('canonical')@yield('canonical')@else{{ url()->current() }}@endif">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="@yield('og_locale', 'tr_TR')">
    <meta property="og:site_name" content="34Pazar">
    <meta property="og:title" content="@yield('title')">
    <meta property="og:description" content="@yield('description')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="@hasSection('og_image')@yield('og_image')@else{{ asset('images/site/panel-ana-sayfa.png') }}@endif">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="theme-color" content="#CE310D">
    {{-- Kurum bilgisi her sayfada; sayfaya özel JSON-LD ardından gelir. --}}
    @include('site.partials.seo.organization')
    @stack('jsonld')
    @vite(['resources/css/site.css', 'resources/js/site.js'])
</head>
<body>
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[60] focus:bg-ink focus:px-4 focus:py-3 focus:font-semibold focus:text-white">
        İçeriğe geç
    </a>
    @include('site.partials.header')
    <main id="main">
        @yield('content')
    </main>
    @include('site.partials.footer')
</body>
</html>
