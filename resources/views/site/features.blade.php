{{--
    /ozellikler — satıcı diliyle, her özellik "sorun → ne yapar" düzeninde.

    YALNIZ KODDA KARŞILIĞI OLAN ÖZELLİK YAZILIR (9 Ekim 2026'da
    doğrulandı):
      stok          → envanter defteri, kanallara stok gönderimi
      siparişler    → Orders ekranı, süzgeçler, SKU araması
      kanal fiyatı  → ProductChannelController::updatePrice
      fiyat kuralı  → ChannelPriceRule (yüzde + tutar + none/whole/x90/x99, daima yukarı)
      zarar koruması→ min_margin_percent; maliyet altı fiyat gönderilmez
      kampanyalar   → PriceCampaign + campaigns:tick (bitince fiyat döner)
      kargo         → SupportsFulfillment uygulayan adaptörler (cargoPush)
      toplu aktarım → ProductImportController (CSV + kanaldan)
      görseller     → kanaldan içe aktarılan görsel + kanal başına seçim
    Panelden görsel YÜKLEME henüz yok (SyncImportedImages notu) — o yüzden
    "görsel yükle" denmez.

    Ekranı olan özellik gerçek panel görüntüsüyle durur; olmayana uydurma
    görsel çizilmez.
--}}
@extends('site.layout')

