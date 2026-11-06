{{--
    /hizmetler — 34Devs'in 34Pazar çevresinde verdiği hizmetler (6 Kas 2026 ·
    site sprint 1). İçerik `resources/content/services.php`.

    FİYAT YOK: her hizmetin "Teklif al"ı iletişim formunu konu = Hizmet
    teklifi ve hizmet ön seçili açar. Müşteri sayısı, süre, "X mağaza
    kurduk" gibi ölçülmemiş rakam yazılmaz.
--}}
@extends('site.layout')

@section('title', 'Hizmetler: kurulum, veri taşıma, Shopify ve özel entegrasyon')
@section('description', '34Devs ekibinden 34Pazar kurulumu ve veri taşıma, Shopify mağaza kurulumu, WooCommerce, ERP/depo/tedarikçi entegrasyonu ve eğitim. Fiyat kapsamına göre teklifle.')

@push('jsonld')
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Hizmetler', 'url' => route('site.services')],
    ]])
@endpush

@section('content')
    <section class="border-b border-line bg-soft" aria-labelledby="hizmet-h1">
        <div class="wrap pt-12 pb-12 lg:pt-16 lg:pb-14">
            <h1 id="hizmet-h1" class="h-page max-w-[24ch]">Paneli kurmayı bize bırak.</h1>
            <p class="lead mt-5 max-w-[60ch]">
                34Pazar'ı geliştiren 34Devs, Almanya'da kurulu bir Shopify Partner ajansıdır. Kurulumdan veri taşımaya, mağaza açılışından özel entegrasyona kadar işi seninle birlikte yaparız. Her işin fiyatı kapsamına göre yazılı teklifle belirlenir.
            </p>
            <div class="mt-7 flex flex-wrap gap-3">
                <a href="{{ route('site.contact', ['konu' => 'teklif']) }}#form" class="btn btn-primary">Teklif al</a>
                <a href="{{ route('site.contact', ['konu' => 'gorusme']) }}#form" class="btn btn-secondary">Görüşme planla</a>
            </div>
        </div>
    </section>

    <div class="wrap py-12 lg:py-16">
        <ul class="plain-list">
            @foreach ($services as $slug => $service)
                <li id="{{ $slug }}" class="grid gap-6 py-8 lg:grid-cols-12">
                    <div class="lg:col-span-5">
                        <h2 class="heading-2">{{ $service['title'] }}</h2>
                        <p class="mt-3 muted">{{ $service['summary'] }}</p>
                        <div class="mt-5 flex flex-wrap gap-3">
                            <a href="{{ \App\Support\Site\Services::offerUrl($slug) }}" class="btn btn-secondary btn-sm">Teklif al<span class="sr-only">: {{ $service['title'] }}</span></a>
                            @if ($service['detail'])
                                <a href="{{ route('site.service', $slug) }}" class="plain-link self-center text-sm">Ayrıntılar</a>
                            @endif
                        </div>
                    </div>
                    <ul class="grid gap-2 lg:col-span-7">
                        @foreach ($service['items'] as $item)
                            <li class="flex gap-3"><span class="muted" aria-hidden="true">—</span><span>{{ $item }}</span></li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ul>

        <div class="notice mt-12 max-w-3xl">
            <p class="font-semibold text-ink">Ücretli pakette ilk adım zaten ücretsiz</p>
            <p class="mt-1 muted">Ücretli paketlerde ilk kanalı bağlamayı ve ilk ürün aktarımını seninle birlikte, ücretsiz yaparız (<a href="{{ route('site.pricing') }}" class="plain-link">fiyatlar</a>). Bu sayfadaki hizmetler bunun ötesindeki işler içindir.</p>
        </div>
    </div>

    @include('site.partials.cta')
@endsection
