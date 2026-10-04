{{--
    34pazar.com ana sayfası.

    DEĞİŞMEZ KURAL — SAYFA VERİ UYDURMAZ (SiteController ile aynı kural):
      Fiyat ve kanal listesi denetleyiciden gelir. Müşteri sayısı, yorum,
      puan, "bilmem kaç satıcı" gibi sahip olmadığımız hiçbir rakam yok.

    "HİÇ FAZLA SATMAZSIN" DENMEZ: iki kanalda neredeyse aynı anda satış
      olursa ya da kanal satışı geç bildirirse fazla satış yine olabilir
      (panel görüntüsünde de kırmızı bir satır var). Vaat şu: stok her
      kanalda birlikte düşer; ters giden bir şey olursa hemen görürsün.
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

    /*
     * Bulunma eki ('da/'de/'te) elle tutulur: yabancı marka adının okunuşu
     * yazılışından tahmin edilemez ("Shopify" i ile biter, a ile okunur).
     * Sözlükte olmayan kanal başlığa girmez; o zaman genel başlık kullanılır.
     */
    $locative = [
        'trendyol' => "Trendyol'da", 'shopify' => "Shopify'da", 'woocommerce' => "WooCommerce'te",
        'etsy' => "Etsy'de", 'ebay' => "eBay'de", 'hepsiburada' => "Hepsiburada'da",
    ];
    $byCode = array_column($available, null, 'code');
    $heroPair = isset($byCode['trendyol'], $byCode['shopify']) ? ['trendyol', 'shopify'] : null;

    /*
     * Kargo numarasını kanala geri gönderebildiğimiz kanallar. Diğerlerinde
     * satıcı numarayı kanalın kendi panelinde girer. Bu liste features ve
     * channel görünümlerinde de AYNI — biri değişirse ikisi de değişmeli.
     */
    $cargoPush = ['shopify', 'woocommerce'];
    $cargoYes = $join(array_column(array_filter($available, fn ($c) => in_array($c['code'], $cargoPush, true)), 'name'));
    $cargoNo = $join(array_column(array_filter($available, fn ($c) => ! in_array($c['code'], $cargoPush, true)), 'name'));

    $freePlan = collect($plans)->first(fn ($p) => (float) $p['priceMonthly'] === 0.0);
    $limit = fn (?int $v, string $unit): string => $v === null ? "sınırsız {$unit}" : number_format($v, 0, ',', '.')." {$unit}";

    $primaryHref = $isLoggedIn ? url('/panel') : route('register');
    $primaryLabel = $isLoggedIn ? 'Panele git' : 'Ücretsiz başla';

    /*
     * Her satır TEK bir gerçek iş anlatır ve yanında o işin yapıldığı
     * panel ekranı durur. İkon ızgarası yerine bu düzen: satıcı "bu ne
     * işe yarar"ı soyut sıfatlardan değil kendi gününden tanır.
     */
    $scenes = [
        [
            'id' => 'siparisler', 'kicker' => 'Siparişler',
            'title' => 'Bütün siparişler tek listede.',
            'body' => 'Sabah paneli açarsın; hangi kanaldan gelirse gelsin bütün siparişler alt alta. Sekmeler arasında dolaşıp hangisini kaçırdığını aramazsın.',
            'points' => ['Her siparişin hangi kanaldan geldiği yanında yazar', 'Kargo bekleyenler tek tıkla süzülür', 'Stoğu yetmeyen sipariş ayrıca işaretlenir'],
            'shot' => ['src' => 'images/site/panel-siparisler.png', 'alt' => 'Panelde Trendyol, Shopify ve WooCommerce siparişlerinin tek listede görünümü', 'dy' => 0],
        ],
        [
            'id' => 'stok', 'kicker' => 'Stok',
            'title' => 'Stok tek yerde. Ters giden hemen görünür.',
            'body' => 'Ürünün adedi tek bir yerde tutulur. Satış nereden gelirse gelsin adet oradan düşer ve yeni sayı bağlı bütün kanallara gider.',
            'points' => ['Bir kanalda satılan ürünün stoğu diğerlerinde de düşer', 'Fazla satılan ürün kırmızıyla en üstte durur'],
            'shot' => ['src' => 'images/site/panel-stok.png', 'alt' => 'Panelde ürünlerin stok adetleri; fazla satılan ürün kırmızıyla işaretli', 'dy' => 0],
        ],
        [
            'id' => 'kargo', 'kicker' => 'Kargo',
            'title' => 'Takip numarasını bir kez gir.',
            'body' => $cargoYes
                ? "Paketi kargoya verdin, takip numarasını siparişin içine yazdın. {$cargoYes} siparişlerinde numara kanala iletilir; müşterin takibi orada görür."
                : 'Paketi kargoya verdin, takip numarasını siparişin içine yazdın; sipariş panelde kargoda görünür.',
            'points' => array_values(array_filter([
                $cargoYes ? "{$cargoYes} için kanalın panelini açman gerekmez" : null,
                $cargoNo ? "{$cargoNo} siparişlerinde numarayı şimdilik kanalın kendi panelinde girersin" : null,
            ])),
            'shot' => ['src' => 'images/site/panel-kargo.png', 'alt' => 'Sipariş ayrıntısında kargo firması ve takip numarası formu', 'dy' => 30],
        ],
        [
            'id' => 'gunluk', 'kicker' => 'Ana sayfa',
            'title' => 'Paneli açınca ne yapacağını bilirsin.',
            'body' => 'Ana sayfada rapor değil iş listesi durur: kargolanmayı bekleyen siparişler, fazla satılan ürün, kanalın reddettiği ürün. Her satırın yanında oraya giden bağlantı.',
            'points' => ['Bugünkü sipariş sayısı ve satış tutarı en üstte', 'Bağlı kanallarının durumu tek bakışta'],
            'shot' => ['src' => 'images/site/panel-ana-sayfa.png', 'alt' => 'Panelin ana sayfasındaki Yapman gerekenler listesi', 'dy' => 0,
                'crop' => ['ratio' => '16 / 7', 'zoom' => '142%', 'x' => '-32.7%', 'y' => '-23%']],
        ],
    ];

    /*
     * Ekran görüntüsü olmayan özellikler düz liste: her biri için uydurma
     * bir görsel üretmek yerine ne yaptığını tek cümleyle söylemek dürüst.
     */
    $extras = [
        ['Ürünü bir kez hazırla', 'Ürün bilgisini bir kere gir, hangi kanalda satılacağını seç ve buradan gönder.'],
        ['Fiyatı bir yerde değiştir', 'Yeni fiyat bağlı kanallara gider; mağaza mağaza dolaşıp düzeltmezsin.'],
        ['Sormadan üzerine yazmayız', 'Bir kanalın içinde fiyatı kendin değiştirdiysen fark ederiz; hangisinin geçerli olacağına sen karar verirsin.'],
        ['Telefonda da açılır', 'Uygulama indirmen gerekmez; panel telefonunun tarayıcısında çalışır.'],
    ];

    /*
     * SSS cevapları YALNIZ doğrulanmış şeyleri söyler. "Kart bilgisi
     * gerekmez" kayıt formuna bakılarak yazıldı (ödeme sormuyor) ve yalnız
     * ücretsiz paket varsa gösterilir.
     */
    $faqs = [
        ['q' => '34Pazar ne işe yarar?', 'a' => 'Birden fazla kanalda satış yapıyorsan stoğunu, ürünlerini ve siparişlerini tek panelden yönetmeni sağlar. En önemlisi: bir kanalda satılan ürünün stoğu diğer kanallarda da düşer.'],
    ];
    if ($availableNames) {
        $faqs[] = ['q' => 'Hangi kanallarla çalışıyor?', 'a' => "Şu an {$availableNames} bağlanabiliyor.".($upcomingNames ? " {$upcomingNames} için çalışıyoruz; hazır olduğunda bağlanabilir hâle gelecek." : '')];
    }
    if ($freePlan) {
        $faqs[] = ['q' => 'Ücretsiz deneyebilir miyim?', 'a' => "Evet. {$freePlan['name']} paketle kart bilgisi girmeden başlarsın. Bu pakette {$limit($freePlan['productLimit'], 'ürün')} ve {$limit($freePlan['channelLimit'], 'kanal')} hakkın var."];
    }
    $faqs[] = ['q' => 'Bir kanalda satış olunca diğerlerinde ne olur?', 'a' => 'Stok tek bir yerde tutulur. Ürün hangi kanalda satılırsa satılsın adet oradan düşer ve yeni adet bağlı bütün kanallara gönderilir.'];
    $faqs[] = ['q' => 'Yine de fazla satış olabilir mi?', 'a' => 'Nadiren olabilir: iki kanalda neredeyse aynı anda satış olursa ya da bir kanal satışı geç bildirirse. Böyle bir durumda ürün panelinde kırmızıyla en üstte görünür; stoğu düzeltir ya da müşteriye haber verirsin.'];
    $faqs[] = ['q' => 'Kanalın kendi panelinde fiyat değiştirirsem ne olur?', 'a' => 'Değişikliğini sessizce ezmeyiz. Farkı gördüğümüzde sana gösteririz; hangi fiyatın geçerli olacağına sen karar verirsin.'];
    $faqs[] = ['q' => 'Teknik bilgi gerekiyor mu?', 'a' => 'Kod yazman gerekmez. Bir kanalı bağlamak için o kanalın satıcı panelinden API anahtarı gibi birkaç bilgiyi alıp bağlantı formuna yapıştırırsın; form hangi bilgiyi istediğini tek tek gösterir.'];
    $faqs[] = ['q' => 'Muhasebe ya da e-fatura var mı?', 'a' => 'Henüz yok. Muhasebe modülü yakında geliyor. Şu an 34Pazar stok, ürün, sipariş ve kargo takibine odaklanıyor.'];

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
    {{-- ═══════════════════════════════════════ HERO --}}
    <section aria-labelledby="hero-baslik" class="relative">
        <div class="wrap pt-10 sm:pt-16 lg:pt-20">
            <p class="eyebrow"><b>34Pazar</b> Pazaryeri entegrasyonu</p>

            <h1 id="hero-baslik" class="display t-hero mt-6 max-w-[15ch] lg:mt-8">
                @if ($heroPair)
                    {{ $locative[$heroPair[0]] }} sattın. <span class="muted">{{ $locative[$heroPair[1]] }}ki stok</span> <span class="accent">kendiliğinden</span> <span class="muted">düştü.</span>
                @else
                    Bir kanalda sattın. <span class="muted">Ötekilerde stok</span> <span class="accent">kendiliğinden</span> <span class="muted">düştü.</span>
                @endif
            </h1>

            <div class="mt-10 grid gap-8 lg:mt-14 lg:grid-cols-12 lg:items-end">
                <p class="lead max-w-[36ch] lg:col-span-6">
                    34Pazar bütün mağazalarını tek panele bağlar. Stoğun tek yerde tutulur, siparişlerin tek listeye düşer.
                </p>
                <div class="flex flex-wrap items-center gap-x-6 gap-y-4 lg:col-span-6 lg:justify-end">
                    <a href="{{ $primaryHref }}" class="btn btn-primary">{{ $primaryLabel }} <span class="arrow" aria-hidden="true">→</span></a>
                    <a href="{{ route('site.features') }}" class="link-u font-semibold">Nasıl çalıştığını gör</a>
                </div>
            </div>
            @if ($freePlan && ! $isLoggedIn)
                <p class="mt-4 text-sm muted lg:text-right">Ücretsiz paketle, kart bilgisi girmeden.</p>
            @endif

            {{--
                Ekran görüntüsü alttaki siyah banda TAŞAR: sayfa "yazı + görsel +
                yazı" diye üç kutuya bölünmesin, tek akış gibi okunsun.
            --}}
            <div class="relative z-10 mt-12 -mb-[22vw] lg:mt-16 lg:-mb-[18rem]">
                @include('site.partials.shot', [
                    'src' => 'images/site/panel-ana-sayfa.png',
                    'alt' => '34Pazar paneli: günün siparişleri, Yapman gerekenler listesi ve bağlı kanallar',
                    'eager' => true,
                ])
            </div>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ SORUN (siyah bant) --}}
    <section aria-labelledby="sorun-baslik" class="on-dark">
        <div class="wrap pt-[calc(22vw+5rem)] pb-24 lg:pt-[calc(18rem+8rem)] lg:pb-36">
            <div class="grid gap-14 lg:grid-cols-12">
                <div class="lg:col-span-6" data-reveal>
                    <p class="eyebrow">Tanıdık geldi mi?</p>
                    <h2 id="sorun-baslik" class="display t-2 mt-6">Rafta bir tane vardı. İki kanalda birden satıldı.</h2>
                    <p class="lead mt-8 max-w-[44ch] muted">
                        Birden fazla yerde satan herkes bunu yaşamıştır. Stoğu her mağazada elle düzeltmeye yetişemezsin;
                        sonunda müşteriye "ürün kalmamış" diye yazar, iptal edersin. Hem müşteri gider hem mağaza puanın düşer.
                    </p>
                </div>

                <ol class="self-end lg:col-span-5 lg:col-start-8" data-reveal>
                    <li class="grid grid-cols-[6rem_1fr] gap-4 border-t border-line-dark py-5">
                        <span class="font-semibold tabular-nums muted">14:02</span>
                        <span>Son ürün bir pazaryerinde satılıyor.</span>
                    </li>
                    <li class="grid grid-cols-[6rem_1fr] gap-4 border-t border-line-dark py-5">
                        <span class="font-semibold tabular-nums muted">14:05</span>
                        <span>Kendi siten hâlâ "stokta 1" gösteriyor. Orada da satılıyor.</span>
                    </li>
                    <li class="grid grid-cols-[6rem_1fr] gap-4 border-t border-line-dark py-5">
                        <span class="font-semibold muted">Ertesi gün</span>
                        <span>İki sipariş, bir ürün. Biri iptal, biri özür mesajı.</span>
                    </li>
                    <li class="grid grid-cols-[6rem_1fr] gap-4 border-y border-line-dark py-5">
                        <span class="font-semibold text-[#ff6a47]">34Pazar ile</span>
                        <span class="font-semibold">14:02'deki satış stoğu bütün kanallarda düşürür. Bir şey yine de ters giderse panelin en üstünde görürsün.</span>
                    </li>
                </ol>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ KANAL BANDI (kırmızı) --}}
    @if (count($available))
        <section aria-labelledby="bant-baslik" class="on-brand py-8 lg:py-10">
            <h2 id="bant-baslik" class="wrap eyebrow mb-2 text-white">Bugün bağlayabildiğin kanallar</h2>
            <div class="marquee">
                <div class="marquee__track">
                    {{-- İki kopya: iz -%50 kayınca ikincisi birincinin yerine oturur. --}}
                    @foreach ([false, true] as $copy)
                        <ul class="marquee__group" @if ($copy) aria-hidden="true" @endif>
                            @foreach (array_merge($available, $available) as $ch)
                                <li class="marquee__item">{{ $ch['name'] }}</li>
                            @endforeach
                        </ul>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ═══════════════════════════════════════ NASIL ÇALIŞIR --}}
    <section id="nasil-calisir" aria-labelledby="nasil-baslik">
        <div class="wrap py-24 lg:py-36">
            <div class="grid gap-8 lg:grid-cols-12 lg:items-end">
                <h2 id="nasil-baslik" class="display t-1 lg:col-span-8">Üç adımda kurulur.</h2>
                <p class="lead muted lg:col-span-4">Kod yazmazsın. Kanalın satıcı panelinden birkaç API bilgisini alıp buraya yapıştırman yeterli.</p>
            </div>
            <ol class="mt-16 grid gap-12 md:grid-cols-3 md:gap-8 lg:mt-24 lg:gap-12">
                @foreach ([
                    ['Kanalını bağla', 'Satış yaptığın mağazaları ekle. Form, kanalın hangi bilgisini istediğini tek tek gösterir.'],
                    ['Ürünlerini aktar', 'Ürünlerini ve stok adetlerini tek yerde topla. Hangi ürün hangi kanalda satılacak, sen seçersin.'],
                    ['Tek panelden yönet', 'Siparişler tek listeye düşer, stok her satışta bütün kanallarda birlikte azalır. Sen paketlersin.'],
                ] as [$title, $body])
                    <li class="border-t-2 border-ink pt-8" data-reveal>
                        <span class="step-num" aria-hidden="true">{{ sprintf('%02d', $loop->iteration) }}</span>
                        <h3 class="display t-3 mt-8"><span class="sr-only">{{ $loop->iteration }}. adım: </span>{{ $title }}</h3>
                        <p class="mt-4 max-w-[34ch] muted">{{ $body }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ ÖZELLİKLER (zikzak) --}}
    <section aria-labelledby="ozellik-baslik" class="border-t border-line">
        <div class="wrap pt-24 lg:pt-36">
            <p class="eyebrow">Panelde bir gün</p>
            <h2 id="ozellik-baslik" class="display t-1 mt-6 max-w-[14ch]">Gördüğün ekran, kullanacağın ekran.</h2>
            <p class="lead mt-6 max-w-[48ch] muted">Aşağıdakiler çizim değil, panelin kendisi. Kayıt olduğunda karşına bunlar çıkar.</p>
        </div>

        <div class="wrap space-y-28 py-24 lg:space-y-44 lg:py-36">
            @foreach ($scenes as $i => $scene)
                @php $flip = $i % 2 === 1; @endphp
                <article id="{{ $scene['id'] }}" class="grid items-center gap-10 lg:grid-cols-12 lg:gap-16" aria-labelledby="{{ $scene['id'] }}-baslik">
                    <div class="lg:col-span-5 {{ $flip ? 'lg:order-2 lg:col-start-8' : '' }}" data-reveal>
                        <p class="eyebrow"><b>{{ sprintf('%02d', $i + 1) }}</b> {{ $scene['kicker'] }}</p>
                        <h3 id="{{ $scene['id'] }}-baslik" class="display t-scene mt-6">{{ $scene['title'] }}</h3>
                        <p class="mt-6 text-lg leading-relaxed muted">{{ $scene['body'] }}</p>
                        <ul class="mt-8 border-t border-line">
                            @foreach ($scene['points'] as $point)
                                <li class="flex gap-4 border-b border-line py-3.5 font-medium">
                                    <span class="mt-[0.6rem] size-2 shrink-0 bg-brand" aria-hidden="true"></span>{{ $point }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    {{-- Görsel geniş sütunda ve xl'de sayfa kenarına taşar: ekran okunur büyüklükte kalsın. --}}
                    <div class="lg:col-span-7 {{ $flip ? 'lg:order-1 lg:col-start-1 xl:-ml-12' : 'xl:-mr-12' }}" data-reveal>
                        @include('site.partials.shot', $scene['shot'])
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    {{-- ═══════════════════════════════════════ BİR DE ŞUNLAR + TELEFON --}}
    <section aria-labelledby="diger-baslik" class="bg-sand">
        <div class="wrap grid gap-16 py-24 lg:grid-cols-12 lg:py-36">
            <div class="lg:col-span-7">
                <h2 id="diger-baslik" class="display t-1">Bir de şunlar.</h2>
                <dl class="mt-12 border-t-2 border-ink">
                    @foreach ($extras as [$title, $body])
                        <div class="grid gap-2 border-b border-line py-7 sm:grid-cols-[15rem_1fr] sm:gap-8" data-reveal>
                            <dt class="display t-3 text-[1.375rem]!">{{ $title }}</dt>
                            <dd class="muted">{{ $body }}</dd>
                        </div>
                    @endforeach
                    <div class="grid gap-2 border-b border-line py-7 sm:grid-cols-[15rem_1fr] sm:gap-8">
                        <dt class="display t-3 text-[1.375rem]! muted">Muhasebe modülü</dt>
                        <dd><span class="tag tag--soon">Yakında</span></dd>
                    </div>
                </dl>
            </div>
            <div class="flex items-start justify-center lg:col-span-4 lg:col-start-9 lg:justify-end" data-reveal>
                <figure class="phone lg:-mt-48">
                    <img src="{{ asset('images/site/panel-mobil.png') }}" alt="34Pazar paneli telefon tarayıcısında" width="390" height="844" loading="lazy" decoding="async">
                </figure>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ KANALLAR --}}
    <section id="kanallar" aria-labelledby="kanal-baslik">
        <div class="wrap py-24 lg:py-36">
            <div class="grid gap-8 lg:grid-cols-12 lg:items-end">
                <div class="lg:col-span-8">
                    <p class="eyebrow"><b>{{ count($available) }}/{{ count($channels) }}</b> Kanallar</p>
                    <h2 id="kanal-baslik" class="display t-1 mt-6">Bağlayabildiğin kanallar.</h2>
                </div>
                <p class="max-w-[34ch] muted lg:col-span-4">Bugün açık olanlar ve üzerinde çalıştıklarımız. Her kanalda neyin çalıştığını kanal sayfasında yazıyoruz.</p>
            </div>
            <div class="mt-14 lg:mt-20">
                @include('site.partials.channel-list', ['channels' => $channels])
            </div>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ FİYATLAR --}}
    <section id="fiyatlar" aria-labelledby="fiyat-baslik" class="border-t border-line">
        <div class="wrap py-24 lg:py-36">
            <div class="flex flex-wrap items-end justify-between gap-8">
                <h2 id="fiyat-baslik" class="display t-1 max-w-[13ch]">Ürün ve kanal sayına göre.</h2>
                <div class="max-w-[30ch]">
                    <p class="muted">Büyüdükçe paketi yükseltirsin. Fiyatlar aylıktır.</p>
                    <a href="{{ route('site.pricing') }}" class="link-u mt-3 inline-block font-semibold">Bütün ayrıntılar →</a>
                </div>
            </div>
            <div class="mt-14 lg:mt-20">
                @include('site.partials.plans', ['plans' => $plans, 'isLoggedIn' => $isLoggedIn])
            </div>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ SSS --}}
    <section id="sss" aria-labelledby="sss-baslik" class="bg-sand">
        <div class="wrap py-24 lg:py-36">
            <div class="grid gap-8 lg:grid-cols-12 lg:items-end">
                <h2 id="sss-baslik" class="display t-1 lg:col-span-8">Sık sorulanlar.</h2>
                <p class="muted lg:col-span-4">
                    Cevabını bulamadın mı? <a href="{{ route('site.contact') }}" class="link-u font-semibold text-ink">Bize yaz.</a>
                </p>
            </div>
            <div class="mt-14 lg:mt-20 lg:ml-[33.333%]">
                @include('site.partials.faq', ['faqs' => $faqs, 'openFirst' => true])
            </div>
        </div>
    </section>
@endsection
