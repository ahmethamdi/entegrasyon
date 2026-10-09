{{--
    34pazar.com ana sayfası — kurumsal düzen (9 Ekim 2026).

    Akış: ne yaptığımız tek cümlede → bağlanan kanallar → 3 adımda nasıl
    çalışır → sorun/çözüm blokları (gerçek panel ekranlarıyla) → diğer
    özellikler → fiyat özeti → SSS → son çağrı.

    DEĞİŞMEZ KURAL — SAYFA VERİ UYDURMAZ (SiteController ile aynı kural):
      Fiyat ve kanal listesi denetleyiciden gelir. Müşteri sayısı, yorum,
      puan, "bilmem kaç satıcı" gibi sahip olmadığımız hiçbir rakam yok.

    "HİÇ FAZLA SATMAZSIN" DENMEZ: iki kanalda neredeyse aynı anda satış
      olursa ya da kanal satışı geç bildirirse fazla satış yine olabilir
      (panel görüntüsünde de kırmızı bir satır var). Vaat şu: stok her
      kanalda birlikte düşer; ters giden bir şey olursa hemen görürsün.

    KARGO BİLDİRİMİ kanal kanal: `cargoPush` adaptörün yeteneğinden gelir
      (SiteController::presentChannel); burada elle liste tutulmaz.
--}}
@extends('site.layout')

