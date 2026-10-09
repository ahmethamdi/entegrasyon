{{--
    Sitenin başlığı — kurumsal düzen: solda logo, ortada menü, sağda
    "Giriş yap" ve "Ücretsiz dene". Bağlantılar GERÇEK sayfalara gider
    (çapa değil): her sayfa ayrı adreste dizine girer.

    `aria-current`: bulunulan bölüm koyu kırmızıyla durur; kanal sayfası
    "Entegrasyonlar"ın, blog yazısı "Blog"un altında sayılır.

    Giriş yapmış kullanıcıya iki düğme yerine tek "Panele git" gösterilir.
--}}
@php
    $nav = [
        ['label' => 'Özellikler', 'route' => 'site.features', 'match' => ['site.features']],
        ['label' => 'Fiyatlar', 'route' => 'site.pricing', 'match' => ['site.pricing']],
        ['label' => 'Entegrasyonlar', 'route' => 'site.channels', 'match' => ['site.channels', 'site.channel']],
        ['label' => 'Blog', 'route' => 'site.blog', 'match' => ['site.blog', 'site.blog.show']],
        ['label' => 'İletişim', 'route' => 'site.contact', 'match' => ['site.contact']],
    ];
    $loggedIn = $isLoggedIn ?? false;
@endphp
<header class="site-header">
    <div class="wrap flex h-16 items-center justify-between gap-6">
        <a href="{{ route('home') }}" class="inline-flex min-h-11 shrink-0 items-center" aria-label="34Pazar ana sayfa">
            <img src="{{ asset('images/34pazar-logo.png') }}" alt="34Pazar" width="600" height="137" class="h-6 w-auto sm:h-[1.625rem]">
        </a>

        <nav aria-label="Ana gezinme" class="hidden lg:block">
            <ul class="flex items-center gap-8">
                @foreach ($nav as $item)
                    <li>
                        <a href="{{ route($item['route']) }}" class="nav-link" @if (request()->routeIs(...$item['match'])) aria-current="page" @endif>{{ $item['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="flex items-center gap-2 sm:gap-3">
            @if ($loggedIn)
                <a href="{{ url('/panel') }}" class="btn btn-primary btn-sm hidden sm:inline-flex">Panele git</a>
            @else
                <a href="{{ route('login') }}" class="nav-link hidden px-2 sm:inline-flex">Giriş yap</a>
                <a href="{{ route('register') }}" class="btn btn-primary btn-sm hidden sm:inline-flex">Ücretsiz dene</a>
            @endif

            <button type="button" class="-mr-2 inline-flex size-11 items-center justify-center rounded-lg text-ink hover:bg-soft lg:hidden" aria-expanded="false" aria-controls="mobil-menu" aria-label="Menüyü aç" data-menu-button>
                <svg data-icon-open width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <svg data-icon-close hidden width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
        </div>
    </div>

    <div id="mobil-menu" class="mobile-menu lg:hidden" hidden>
        <nav aria-label="Mobil gezinme" class="wrap pt-2 pb-6">
            <ul>
                @foreach ($nav as $item)
                    <li><a href="{{ route($item['route']) }}" class="menu-item" @if (request()->routeIs(...$item['match'])) aria-current="page" @endif>{{ $item['label'] }}</a></li>
                @endforeach
                <li><a href="{{ route('site.about') }}" class="menu-item" @if (request()->routeIs('site.about')) aria-current="page" @endif>Hakkımızda</a></li>
            </ul>
            <div class="mt-6 grid gap-3">
                @if ($loggedIn)
                    <a href="{{ url('/panel') }}" class="btn btn-primary w-full">Panele git</a>
                @else
                    <a href="{{ route('register') }}" class="btn btn-primary w-full">Ücretsiz dene</a>
                    <a href="{{ route('login') }}" class="btn btn-secondary w-full">Giriş yap</a>
                @endif
            </div>
        </nav>
    </div>
</header>