@php
    $available = array_values(array_filter($channels, fn ($c) => $c['available']));

    $sections = [
        [
            'id' => 'stok', 'icon' => 'stock', 'kicker' => 'Tek stok · fazla satış koruması', 'toc' => 'Tek stok',
            'problem' => 'Son ürün bir pazaryerinde satılıyor; kendi siten hâlâ "stokta 1" gösteriyor ve orada da satılıyor.',
            'title' => 'Tek stok, bütün kanallar.',
            'body' => 'Her ürünün adedi tek bir yerde tutulur. Bir kanalda satış olunca adet buradan düşer ve yeni sayı bağlı bütün kanallara gönderilir. Adedi panelde değiştirdiğinde de kanallar güncellenir.',
            'points' => [
                'Depodaki, ayrılmış ve satılabilir adet ayrı ayrı görünür',
                'Fazla satılan ürün kırmızıyla listenin en üstüne çıkar',
                'Adedi eksiye düşen ürün için kanallara sıfır gönderilir; ürün orada satışa kapanır',
            ],
            'shot' => ['src' => 'images/site/panel-stok.png', 'alt' => 'Stok ekranı: ürünlerin depodaki, ayrılmış ve satılabilir adetleri; fazla satılan ürün kırmızıyla işaretli'],
        ],
        [
            'id' => 'siparisler', 'icon' => 'orders', 'kicker' => 'Sipariş toplama', 'toc' => 'Siparişler',
            'problem' => 'Her kanalın siparişine ayrı panelden bakıyorsun; biri gözden kaçınca kargo gecikiyor.',
            'title' => 'Bütün kanalların siparişi tek listede.',
            'body' => 'Siparişler geldiği kanaldan bağımsız olarak tek listeye düşer. Her satırda kanalın adı, siparişin durumu ve stoğun düşüp düşmediği yazar.',
            'points' => [
                'Kargo bekleyen, fazla satış içeren ve tanınmayan ürün içeren siparişler tek tıkla süzülür',
                'Sipariş numarası ya da stok kodu (SKU) ile arama',
                'Siparişteki ürün kataloğunda yoksa işaretlenir; stok kodunu eşleyince stok düşer',
            ],
            'shot' => ['src' => 'images/site/panel-siparisler.png', 'alt' => 'Sipariş listesi: Trendyol, Shopify ve WooCommerce siparişleri durum ve stok bilgisiyle'],
        ],
        [
            'id' => 'fiyat-kurallari', 'icon' => 'price', 'kicker' => 'Kanal başına fiyat · fiyat kuralları', 'toc' => 'Fiyat kuralları',
            'problem' => 'Pazaryerinin komisyonunu karşılamak için her ürünün fiyatını kanal kanal hesaplayıp elle yazıyorsun.',
            'title' => 'Her kanala kendi fiyatı, kendiliğinden.',
            'body' => 'Kanal için bir kural tanımlarsın: örneğin Trendyol\'a +%15 ve ,90 ile biten fiyat. Ürünün fiyatını değiştirdiğinde kanala giden fiyat bu kurala göre hesaplanır. Ekrandaki örnek hesap, kaydetmeden önce sonucu gösterir.',
            'points' => [
                'Yüzde fark ve sabit tutar (indirim için eksi yazılabilir)',
                'Yuvarlama: tam sayıya, ,90 ya da ,99 ile biten fiyata; her zaman yukarı yuvarlar, kârından kırpmaz',
                'Bir ürüne bir kanal için elle fiyat girersen o fiyat kuraldan bağımsız, olduğu gibi gider',
            ],
            'shot' => ['src' => 'images/site/panel-fiyat-kurali.png', 'alt' => 'Kanal fiyat kuralı ekranı: yüzde fark, ,90 yuvarlama, zarar koruması ve örnek hesap'],
        ],
        [
            'id' => 'zarar-korumasi', 'icon' => 'shield', 'kicker' => 'Zarar koruması', 'toc' => 'Zarar koruması',
            'problem' => 'Bir indirim ya da yanlış girilen fiyat yüzünden ürün maliyetinin altında satılıyor.',
            'title' => 'Maliyetin altındaki fiyat kanala gitmez.',
            'body' => 'Ürüne alış maliyetini girersin, kanal için en az kâr oranını seçersin. Hesaplanan fiyat bu tabanın altına düşerse o fiyat kanala gönderilmez ve nedeni ürünün kanallar sayfasında yazar.',
            'points' => [
                'Fiyat kuralında, kampanyada ve ilan açarken aynı denetim',
                'Maliyeti girilmemiş ürün denetlenmez; hangi ürünlerin korunduğunu sen belirlersin',
            ],
            'shot' => null,
        ],
        [
            'id' => 'kampanyalar', 'icon' => 'calendar', 'kicker' => 'Süreli kampanyalar', 'toc' => 'Kampanyalar',
            'problem' => 'İndirimi başlatmayı da, bitince fiyatları eski hâline getirmeyi de elle yapıyorsun.',
            'title' => 'Tarihini seç, kampanya kendiliğinden başlasın ve bitsin.',
            'body' => 'Kampanyaya ürünleri ve kanalları seçer, yüzde ya da tutar indirim ve tarih aralığı girersin. Başlangıçta indirimli fiyat kanallara gider; bitişte fiyat kendiliğinden normale döner.',
            'points' => [
                'Normal fiyat istersen üstü çizili gösterilir',
                'Aynı ürün iki kampanyadaysa en düşük fiyat geçerli olur',
                'Zarar koruması kampanyada da geçerli: maliyetin altına düşen fiyat gönderilmez',
            ],
            'shot' => ['src' => 'images/site/panel-kampanya.png', 'alt' => 'Kampanya oluşturma ekranı: yüzde indirim, başlangıç ve bitiş tarihi, kanal ve ürün seçimi'],
        ],
        [
            'id' => 'kargo', 'icon' => 'truck', 'kicker' => 'Kargo takip numarası', 'toc' => 'Kargo takibi',
            'problem' => 'Takip numarasını her kanalın paneline ayrı ayrı yazıyorsun.',
            'title' => 'Takip numarasını bir kez gir.',
            'body' => 'Siparişin içinde kargo firmasını ve takip numarasını yazarsın. Kanal destekliyorsa numara oraya iletilir ve sipariş kanalda da kargolandı olarak işaretlenir. Hangi kanalda nasıl çalıştığı aşağıdaki tabloda.',
            'points' => [],
            'shot' => ['src' => 'images/site/panel-kargo.png', 'alt' => 'Sipariş ayrıntısı: kargo firması ve takip numarası formu', 'dy' => 30],
        ],
    ];

    // Ekranı sayfada gösterilmeyen özellikler: somut cümle, uydurma görsel yok.
    $plain = [
        ['toplu-aktarim', 'upload', 'Toplu ürün aktarımı', 'Ürünlerin zaten bir kanalda duruyorsa oradan içe aktarırsın; yoksa CSV dosyası yüklersin. Ürünler stok koduyla (SKU) eşleşir, aynı ürün iki kez açılmaz. Büyük dosyalar arka planda işlenir; sonucu (eklenen, güncellenen, atlanan) panelde görürsün.'],
        ['gorseller', 'image', 'Görsel yönetimi', 'Kanaldan içe aktarılan görseller ürünle birlikte durur. Hangi görselin hangi kanala gideceğini ürün sayfasından seçersin. Kanalın kabul ettiği görsel sayısı aşılırsa hangilerinin dışarıda kaldığı yazar; görsel sessizce düşmez.'],
        ['urun-yayinlama', 'link', 'Ürün yayınlama', 'Ürün bilgisini bir kere girersin, hangi kanalda satılacağını seçer ve buradan gönderirsin. Kanal ürünü reddederse sebebi panelde yazar; düzeltip tekrar gönderirsin.'],
        ['fiyat-farki', 'scale', 'Sormadan üzerine yazmayız', 'Fiyatı bir kanalın kendi panelinde değiştirdiysen farkı gösteririz; hangi fiyatın geçerli olacağına sen karar verirsin.'],
        ['ana-sayfa', 'dashboard', 'Rapor değil, iş listesi', 'Paneli açtığında önce yapman gerekenleri görürsün: kargolanmayı bekleyen siparişler, fazla satılan ürün, kanalın reddettiği ürün. Her satır seni ilgili ekrana götürür.'],
        ['mobil', 'phone', 'Uygulama indirmeden telefonda', 'Panel telefonunun tarayıcısında açılır. Siparişe bakmak ya da takip numarası girmek için bilgisayar başına geçmen gerekmez.'],
    ];

    $toc = array_merge(
        array_map(fn ($s) => [$s['id'], $s['toc']], $sections),
        array_map(fn ($p) => [$p[0], $p[2]], $plain),
    );
