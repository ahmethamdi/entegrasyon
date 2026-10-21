{{--
    Ana sayfa girişindeki stok diyagramı — ürünün kendisini anlatır (21 Eki 2026).

    Trendyol'dan bir sipariş gelir, ÖRNEK ürünün (Seramik kupa · KP-120)
    stoğu 12'den 11'e düşer ve yeni sayı diğer kanallara gider. Rakamlar
    açıkça örnektir: kartta "örnek ürün" yazar, ekran okuyucu da bunu duyar.

    Hareket SMIL ile (JS yok), 8 sn'lik sakin döngü. `prefers-reduced-motion`
    açıksa hareketli katman gizlenir ve son kare (stok 11, her kanalda 11)
    durağan görünür (site.css · .dg-anim / .dg-final).

    Değişkenler: $lang ('tr' | 'en')
--}}
@php
    use App\Domain\Channels\Support\ChannelLogo;

    $en = ($lang ?? 'tr') === 'en';
    $t = fn (string $tr, string $enText): string => $en ? $enText : $tr;

    // viewBox 640×450; kaynak kanal sol üstte, ürün kartı ortada.
    [$cx, $cy] = [320, 210];
    $source = ['trendyol', 'Trendyol', 90, 90];
    $targets = [
        ['amazon', 'Amazon', 550, 90],
        ['hepsiburada', 'Hepsiburada', 90, 330],
        ['shopify', 'Shopify', 550, 330],
        ['n11', 'n11', 320, 40],
        ['kaufland', 'Kaufland', 320, 380],
    ];
    $all = [$source, ...$targets];
    $dur = '8s';
    $label = $t(
        "Örnek ürün: Trendyol'dan gelen bir sipariş seramik kupanın stoğunu 12'den 11'e düşürür; yeni sayı Amazon, Hepsiburada, Shopify, n11 ve Kaufland'a gönderilir.",
        'Example product: an order from Trendyol reduces the ceramic mug\'s stock from 12 to 11; the new number is sent to Amazon, Hepsiburada, Shopify, n11 and Kaufland.',
    );
