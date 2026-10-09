{{--
    /entegrasyonlar — bütün kanallar `$channels`'tan (channel_types).
    Açık kanal "Aktif", kapalı kanal "Yakında"; ikisi de kendi sayfasına
    bağlanır (kapalı kanalın sayfası "hazırlanıyor" der).
--}}
@extends('site.layout')

@php
    $available = array_values(array_filter($channels, fn ($c) => $c['available']));
    $upcoming = array_values(array_filter($channels, fn ($c) => ! $c['available']));
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
    <section class="border-b border-line bg-soft" aria-labelledby="kanal-h1">
        <div class="wrap pt-12 pb-12 lg:pt-16 lg:pb-14">
            <p class="eyebrow">Entegrasyonlar</p>
            <h1 id="kanal-h1" class="h-page mt-3 max-w-[24ch]">Bir panel, bütün satış kanalların.</h1>
            <p class="lead mt-5 max-w-[56ch]">
                Bugün bağlayabildiğin kanallar ve üzerinde çalıştıklarımız. Her kanalın sayfasında orada neyin çalıştığını tek tek yazıyoruz.
            </p>
            <p class="mt-5 text-sm muted">
                <span class="font-semibold text-ink">{{ count($available) }}</span> aktif ·
                <span class="font-semibold text-ink">{{ count($upcoming) }}</span> yakında
            </p>
        </div>
    </section>

    @if (count($available))
        <section class="wrap pt-12 lg:pt-16" aria-labelledby="aktif-h2">
            <h2 id="aktif-h2" class="heading-3">Aktif kanallar</h2>
            <p class="mt-1 text-[0.9875rem] muted">Bugün bağlayabilirsin.</p>
            <div class="mt-5">
                @include('site.partials.channel-list', ['channels' => $available])
            </div>
        </section>
    @endif

    @if (count($upcoming))
        <section class="wrap pt-12 pb-16 lg:pb-20" aria-labelledby="yakinda-h2">
            <h2 id="yakinda-h2" class="heading-3">Yakında</h2>
            <p class="mt-1 text-[0.9875rem] muted">Üzerinde çalışıyoruz; hazır olduğunda kanalın sayfasında yazacak.</p>
            <div class="mt-5">
                @include('site.partials.channel-list', ['channels' => $upcoming])
            </div>
        </section>
    @else
        <div class="pb-16 lg:pb-20"></div>
    @endif

    {{-- Listede olmayan kanal için ne yapılacağı. --}}
    <section class="border-t border-line bg-soft" aria-labelledby="eksik-kanal">
        <div class="wrap flex flex-col gap-6 py-12 lg:flex-row lg:items-center lg:justify-between">
            <div class="max-w-2xl">
                <h2 id="eksik-kanal" class="heading-2">Satış yaptığın kanal listede yok mu?</h2>
                <p class="mt-3 muted">Hangi kanalda sattığını bize yaz. Sıradaki entegrasyonu planlarken bu mesajlara bakıyoruz.</p>
            </div>
            <a href="{{ route('site.contact') }}" class="btn btn-secondary">Bize yaz</a>
        </div>
    </section>

    @include('site.partials.cta')
@endsection