@endphp

@section('title', 'Özellikler: merkezi stok, sipariş, kargo ve fiyat')
@section('description', 'Stok tek yerde, siparişler tek listede, kargo numarası bir kez girilir. 34Pazar panelinin her ekranı ve her kanalda neyin çalıştığı.')

@push('jsonld')
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Özellikler', 'url' => route('site.features')],
    ]])
@endpush

@section('content')
    {{-- ═══════════════════════════════════════ GİRİŞ --}}
    <section class="border-b border-line bg-soft" aria-labelledby="ozellik-h1">
        <div class="wrap grid gap-10 pt-12 pb-14 lg:grid-cols-12 lg:pt-16 lg:pb-16">
            <div class="lg:col-span-7">
                <p class="eyebrow">Özellikler</p>
                <h1 id="ozellik-h1" class="h-page mt-3">Satış her yerde, iş tek panelde.</h1>
                <p class="lead mt-5 max-w-[52ch]">
                    Aşağıda panelin gerçek ekranları ve her birinin hangi derdini çözdüğü var. Neyin çalıştığını da, neyin henüz çalışmadığını da açıkça yazıyoruz.
                </p>
                <div class="mt-7 flex flex-wrap gap-3">
                    @if ($isLoggedIn)
                        <a href="{{ url('/panel') }}" class="btn btn-primary">Panele git</a>
                    @else
                        <a href="{{ route('register') }}" class="btn btn-primary">Ücretsiz dene</a>
                    @endif
                    <a href="{{ route('site.pricing') }}" class="btn btn-secondary">Fiyatları gör</a>
                </div>
            </div>
            {{-- İçindekiler: uzun sayfada istediği bölüme atlasın. --}}
            <nav aria-labelledby="icindekiler" class="lg:col-span-5">
                <div class="card">
                    <h2 id="icindekiler" class="text-sm font-semibold muted">Bu sayfada</h2>
                    <ol class="mt-3 gap-x-6 sm:columns-2">
                        @foreach ($toc as [$id, $label])
                            <li class="break-inside-avoid">
                                <a href="#{{ $id }}" class="flex min-h-10 items-center text-[0.9375rem] font-medium hover:text-brand-700 hover:underline">{{ $label }}</a>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </nav>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ ANA ÖZELLİKLER --}}
    <div class="wrap divide-y divide-line">
        @foreach ($sections as $i => $s)
            @php $flip = $i % 2 === 1; @endphp
            <section id="{{ $s['id'] }}" aria-labelledby="{{ $s['id'] }}-baslik" class="grid items-center gap-8 py-14 lg:grid-cols-12 lg:gap-14 lg:py-20">
                <div class="{{ $s['shot'] ? 'lg:col-span-5' : 'lg:col-span-7' }} {{ $flip && $s['shot'] ? 'lg:order-2' : '' }}">
                    <span class="icon-box">@include('site.partials.icon', ['name' => $s['icon']])</span>
                    <p class="eyebrow mt-4 block">{{ $s['kicker'] }}</p>
                    <h2 id="{{ $s['id'] }}-baslik" class="heading-2 mt-2">{{ $s['title'] }}</h2>
                    <p class="problem mt-4"><b>Sorun:</b> <span>{{ $s['problem'] }}</span></p>
                    <p class="mt-4 muted">{{ $s['body'] }}</p>

                    @if ($s['points'])
                        <ul class="check-list mt-5">
                            @foreach ($s['points'] as $point)
                                <li>{{ $point }}</li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- Kargo: kanal kanal dürüst tablo; yetenek adaptörden (cargoPush). --}}
                    @if ($s['id'] === 'kargo' && count($available))
                        <div class="mt-6 overflow-hidden rounded-xl border border-line">
                            <table class="w-full text-left text-[0.9375rem]">
                                <caption class="sr-only">Takip numarasının kanala gönderilip gönderilmediği, kanal kanal</caption>
                                <thead class="bg-soft">
                                    <tr>
                                        <th scope="col" class="px-4 py-3 font-semibold">Kanal</th>
                                        <th scope="col" class="px-4 py-3 font-semibold">Takip numarası</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($available as $ch)
                                        <tr class="border-t border-line">
                                            <th scope="row" class="px-4 py-3 font-semibold">{{ $ch['name'] }}</th>
                                            <td class="px-4 py-3">
                                                @if ($ch['cargoPush'])
                                                    <span class="badge badge--active">Kanala iletilir</span>
                                                @else
                                                    <span class="muted">Şimdilik kanalın kendi panelinde girilir</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                @if ($s['shot'])
                    <div class="lg:col-span-7 {{ $flip ? 'lg:order-1' : '' }}">
                        @include('site.partials.shot', $s['shot'])
                    </div>
                @else
                    {{-- Ekranı ayrı olmayan özellik: örnek yerine kısa bir "nasıl" kutusu. --}}
                    <div class="lg:col-span-5">
                        <div class="card bg-soft">
                            <h3 class="heading-3">Nasıl ayarlanır?</h3>
                            <ol class="mt-3 list-decimal space-y-2 pl-5 muted">
                                <li>Ürün düzenleme ekranında varyantın alış maliyetini gir.</li>
                                <li>Kanallar ekranında kanalın "Fiyat kuralı" sayfasını aç.</li>
                                <li>"Zarar koruması"nı işaretle ve en az kâr oranını yaz.</li>
                            </ol>
                        </div>
                    </div>
                @endif
            </section>
        @endforeach
    </div>

    {{-- ═══════════════════════════════════════ DİĞER ÖZELLİKLER --}}
    <section class="section bg-soft" aria-labelledby="diger-h2">
        <div class="wrap">
            <div class="grid gap-12 lg:grid-cols-12">
                <div class="lg:col-span-8">
                    <h2 id="diger-h2" class="heading-2">Ve gerisi.</h2>
                    <div class="mt-8 grid gap-5 sm:grid-cols-2">
                        @foreach ($plain as [$id, $icon, $title, $body])
                            <article id="{{ $id }}" class="card" aria-labelledby="{{ $id }}-baslik">
                                <span class="icon-box">@include('site.partials.icon', ['name' => $icon])</span>
                                <h3 id="{{ $id }}-baslik" class="heading-3 mt-4">{{ $title }}</h3>
                                <p class="mt-2 muted">{{ $body }}</p>
                            </article>
                        @endforeach
                        <div class="card border-dashed">
                            <h3 class="heading-3 muted">Muhasebe modülü</h3>
                            <p class="mt-2"><span class="badge badge--soon">Yakında</span></p>
                        </div>
                    </div>
                </div>
                <div class="flex items-start justify-center lg:col-span-4 lg:pt-16">
                    <figure class="phone">
                        <img src="{{ asset('images/site/panel-mobil.png') }}" alt="34Pazar paneli telefon tarayıcısında" width="390" height="844" loading="lazy" decoding="async">
                    </figure>
                </div>
            </div>
        </div>
    </section>

    @include('site.partials.cta')
@endsection
