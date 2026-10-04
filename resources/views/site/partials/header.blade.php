{{--
    Sitenin başlığı. Bağlantılar GERÇEK sayfalara gider (çapa değil):
    her sayfa ayrı adreste dizine girer.

    `aria-current`: bulunulan bölümün altında kırmızı çizgi durur; kanal
    sayfası "Entegrasyonlar"ın, blog yazısı "Blog"un altında sayılır.
--}}
@php
    $nav = [
        ['label' => 'Özellikler', 'route' => 'site.features', 'match' => ['site.features']],
        ['label' => 'Entegrasyonlar', 'route' => 'site.channels', 'match' => ['site.channels', 'site.channel']],
        ['label' => 'Fiyatlar', 'route' => 'site.pricing', 'match' => ['site.pricing']],
        ['label' => 'Blog', 'route' => 'site.blog', 'match' => ['site.blog', 'site.blog.show']],
    ];
    $loggedIn = $isLoggedIn ?? false;
@endphp
<header class="site-header">
    <div class="wrap flex h-[4.25rem] items-center justify-between gap-6">
        <a href="{{ route('home') }}" class="shrink-0" aria-label="34Pazar ana sayfa">
            <img src="{{ asset('images/34pazar-logo.png') }}" alt="34Pazar" width="600" height="137" class="h-[1.6rem] w-auto sm:h-[1.75rem]">
        </a>

        <nav aria-label="Ana gezinme" class="hidden lg:block">
            <ul class="flex items-center gap-9">
                @foreach ($nav as $item)
                    <li>
                        <a href="{{ route($item['route']) }}" class="nav-link" @if (request()->routeIs(...$item['match'])) aria-current="page" @endif>{{ $item['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="flex items-center gap-2 sm:gap-5">
            @if ($loggedIn)
                <a href="{{ url('/panel') }}" class="btn btn-primary btn-sm hidden sm:inline-flex">Panele git <span class="arrow" aria-hidden="true">→</span></a>
            @else
                <a href="{{ route('login') }}" class="nav-link hidden sm:inline-block">Giriş</a>
                <a href="{{ route('register') }}" class="btn btn-primary btn-sm hidden sm:inline-flex">Ücretsiz başla <span class="arrow" aria-hidden="true">→</span></a>
            @endif

            <button type="button" class="-mr-2 inline-flex h-11 items-center gap-2 px-2 text-[0.9375rem] font-semibold lg:hidden" aria-expanded="false" aria-controls="mobil-menu" data-menu-button>
                <span data-label-open>Menü</span>
                <span data-label-close hidden>Kapat</span>
                <svg width="22" height="22" viewBox="0 0 22 22" aria-hidden="true"><path d="M2 7h18M2 15h18" stroke="currentColor" stroke-width="2.2"/></svg>
            </button>
        </div>
    </div>

    <div id="mobil-menu" class="mobile-menu on-dark lg:hidden" hidden>
        <nav aria-label="Mobil gezinme" class="wrap flex min-h-full flex-col pt-6 pb-10">
            <ul>
                <li><a href="{{ route('home') }}" class="menu-big">Ana sayfa</a></li>
                @foreach ($nav as $item)
                    <li><a href="{{ route($item['route']) }}" class="menu-big" @if (request()->routeIs(...$item['match'])) aria-current="page" @endif>{{ $item['label'] }}</a></li>
                @endforeach
            </ul>
            <div class="mt-auto flex flex-col gap-3 pt-10">
                @if ($loggedIn)
                    <a href="{{ url('/panel') }}" class="btn btn-primary w-full">Panele git <span class="arrow" aria-hidden="true">→</span></a>
                @else
                    <a href="{{ route('register') }}" class="btn btn-primary w-full">Ücretsiz başla <span class="arrow" aria-hidden="true">→</span></a>
                    <a href="{{ route('login') }}" class="btn w-full border-white/40 text-white">Giriş yap</a>
                @endif
            </div>
        </nav>
    </div>
</header>