@endphp
<svg class="dg" viewBox="0 0 640 450" role="img" aria-labelledby="dg-title dg-desc">
    <title id="dg-title">{{ $t('Örnek ürün ile stok akışı', 'Stock flow with an example product') }}</title>
    <desc id="dg-desc">{{ $label }}</desc>

    @foreach ($all as [$code, $name, $x, $y])
        <line x1="{{ $x }}" y1="{{ $y }}" x2="{{ $cx }}" y2="{{ $cy }}" class="dg-line"/>
    @endforeach

    @foreach ($all as [$code, $name, $x, $y])
        <rect x="{{ $x - 26 }}" y="{{ $y - 26 }}" width="52" height="52" rx="10" class="dg-node"/>
        @if ($icon = ChannelLogo::icon($code))
            <image href="{{ $icon }}" x="{{ $x - 18 }}" y="{{ $y - 18 }}" width="36" height="36" preserveAspectRatio="xMidYMid meet"/>
        @endif
        <text x="{{ $x }}" y="{{ $y + 44 }}" text-anchor="middle" class="dg-name">{{ $name }}</text>
    @endforeach

    {{-- Ürün kartı --}}
    <rect x="{{ $cx - 112 }}" y="{{ $cy - 46 }}" width="224" height="92" rx="12" class="dg-node"/>
    <rect x="{{ $cx - 98 }}" y="{{ $cy - 32 }}" width="40" height="40" rx="8" fill="#f3f4f6"/>
    <path d="M{{ $cx - 88 }} {{ $cy - 22 }} h16 v14 a4 4 0 0 1 -4 4 h-8 a4 4 0 0 1 -4 -4z M{{ $cx - 72 }} {{ $cy - 18 }} h4 a3 3 0 0 1 0 6 h-4" fill="none" stroke="#6b7280" stroke-width="1.6"/>
    <text x="{{ $cx - 48 }}" y="{{ $cy - 16 }}" class="dg-title">{{ $t('Seramik kupa', 'Ceramic mug') }}</text>
    <text x="{{ $cx - 48 }}" y="{{ $cy + 2 }}" class="dg-sku">SKU KP-120</text>
    <text x="{{ $cx - 98 }}" y="{{ $cy + 32 }}" class="dg-sku">{{ $t('Stok', 'Stock') }}</text>
    <text x="{{ $cx + 100 }}" y="{{ $cy + 32 }}" text-anchor="end" class="dg-note">{{ $t('örnek ürün', 'example product') }}</text>
    <text x="{{ $cx - 64 }}" y="{{ $cy + 33 }}" class="dg-stock dg-stock--new dg-final">11</text>

    <g class="dg-anim">
        <text x="{{ $cx - 64 }}" y="{{ $cy + 33 }}" class="dg-stock">12<animate attributeName="opacity" dur="{{ $dur }}" repeatCount="indefinite" values="1;1;0;0;1" keyTimes="0;0.22;0.24;0.97;1"/></text>
        <text x="{{ $cx - 64 }}" y="{{ $cy + 33 }}" class="dg-stock dg-stock--new" opacity="0">11<animate attributeName="opacity" dur="{{ $dur }}" repeatCount="indefinite" values="0;0;1;1;0" keyTimes="0;0.22;0.24;0.97;1"/></text>

        {{-- 1) kaynakta "Sipariş" etiketi, 2) nokta kaynaktan karta --}}
        <g opacity="0">
            <rect x="{{ $source[2] - 34 }}" y="{{ $source[3] - 62 }}" width="68" height="22" rx="11" class="dg-accent"/>
            <text x="{{ $source[2] }}" y="{{ $source[3] - 47 }}" text-anchor="middle" class="dg-pill">{{ $t('Sipariş', 'Order') }}</text>
            <animate attributeName="opacity" dur="{{ $dur }}" repeatCount="indefinite" values="0;1;1;0;0" keyTimes="0;0.04;0.2;0.26;1"/>
        </g>
        <circle r="6" class="dg-accent" opacity="0">
            <animateMotion dur="{{ $dur }}" repeatCount="indefinite" path="M{{ $source[2] }},{{ $source[3] }} L{{ $cx }},{{ $cy }}" keyPoints="0;0;1;1" keyTimes="0;0.06;0.22;1" calcMode="linear"/>
            <animate attributeName="opacity" dur="{{ $dur }}" repeatCount="indefinite" values="0;1;1;0;0" keyTimes="0;0.06;0.21;0.23;1"/>
        </circle>

        {{-- 3) noktalar karttan kanallara, 4) kanalda "11" --}}
        @foreach ($targets as [$code, $name, $x, $y])
            <circle r="5" class="dg-accent" opacity="0">
                <animateMotion dur="{{ $dur }}" repeatCount="indefinite" path="M{{ $cx }},{{ $cy }} L{{ $x }},{{ $y }}" keyPoints="0;0;1;1" keyTimes="0;0.3;0.48;1" calcMode="linear"/>
                <animate attributeName="opacity" dur="{{ $dur }}" repeatCount="indefinite" values="0;0;1;1;0;0" keyTimes="0;0.29;0.3;0.47;0.49;1"/>
            </circle>
            <g opacity="0">
                <rect x="{{ $x + 22 }}" y="{{ $y - 38 }}" width="34" height="20" rx="10" class="dg-node"/>
                <text x="{{ $x + 39 }}" y="{{ $y - 24 }}" text-anchor="middle" class="dg-num">11</text>
                <animate attributeName="opacity" dur="{{ $dur }}" repeatCount="indefinite" values="0;0;1;1;0" keyTimes="0;0.48;0.52;0.94;1"/>
            </g>
        @endforeach
    </g>

    {{-- Hareketsiz son kare (reduced-motion) --}}
    <g class="dg-final">
        @foreach ($targets as [$code, $name, $x, $y])
            <rect x="{{ $x + 22 }}" y="{{ $y - 38 }}" width="34" height="20" rx="10" class="dg-node"/>
            <text x="{{ $x + 39 }}" y="{{ $y - 24 }}" text-anchor="middle" class="dg-num">11</text>
        @endforeach
    </g>
</svg>
