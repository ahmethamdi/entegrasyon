{{--
    /iletisim — e-posta, telefon, adres; config/site.php'den.

    FORM YOK, BİLEREK: henüz e-posta gönderim sağlayıcısı yok. Gönderilen
    ama kimseye ulaşmayan bir form, hiç form olmamasından kötüdür.

    ⚠️ `contact_email` kesinleşmedi (config notu) — yayından önce kullanıcı
    onaylayacak; değer değişince bu sayfa kendiliğinden güncellenir.
--}}
@extends('site.layout')

@php
    $email = config('site.contact_email');
    $phone = config('site.phone');
    // tel: bağlantısında boşluk olmaz; görünen metin okunaklı kalır.
    $phoneHref = 'tel:'.preg_replace('/[^\d+]/', '', $phone);
@endphp

@section('title', 'İletişim')
@section('description', '34Pazar ile ilgili sorun ya da öneri için bize e-postayla veya telefonla ulaş. 34Devs, Korschenbroich, Almanya.')

@push('jsonld')
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'İletişim', 'url' => route('site.contact')],
    ]])
@endpush

@section('content')
    <section class="border-b border-line bg-soft" aria-labelledby="iletisim-h1">
        <div class="wrap pt-12 pb-12 lg:pt-16 lg:pb-16">
            <p class="eyebrow">İletişim</p>
            <h1 id="iletisim-h1" class="h-page mt-3">Bize ulaş</h1>
            <p class="lead mt-5 max-w-[56ch]">
                Bir kanalı bağlarken takıldıysan, bir şey beklediğin gibi çalışmıyorsa ya da listede olmayan bir kanalda satıyorsan yaz.
            </p>
        </div>
    </section>

    <section class="section" aria-label="İletişim bilgileri">
        <div class="wrap">
            <ul class="grid gap-5 md:grid-cols-3">
                <li class="card">
                    <span class="icon-box">@include('site.partials.icon', ['name' => 'mail'])</span>
                    <h2 class="heading-3 mt-4">E-posta</h2>
                    <p class="mt-1 text-sm muted">Sorular, destek ve öneriler için.</p>
                    <a href="mailto:{{ $email }}" class="link mt-3 inline-block [overflow-wrap:anywhere]">{{ $email }}</a>
                </li>
                <li class="card">
                    <span class="icon-box">@include('site.partials.icon', ['name' => 'call'])</span>
                    <h2 class="heading-3 mt-4">Telefon</h2>
                    <p class="mt-1 text-sm muted">Almanya numarası.</p>
                    <a href="{{ $phoneHref }}" class="link mt-3 inline-block">{{ $phone }}</a>
                </li>
                <li class="card">
                    <span class="icon-box">@include('site.partials.icon', ['name' => 'pin'])</span>
                    <h2 class="heading-3 mt-4">Adres</h2>
                    <address class="mt-3 not-italic">
                        {{ config('site.operator') }}<br>
                        {{ config('site.street') }}<br>
                        {{ config('site.postal_code') }} {{ config('site.city') }}, {{ config('site.country') }}
                    </address>
                </li>
            </ul>
        </div>
    </section>

    <section class="border-t border-line bg-soft" aria-labelledby="iletisim-alt">
        <div class="wrap flex flex-col gap-6 py-12 lg:flex-row lg:items-center lg:justify-between">
            <div class="max-w-2xl">
                <h2 id="iletisim-alt" class="heading-2">Önce kendin bakmak istersen</h2>
                <p class="mt-3 muted">
                    Sık sorulan soruların cevapları ana sayfada. Hesabın varsa panelin "Yardım" ekranına da bakabilirsin.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('home') }}#sss" class="btn btn-secondary">Sık sorulanlar</a>
                @if ($isLoggedIn)
                    <a href="{{ url('/panel') }}" class="btn btn-primary">Panele git</a>
                @else
                    <a href="{{ route('register') }}" class="btn btn-primary">Ücretsiz dene</a>
                @endif
            </div>
        </div>
    </section>
@endsection
