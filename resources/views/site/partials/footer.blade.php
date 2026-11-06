{{--
    Alt bilgi — kurumsal düzen: marka + şirket bilgisi solda, bağlantı
    sütunları sağda, en altta telif ve yasal bağlantılar.

    6 Kas 2026 · site sprint 1: AÇIK ZEMİN (koyu tam genişlik alt bilgi
    kaldırıldı). Kurumsal bilgi künyedekiyle AYNI kaynaktan
    (`config/site.php`): işletmeci, şahıs işletmesi, adres, e-posta,
    WhatsApp. Vergi numarası / MERSİS YOK — bize bildirilmedi, uydurulmaz.

    Değerlendirme rozeti (ProvenExpert vb.) YOK: elimizde gerçek bir
    değerlendirme hesabı yok, olmayanı göstermeyiz.
--}}
@php
    $channelList = $channels ?? [];
    $legal = $legalPages ?? [];
    $email = config('site.contact_email');
@endphp
<footer class="site-footer">
    <div class="wrap grid gap-12 pt-14 pb-12 lg:grid-cols-12 lg:pt-16">
        <div class="lg:col-span-3">
            <img src="{{ asset('images/34pazar-logo.png') }}" alt="34Pazar" width="600" height="137" loading="lazy" decoding="async" class="h-7 w-auto">
            <p class="mt-5 max-w-[34ch] text-[0.9875rem] muted">
                Pazaryeri ve e-ticaret mağazalarının stoğunu, siparişini ve fiyatını tek panelden yönetmek için.
            </p>
            <address class="mt-6 space-y-1 text-[0.9375rem] muted not-italic">
                <span class="block font-semibold text-ink">{{ config('site.operator') }}</span>
                <span class="block">Şahıs işletmesi (Einzelunternehmen)</span>
                <span class="block">{{ config('site.street') }}, {{ config('site.postal_code') }} {{ config('site.city') }}, {{ config('site.country') }}</span>
                <a class="footer-link block" href="mailto:{{ $email }}">{{ $email }}</a>
                <a class="footer-link block" href="https://wa.me/{{ config('site.whatsapp') }}">WhatsApp {{ config('site.whatsapp_display') }}</a>
                <a class="footer-link block" href="{{ route('site.legal', 'kunye') }}">Künye</a>
            </address>
        </div>

        <div class="grid grid-cols-2 gap-x-6 gap-y-10 text-[0.9375rem] sm:grid-cols-3 lg:col-span-9 lg:grid-cols-6">
            <nav aria-labelledby="f-urun">
                <h2 id="f-urun" class="footer-title">Ürün</h2>
                <ul class="space-y-2.5">
                    <li><a class="footer-link" href="{{ route('site.features') }}">Özellikler</a></li>
                    <li><a class="footer-link" href="{{ route('site.pricing') }}">Fiyatlar</a></li>
                    <li><a class="footer-link" href="{{ route('site.channels') }}">Entegrasyonlar</a></li>
                    <li><a class="footer-link" href="{{ route('site.faq') }}">Sık sorulanlar</a></li>
                    <li><a class="footer-link" href="{{ route('site.help') }}">Kanal bağlama rehberleri</a></li>
                    <li><a class="footer-link" href="{{ route('site.security') }}">Güvenlik ve KVKK</a></li>
                </ul>
            </nav>

            <nav aria-labelledby="f-cozum">
                <h2 id="f-cozum" class="footer-title">Çözümler</h2>
                <ul class="space-y-2.5">
                    @foreach ($problemMenu ?? [] as $item)
                        <li><a class="footer-link" href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>

            <nav aria-labelledby="f-hizmet">
                <h2 id="f-hizmet" class="footer-title">Hizmetler</h2>
                <ul class="space-y-2.5">
                    @foreach ($serviceMenu ?? [] as $item)
                        <li><a class="footer-link" href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>

            <nav aria-labelledby="f-kanal">
                <h2 id="f-kanal" class="footer-title">Kanallar</h2>
                <ul class="space-y-2.5">
                    @foreach ($channelList as $ch)
                        <li>
                            <a class="footer-link" href="{{ route('site.channel', $ch['code']) }}">{{ $ch['name'] }}</a>
                            @unless ($ch['available'])
                                <span class="ml-1 text-xs muted">· yakında</span>
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
                    <li><a class="footer-link" href="{{ route('site.contact', ['konu' => 'gorusme']) }}#form">Görüşme planla</a></li>
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

    <div class="border-t border-line">
        <div class="wrap flex flex-col gap-2 py-6 text-sm muted sm:flex-row sm:items-center sm:justify-between">
            <p>© {{ date('Y') }} {{ config('site.operator') }} · 34Pazar bir 34Devs ürünüdür.</p>
            <p>{{ config('site.city') }}, {{ config('site.country') }}</p>
        </div>
    </div>
</footer>
