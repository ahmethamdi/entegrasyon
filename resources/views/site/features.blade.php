{{--
    /ozellikler — her özellik ya gerçek panel ekranıyla ya da somut bir
    açıklamayla durur. Ekranı olmayan özelliğe uydurma görsel çizilmez.

    Kargo notu KANAL KANAL ve dürüst: takip numarasını yalnız bazı
    kanallara geri gönderebiliyoruz. Liste home ve channel görünümlerindeki
    `$cargoPush` ile AYNI olmalı.
--}}
@extends('site.layout')

@php
    $available = array_values(array_filter($channels, fn ($c) => $c['available']));
    $cargoPush = ['shopify', 'woocommerce'];

    $sections = [
        [
            'id' => 'stok', 'kicker' => 'Merkezi stok',
            'title' => 'Tek stok, bütün kanallar.',
            'body' => 'Her ürünün adedi tek bir yerde tutulur. Bir kanalda satış olunca adet buradan düşer ve yeni sayı bağlı bütün kanallara gönderilir. Elle düzeltme de aynı yoldan gider: adedi panelde değiştirirsin, kanallar güncellenir.',
            'points' => [
                'Depodaki, ayrılmış ve satılabilir adet ayrı ayrı görünür',
                'Fazla satılan ürün kırmızıyla listenin en üstüne çıkar',
                'Adedi eksiye düşen ürün için kanallara sıfır gönderilir; ürün orada satışa kapanır',
            ],
            'shot' => ['src' => 'images/site/panel-stok.png', 'alt' => 'Stok ekranı: ürünlerin depodaki, ayrılmış ve satılabilir adetleri; fazla satılan ürün kırmızıyla işaretli'],
        ],
        [
            'id' => 'siparisler', 'kicker' => 'Siparişler',
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
            'id' => 'kargo', 'kicker' => 'Kargo',
            'title' => 'Takip numarasını bir kez gir.',
            'body' => 'Siparişin içinde kargo firmasını ve takip numarasını yazarsın. Kanal destekliyorsa numara oraya iletilir ve sipariş kanalda da kargolandı olarak işaretlenir.',
            'points' => [],
            'shot' => ['src' => 'images/site/panel-kargo.png', 'alt' => 'Sipariş ayrıntısı: kargo firması ve takip numarası formu', 'dy' => 30],
        ],
        [
            'id' => 'gunluk', 'kicker' => 'Ana sayfa',
            'title' => 'Rapor değil, iş listesi.',
            'body' => 'Paneli açtığında önce ne yapman gerektiğini görürsün: kargolanmayı bekleyen siparişler, fazla satılan ürün, kanalın reddettiği ürün. Her satır seni ilgili ekrana götürür.',
            'points' => ['Bugünkü sipariş sayısı ve satış tutarı', 'Bağlı kanallar ve bağlantı durumları'],
            'shot' => ['src' => 'images/site/panel-ana-sayfa.png', 'alt' => 'Ana sayfadaki Yapman gerekenler listesi',
                'crop' => ['ratio' => '16 / 7', 'zoom' => '142%', 'x' => '-32.7%', 'y' => '-23%']],
        ],
    ];

    // Ekranı olmayan özellikler: somut cümle, uydurma görsel yok.
    $plain = [
        ['urun', 'Ürün yayınlama', 'Ürünü bir kez hazırla, istediğin kanala gönder.', 'Ürün bilgisini bir kere girersin, hangi kanalda satılacağını seçer ve buradan gönderirsin. Kanal ürünü reddederse sebebi panelde yazar; düzeltip tekrar gönderirsin.'],
        ['fiyat', 'Fiyat', 'Fiyatı bir yerde değiştir.', 'Yeni fiyat bağlı kanallara gider. Fiyatı bir kanalın kendi panelinde değiştirdiysen üzerine sessizce yazmayız: farkı gösteririz, hangisinin geçerli olacağına sen karar verirsin.'],
        ['mobil', 'Telefon', 'Uygulama indirmeden telefonda.', 'Panel telefonunun tarayıcısında açılır. Siparişe bakmak ya da takip numarası girmek için bilgisayar başına geçmen gerekmez.'],
    ];
@endphp

@section('title', 'Özellikler: merkezi stok, sipariş, kargo ve fiyat')
@section('description', 'Stok tek yerde, siparişler tek listede, kargo numarası bir kez girilir. 34Pazar panelinin her ekranı ve her kanalda neyin çalıştığı.')

