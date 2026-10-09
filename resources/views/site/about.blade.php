{{--
    /hakkimizda — YALNIZ bilinen gerçekler: 34Devs bir Shopify partner
    ajansı, Almanya ve Türkiye'de çalışıyor; 34Pazar'ı birden çok kanalda
    satan müşterileri fazla satış yaşadığı için yaptı.

    Kuruluş yılı, ekip büyüklüğü, müşteri sayısı, ödül gibi doğrulanmamış
    hiçbir rakam ya da iddia YOK.
--}}
@extends('site.layout')

@section('title', 'Hakkımızda: 34Pazar\'ı 34Devs yapıyor')
@section('description', '34Pazar, Almanya ve Türkiye\'de çalışan Shopify partner ajansı 34Devs\'in ürünü. Birden fazla kanalda satan müşterilerimizin fazla satış derdi için yaptık.')

@push('jsonld')
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Hakkımızda', 'url' => route('site.about')],
    ]])
@endpush

@section('content')
    <section class="border-b border-line bg-soft" aria-labelledby="hakkinda-h1">
        <div class="wrap pt-12 pb-12 lg:pt-16 lg:pb-16">
            <p class="eyebrow">Hakkımızda</p>
            <h1 id="hakkinda-h1" class="h-page mt-3 max-w-[22ch]">34Pazar'ı 34Devs yapıyor.</h1>
            <p class="lead mt-5 max-w-[56ch]">Mağaza kuruyorduk. Birden fazla kanalda satan müşterilerimizin stok derdini orada gördük.</p>
        </div>
    </section>

    <section class="section" aria-labelledby="hikaye-h2">
        <div class="wrap grid gap-10 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <p class="eyebrow">Hikâye</p>
                <h2 id="hikaye-h2" class="heading-2 mt-2">Neden yaptık?</h2>
            </div>
            <div class="space-y-5 text-lg leading-relaxed lg:col-span-7 lg:col-start-6">
                <p>
                    34Devs, Almanya ve Türkiye'de çalışan bir Shopify partner ajansı. İşimiz satıcılar için
                    e-ticaret siteleri kurmak.
                </p>
                <p class="muted">
                    Birden fazla kanalda satan müşterilerimizden aynı şikâyeti duyduk: ürün bir kanalda
                    satılıyor, öbüründe hâlâ stokta görünüyor. Sonu iptal, özür mesajı ve düşen mağaza puanı.
                </p>
                <p class="muted">
                    34Pazar'ı bunun için yaptık. Stok tek yerde tutulsun, bir kanalda satılan ürün
                    diğerlerinde de düşsün, siparişler tek listede dursun.
                </p>
            </div>
        </div>
    </section>

    {{-- Nasıl çalışıyoruz: ürünün kendisinde görülebilen kurallar, slogan değil. --}}
    <section class="section bg-soft" aria-labelledby="ilke-h2">
        <div class="wrap">
            <h2 id="ilke-h2" class="heading-2">Üç kuralımız var.</h2>
            <ol class="mt-8 grid gap-5 md:grid-cols-3">
                @foreach ([
                    ['Gösterdiğimiz ekran gerçek.', 'Sitedeki görseller panelin kendisi. Kayıt olduğunda karşına aynıları çıkar.'],
                    ['Sormadan üzerine yazmayız.', 'Fiyatı bir kanalın içinde değiştirdiysen fark ederiz; hangisinin geçerli olacağına sen karar verirsin.'],
                    ['Çalışmayanı da yazarız.', 'Her kanalın sayfasında neyin çalıştığını, neyi kanalın kendi panelinde yapman gerektiğini açıkça yazıyoruz.'],
                ] as [$title, $body])
                    <li class="card">
                        <span class="step-num" aria-hidden="true">{{ $loop->iteration }}</span>
                        <h3 class="heading-3 mt-5">{{ $title }}</h3>
                        <p class="mt-2 muted">{{ $body }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    <section class="section" aria-labelledby="sirket-h2">
        <div class="wrap grid gap-10 lg:grid-cols-12">
            <h2 id="sirket-h2" class="heading-2 lg:col-span-4">Şirket bilgileri</h2>
            <dl class="info-list border-t border-line lg:col-span-7 lg:col-start-6">
                <div>
                    <dt class="muted">İşleten</dt>
                    <dd class="font-semibold">{{ config('site.operator') }}</dd>
                </div>
                <div>
                    <dt class="muted">Adres</dt>
                    <dd class="font-semibold">{{ config('site.street') }}, {{ config('site.postal_code') }} {{ config('site.city') }}, {{ config('site.country') }}</dd>
                </div>
                <div>
                    <dt class="muted">Ajans</dt>
                    <dd><a href="{{ config('site.parent_url') }}" class="link" rel="noopener">{{ preg_replace('#^https?://#', '', config('site.parent_url')) }}</a></dd>
                </div>
                <div>
                    <dt class="muted">Yasal bilgiler</dt>
                    <dd><a href="{{ route('site.legal', 'kunye') }}" class="link">Künye</a></dd>
                </div>
            </dl>
        </div>
    </section>

    @include('site.partials.cta')
@endsection
