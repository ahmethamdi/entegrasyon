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
    <section class="wrap pt-14 pb-16 sm:pt-20 lg:pt-28 lg:pb-24" aria-labelledby="iletisim-h1">
        <p class="eyebrow">İletişim</p>
        <h1 id="iletisim-h1" class="display t-hero mt-6">Bize <span class="accent">yaz.</span></h1>
        <p class="lead mt-10 max-w-[44ch] lg:mt-14">
            Bir kanalı bağlarken takıldıysan, bir şey beklediğin gibi çalışmıyorsa ya da listede olmayan bir kanalda satıyorsan yaz.
        </p>
    </section>

    <section class="on-dark" aria-label="İletişim bilgileri">
        <div class="wrap py-20 lg:py-28">
            {{-- E-posta adresi dev yazıyla: sayfanın asıl içeriği bu. Uzun adres dar ekranda kırılabilir. --}}
            <p class="eyebrow"><b>01</b> E-posta</p>
            <a href="mailto:{{ $email }}" class="display mt-6 block text-[clamp(1.75rem,0.6rem+5.4vw,6.5rem)] leading-none [overflow-wrap:anywhere] hover:text-[#ff6a47]">{{ $email }}</a>

            <div class="mt-20 grid gap-12 border-t border-line-dark pt-12 md:grid-cols-2 lg:mt-28">
                <div>
                    <p class="eyebrow"><b>02</b> Telefon</p>
                    <a href="{{ $phoneHref }}" class="display t-3 mt-5 block hover:text-[#ff6a47]">{{ $phone }}</a>
                </div>
                <div>
                    <p class="eyebrow"><b>03</b> Adres</p>
                    <address class="mt-5 text-lg leading-relaxed not-italic">
                        {{ config('site.operator') }}<br>
                        {{ config('site.street') }}<br>
                        {{ config('site.postal_code') }} {{ config('site.city') }}, {{ config('site.country') }}
                    </address>
                </div>
            </div>
        </div>
    </section>

    <section class="wrap py-24 lg:py-32" aria-labelledby="iletisim-alt">
        <div class="grid gap-10 lg:grid-cols-12 lg:items-end">
            <div class="lg:col-span-7">
                <h2 id="iletisim-alt" class="display t-2">Önce kendin bakmak istersen.</h2>
                <p class="mt-6 max-w-[48ch] muted">
                    Sık sorulan soruların cevapları ana sayfada. Hesabın varsa panelin "Yardım" ekranına da bakabilirsin.
                </p>
            </div>
            <div class="flex flex-wrap gap-4 lg:col-span-5 lg:justify-end">
                <a href="{{ route('home') }}#sss" class="btn btn-outline">Sık sorulanlar</a>
                @if ($isLoggedIn)
                    <a href="{{ url('/panel') }}" class="btn btn-primary">Panele git <span class="arrow" aria-hidden="true">→</span></a>
                @else
                    <a href="{{ route('register') }}" class="btn btn-primary">Ücretsiz başla <span class="arrow" aria-hidden="true">→</span></a>
                @endif
            </div>
        </div>
    </section>
@endsection
