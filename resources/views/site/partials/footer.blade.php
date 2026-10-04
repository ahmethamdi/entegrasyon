{{--
    Büyük alt bilgi: kapanış çağrısı + bağlantı sütunları + sayfa genişliğinde
    "34PAZAR" yazısı. Kanal listesi veritabanından gelir; kapalı kanal
    "yakında" etiketiyle durur, "destekleniyor" diye değil.

    Şirket bilgisi config/site.php'den okunur: adres değişince künye,
    yasal metin ve alt bilgi aynı anda güncellensin.
--}}
@php
    $loggedIn = $isLoggedIn ?? false;
    $channelList = $channels ?? [];
    $legal = $legalPages ?? [];
    $address = config('site.street').', '.config('site.postal_code').' '.config('site.city').', '.config('site.country');
@endphp
<footer class="on-dark overflow-hidden">
    <div class="wrap pt-20 pb-10 lg:pt-28">
        <div class="grid gap-10 border-b border-line-dark pb-16 lg:grid-cols-12 lg:items-end lg:pb-20">
            <p class="display t-1 lg:col-span-8">Stoğun tek yerde.<br><span class="text-muted-dark">Satış her yerde.</span></p>
            <div class="flex flex-wrap gap-3 lg:col-span-4 lg:justify-end">
                @if ($loggedIn)
                    <a href="{{ url('/panel') }}" class="btn btn-primary">Panele git <span class="arrow" aria-hidden="true">→</span></a>
                @else
                    <a href="{{ route('register') }}" class="btn btn-primary">Ücretsiz başla <span class="arrow" aria-hidden="true">→</span></a>
                    <a href="{{ route('login') }}" class="btn border-white/40 text-white hover:border-white">Giriş yap</a>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-2 gap-x-6 gap-y-12 pt-14 text-[0.9875rem] md:grid-cols-3 lg:grid-cols-5">
            <nav aria-labelledby="f-urun">
                <h2 id="f-urun" class="mb-5 text-xs font-semibold tracking-[0.14em] text-white uppercase">Ürün</h2>
                <ul class="space-y-3">
                    <li><a class="footer-link" href="{{ route('site.features') }}">Özellikler</a></li>
                    <li><a class="footer-link" href="{{ route('site.pricing') }}">Fiyatlar</a></li>
                    <li><a class="footer-link" href="{{ route('site.channels') }}">Entegrasyonlar</a></li>
                    @if ($loggedIn)
                        <li><a class="footer-link" href="{{ url('/panel') }}">Panel</a></li>
                    @else
                        <li><a class="footer-link" href="{{ route('login') }}">Giriş yap</a></li>
                    @endif
                </ul>
            </nav>

            <nav aria-labelledby="f-kanal">
                <h2 id="f-kanal" class="mb-5 text-xs font-semibold tracking-[0.14em] text-white uppercase">Kanallar</h2>
                <ul class="space-y-3">
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

            <nav aria-labelledby="f-kaynak">
                <h2 id="f-kaynak" class="mb-5 text-xs font-semibold tracking-[0.14em] text-white uppercase">Kaynaklar</h2>
                <ul class="space-y-3">
                    <li><a class="footer-link" href="{{ route('site.blog') }}">Blog</a></li>
                    <li><a class="footer-link" href="{{ route('home') }}#sss">Sık sorulanlar</a></li>
                </ul>
            </nav>

            <nav aria-labelledby="f-sirket">
                <h2 id="f-sirket" class="mb-5 text-xs font-semibold tracking-[0.14em] text-white uppercase">Şirket</h2>
                <ul class="space-y-3">
                    <li><a class="footer-link" href="{{ route('site.about') }}">Hakkımızda</a></li>
                    <li><a class="footer-link" href="{{ route('site.contact') }}">İletişim</a></li>
                    <li><a class="footer-link" href="{{ config('site.parent_url') }}" rel="noopener">34Devs</a></li>
                </ul>
            </nav>

            <nav aria-labelledby="f-yasal">
                <h2 id="f-yasal" class="mb-5 text-xs font-semibold tracking-[0.14em] text-white uppercase">Yasal</h2>
                <ul class="space-y-3">
                    @foreach ($legal as $slug => $title)
                        <li><a class="footer-link" href="{{ route('site.legal', $slug) }}">{{ $title }}</a></li>
                    @endforeach
                </ul>
            </nav>
        </div>
    </div>

    {{-- Dev yazı süs: anlamı yukarıdaki logoyla aynı, ekran okuyucu atlar. --}}
    <div class="wrap select-none" aria-hidden="true">
        <span class="wordmark -mb-[0.06em] pt-6"><span class="text-brand">34</span>PAZAR</span>
    </div>

    <div class="border-t border-line-dark">
        <div class="wrap flex flex-col gap-2 py-6 text-sm text-muted-dark sm:flex-row sm:items-center sm:justify-between">
            <p>© {{ date('Y') }} 34Devs · 34Pazar bir 34Devs ürünüdür.</p>
            <p>{{ $address }}</p>
        </div>
    </div>
</footer>
