{{--
    Alt bilgi — kurumsal düzen: marka + şirket bilgisi solda, bağlantı
    sütunları sağda, en altta telif ve yasal bağlantılar.

    Şirket bilgisi config/site.php'den okunur: adres değişince künye,
    yasal metin ve alt bilgi aynı anda güncellensin. Kanal listesi
    veritabanından gelir; kapalı kanal "yakında" etiketiyle durur.

    Değerlendirme rozeti (ProvenExpert vb.) YOK: elimizde gerçek bir
    değerlendirme hesabı yok, olmayanı göstermeyiz.
--}}
@php
    $channelList = $channels ?? [];
    $legal = $legalPages ?? [];
    $email = config('site.contact_email');
    $phone = config('site.phone');
    $phoneHref = 'tel:'.preg_replace('/[^\d+]/', '', $phone);
@endphp
<footer class="on-dark">
    <div class="wrap grid gap-12 pt-16 pb-12 lg:grid-cols-12 lg:pt-20">
        <div class="lg:col-span-4">
            <img src="{{ asset('images/34pazar-logo-beyaz.png') }}" alt="34Pazar" width="600" height="137" loading="lazy" decoding="async" class="h-7 w-auto">
            <p class="mt-5 max-w-[34ch] text-[0.9875rem] text-muted-dark">
                Pazaryeri ve e-ticaret mağazalarının stoğunu, siparişini ve fiyatını tek panelden yönetmek için.
            </p>
            <address class="mt-6 space-y-1 text-[0.9375rem] text-muted-dark not-italic">
                <span class="block font-semibold text-white">{{ config('site.operator') }}</span>
                <span class="block">{{ config('site.street') }}, {{ config('site.postal_code') }} {{ config('site.city') }}, {{ config('site.country') }}</span>
                <a class="footer-link block" href="mailto:{{ $email }}">{{ $email }}</a>
                <a class="footer-link block" href="{{ $phoneHref }}">{{ $phone }}</a>
            </address>
        </div>

        <div class="grid grid-cols-2 gap-x-6 gap-y-10 text-[0.9375rem] sm:grid-cols-4 lg:col-span-8">
            <nav aria-labelledby="f-urun">
                <h2 id="f-urun" class="footer-title">Ürün</h2>
                <ul class="space-y-2.5">
                    <li><a class="footer-link" href="{{ route('site.features') }}">Özellikler</a></li>
                    <li><a class="footer-link" href="{{ route('site.pricing') }}">Fiyatlar</a></li>
                    <li><a class="footer-link" href="{{ route('site.channels') }}">Entegrasyonlar</a></li>
                    <li><a class="footer-link" href="{{ route('home') }}#sss">Sık sorulanlar</a></li>
                </ul>
            </nav>

            <nav aria-labelledby="f-kanal">
                <h2 id="f-kanal" class="footer-title">Kanallar</h2>
                <ul class="space-y-2.5">
                    @foreach ($channelList as $ch)
                        <li>
                            <a class="footer-link" href="{{ route('site.channel', $ch['code']) }}">{{ $ch['name'] }}</a>
                            @unless ($ch['available'])
                                <span class="ml-1 text-xs text-muted-dark">· yakında</span>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            </nav>

            <nav aria-labelledby="f-sirket">
                <h2 id="f-sirket" class="footer-title">Şirket</h2>
                <ul class="space-y-2.5">
                    <li><a class="footer-link" href="{{ route('site.about') }}">Hakkımızda</a></li>
                    <li><a class="footer-link" href="{{ route('site.contact') }}">İletişim</a></li>
                    <li><a class="footer-link" href="{{ route('site.blog') }}">Blog</a></li>
                    <li><a class="footer-link" href="{{ config('site.parent_url') }}" rel="noopener">34Devs</a></li>
                </ul>
            </nav>

            <nav aria-labelledby="f-yasal">
                <h2 id="f-yasal" class="footer-title">Yasal</h2>
                <ul class="space-y-2.5">
                    @foreach ($legal as $slug => $title)
                        <li><a class="footer-link" href="{{ route('site.legal', $slug) }}">{{ $title }}</a></li>
                    @endforeach
                </ul>
            </nav>
        </div>
    </div>

    <div class="border-t border-line-dark">
        <div class="wrap flex flex-col gap-2 py-6 text-sm text-muted-dark sm:flex-row sm:items-center sm:justify-between">
            <p>© {{ date('Y') }} 34Devs · 34Pazar bir 34Devs ürünüdür.</p>
            <p>{{ config('site.city') }}, {{ config('site.country') }}</p>
        </div>
    </div>
</footer>
