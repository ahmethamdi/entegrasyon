{{--
    /entegrasyonlar/{kod} — tek şablon, `$channel` ile sürülür.

    AÇIK KANAL: neyin çalıştığı satır satır. Kargo numarasını kanala geri
    gönderme YALNIZ `$cargoPush` listesindeki kanallarda var (home ve
    features ile AYNI liste); diğerlerinde "kanalın panelinde girilir" denir.

    KAPALI KANAL: hiçbir yetenek iddiası yok. "Hazırlanıyor" der, yine de
    kayıt olmaya çağırır (satıcı diğer kanallarıyla başlayabilir).

    Bağlantı için istenen bilgiler ChannelConnectForm'daki alanlardan
    yazıldı — form değişirse buradaki cümle de değişmeli.
--}}
@extends('site.layout')

@php
    $code = $channel['code'];
    $name = $channel['name'];
    $isOn = $channel['available'];

    // Bulunma eki elle tutulur (home ile aynı gerekçe); sözlükte yoksa nötr kalıp.
    $locative = [
        'trendyol' => "Trendyol'da", 'shopify' => "Shopify'da", 'woocommerce' => "WooCommerce'te",
        'etsy' => "Etsy'de", 'ebay' => "eBay'de", 'hepsiburada' => "Hepsiburada'da",
    ];
    $loc = $locative[$code] ?? "{$name} mağazanda";

    $copy = [
        'trendyol' => [
            'kind' => 'Pazaryeri',
            'connect' => 'Trendyol satıcı panelindeki entegrasyon bilgileri: API key, API secret ve Satıcı ID (Cari ID).',
        ],
        'shopify' => [
            'kind' => 'E-ticaret sitesi',
            'connect' => 'Mağaza adresin, Admin API erişim anahtarı ve webhook imza anahtarı. Stoğun hangi konumdan (location) yönetileceğini de seçersin.',
        ],
        'woocommerce' => [
            'kind' => 'E-ticaret sitesi',
            'connect' => "Site adresin ve WooCommerce'in REST API anahtarları (consumer key ve consumer secret).",
        ],
        'etsy' => [
            'kind' => 'Pazaryeri',
            'connect' => "Etsy uygulama anahtarı (keystring) ve mağaza kimliğin (shop ID). Ardından Etsy hesabınla giriş yapıp 34Pazar'a erişim izni verirsin.",
        ],
        'ebay' => [
            'kind' => 'Pazaryeri',
            'connect' => 'eBay geliştirici hesabındaki uygulama bilgileri; ardından eBay hesabınla giriş yapıp erişim izni verirsin.',
        ],
        'hepsiburada' => [
            'kind' => 'Pazaryeri',
            'connect' => 'Hepsiburada entegrasyon kullanıcı adın ve parolan.',
        ],
    ];
    $info = $copy[$code] ?? ['kind' => 'Satış kanalı', 'connect' => null];

    $cargoPush = ['shopify', 'woocommerce'];
    $cargoOk = in_array($code, $cargoPush, true);

    // [başlık, açıklama, kanalda çalışıyor mu]
    $caps = [
        ['Stok', "Başka bir kanalda satış olunca {$loc}ki stok da düşer. Adedi panelde düzeltirsen yeni sayı buraya da gider.", true],
        ['Siparişler', "{$name} siparişleri diğer kanallarının siparişleriyle aynı listeye düşer.", true],
        ['Ürün yayınlama', 'Ürünü panelden bu kanala gönderirsin. Kanal reddederse sebebi panelde yazar; düzeltip tekrar gönderirsin.', true],
        ['Fiyat', "Panelde değiştirdiğin fiyat bu kanala da gider. Fiyatı {$loc} kendin değiştirirsen sessizce üzerine yazmayız; hangisinin geçerli olacağına sen karar verirsin.", true],
        ['Kargo takip numarası', $cargoOk
            ? 'Numarayı panelde siparişin içine yazarsın; numara bu kanala iletilir ve sipariş orada da kargolandı olarak işaretlenir.'
            : "Şimdilik numarayı {$loc}, kanalın kendi panelinde girersin. Panel bu siparişi yine de listede gösterir.", $cargoOk],
    ];

    $others = array_values(array_filter($channels, fn ($c) => $c['code'] !== $code));
    $availableOthers = array_column(array_filter($others, fn ($c) => $c['available']), 'name');
    $othersText = count($availableOthers) > 1
        ? implode(', ', array_slice($availableOthers, 0, -1)).' ve '.end($availableOthers)
        : implode('', $availableOthers);

    $primaryHref = $isLoggedIn ? url('/panel') : route('register');
    $primaryLabel = $isLoggedIn ? 'Panele git' : ($isOn ? 'Ücretsiz başla' : 'Ücretsiz hesap aç');

    $description = $isOn
        ? "{$name} entegrasyonu: {$loc}ki stok, fiyat, sipariş ve ürünlerini diğer kanallarınla birlikte tek panelden yönet. Neyin çalıştığı tek tek."
        : "{$name} entegrasyonu hazırlanıyor. Şu an bağlanamıyor; hazır olduğunda bu sayfada yazacak. Diğer kanallarınla bugün başlayabilirsin.";
@endphp

@section('title', "{$name} entegrasyonu".($isOn ? ': stok, sipariş ve fiyat tek panelde' : ' (yakında)'))
@section('description', \Illuminate\Support\Str::limit($description, 157))

