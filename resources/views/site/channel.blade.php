{{--
    /entegrasyonlar/{kod} — tek şablon, `$channel` ile sürülür.

    AÇIK KANAL: neyin çalıştığı satır satır. Kargo numarasını kanala geri
    gönderme `$channel['cargoPush']`'tan gelir (adaptörün SupportsFulfillment
    yeteneği, SiteController); diğerlerinde "kanalın panelinde girilir" denir.

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

    // Bulunma eki elle tutulur: yabancı marka adının okunuşu yazılışından
    // tahmin edilemez ("Shopify" i ile biter, a ile okunur). Sözlükte yoksa nötr kalıp.
    $locative = [
        'trendyol' => "Trendyol'da", 'shopify' => "Shopify'da", 'woocommerce' => "WooCommerce'te",
        'etsy' => "Etsy'de", 'ebay' => "eBay'de", 'hepsiburada' => "Hepsiburada'da",
    ];
    $loc = $locative[$code] ?? "{$name} mağazanda";

    $connect = [
        'trendyol' => 'Trendyol satıcı panelindeki entegrasyon bilgileri: API key, API secret ve Satıcı ID (Cari ID).',
        'shopify' => 'Mağaza adresin, Admin API erişim anahtarı ve webhook imza anahtarı. Stoğun hangi konumdan (location) yönetileceğini de seçersin.',
        'woocommerce' => "Site adresin ve WooCommerce'in REST API anahtarları (consumer key ve consumer secret).",
        'etsy' => "Etsy uygulama anahtarı (keystring) ve mağaza kimliğin (shop ID). Ardından Etsy hesabınla giriş yapıp 34Pazar'a erişim izni verirsin.",
        'ebay' => 'eBay geliştirici hesabındaki uygulama bilgileri; ardından eBay hesabınla giriş yapıp erişim izni verirsin.',
        'hepsiburada' => 'Hepsiburada entegrasyon kullanıcı adın ve parolan.',
    ];
    $connectText = $connect[$code] ?? null;
    $kind = $channel['marketplace'] ? 'Pazaryeri' : 'E-ticaret sitesi';

    $cargoOk = $channel['cargoPush'];

    // [başlık, açıklama, kanalda çalışıyor mu]
    $caps = [
        ['Stok', "Başka bir kanalda satış olunca {$loc}ki stok da düşer. Adedi panelde düzeltirsen yeni sayı buraya da gider.", true],
        ['Siparişler', "{$name} siparişleri diğer kanallarının siparişleriyle aynı listeye düşer.", true],
        ['Ürün yayınlama', 'Ürünü panelden bu kanala gönderirsin. Kanal reddederse sebebi panelde yazar; düzeltip tekrar gönderirsin.', true],
        ['Fiyat', "Panelde değiştirdiğin fiyat bu kanala da gider; kanal için fiyat kuralı tanımlayabilirsin. Fiyatı {$loc} kendin değiştirirsen sessizce üzerine yazmayız; hangisinin geçerli olacağına sen karar verirsin.", true],
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
    $primaryLabel = $isLoggedIn ? 'Panele git' : 'Ücretsiz dene';

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
    <section class="border-b border-line bg-soft" aria-labelledby="kanal-h1">
        <div class="wrap pt-8 pb-12 lg:pt-10 lg:pb-16">
            <nav aria-label="Konum" class="text-sm muted">
                <ol class="flex flex-wrap items-center gap-2">
                    <li><a href="{{ route('home') }}" class="link-u hover:text-ink">Ana sayfa</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('site.channels') }}" class="link-u hover:text-ink">Entegrasyonlar</a></li>
                    <li aria-hidden="true">/</li>
                    <li aria-current="page" class="font-semibold text-ink">{{ $name }}</li>
                </ol>
            </nav>

            <div class="mt-8 flex flex-wrap items-center gap-3">
                <span class="text-sm font-semibold muted">{{ $kind }}</span>
                @if ($isOn)
                    <span class="badge badge--active">Aktif</span>
                @else
                    <span class="badge badge--soon">Yakında</span>
                @endif
            </div>

            <h1 id="kanal-h1" class="h-page mt-3 [overflow-wrap:anywhere]">{{ $name }} entegrasyonu</h1>

            @if ($isOn)
                <p class="lead mt-4 max-w-[56ch]">
                    {{ $name }} mağazanı 34Pazar'a bağla. {{ $loc }} satılan ürünün stoğu diğer kanallarında da düşer; siparişlerin tek listede durur.
                </p>
            @else
                <p class="lead mt-4 max-w-[56ch]">
                    <strong class="font-semibold text-ink">Bu entegrasyon hazırlanıyor.</strong>
                    Şu an {{ $name }} mağazanı 34Pazar'a bağlayamazsın. Hazır olduğunda bu sayfada yazacak.
                    @if ($othersText) Bu arada {{ $othersText }} ile başlayabilirsin. @endif
                </p>
            @endif
            <div class="mt-7 flex flex-wrap gap-3">
                <a href="{{ $primaryHref }}" class="btn btn-primary">{{ $primaryLabel }}</a>
                <a href="{{ route('site.channels') }}" class="btn btn-secondary">Bütün kanallar</a>
            </div>
        </div>
    </section>

    @if ($isOn)
        {{-- ═══════════════════════════════════ NE ÇALIŞIR --}}
        <section class="section" aria-labelledby="calisan-h2">
            <div class="wrap">
                <div class="section-head">
                    <h2 id="calisan-h2" class="heading-2">{{ $loc }} neler çalışır.</h2>
                    <p class="lead">Satır satır. Çalışmayanı da yazıyoruz.</p>
                </div>
                <dl class="mt-8 overflow-hidden rounded-xl border border-line">
                    @foreach ($caps as [$title, $body, $ok])
                        <div class="grid gap-2 px-5 py-5 lg:grid-cols-12 lg:items-center lg:gap-6 {{ ! $loop->first ? 'border-t border-line' : '' }}">
                            <dt class="heading-3 lg:col-span-3">{{ $title }}</dt>
                            <dd class="muted lg:col-span-7">{{ $body }}</dd>
                            <dd class="lg:col-span-2 lg:text-right">
                                @if ($ok)
                                    <span class="badge badge--active">Çalışır</span>
                                @else
                                    <span class="badge badge--soon">Kanalda girilir</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </section>

        {{-- ═══════════════════════════════════ BAĞLANTI --}}
        <section class="section bg-soft" aria-labelledby="baglanti-h2">
            <div class="wrap grid items-center gap-10 lg:grid-cols-12">
                <div class="lg:col-span-5">
                    @if ($connectText)
                        <p class="eyebrow">Bağlantı</p>
                        <h2 id="baglanti-h2" class="heading-2 mt-2">Bağlamak için ne lazım?</h2>
                        <p class="mt-4">{{ $connectText }}</p>
                        <p class="mt-4 muted">Kod yazmazsın. Bilgileri panelde "Kanal ekle" formuna yapıştırırsın; bağlantının çalışıp çalışmadığını "Kanallarım" ekranından kontrol edersin.</p>
                    @else
                        <h2 id="baglanti-h2" class="heading-2">Siparişlerin tek listede.</h2>
                        <p class="mt-4 muted">{{ $name }} siparişleri diğer kanallarının siparişleriyle aynı listeye düşer.</p>
                    @endif
                </div>
                <div class="lg:col-span-7">
                    @include('site.partials.shot', [
                        'src' => 'images/site/panel-siparisler.png',
                        'alt' => 'Sipariş listesi: farklı kanallardan gelen siparişler tek listede',
                    ])
                </div>
            </div>
        </section>
    @endif

    {{-- ═══════════════════════════════════ DİĞER KANALLAR --}}
    @if (count($others))
        <section class="section" aria-labelledby="diger-kanal-h2">
            <div class="wrap">
                <h2 id="diger-kanal-h2" class="heading-2">Diğer kanallar</h2>
                <div class="mt-8">
                    @include('site.partials.channel-list', ['channels' => $others])
                </div>
            </div>
        </section>
    @endif

    @include('site.partials.cta')
@endsection
