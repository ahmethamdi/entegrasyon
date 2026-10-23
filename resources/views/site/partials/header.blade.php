{{--
    Sitenin başlığı — kurumsal düzen: solda logo, ortada mega menü, sağda
    "Giriş yap" ve "Ücretsiz dene" (21 Eki 2026).

    Mega menü: masaüstünde üzerine gelince ya da tıklayınca açılır (klavye:
    düğme + Escape, site.js). JS çalışmasa da hover/odakla açılır ve bütün
    bağlantılar alt bilgide de vardır. Mobilde gruplar <details> akordeonu.

    Kanallar veritabanından (`$channels`, logolu, Beta rozetli); çözümler
    `resources/content/solutions.php`'den (`$solutionMenu`). Elle liste yok.
--}}
@php
    $en = ($lang ?? 'tr') === 'en';
    $loggedIn = $isLoggedIn ?? false;
    $t = fn (string $tr, string $enText): string => $en ? $enText : $tr;

    $turkey = ['trendyol', 'hepsiburada', 'n11', 'pazarama', 'ciceksepeti'];
    $abroad = ['amazon', 'ebay', 'etsy', 'otto', 'kaufland'];
    $byCode = collect($channels ?? [])->keyBy('code');
    $pick = fn (array $codes) => collect($codes)->map(fn ($c) => $byCode->get($c))->filter()->values()->all();
    $stores = collect($channels ?? [])->filter(fn ($c) => ! $c['marketplace'])->values()->all();

    $groups = [
        'pazaryerleri' => [
            'label' => $t('Pazaryerleri', 'Marketplaces'),
            'match' => ['site.channel', 'site.channels'],
            'columns' => [
                [$t('Türkiye', 'Turkey'), $pick($turkey)],
                [$t('Yurtdışı', 'International'), $pick($abroad)],
            ],
            'footer' => [$t('Bütün entegrasyonlar', 'All integrations'), route('site.channels')],
        ],
        'siteler' => [
            'label' => $t('E-ticaret siteleri', 'Online stores'),
            'match' => [],
            'columns' => [[$t('Altyapılar', 'Platforms'), $stores]],
            'footer' => [$t('Bütün entegrasyonlar', 'All integrations'), route('site.channels')],
        ],
        'cozumler' => [
            'label' => $t('Çözümler', 'Solutions'),
            'match' => ['site.solution', 'site.en.solution', 'site.features'],
            'solutions' => $solutionMenu ?? [],
            'footer' => [$t('Bütün özellikler', 'All features'), route('site.features')],
        ],
        'kaynaklar' => [
            'label' => $t('Kaynaklar', 'Resources'),
            'match' => ['site.blog', 'site.blog.show', 'site.en'],
            'links' => [
                [$t('Blog', 'Blog'), route('site.blog'), $t('E-ticaret ve pazaryeri yazıları', 'Articles (Turkish)')],
                [$t('Belgeler', 'Documentation'), route('site.en', 'docs'), $t('Panelin kullanımı (İngilizce)', 'How the panel works')],
                [$t('Sık sorulanlar', 'FAQ'), $en ? route('site.en', 'faq') : route('home').'#sss', $t('Kısa cevaplar', 'Short answers')],
                [$t('Sürüm notları', 'Changelog'), route('site.en', 'changelog'), $t('Yeni gelenler (İngilizce)', 'What\'s new')],
            ],
        ],
        'kurumsal' => [
            'label' => $t('Kurumsal', 'Company'),
            'match' => ['site.about', 'site.contact'],
            'links' => [
                [$t('Hakkımızda', 'About'), route('site.about'), $t('34Pazar\'ı kim yapıyor', 'Who builds 34Pazar')],
                [$t('İletişim', 'Contact'), route('site.contact'), $t('Bize yaz', 'Get in touch')],
            ],
        ],
    ];