@push('jsonld')
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Entegrasyonlar', 'url' => route('site.channels')],
        ['name' => $name, 'url' => route('site.channel', $code)],
    ]])
@endpush

@section('content')
    <section class="wrap pt-10 pb-16 sm:pt-14 lg:pt-20 lg:pb-24" aria-labelledby="kanal-h1">
        <nav aria-label="Konum" class="text-sm muted">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('home') }}" class="hover:text-ink">Ana sayfa</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="{{ route('site.channels') }}" class="hover:text-ink">Entegrasyonlar</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page" class="font-semibold text-ink">{{ $name }}</li>
            </ol>
        </nav>

        <div class="mt-10 flex flex-wrap items-center gap-x-5 gap-y-2 lg:mt-14">
            <p class="eyebrow">{{ $info['kind'] }}</p>
            @if ($isOn)
                <span class="tag">Bağlanabilir</span>
            @else
                <span class="tag tag--soon">Yakında</span>
            @endif
        </div>

        <h1 id="kanal-h1" class="display t-hero mt-6 [overflow-wrap:anywhere]">
            {{ $name }}<br><span class="{{ $isOn ? 'accent' : 'muted' }}">entegrasyonu.</span>
        </h1>

        <div class="mt-10 grid gap-8 lg:mt-14 lg:grid-cols-12 lg:items-end">
            @if ($isOn)
                <p class="lead max-w-[40ch] lg:col-span-6">
                    {{ $name }} mağazanı 34Pazar'a bağla. {{ $loc }} satılan ürünün stoğu diğer kanallarında da düşer; siparişlerin tek listede durur.
                </p>
            @else
                <p class="lead max-w-[44ch] lg:col-span-6">
                    <strong class="font-semibold">Bu entegrasyon hazırlanıyor.</strong>
                    Şu an {{ $name }} mağazanı 34Pazar'a bağlayamazsın. Hazır olduğunda bu sayfada yazacak.
                    @if ($othersText) Bu arada {{ $othersText }} ile başlayabilirsin. @endif
                </p>
            @endif
            <div class="flex flex-wrap gap-4 lg:col-span-6 lg:justify-end">
                <a href="{{ $primaryHref }}" class="btn btn-primary">{{ $primaryLabel }} <span class="arrow" aria-hidden="true">→</span></a>
                <a href="{{ route('site.channels') }}" class="btn btn-outline">Bütün kanallar</a>
            </div>
        </div>
    </section>

    @if ($isOn)
        {{-- ═══════════════════════════════════ NE ÇALIŞIR (siyah) --}}
        <section class="on-dark" aria-labelledby="calisan-h2">
            <div class="wrap py-24 lg:py-32">
                <div class="grid gap-8 lg:grid-cols-12 lg:items-end">
                    <h2 id="calisan-h2" class="display t-1 lg:col-span-8">{{ $loc }} neler çalışır.</h2>
                    <p class="muted lg:col-span-4">Satır satır. Çalışmayanı da yazıyoruz.</p>
                </div>
                <dl class="mt-14 border-t border-line-dark lg:mt-20">
                    @foreach ($caps as $n => [$title, $body, $ok])
                        <div class="grid gap-3 border-b border-line-dark py-8 lg:grid-cols-12 lg:gap-8" data-reveal>
                            <dt class="display t-3 lg:col-span-4">
                                <span class="mr-3 align-middle text-sm text-[#ff6a47] tabular-nums">{{ sprintf('%02d', $n + 1) }}</span>{{ $title }}
                            </dt>
                            <dd class="muted lg:col-span-6">{{ $body }}</dd>
                            <dd class="lg:col-span-2 lg:text-right">
                                @if ($ok)
                                    <span class="tag text-white">Çalışır</span>
                                @else
                                    <span class="tag tag--soon">Kanalda girilir</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </section>

        {{-- ═══════════════════════════════════ BAĞLANTI --}}
        @if ($info['connect'])
            <section class="wrap py-24 lg:py-32" aria-labelledby="baglanti-h2">
                <div class="grid gap-12 lg:grid-cols-12">
                    <div class="lg:col-span-5">
                        <p class="eyebrow">Bağlantı</p>
                        <h2 id="baglanti-h2" class="display t-2 mt-6">Bağlamak için ne lazım?</h2>
                    </div>
                    <div class="lg:col-span-6 lg:col-start-7">
                        <p class="lead">{{ $info['connect'] }}</p>
                        <p class="mt-6 muted">Kod yazmazsın. Bilgileri panelde "Kanal ekle" formuna yapıştırırsın; bağlantının çalışıp çalışmadığını "Kanallarım" ekranından kontrol edersin.</p>
                    </div>
                </div>
            </section>
        @endif

        <section class="wrap pb-24 lg:pb-32" aria-label="Panelden bir ekran">
            @include('site.partials.shot', [
                'src' => 'images/site/panel-siparisler.png',
                'alt' => 'Sipariş listesi: farklı kanallardan gelen siparişler tek listede',
            ])
        </section>
    @endif

    {{-- ═══════════════════════════════════ DİĞER KANALLAR --}}
    @if (count($others))
        <section class="border-t border-line" aria-labelledby="diger-kanal-h2">
            <div class="wrap py-24 lg:py-32">
                <h2 id="diger-kanal-h2" class="display t-2">Diğer kanallar.</h2>
                <div class="mt-12">
                    @include('site.partials.channel-list', ['channels' => $others])
                </div>
            </div>
        </section>
    @endif
@endsection
