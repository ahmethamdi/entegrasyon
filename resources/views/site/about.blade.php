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
    <section class="wrap pt-14 pb-20 sm:pt-20 lg:pt-28 lg:pb-28" aria-labelledby="hakkinda-h1">
        <p class="eyebrow">Hakkımızda</p>
        <h1 id="hakkinda-h1" class="display t-hero mt-6 max-w-[14ch]">Mağaza kuruyorduk. <span class="muted">Stok derdini orada</span> <span class="accent">gördük.</span></h1>
    </section>

    <section class="on-dark" aria-labelledby="hikaye-h2">
        <div class="wrap grid gap-14 py-24 lg:grid-cols-12 lg:py-32">
            <div class="lg:col-span-5">
                <p class="eyebrow">Hikâye</p>
                <h2 id="hikaye-h2" class="display t-2 mt-6">34Pazar'ı 34Devs yapıyor.</h2>
            </div>
            <div class="space-y-6 text-lg leading-relaxed lg:col-span-6 lg:col-start-7">
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
    <section class="wrap py-24 lg:py-36" aria-labelledby="ilke-h2">
        <h2 id="ilke-h2" class="display t-1 max-w-[16ch]">Üç kuralımız var.</h2>
        <ol class="mt-16 grid gap-12 md:grid-cols-3 md:gap-8 lg:mt-24 lg:gap-12">
            @foreach ([
                ['Gösterdiğimiz ekran gerçek.', 'Sitedeki görseller panelin kendisi. Kayıt olduğunda karşına aynıları çıkar.'],
                ['Sormadan üzerine yazmayız.', 'Fiyatı bir kanalın içinde değiştirdiysen fark ederiz; hangisinin geçerli olacağına sen karar verirsin.'],
                ['Çalışmayanı da yazarız.', 'Her kanalın sayfasında neyin çalıştığını, neyi kanalın kendi panelinde yapman gerektiğini açıkça yazıyoruz.'],
            ] as [$title, $body])
                <li class="border-t-2 border-ink pt-8" data-reveal>
                    <span class="step-num" aria-hidden="true">{{ sprintf('%02d', $loop->iteration) }}</span>
                    <h3 class="display t-3 mt-8">{{ $title }}</h3>
                    <p class="mt-4 max-w-[34ch] muted">{{ $body }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    <section class="bg-sand" aria-labelledby="sirket-h2">
        <div class="wrap grid gap-12 py-24 lg:grid-cols-12 lg:py-32">
            <h2 id="sirket-h2" class="display t-2 lg:col-span-5">Şirket bilgileri.</h2>
            <dl class="border-t-2 border-ink lg:col-span-6 lg:col-start-7">
                <div class="grid gap-1 border-b border-line py-5 sm:grid-cols-[10rem_1fr] sm:gap-6">
                    <dt class="muted">İşleten</dt>
                    <dd class="font-semibold">{{ config('site.operator') }}</dd>
                </div>
                <div class="grid gap-1 border-b border-line py-5 sm:grid-cols-[10rem_1fr] sm:gap-6">
                    <dt class="muted">Adres</dt>
                    <dd class="font-semibold">{{ config('site.street') }}, {{ config('site.postal_code') }} {{ config('site.city') }}, {{ config('site.country') }}</dd>
                </div>
                <div class="grid gap-1 border-b border-line py-5 sm:grid-cols-[10rem_1fr] sm:gap-6">
                    <dt class="muted">Ajans</dt>
                    <dd><a href="{{ config('site.parent_url') }}" class="link-u font-semibold" rel="noopener">{{ preg_replace('#^https?://#', '', config('site.parent_url')) }}</a></dd>
                </div>
            </dl>
        </div>
    </section>
    {{-- Kapanış çağrısı alt bilginin kendisinde; burada ikinci kez tekrarlanmaz. --}}
@endsection
