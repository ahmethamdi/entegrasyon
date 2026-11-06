{{--
    Çözüm sayfası — TR (/cozumler/{slug}) ve EN (/en/solutions/{slug}).
    İçerik `resources/content/solutions.php`'den; kanal listesi adaptör
    yeteneğinden (`Solutions::channels`). Ekran görüntüsü panelden gerçek.

    6 Kas 2026 · site sprint 1: bu sayfalar artık menüde "Özellikler"
    altında (iş sorununa göre sayfalar `problem.blade.php`). Kanal kartında
    "çalışır" yerine o yeteneğin doğrulama düzeyi (S15 matrisi); e-fatura
    sayfasında Nilvera'nın düzeyi kanıt kaydından (`SiteProof`).
--}}
@extends('site.layout')

@php
    $en = $lang === 'en';
    $t = fn (string $tr, string $enText): string => $en ? $enText : $tr;
    $faqs = $copy['faqs'];
    $proofSource = app(\App\Support\Site\SiteProof::class);
    $ruleCapability = [
        'SupportsInventory' => \App\Domain\Channels\Certification\ChannelCapability::INVENTORY,
        'SupportsOrders' => \App\Domain\Channels\Certification\ChannelCapability::ORDER_IMPORT,
        'SupportsPricing' => \App\Domain\Channels\Certification\ChannelCapability::PRICE,
        'SupportsFulfillment' => \App\Domain\Channels\Certification\ChannelCapability::SHIPMENT,
    ][is_string($solution['channels'] ?? null) ? $solution['channels'] : ''] ?? null;
    $invoiceStatus = $solution['slug'] === 'e-fatura' ? $proofSource->service('Nilvera e-fatura') : null;
    $tonEn = fn ($s) => match ($s->publicTone()) { 'live' => 'Verified live', 'sandbox' => 'Verified in sandbox', 'testing' => 'Being tested', default => 'In channel panel' };
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
        ['name' => $t('Özellikler', 'Features'), 'url' => route('site.features')],
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
                        <li><a href="{{ route('site.features') }}" class="link-u hover:text-ink">{{ $t('Özellikler', 'Features') }}</a></li>
                    </ol>
                </nav>
                <p class="eyebrow mt-6">{{ $copy['menu'] }}</p>
                <h1 id="cozum-h1" class="h-page mt-3">{{ $copy['h1'] }}</h1>
                <p class="lead mt-5 max-w-[52ch]">{{ $copy['lead'] }}</p>
                @if ($invoiceStatus && $invoiceStatus !== \App\Domain\Channels\Certification\CertificationStatus::PRODUCTION_VERIFIED)
                    <div class="notice mt-6" role="note">
                        <p class="flex flex-wrap items-center gap-2 font-semibold text-ink">{{ $t('Durum:', 'Status:') }} <span class="badge badge--{{ $invoiceStatus->publicTone() }}" data-status="{{ $invoiceStatus->value }}">{{ $en ? $tonEn($invoiceStatus) : $invoiceStatus->publicLabel() }}</span></p>
                        <p class="mt-1 text-sm muted">{{ $t($invoiceStatus->publicNote().' Faturalarını bu ekrandan kesmeye başlamadan önce bize yaz; ilk faturaları birlikte deneyelim.', 'Built and tested against the documented Nilvera examples; not yet tried on a real Nilvera account. Write to us before relying on it.') }}</p>
                    </div>
                @endif
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
                        <span class="muted" aria-hidden="true">—</span>
                        <span>{{ $problem }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="section bg-soft" aria-labelledby="nasil-h2">
        <div class="wrap">
            <h2 id="nasil-h2" class="heading-2">{{ $t('Nasıl çalışır?', 'How it works') }}</h2>
            @php $howShots = \App\Support\Site\StepShots::row($solution['how_shots'] ?? [], count($copy['how']), $lang); @endphp
            <ol class="mt-10 grid gap-5 md:grid-cols-3">
                @foreach ($copy['how'] as $i => [$title, $body])
                    <li class="card">
                        @if ($howShots)
                            <div class="mb-5">
                                @include('site.partials.step-shot', ['shot' => $howShots[$i], 'n' => $i + 1, 'alt' => $t('Panelden ekran: ', 'Panel screen: ').$title])
                            </div>
                        @endif
                        <h3 class="heading-3 mt-2"><span class="muted">{{ $i + 1 }}.</span> {{ $title }}</h3>
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
                <p class="lead mt-3 max-w-[60ch]">{{ $t('Bu özelliğin kodu aşağıdaki kanallarda var. Her kartta gerçek hesapta mı yoksa yalnız testte mi doğrulandığı yazar.', 'This feature is built for the channels below. Each card shows whether it is verified on a real account or only in tests.') }}</p>
                <ul class="mt-8 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5">
                    @foreach ($solutionChannels as $ch)
                        <li>
                            <a href="{{ route('site.channel', $ch['code']) }}" class="channel-card">
                                @include('site.partials.channel-icon', ['ch' => $ch])
                                <span class="mt-3 block font-semibold text-ink [overflow-wrap:anywhere]">{{ $ch['name'] }}</span>
                                @if ($ruleCapability)
                                    @php $cs = $proofSource->cell($ch['code'], $ruleCapability); @endphp
                                    <span class="badge badge--{{ $cs->publicTone() }} mt-2" data-status="{{ $cs->value }}">{{ $en ? $tonEn($cs) : $cs->publicLabel() }}</span>
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
