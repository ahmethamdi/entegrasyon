{{--
    /entegrasyonlar — bütün kanallar `$channels`'tan (channel_types).
    Açık kanal "Bağlanabilir", kapalı kanal "Yakında"; ikisi de kendi
    sayfasına bağlanır (kapalı kanalın sayfası "hazırlanıyor" der).
--}}
@extends('site.layout')

@php
    $available = array_values(array_filter($channels, fn ($c) => $c['available']));
    $names = array_column($available, 'name');
    $list = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)).' ve '.end($names) : implode('', $names);
    $description = $list
        ? "{$list} entegrasyonu: stok, fiyat, sipariş ve ürün tek panelden. Hangi kanalda neyin çalıştığını burada açıkça yazıyoruz."
        : 'Pazaryeri ve e-ticaret entegrasyonları: stok, fiyat, sipariş ve ürün tek panelden.';
@endphp

{{-- Başlıktaki adlar da veritabanından: kapanan kanal başlıkta kalmasın. --}}
@section('title', $names ? 'Entegrasyonlar: '.implode(', ', array_slice($names, 0, 3)).(count($names) > 3 ? ' ve diğerleri' : '') : 'Entegrasyonlar')
@section('description', \Illuminate\Support\Str::limit($description, 157))

@push('jsonld')
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Entegrasyonlar', 'url' => route('site.channels')],
    ]])
@endpush

@section('content')
    <section class="wrap pt-14 pb-16 sm:pt-20 lg:pt-28 lg:pb-24" aria-labelledby="kanal-h1">
        <p class="eyebrow"><b>{{ count($available) }}/{{ count($channels) }}</b> Entegrasyonlar</p>
        <div class="mt-6 grid gap-10 lg:grid-cols-12 lg:items-end">
            <h1 id="kanal-h1" class="display t-hero lg:col-span-8">Bir panel. <span class="muted">Bütün</span> <span class="accent">mağazaların.</span></h1>
            <p class="lead lg:col-span-4">
                Bugün bağlayabildiklerin ve üzerinde çalıştıklarımız. Her kanalın sayfasında orada neyin çalıştığını tek tek yazıyoruz.
            </p>
        </div>
    </section>

    <section class="wrap pb-24 lg:pb-36" aria-label="Kanal listesi">
        @include('site.partials.channel-list', ['channels' => $channels])
    </section>

    {{-- Kırmızı bant: listede olmayan kanal için ne yapılacağı. --}}
    <section class="on-brand" aria-labelledby="eksik-kanal">
        <div class="wrap grid gap-8 py-20 lg:grid-cols-12 lg:items-center lg:py-24">
            <h2 id="eksik-kanal" class="display t-2 lg:col-span-7">Satış yaptığın kanal listede yok mu?</h2>
            <div class="lg:col-span-4 lg:col-start-9">
                <p class="text-lg">Hangi kanalda sattığını bize yaz. Sıradaki entegrasyonu planlarken bu mesajlara bakıyoruz.</p>
                <a href="{{ route('site.contact') }}" class="btn btn-light mt-6">Bize yaz <span class="arrow" aria-hidden="true">→</span></a>
            </div>
        </div>
    </section>
@endsection