@php
    $available = array_values(array_filter($channels, fn ($c) => $c['available']));
    $upcoming = array_values(array_filter($channels, fn ($c) => ! $c['available']));

    // "A, B ve C" — Türkçe liste birleştirme.
    $join = function (array $names): string {
        if (count($names) <= 1) {
            return implode('', $names);
        }

        return implode(', ', array_slice($names, 0, -1)).' ve '.$names[count($names) - 1];
    };
    $availableNames = $join(array_column($available, 'name'));
    $upcomingNames = $join(array_column($upcoming, 'name'));

    $cargoYes = $join(array_column(array_filter($available, fn ($c) => $c['cargoPush']), 'name'));
    $cargoNo = $join(array_column(array_filter($available, fn ($c) => ! $c['cargoPush']), 'name'));

    $freePlan = collect($plans)->first(fn ($p) => (float) $p['priceMonthly'] === 0.0);
    $limit = fn (?int $v, string $unit): string => $v === null ? "sınırsız {$unit}" : number_format($v, 0, ',', '.')." {$unit}";

    /*
     * Sorun → çözüm blokları. Her blok TEK bir gerçek işi anlatır ve
     * yanında o işin yapıldığı panel ekranı durur.
     */
    $blocks = [
        [
            'id' => 'stok', 'icon' => 'stock', 'kicker' => 'Stok',
            'problem' => 'Ürün bir kanalda satıldı, öbür kanalda hâlâ stokta görünüyor.',
            'title' => 'Tek stok, bütün kanallar.',
            'body' => 'Ürünün adedi tek bir yerde tutulur. Satış hangi kanaldan gelirse gelsin adet oradan düşer ve yeni sayı bağlı bütün kanallara gönderilir.',
            'points' => ['Bir kanalda satılan ürünün stoğu diğerlerinde de düşer', 'Depodaki, ayrılmış ve satılabilir adet ayrı ayrı görünür', 'Ters giden bir şey olursa ürün kırmızıyla listenin en üstüne çıkar'],
            'shot' => ['src' => 'images/site/panel-stok.png', 'alt' => 'Panelde ürünlerin stok adetleri; fazla satılan ürün kırmızıyla işaretli'],
        ],
        [
            'id' => 'siparisler', 'icon' => 'orders', 'kicker' => 'Siparişler',
            'problem' => 'Her sabah sipariş kontrolü için ayrı ayrı panellere giriyorsun.',
            'title' => 'Bütün siparişler tek listede.',
            'body' => 'Hangi kanaldan gelirse gelsin bütün siparişler alt alta düşer. Her satırda siparişin hangi kanaldan geldiği ve durumu yazar.',
            'points' => ['Kargo bekleyen siparişler tek tıkla süzülür', 'Sipariş numarası ya da stok kodu (SKU) ile arama', 'Stoğu yetmeyen ya da tanınmayan ürün içeren sipariş ayrıca işaretlenir'],
            'shot' => ['src' => 'images/site/panel-siparisler.png', 'alt' => 'Panelde Trendyol, Shopify ve WooCommerce siparişlerinin tek listede görünümü'],
        ],
        [
            'id' => 'fiyat', 'icon' => 'price', 'kicker' => 'Fiyat',
            'problem' => 'Komisyonu karşılamak için her kanalın fiyatını elle hesaplıyorsun.',
            'title' => 'Kanal başına fiyat kuralı ve zarar koruması.',
            'body' => 'Her kanal için bir fark tanımlarsın; örneğin pazaryerine +%15 ve ,90 ile biten fiyat. Ürünün fiyatını değiştirdiğinde kanallara giden fiyat kendiliğinden hesaplanır.',
            'points' => ['Yüzde ve sabit tutar farkı; tam sayıya, ,90 ya da ,99 ile biten fiyata yuvarlama', 'Zarar koruması: alış maliyetinin altına düşen fiyat kanala gönderilmez', 'İstersen bir ürüne kanal için elle fiyat da girebilirsin'],
            'shot' => ['src' => 'images/site/panel-fiyat-kurali.png', 'alt' => 'Kanal fiyat kuralı ekranı: yüzde fark, ,90 yuvarlama, zarar koruması ve örnek hesap'],
        ],
        [
            'id' => 'kargo', 'icon' => 'truck', 'kicker' => 'Kargo',
            'problem' => 'Takip numarasını her kanalın paneline ayrı ayrı yazıyorsun.',
            'title' => 'Takip numarasını bir kez gir.',
            'body' => $cargoYes
                ? "Kargo firmasını ve takip numarasını siparişin içine yazarsın. {$cargoYes} siparişlerinde numara kanala iletilir; müşterin takibi orada görür."
                : 'Kargo firmasını ve takip numarasını siparişin içine yazarsın; sipariş panelde kargoda görünür.',
            'points' => array_values(array_filter([
                $cargoYes ? "{$cargoYes} için kanalın panelini açman gerekmez" : null,
                $cargoNo ? "{$cargoNo} siparişlerinde numarayı şimdilik kanalın kendi panelinde girersin" : null,
            ])),
            'shot' => ['src' => 'images/site/panel-kargo.png', 'alt' => 'Sipariş ayrıntısında kargo firması ve takip numarası formu', 'dy' => 30],
        ],
    ];

    // Ekranı ana sayfada gösterilmeyen özellikler: kısa kartlar.
    $extras = [
        ['calendar', 'Süreli kampanyalar', 'Seçtiğin ürünlere, seçtiğin kanallarda tarih aralıklı indirim. Kampanya bitince fiyat kendiliğinden eski hâline döner.'],
        ['upload', 'Toplu ürün aktarımı', 'Ürünlerini bağlı kanalından ya da CSV dosyasıyla içe aktar; yüzlerce ürünü tek tek girmezsin.'],
        ['image', 'Görsel yönetimi', 'Kanaldan gelen görseller ürünle birlikte durur. Hangi görselin hangi kanala gideceğini sen seçersin.'],
        ['scale', 'Sormadan üzerine yazmayız', 'Fiyatı bir kanalın kendi panelinde değiştirdiysen farkı gösteririz; hangisinin geçerli olacağına sen karar verirsin.'],
        ['dashboard', 'Rapor değil, iş listesi', 'Paneli açınca önce yapman gerekenleri görürsün: kargolanmayı bekleyen sipariş, fazla satılan ürün, kanalın reddettiği ürün.'],
        ['phone', 'Telefonda da çalışır', 'Uygulama indirmen gerekmez; panel telefonunun tarayıcısında açılır.'],
    ];

    /*
     * SSS cevapları YALNIZ doğrulanmış şeyleri söyler. "Kart bilgisi
     * gerekmez" kayıt formuna bakılarak yazıldı (ödeme sormuyor) ve yalnız
     * ücretsiz paket varsa gösterilir. Aylık abonelik ve "istediğin zaman
     * iptal" kullanım koşullarındaki maddelerle aynı.
     */
    $faqs = [
        ['q' => '34Pazar ne işe yarar?', 'a' => 'Birden fazla kanalda satış yapıyorsan stoğunu, ürünlerini, fiyatlarını ve siparişlerini tek panelden yönetmeni sağlar. En önemlisi: bir kanalda satılan ürünün stoğu diğer kanallarda da düşer.'],
    ];
    if ($availableNames) {
        $faqs[] = ['q' => 'Hangi kanallarla çalışıyor?', 'a' => "Şu an {$availableNames} bağlanabiliyor.".($upcomingNames ? " {$upcomingNames} için çalışıyoruz; hazır olduğunda bağlanabilir hâle gelecek." : '')];
    }
    if ($freePlan) {
        $faqs[] = ['q' => 'Kart bilgisi vermeden başlayabilir miyim?', 'a' => "Evet. {$freePlan['name']} paketle kart bilgisi girmeden başlarsın. Bu pakette {$limit($freePlan['productLimit'], 'ürün')} ve {$limit($freePlan['channelLimit'], 'kanal')} hakkın var."];
    }
    $faqs[] = ['q' => 'Yıllık ödeme zorunlu mu?', 'a' => 'Hayır. Ücretli paketler aylık abonelikle çalışır. Aboneliğini istediğin zaman iptal edebilirsin; iptal, ödemesi yapılmış dönemin sonunda geçerli olur.'];
    $faqs[] = ['q' => 'Bir kanalda satış olunca diğerlerinde ne olur?', 'a' => 'Stok tek bir yerde tutulur. Ürün hangi kanalda satılırsa satılsın adet oradan düşer ve yeni adet bağlı bütün kanallara gönderilir.'];
    $faqs[] = ['q' => 'Yine de fazla satış olabilir mi?', 'a' => 'Nadiren olabilir: iki kanalda neredeyse aynı anda satış olursa ya da bir kanal satışı geç bildirirse. Böyle bir durumda ürün panelinde kırmızıyla en üstte görünür; stoğu düzeltir ya da müşteriye haber verirsin.'];
    $faqs[] = ['q' => 'Her kanalda farklı fiyat kullanabilir miyim?', 'a' => 'Evet. Kanal başına yüzde ya da tutar farkı ve yuvarlama tanımlayabilir, istersen bir ürüne kanal için elle fiyat girebilirsin. Alış maliyetini girdiysen maliyetin altına düşen fiyat kanala gönderilmez.'];
    $faqs[] = ['q' => 'Kanalın kendi panelinde fiyat değiştirirsem ne olur?', 'a' => 'Değişikliğini sessizce ezmeyiz. Farkı gördüğümüzde sana gösteririz; hangi fiyatın geçerli olacağına sen karar verirsin.'];
    $faqs[] = ['q' => 'Teknik bilgi gerekiyor mu?', 'a' => 'Kod yazman gerekmez. Bir kanalı bağlamak için o kanalın satıcı panelinden API anahtarı gibi birkaç bilgiyi alıp bağlantı formuna yapıştırırsın; form hangi bilgiyi istediğini tek tek gösterir.'];
    $faqs[] = ['q' => 'Muhasebe ya da e-fatura var mı?', 'a' => 'Henüz yok. Muhasebe modülü yakında geliyor. Şu an 34Pazar stok, ürün, fiyat, sipariş ve kargo takibine odaklanıyor.'];

    $description = $availableNames
        ? "{$availableNames} mağazalarını tek panelden yönet. Bir kanalda satılan ürünün stoğu diğerlerinde de düşer; siparişler tek listede."
        : 'Bütün satış kanallarını tek panelden yönet. Bir kanalda satılan ürünün stoğu diğerlerinde de düşer; siparişler tek listede.';
