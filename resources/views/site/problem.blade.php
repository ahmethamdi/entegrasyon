{{--
    /cozumler/{slug} — İŞ SORUNUNA göre çözüm sayfası (6 Kas 2026 · site sprint 1).
    İçerik `resources/content/problems.php`'den.

    Kalıp: sorun → çözüm → nasıl çalışır (gerçek ekran) → kanıt → çağrı.
    KANIT tablosu sertifikasyon matrisinden (`$proofRows`, `$proofServices`
    — SiteController::proofRows, SiteProof); sayfada elle durum yazılmaz.
--}}
@extends('site.layout')

@php
    $capLabels = [
        'inventory' => 'Stok',
        'order_import' => 'Sipariş alma',
        'shipment' => 'Kargo bildirimi',
        'product_import' => 'Ürün içe aktarma',
        'publishing' => 'Ürün gönderme',
        'price' => 'Fiyat',
    ];
    $caps = $problem['proof']['capabilities'];
    $howShots = \App\Support\Site\StepShots::row($problem['how_shots'] ?? [], count($problem['how']), 'tr');
    $feature = $problem['feature'] ? collect($solutionMenu)->firstWhere('slug', $problem['feature']) : null;
@endphp

@section('title', $problem['title'])
@section('description', \Illuminate\Support\Str::limit($problem['description'], 157))
@section('og_image', asset($problem['shot']))

@push('jsonld')
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Çözümler', 'url' => route('home').'#cozumler'],
        ['name' => $problem['title'], 'url' => url()->current()],
    ]])
@endpush

@section('content')
    {{-- ═══════════════════════════════════ SORUN --}}
    <section class="border-b border-line" aria-labelledby="sorun-h1">
        <div class="wrap grid items-center gap-10 pt-10 pb-14 lg:grid-cols-12 lg:pt-14 lg:pb-16">
            <div class="lg:col-span-5">
                <nav aria-label="Konum" class="text-sm muted">
                    <ol class="flex flex-wrap items-center gap-2">
                        <li><a href="{{ route('home') }}" class="link-u hover:text-ink">Ana sayfa</a></li>
                        <li aria-hidden="true">/</li>
                        <li><a href="{{ route('home') }}#cozumler" class="link-u hover:text-ink">Çözümler</a></li>
                        <li aria-hidden="true">/</li>
                        <li aria-current="page" class="font-semibold text-ink">{{ $problem['title'] }}</li>
                    </ol>
                </nav>
                <h1 id="sorun-h1" class="h-page mt-6">{{ $problem['h1'] }}</h1>
                <p class="lead mt-5 max-w-[52ch]">{{ $problem['lead'] }}</p>
                <ul class="mt-6 grid gap-2">
                    @foreach ($problem['problems'] as $item)
                        <li class="flex gap-3"><span class="muted" aria-hidden="true">—</span><span>{{ $item }}</span></li>
                    @endforeach
                </ul>
            </div>
            <div class="lg:col-span-7">
                @include('site.partials.shot', ['src' => $problem['shot'], 'alt' => $problem['shot_alt'], 'eager' => true])
                <p class="mt-2 text-xs muted">Panelden gerçek ekran görüntüsü (demo mağaza).</p>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════════════════ ÇÖZÜM --}}
    <section class="section" aria-labelledby="cozum-h2">
        <div class="wrap grid gap-10 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <h2 id="cozum-h2" class="heading-2">34Pazar ne yapar?</h2>
                @if ($feature)
                    <p class="mt-4"><a href="{{ $feature['url'] }}" class="plain-link">Özellik ayrıntısı: {{ $feature['label'] }}</a></p>
                @endif
            </div>
            <ul class="plain-list lg:col-span-8">
                @foreach ($problem['solution'] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- ═══════════════════════════════════ NASIL ÇALIŞIR --}}
    <section class="section bg-soft home-band" aria-labelledby="nasil-h2">
        <div class="wrap">
            <h2 id="nasil-h2" class="heading-2">Nasıl çalışır?</h2>
            <ol class="mt-10 grid gap-6 md:grid-cols-3">
                @foreach ($problem['how'] as $i => [$title, $body])
                    <li class="step-card">
                        @if ($howShots)
                            @include('site.partials.step-shot', ['shot' => $howShots[$i], 'n' => $i + 1, 'alt' => 'Panelden ekran: '.$title])
                        @endif
                        <h3 class="heading-3 mt-5"><span class="muted">{{ $i + 1 }}.</span> {{ $title }}</h3>
                        <p class="mt-2 muted">{{ $body }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ═══════════════════════════════════ KANIT (matristen) --}}
    <section class="section" aria-labelledby="kanit-h2">
        <div class="wrap">
            <div class="section-head">
                <h2 id="kanit-h2" class="heading-2">Hangi kanalda ne kadar doğrulandı?</h2>
                <p class="lead">Durumlar elle yazılmaz; ürün içindeki doğrulama kaydından gelir. Gerçek hesapta denenmemiş iş "test ediliyor" görünür.</p>
            </div>

            @if (count($proofRows))
                <div class="mt-8 overflow-x-auto rounded-xl border border-line">
                    <table class="proof-table min-w-[32rem]">
                        <caption class="sr-only">Kanallara göre doğrulama durumu</caption>
                        <thead>
                            <tr>
                                <th scope="col">Kanal</th>
                                @foreach ($caps as $cap)
                                    <th scope="col">{{ $capLabels[$cap] ?? $cap }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($proofRows as $row)
                                <tr>
                                    <th scope="row">
                                        <a href="{{ route('site.channel', $row['code']) }}" class="link-u hover:text-brand-700">{{ $row['name'] }}</a>
                                        @unless ($row['available'])
                                            <span class="block text-xs font-normal muted">Panelde henüz açık değil</span>
                                        @endunless
                                    </th>
                                    @foreach ($caps as $cap)
                                        @php $s = $row['cells'][$cap]; @endphp
                                        <td><span class="badge badge--{{ $s->publicTone() }}" data-status="{{ $s->value }}">{{ $s->publicLabel() }}</span></td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if (count($proofServices))
                <h3 class="heading-3 mt-10">Kargo firmaları</h3>
                <ul class="plain-list mt-4">
                    @foreach ($proofServices as $service)
                        <li class="flex flex-wrap items-center justify-between gap-3">
                            <span class="font-semibold text-ink">{{ $service['name'] }}</span>
                            <span class="flex flex-wrap items-center gap-3">
                                <span class="text-sm muted">{{ $service['status']->publicNote() }}</span>
                                <span class="badge badge--{{ $service['status']->publicTone() }}" data-status="{{ $service['status']->value }}">{{ $service['status']->publicLabel() }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @include('site.partials.proof-legend')

            @if ($demoAvailable)
                <p class="mt-8">Kendin bakmak istersen <a href="{{ route('demo') }}" class="plain-link" rel="nofollow">demo hesabını aç</a>: kayıt gerekmez, salt okunurdur.</p>
            @endif
        </div>
    </section>

    @include('site.partials.cta')
@endsection
