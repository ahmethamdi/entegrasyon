{{--
    BreadcrumbList JSON-LD.

    Kullanım: @include('site.partials.seo.breadcrumb', ['items' => [['name' => 'Ana sayfa', 'url' => route('home')], …]])
    Adresler mutlak olmalı (route()/url() zaten mutlak üretir); göreli
    adres verilirse url() ile tamamlanır.
--}}
@if (! empty($items))
@php
    $breadcrumb = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => array_map(fn (array $item, int $i): array => [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'name' => (string) $item['name'],
            'item' => url((string) $item['url']),
        ], array_values($items), array_keys(array_values($items))),
    ];
@endphp
<script type="application/ld+json">{!! json_encode($breadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endif
