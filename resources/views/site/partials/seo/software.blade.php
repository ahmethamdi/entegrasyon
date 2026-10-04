{{--
    SoftwareApplication JSON-LD — fiyatlar `$plans`'tan (veritabanı).

    Fiyat elle yazılmaz: sitede ve aramada görünen fiyat panelde ödenen
    fiyattan farklı olamasın (SiteController — SİTE VERİ UYDURMAZ).
    `aggregateRating` BİLEREK yok: gerçek değerlendirme verimiz yok ve
    uydurma puan Google yönergelerine göre ceza sebebidir.

    Kullanım: @include('site.partials.seo.software') — `$plans` görünümde hazır.
--}}
@php
    // Açıklamadaki kanal adları da veritabanından: "yakında" olan kanal
    // aramada "destekleniyor" gibi görünmesin.
    $liveChannels = array_column(array_filter($channels ?? [], fn (array $c): bool => $c['available']), 'name');
    $software = [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => config('site.brand'),
        'url' => url('/'),
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'inLanguage' => 'tr',
        'description' => ($liveChannels !== [] ? implode(', ', $liveChannels).' gibi satış kanallarını' : 'Satış kanallarını')
            .' tek panelde toplayan pazaryeri entegrasyonu: merkezi stok, sipariş ve fiyat yönetimi.',
        'publisher' => ['@type' => 'Organization', 'name' => '34Devs', 'url' => config('site.parent_url')],
        'offers' => array_map(fn (array $plan): array => [
            '@type' => 'Offer',
            'name' => $plan['name'],
            // Schema.org fiyatı nokta ayraçlı düz sayı ister ("1499.00"), "1.499 TL" değil.
            'price' => number_format((float) $plan['priceMonthly'], 2, '.', ''),
            'priceCurrency' => $plan['currency'] ?? 'TRY',
            'url' => route('site.pricing'),
        ], $plans ?? []),
    ];
@endphp
<script type="application/ld+json">{!! json_encode($software, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
