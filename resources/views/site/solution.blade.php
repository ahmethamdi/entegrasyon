{{--
    Çözüm sayfası — TR (/cozumler/{slug}) ve EN (/en/solutions/{slug}).
    İçerik `resources/content/solutions.php`'den; kanal listesi adaptör
    yeteneğinden (`Solutions::channels`). Ekran görüntüsü panelden gerçek.
--}}
@extends('site.layout')

@php
    $en = $lang === 'en';
    $t = fn (string $tr, string $enText): string => $en ? $enText : $tr;
    $faqs = $copy['faqs'];
@endphp

@section('lang', $lang)
@section('og_locale', $en ? 'en_US' : 'tr_TR')
@section('title', $copy['title'])
@section('description', \Illuminate\Support\Str::limit($copy['description'], 157))
@section('og_image', asset($solution['shot']))

@push('jsonld')
    <link rel="alternate" hreflang="tr" href="{{ $alternate['tr'] }}">
    <link rel="alternate" hreflang="en" href="{{ $alternate['en'] }}">
    <link rel="alternate" hreflang="x-default" href="{{ $alternate['tr'] }}">
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => $t('Ana sayfa', 'Home'), 'url' => route('home')],
        ['name' => $t('Çözümler', 'Solutions'), 'url' => route('site.features')],
        ['name' => $copy['menu'], 'url' => url()->current()],
    ]])
    @include('site.partials.seo.faq', ['faqs' => $faqs])
@endpush

@section('content')
    <section class="border-b border-line bg-soft" aria-labelledby="cozum-h1">
        <div class="wrap grid items-center gap-10 pt-10 pb-14 lg:grid-cols-12 lg:pt-16 lg:pb-20">
            <div class="lg:col-span-5">
                <nav aria-label="{{ $t('Konum', 'Breadcrumb') }}" class="text-sm muted">
                    <ol class="flex flex-wrap items-center gap-2">
                        <li><a href="{{ route('home') }}" class="link-u hover:text-ink">{{ $t('Ana sayfa', 'Home') }}</a></li>
                        <li aria-hidden="true">/</li>
                        <li><a href="{{ route('site.features') }}" class="link-u hover:text-ink">{{ $t('Çözümler', 'Solutions') }}</a></li>
                    </ol>
                </nav>
                <p class="eyebrow mt-6">{{ $copy['menu'] }}</p>
                <h1 id="cozum-h1" class="h-page mt-3">{{ $copy['h1'] }}</h1>
                <p class="lead mt-5 max-w-[52ch]">{{ $copy['lead'] }}</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('register') }}" class="btn btn-primary btn-lg">{{ $t('Ücretsiz dene', 'Try free') }}</a>
                    <a href="{{ route('site.pricing') }}" class="btn btn-secondary btn-lg">{{ $t('Fiyatlar', 'Pricing') }}</a>
                </div>
                <p class="mt-4 text-sm muted">
                    <a href="{{ $en ? $alternate['tr'] : $alternate['en'] }}" hreflang="{{ $en ? 'tr' : 'en' }}" class="link-u">{{ $en ? 'Türkçe' : 'English' }}</a>
                </p>
            </div>
            <div class="lg:col-span-7">
                @include('site.partials.shot', [
                    'src' => $solution['shot'],
                    'alt' => $t('34Pazar paneli: ', '34Pazar panel: ').$copy['menu'],
                    'eager' => true,
                ])
                <p class="mt-2 text-xs muted">{{ $t('Panelden gerçek ekran görüntüsü (demo mağaza).', 'Real screenshot from the panel (demo store, Turkish interface).') }}</p>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="sorun-h2">
        <div class="wrap grid gap-10 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <h2 id="sorun-h2" class="heading-2">{{ $t('Ne çözer?', 'What it solves') }}</h2>
            </div>
            <ul class="grid gap-4 lg:col-span-8">
                @foreach ($copy['problems'] as $problem)
                    <li class="card flex items-start gap-3">
                        <span class="text-brand-700 font-bold" aria-hidden="true">✕</span>
                        <span>{{ $problem }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="section bg-soft" aria-labelledby="nasil-h2">
        <div class="wrap">
            <h2 id="nasil-h2" class="heading-2">{{ $t('Nasıl çalışır?', 'How it works') }}</h2>
            <ol class="mt-10 grid gap-5 md:grid-cols-3">
                @foreach ($copy['how'] as $i => [$title, $body])
                    <li class="card">
                        <span class="inline-flex size-9 items-center justify-center rounded-full bg-ink font-bold text-white">{{ $i + 1 }}</span>
                        <h3 class="heading-3 mt-4">{{ $title }}</h3>
                        <p class="mt-2 muted">{{ $body }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    @if (count($solutionChannels))
        <section class="section" aria-labelledby="kanal-h2">
            <div class="wrap">
                <h2 id="kanal-h2" class="heading-2">{{ $t('Hangi kanallarda?', 'Which channels?') }}</h2>
                <p class="lead mt-3 max-w-[60ch]">{{ $t('"Beta" rozetli kanallar yeni: ilk kurulumda ekibimiz seninle birlikte kontrol eder.', 'Channels marked "Beta" are new: our team checks the first setup with you.') }}</p>
                <ul class="mt-8 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5">
                    @foreach ($solutionChannels as $ch)
                        <li>
                            <a href="{{ route('site.channel', $ch['code']) }}" class="channel-tile">
                                <span class="flex h-7 w-full items-center gap-2.5">
                                    @if ($ch['logo'])
                                        <img src="{{ $ch['logo'] }}" alt="{{ $ch['name'] }} {{ $t('logosu', 'logo') }}" class="h-7 w-auto {{ $ch['wordmark'] ? 'max-w-full' : 'max-w-[5rem]' }} min-w-0 shrink object-contain object-left" loading="lazy">
                                    @endif
                                    <span class="min-w-0 truncate text-ink {{ $ch['logo'] && $ch['wordmark'] ? 'sr-only' : '' }}">{{ $ch['name'] }}</span>
                                </span>
                                @if ($ch['beta'])
                                    <span class="badge badge--brand">Beta</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    <section class="section {{ count($solutionChannels) ? 'bg-soft' : '' }}" aria-labelledby="sss-h2">
        <div class="wrap-narrow">
            <h2 id="sss-h2" class="heading-2">{{ $t('Sık sorulanlar', 'Frequently asked questions') }}</h2>
            <div class="mt-8">
                @include('site.partials.faq', ['faqs' => $faqs, 'openFirst' => true])
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="diger-h2">
        <div class="wrap">
            <h2 id="diger-h2" class="heading-3">{{ $t('Diğer çözümler', 'Other solutions') }}</h2>
            <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($solutionMenu as $item)
                    @continue($item['slug'] === $solution['slug'])
                    <li>
                        <a href="{{ $item['url'] }}" class="card card-link flex items-start gap-3">
                            <span class="icon-box icon-box--sm">@include('site.partials.icon', ['name' => $item['icon']])</span>
                            <span>
                                <span class="block font-semibold text-ink">{{ $item['label'] }}</span>
                                <span class="mt-0.5 block text-sm muted">{{ $item['hint'] }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    @unless ($en)
        @include('site.partials.cta')
    @endunless
@endsection