@endphp

@section('title', 'Pazaryeri entegrasyonu: stok, sipariş ve kargo tek panelde')
@section('description', \Illuminate\Support\Str::limit($description, 157))

@push('jsonld')
    @include('site.partials.seo.software', ['plans' => $plans])
    @include('site.partials.seo.faq', ['faqs' => $faqs])
@endpush

@section('content')
    {{-- ═══════════════════════════════════════ GİRİŞ --}}
    <section aria-labelledby="hero-baslik" class="bg-gradient-to-b from-soft to-white">
        <div class="wrap grid items-center gap-12 pt-12 pb-16 lg:grid-cols-12 lg:gap-10 lg:pt-20 lg:pb-24">
            <div class="lg:col-span-5">
                <p class="eyebrow">Pazaryeri ve e-ticaret entegrasyonu</p>
                <h1 id="hero-baslik" class="h-hero mt-3">Tüm mağazalarını tek panelden yönet: stok, sipariş, fiyat.</h1>
                <p class="lead mt-5 max-w-[46ch]">
                    @if ($availableNames)
                        {{ $availableNames }} mağazalarını 34Pazar'a bağla. Bir kanalda satılan ürünün stoğu diğerlerinden de düşer, siparişler tek listede toplanır.
                    @else
                        Satış kanallarını 34Pazar'a bağla. Bir kanalda satılan ürünün stoğu diğerlerinden de düşer, siparişler tek listede toplanır.
                    @endif
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    @if ($isLoggedIn)
                        <a href="{{ url('/panel') }}" class="btn btn-primary btn-lg">Panele git</a>
                    @else
                        <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Ücretsiz dene</a>
                    @endif
                    <a href="#nasil-calisir" class="btn btn-secondary btn-lg">Nasıl çalışır</a>
                </div>
                <ul class="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-sm muted">
                    @if ($freePlan)
                        <li class="flex items-center gap-1.5"><span class="text-ok" aria-hidden="true">✓</span> Kart bilgisi gerekmez</li>
                    @endif
                    <li class="flex items-center gap-1.5"><span class="text-ok" aria-hidden="true">✓</span> Aylık ödeme, yıllık taahhüt yok</li>
                    <li class="flex items-center gap-1.5"><span class="text-ok" aria-hidden="true">✓</span> Kod yazmadan kurulum</li>
                </ul>
            </div>
            <div class="lg:col-span-7">
                @include('site.partials.shot', [
                    'src' => 'images/site/panel-ana-sayfa.png',
                    'alt' => '34Pazar paneli: günün siparişleri, Yapman gerekenler listesi ve bağlı kanallar',
                    'eager' => true,
                ])
            </div>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ KANALLAR --}}
    @if (count($channels))
        <section id="kanallar" aria-labelledby="kanal-baslik" class="border-y border-line">
            <div class="wrap py-12 lg:py-14">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h2 id="kanal-baslik" class="heading-3">Bağlanabilen kanallar</h2>
                        <p class="mt-1 text-[0.9875rem] muted">"Aktif" olanları bugün bağlayabilirsin; "Yakında" olanlar üzerinde çalışıyoruz.</p>
                    </div>
                    <a href="{{ route('site.channels') }}" class="link text-[0.9875rem]">Tüm entegrasyonlar</a>
                </div>
                <div class="mt-6">
                    @include('site.partials.channel-list', ['channels' => $channels, 'columns' => 'grid-cols-2 md:grid-cols-3 lg:grid-cols-5'])
                </div>
            </div>
        </section>
    @endif

    {{-- ═══════════════════════════════════════ NASIL ÇALIŞIR --}}
    <section id="nasil-calisir" aria-labelledby="nasil-baslik" class="section">
        <div class="wrap">
            <div class="section-head section-head--center">
                <p class="eyebrow">Nasıl çalışır</p>
                <h2 id="nasil-baslik" class="heading-2">Üç adımda kurulur.</h2>
                <p class="lead">Kod yazmazsın. Kanalın satıcı panelinden birkaç bilgiyi alıp bağlantı formuna yapıştırman yeterli.</p>
            </div>
            <ol class="mt-12 grid gap-5 md:grid-cols-3">
                @foreach ([
                    ['Kanallarını bağla', 'Satış yaptığın mağazaları ekle. Bağlantı formu, kanalın satıcı panelinden hangi bilgiyi alacağını tek tek gösterir.'],
                    ['Ürünlerini eşle', 'Ürünlerini kanaldan ya da CSV dosyasıyla içe aktar. Ürünler stok koduyla (SKU) eşleşir; aynı ürün iki kez açılmaz.'],
                    ['Tek yerden yönet', 'Siparişler tek listeye düşer, stok her satışta bütün kanallarda birlikte güncellenir, fiyatı tek yerden değiştirirsin.'],
                ] as [$title, $body])
                    <li class="card">
                        <span class="step-num" aria-hidden="true">{{ $loop->iteration }}</span>
                        <h3 class="heading-3 mt-5"><span class="sr-only">{{ $loop->iteration }}. adım: </span>{{ $title }}</h3>
                        <p class="mt-2 muted">{{ $body }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ SORUN → ÇÖZÜM --}}
    <section aria-labelledby="ozellik-baslik" class="section bg-soft">
        <div class="wrap">
            <div class="section-head section-head--center">
                <p class="eyebrow">Özellikler</p>
                <h2 id="ozellik-baslik" class="heading-2">Her gün uğraştığın işler, tek panelde.</h2>
                <p class="lead">Aşağıdaki görseller çizim değil, panelin kendisi. Kayıt olduğunda karşına bunlar çıkar.</p>
            </div>

            <div class="mt-14 space-y-16 lg:mt-20 lg:space-y-24">
                @foreach ($blocks as $i => $b)
                    @php $flip = $i % 2 === 1; @endphp
                    <article id="{{ $b['id'] }}" class="grid items-center gap-8 lg:grid-cols-12 lg:gap-14" aria-labelledby="{{ $b['id'] }}-baslik">
                        <div class="lg:col-span-5 {{ $flip ? 'lg:order-2' : '' }}">
                            <span class="icon-box">@include('site.partials.icon', ['name' => $b['icon']])</span>
                            <p class="eyebrow mt-4 block">{{ $b['kicker'] }}</p>
                            <p class="problem mt-3"><b>Sorun:</b> <span>{{ $b['problem'] }}</span></p>
                            <h3 id="{{ $b['id'] }}-baslik" class="heading-2 mt-5 text-[clamp(1.5rem,1.2rem+1vw,2rem)]">{{ $b['title'] }}</h3>
                            <p class="mt-3 muted">{{ $b['body'] }}</p>
                            @if ($b['points'])
                                <ul class="check-list mt-5">
                                    @foreach ($b['points'] as $point)
                                        <li>{{ $point }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        <div class="lg:col-span-7 {{ $flip ? 'lg:order-1' : '' }}">
                            @include('site.partials.shot', $b['shot'])
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ DİĞER ÖZELLİKLER --}}
    <section aria-labelledby="diger-baslik" class="section">
        <div class="wrap">
            <div class="flex flex-wrap items-end justify-between gap-6">
                <div class="section-head">
                    <h2 id="diger-baslik" class="heading-2">Bunlar da hazır.</h2>
                    <p class="lead">Bütün paketlerde aynı özellikler var; paketler yalnız ürün ve kanal sayısıyla ayrılır.</p>
                </div>
                <a href="{{ route('site.features') }}" class="btn btn-secondary">Bütün özellikler</a>
            </div>
            <ul class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($extras as [$icon, $title, $body])
                    <li class="card">
                        <span class="icon-box">@include('site.partials.icon', ['name' => $icon])</span>
                        <h3 class="heading-3 mt-4">{{ $title }}</h3>
                        <p class="mt-2 muted">{{ $body }}</p>
                    </li>
                @endforeach
                <li class="card border-dashed bg-soft">
                    <h3 class="heading-3 muted">Muhasebe modülü</h3>
                    <p class="mt-2"><span class="badge badge--soon">Yakında</span></p>
                </li>
            </ul>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ FİYAT ÖZETİ --}}
    @if (count($plans))
        <section id="fiyatlar" aria-labelledby="fiyat-baslik" class="section bg-soft">
            <div class="wrap">
                <div class="section-head section-head--center">
                    <p class="eyebrow">Fiyatlar</p>
                    <h2 id="fiyat-baslik" class="heading-2">Ürün ve kanal sayına göre aylık paketler.</h2>
                    <p class="lead">Yıllık peşin ödeme yok. Büyüdükçe paketini yükseltirsin, istediğin zaman iptal edersin.</p>
                </div>
                <div class="mt-12">
                    @include('site.partials.plans', ['plans' => $plans, 'isLoggedIn' => $isLoggedIn])
                </div>
                <p class="mt-8 text-center">
                    <a href="{{ route('site.pricing') }}" class="link">Paketleri karşılaştır</a>
                </p>
            </div>
        </section>
    @endif

    {{-- ═══════════════════════════════════════ SSS --}}
    <section id="sss" aria-labelledby="sss-baslik" class="section">
        <div class="wrap grid gap-10 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <p class="eyebrow">SSS</p>
                <h2 id="sss-baslik" class="heading-2 mt-2">Sık sorulanlar</h2>
                <p class="mt-4 muted">
                    Cevabını bulamadın mı? <a href="{{ route('site.contact') }}" class="link">Bize yaz.</a>
                </p>
            </div>
            <div class="lg:col-span-8">
                @include('site.partials.faq', ['faqs' => $faqs, 'openFirst' => true])
            </div>
        </div>
    </section>

    @include('site.partials.cta')
@endsection
