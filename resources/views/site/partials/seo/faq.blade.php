{{--
    FAQPage JSON-LD.

    Kullanım: @include('site.partials.seo.faq', ['faqs' => [['q' => '…', 'a' => '…'], …]])
    Soru-cevaplar sayfada GÖRÜNÜR olmalı; yalnız yapılandırılmış veride
    duran SSS Google yönergelerine aykırıdır. Cevaptaki HTML etiketleri
    atılır — JSON-LD düz metin bekler.
--}}
@if (! empty($faqs))
@php
    $faqPage = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn (array $item): array => [
            '@type' => 'Question',
            'name' => strip_tags((string) $item['q']),
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags((string) $item['a'])],
        ], array_values($faqs)),
    ];
@endphp
<script type="application/ld+json">{!! json_encode($faqPage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endif