@endphp
<header class="site-header">
    <div class="wrap flex h-16 items-center justify-between gap-6">
        <a href="{{ route('home') }}" class="inline-flex min-h-11 shrink-0 items-center" aria-label="{{ $t('34Pazar ana sayfa', '34Pazar home') }}">
            <img src="{{ asset('images/34pazar-logo.png') }}" alt="34Pazar" width="600" height="137" class="h-6 w-auto sm:h-[1.625rem]">
        </a>

        <nav aria-label="{{ $t('Ana gezinme', 'Main navigation') }}" class="hidden self-stretch xl:flex">
            <ul class="flex items-stretch gap-6">
                @foreach ($groups as $key => $group)
                    <li class="mega-item flex items-center" data-mega>
                        <button type="button" class="nav-link gap-1" aria-expanded="false" aria-controls="mega-{{ $key }}" data-mega-button @if ($group['match'] && request()->routeIs(...$group['match'])) aria-current="page" @endif>
                            {{ $group['label'] }}
                            <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M3 4.5l3 3 3-3" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round"/></svg>
                        </button>
                        <div id="mega-{{ $key }}" class="mega-panel" data-mega-panel>
                            <div class="wrap py-8">
                                @if (isset($group['columns']))
                                    <div class="grid gap-8 {{ count($group['columns']) > 1 ? 'grid-cols-2' : 'grid-cols-1' }}">
                                        @foreach ($group['columns'] as [$heading, $items])
                                            <div>
                                                <p class="mega-heading">{{ $heading }}</p>
                                                <ul class="mt-3 grid grid-cols-2 gap-2">
                                                    @foreach ($items as $ch)
                                                        <li>
                                                            <a href="{{ route('site.channel', $ch['code']) }}" class="mega-link">
                                                                @include('site.partials.channel-icon', ['ch' => $ch, 'size' => 'sm'])
                                                                <span class="font-semibold text-ink">{{ $ch['name'] }}</span>
                                                                @if ($ch['beta'] ?? false)
                                                                    <span class="badge badge--brand ml-auto text-[0.6875rem]">Beta</span>
                                                                @endif
                                                            </a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif (isset($group['solutions']))
                                    <ul class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                                        @foreach ($group['solutions'] as $item)
                                            <li>
                                                <a href="{{ $item['url'] }}" class="mega-link items-start">
                                                    <span class="icon-box icon-box--sm">@include('site.partials.icon', ['name' => $item['icon']])</span>
                                                    <span>
                                                        <span class="block font-semibold text-ink">{{ $item['label'] }}</span>
                                                        <span class="mt-0.5 block text-sm muted">{{ $item['hint'] }}</span>
                                                    </span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <ul class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                                        @foreach ($group['links'] as [$label, $url, $hint])
                                            <li>
                                                <a href="{{ $url }}" class="mega-link flex-col items-start gap-0.5">
                                                    <span class="font-semibold text-ink">{{ $label }}</span>
                                                    <span class="text-sm muted">{{ $hint }}</span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if (isset($group['footer']))
                                    <p class="mt-6 border-t border-line pt-4 text-sm">
                                        <a href="{{ $group['footer'][1] }}" class="font-semibold text-brand-700 link-u">{{ $group['footer'][0] }} →</a>
                                    </p>
                                @endif
                            </div>
                        </div>
                    </li>
                    @if ($key === 'cozumler')
                        <li class="flex items-center"><a href="{{ route('site.pricing') }}" class="nav-link" @if (request()->routeIs('site.pricing')) aria-current="page" @endif>{{ $t('Fiyatlar', 'Pricing') }}</a></li>
                    @endif
                @endforeach
            </ul>
        </nav>

        <div class="flex items-center gap-2 sm:gap-3">
            @if ($loggedIn)
                <a href="{{ url('/panel') }}" class="btn btn-primary btn-sm hidden sm:inline-flex">{{ $t('Panele git', 'Open panel') }}</a>
            @else
                <a href="{{ route('login') }}" class="nav-link hidden px-2 sm:inline-flex">{{ $t('Giriş yap', 'Log in') }}</a>
                <a href="{{ route('register') }}" class="btn btn-primary btn-sm hidden sm:inline-flex">{{ $t('Ücretsiz dene', 'Try free') }}</a>
            @endif

            <button type="button" class="-mr-2 inline-flex size-11 items-center justify-center rounded-lg text-ink hover:bg-soft xl:hidden" aria-expanded="false" aria-controls="mobil-menu" aria-label="{{ $t('Menüyü aç', 'Open menu') }}" data-menu-button>
                <svg data-icon-open width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <svg data-icon-close hidden width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
        </div>
    </div>

    <div id="mobil-menu" class="mobile-menu xl:hidden" hidden>
        <nav aria-label="{{ $t('Mobil gezinme', 'Mobile navigation') }}" class="wrap pt-2 pb-6">
            @foreach ($groups as $key => $group)
                <details class="mobile-group">
                    <summary class="menu-item">{{ $group['label'] }}</summary>
                    <ul class="pb-3">
                        @if (isset($group['columns']))
                            @foreach ($group['columns'] as [$heading, $items])
                                <li class="mega-heading mt-3">{{ $heading }}</li>
                                @foreach ($items as $ch)
                                    <li>
                                        <a href="{{ route('site.channel', $ch['code']) }}" class="mobile-sub">
                                            @include('site.partials.channel-icon', ['ch' => $ch, 'size' => 'sm'])
                                            <span>{{ $ch['name'] }}</span>
                                            @if ($ch['beta'] ?? false)<span class="badge badge--brand ml-auto text-[0.6875rem]">Beta</span>@endif
                                        </a>
                                    </li>
                                @endforeach
                            @endforeach
                        @elseif (isset($group['solutions']))
                            @foreach ($group['solutions'] as $item)
                                <li><a href="{{ $item['url'] }}" class="mobile-sub">{{ $item['label'] }}</a></li>
                            @endforeach
                        @else
                            @foreach ($group['links'] as [$label, $url])
                                <li><a href="{{ $url }}" class="mobile-sub">{{ $label }}</a></li>
                            @endforeach
                        @endif
                        @if (isset($group['footer']))
                            <li><a href="{{ $group['footer'][1] }}" class="mobile-sub font-semibold text-brand-700">{{ $group['footer'][0] }} →</a></li>
                        @endif
                    </ul>
                </details>
                @if ($key === 'cozumler')
                    <a href="{{ route('site.pricing') }}" class="menu-item" @if (request()->routeIs('site.pricing')) aria-current="page" @endif>{{ $t('Fiyatlar', 'Pricing') }}</a>
                @endif
            @endforeach
            <div class="mt-6 grid gap-3">
                @if ($loggedIn)
                    <a href="{{ url('/panel') }}" class="btn btn-primary w-full">{{ $t('Panele git', 'Open panel') }}</a>
                @else
                    <a href="{{ route('register') }}" class="btn btn-primary w-full">{{ $t('Ücretsiz dene', 'Try free') }}</a>
                    <a href="{{ route('login') }}" class="btn btn-secondary w-full">{{ $t('Giriş yap', 'Log in') }}</a>
                @endif
            </div>
        </nav>
    </div>
</header>