@section('content')
    {{-- ═══════════════════════════════════════ GİRİŞ --}}
    <section class="wrap pt-14 pb-16 sm:pt-20 lg:pt-28 lg:pb-24" aria-labelledby="ozellik-h1">
        <p class="eyebrow">Özellikler</p>
        <h1 id="ozellik-h1" class="display t-hero mt-6 max-w-[13ch]">Satış her yerde. <span class="accent">İş</span> tek panelde.</h1>
        <div class="mt-10 grid gap-10 lg:mt-14 lg:grid-cols-12">
            <p class="lead max-w-[42ch] lg:col-span-6">
                Aşağıda panelin gerçek ekranları ve her birinin ne yaptığı var. Abartı yok: neyin çalıştığını da, neyin henüz çalışmadığını da yazıyoruz.
            </p>
            {{-- İçindekiler: uzun sayfada istediği bölüme atlasın. --}}
            <nav aria-label="Bu sayfada" class="lg:col-span-5 lg:col-start-8">
                <ol class="grid grid-cols-1 border-t-2 border-ink sm:grid-cols-2 sm:gap-x-8">
                    @foreach (array_merge(array_map(fn ($s) => [$s['id'], $s['kicker']], $sections), array_map(fn ($p) => [$p[0], $p[1]], $plain)) as $n => [$id, $label])
                        <li class="border-b border-line">
                            <a href="#{{ $id }}" class="group flex items-baseline gap-4 py-3 font-semibold">
                                <span class="w-6 text-sm tabular-nums accent">{{ sprintf('%02d', $n + 1) }}</span>
                                <span class="group-hover:underline">{{ $label }}</span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            </nav>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ EKRANLI ÖZELLİKLER --}}
    <div class="wrap space-y-28 pb-28 lg:space-y-44 lg:pb-44">
        @foreach ($sections as $i => $s)
            @php $flip = $i % 2 === 1; @endphp
            <section id="{{ $s['id'] }}" aria-labelledby="{{ $s['id'] }}-baslik" class="grid items-center gap-10 border-t border-line pt-16 lg:grid-cols-12 lg:gap-16 lg:pt-24">
                <div class="lg:col-span-5 {{ $flip ? 'lg:order-2 lg:col-start-8' : '' }}" data-reveal>
                    <p class="eyebrow"><b>{{ sprintf('%02d', $i + 1) }}</b> {{ $s['kicker'] }}</p>
                    <h2 id="{{ $s['id'] }}-baslik" class="display t-scene mt-6">{{ $s['title'] }}</h2>
                    <p class="mt-6 text-lg leading-relaxed muted">{{ $s['body'] }}</p>

                    @if ($s['points'])
                        <ul class="mt-8 border-t border-line">
                            @foreach ($s['points'] as $point)
                                <li class="flex gap-4 border-b border-line py-3.5 font-medium">
                                    <span class="mt-[0.6rem] size-2 shrink-0 bg-brand" aria-hidden="true"></span>{{ $point }}
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- Kargo: kanal kanal dürüst tablo. --}}
                    @if ($s['id'] === 'kargo' && count($available))
                        <table class="mt-8 w-full border-t-2 border-ink text-left text-[0.9875rem]">
                            <caption class="sr-only">Takip numarasının kanala gönderilip gönderilmediği, kanal kanal</caption>
                            <thead>
                                <tr class="border-b border-line">
                                    <th scope="col" class="py-3 font-semibold">Kanal</th>
                                    <th scope="col" class="py-3 font-semibold">Takip numarası</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($available as $ch)
                                    <tr class="border-b border-line">
                                        <th scope="row" class="py-3 pr-4 font-semibold">{{ $ch['name'] }}</th>
                                        <td class="py-3">
                                            @if (in_array($ch['code'], $cargoPush, true))
                                                <span class="tag">Kanala iletilir</span>
                                            @else
                                                <span class="muted">Şimdilik kanalın kendi panelinde girilir</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
                <div class="lg:col-span-7 {{ $flip ? 'lg:order-1 lg:col-start-1 xl:-ml-12' : 'xl:-mr-12' }}" data-reveal>
                    @include('site.partials.shot', $s['shot'])
                </div>
            </section>
        @endforeach
    </div>

    {{-- ═══════════════════════════════════════ EKRANSIZ ÖZELLİKLER (siyah) --}}
    <section class="on-dark" aria-labelledby="diger-h2">
        <div class="wrap py-24 lg:py-36">
            <div class="grid gap-14 lg:grid-cols-12">
                <div class="lg:col-span-7">
                    <h2 id="diger-h2" class="display t-1">Ve gerisi.</h2>
                    <div class="mt-14 border-t border-line-dark">
                        @foreach ($plain as $n => [$id, $kicker, $title, $body])
                            <article id="{{ $id }}" class="grid gap-3 border-b border-line-dark py-10 sm:grid-cols-[5rem_1fr]" aria-labelledby="{{ $id }}-baslik" data-reveal>
                                <span class="eyebrow"><b>{{ sprintf('%02d', count($sections) + $n + 1) }}</b></span>
                                <div>
                                    <h3 id="{{ $id }}-baslik" class="display t-3">{{ $title }}</h3>
                                    <p class="mt-4 max-w-[52ch] muted">{{ $body }}</p>
                                </div>
                            </article>
                        @endforeach
                        <div class="grid gap-3 border-b border-line-dark py-10 sm:grid-cols-[5rem_1fr]">
                            <span aria-hidden="true"></span>
                            <div>
                                <h3 class="display t-3 muted">Muhasebe modülü</h3>
                                <p class="mt-4"><span class="tag tag--soon">Yakında</span></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex items-start justify-center lg:col-span-4 lg:col-start-9 lg:justify-end lg:pt-40" data-reveal>
                    <figure class="phone border-[#2a2a2a]">
                        <img src="{{ asset('images/site/panel-mobil.png') }}" alt="34Pazar paneli telefon tarayıcısında" width="390" height="844" loading="lazy" decoding="async">
                    </figure>
                </div>
            </div>
        </div>
    </section>
    {{-- Kapanış çağrısı alt bilginin kendisinde; burada ikinci kez tekrarlanmaz. --}}
@endsection
