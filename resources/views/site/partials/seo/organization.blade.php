{{--
    Organization JSON-LD — 34Pazar, 34Devs'in ürünü.

    Bilgiler config/site.php'den: adres değişince künye, alt bilgi ve
    yapılandırılmış veri aynı anda değişsin. Telefon/e-posta BİLEREK
    eklenmedi: posta kutusu henüz kesinleşmedi (config notu) ve arama
    motorunda yanlış iletişim bilgisi göstermek, hiç göstermemekten kötü.

    Kullanım: @include('site.partials.seo.organization') — parametresiz.
--}}
@php
    $organization = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        '@id' => url('/').'#organization',
        'name' => config('site.brand'),
        'url' => url('/'),
        'logo' => asset('images/34pazar-logo.png'),
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => config('site.street'),
            'postalCode' => config('site.postal_code'),
            'addressLocality' => config('site.city'),
            'addressCountry' => 'DE',
        ],
        'parentOrganization' => [
            '@type' => 'Organization',
            'name' => '34Devs',
            'url' => config('site.parent_url'),
        ],
    ];
@endphp
<script type="application/ld+json">{!! json_encode($organization, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
