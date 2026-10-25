{{--
    34pazar.com ana sayfası — "Türk B2B yazılım" düzeni (B3 · hero A, 21 Ekim 2026).
    TR (/) ve EN (/en) aynı şablon; `$lang` metni seçer.

    Akış: giriş (lacivert, canlı stok diyagramı) → yetenek şeridi → çözümler
    → panelin gerçek ekranı → neden 34Pazar → kanal tablosu → 3 adım →
    paketler → SSS → son çağrı.

    DEĞİŞMEZ KURAL — SAYFA VERİ UYDURMAZ (SiteController ile aynı kural):
      Fiyat ve kanal listesi denetleyiciden gelir. Müşteri sayısı, yorum,
      puan, "bilmem kaç satıcı" gibi sahip olmadığımız hiçbir rakam yok.
      Diyagramdaki 12 → 11 açıkça ÖRNEK üründür (kartta "örnek ürün").

    "HİÇ FAZLA SATMAZSIN" DENMEZ: iki kanalda neredeyse aynı anda satış
      olursa ya da kanal satışı geç bildirirse fazla satış yine olabilir.
      Vaat şu: stok her kanalda birlikte düşer; ters giden bir şey olursa
      hemen görürsün.

    KARGO BİLDİRİMİ kanal kanal: `cargoPush` adaptörün yeteneğinden gelir
      (SiteController::presentChannel); burada elle liste tutulmaz.
--}}
@extends('site.layout')

@php
    $en = ($lang ?? 'tr') === 'en';
    $t = fn (string $tr, string $enText): string => $en ? $enText : $tr;

    $available = array_values(array_filter($channels, fn ($c) => $c['available']));
    $upcoming = array_values(array_filter($channels, fn ($c) => ! $c['available']));

    // "A, B ve C" / "A, B and C".
    $join = function (array $names) use ($t): string {
        if (count($names) <= 1) {
            return implode('', $names);
        }

        return implode(', ', array_slice($names, 0, -1)).$t(' ve ', ' and ').$names[count($names) - 1];
    };
    $availableNames = $join(array_column($available, 'name'));
    $upcomingNames = $join(array_column($upcoming, 'name'));

    $freePlan = collect($plans)->first(fn ($p) => (float) $p['priceMonthly'] === 0.0);
    $limit = fn (?int $v, string $unit): string => $v === null ? $t('sınırsız', 'unlimited')." {$unit}" : number_format($v, 0, ',', '.')." {$unit}";

    // Kanal tablosu grupları — yalnız AÇIK kanallar; başlıktaki mega menüyle aynı ayrım.
    $turkeyCodes = ['trendyol', 'hepsiburada', 'n11', 'pazarama', 'ciceksepeti', 'idefix'];
    $channelGroups = array_filter([
        $t('Türkiye pazaryerleri', 'Turkish marketplaces') => array_values(array_filter($available, fn ($c) => $c['marketplace'] && in_array($c['code'], $turkeyCodes, true))),
        $t('Yurtdışı pazaryerleri', 'International marketplaces') => array_values(array_filter($available, fn ($c) => $c['marketplace'] && ! in_array($c['code'], $turkeyCodes, true))),
        $t('E-ticaret siteleri', 'Online stores') => array_values(array_filter($available, fn ($c) => ! $c['marketplace'])),
    ]);

    $capabilities = [
        $t('Fazla satış koruması', 'Overselling protection'),
        $t('Nilvera ile e-fatura', 'E-invoices via Nilvera'),
        $t('Fiyat kuralı ve kampanya', 'Price rules and campaigns'),
        $t('Aylık ödeme, taahhüt yok', 'Monthly billing, no contract'),
    ];

    // Yalnız koddan doğrulanmış iddialar (B2 incelemesi).
    $why = [
        ['calendar', $t('Aylık öde, yıllık peşin yok', 'Pay monthly, no annual prepayment'), $t('Ücretli paketler aylık abonelik. İstediğin zaman değiştirir ya da iptal edersin.', 'Paid plans are monthly subscriptions. Change or cancel whenever you like.').($freePlan ? $t(' Ücretsiz paket kart bilgisi istemez.', ' The free plan needs no card.') : '')],
        ['shield', $t('Fazla satışa karşı stok çekirdeği', 'One stock core against overselling'), $t('Stok tek yerde tutulur, her satış bir kez düşülür. Kanallara eksi sayı gitmez; fazla satış panelde en üstte görünür.', 'Stock lives in one place and each sale is deducted once. Channels never get a negative number; overselling shows up first in the panel.')],
        ['scale', $t('E-fatura pakette', 'E-invoicing included'), $t('E-fatura ve e-arşiv kendi Nilvera hesabından; her pakette aylık fatura hakkı var, ayrı modül ücreti yok. Kontör Nilvera hesabından düşer.', 'E-invoices and e-archive invoices from your own Nilvera account; every plan includes a monthly invoice allowance, no add-on fee. Credits are deducted from your Nilvera account.')],
        ['map', $t('Yurtdışı ve kur koruması', 'International, currency-safe'), $t('Yurtdışı pazaryerleri aynı stokla çalışır. Kanalın para birimi farklıysa TL fiyat yanlışlıkla euro olarak gitmez.', 'International marketplaces share the same stock. If a channel uses another currency, a lira price is never sent as euros by mistake.')],
    ];

    $steps = [
        [$t('Kanallarını bağla', 'Connect your channels'), $t('Satış yaptığın mağazaları ekle. Bağlantı formu, kanalın satıcı panelinden hangi bilgiyi alacağını tek tek gösterir.', 'Add the stores you sell on. The connection form shows exactly which details to copy from each channel\'s seller panel.')],
        [$t('Ürünlerini eşle', 'Match your products'), $t('Ürünlerini kanaldan ya da CSV dosyasıyla içe aktar. Ürünler stok koduyla (SKU) eşleşir; aynı ürün iki kez açılmaz.', 'Import products from a channel or a CSV file. Products match by SKU, so nothing is created twice.')],
        [$t('Tek yerden yönet', 'Manage from one place'), $t('Siparişler tek listeye düşer, stok her satışta bütün kanallarda birlikte güncellenir, fiyatı tek yerden değiştirirsin.', 'Orders land in one list, stock updates on every channel with each sale, and you change prices in one place.')],
    ];

    // Adım görselleri: panelden gerçek ekran (StepShots; EN sayfada İngilizce panel).
    $stepShots = \App\Support\Site\StepShots::row(['kanal-bagla', 'ice-aktar', 'yapman-gerekenler'], count($steps), $en ? 'en' : 'tr');
    $stepAlts = [
        $t('Panelde kanal bağlama ekranı: kanal seçimi ve bağlantı bilgileri formu', 'Panel screen for connecting a channel: channel picker and credentials form'),
        $t('Panelde toplu içe aktarma ekranı: CSV yükleme ve geçmiş içe aktarmada yeni, güncellenen ve hatalı ürün sayıları', 'Panel import screen: CSV upload and the new, updated and failed counts of a past import'),
        $t('Panel ana sayfası: Yapman gerekenler listesi ve kanalların durumu', 'Panel home: the to-do list and the state of your channels'),
    ];

    /*
     * SSS cevapları YALNIZ doğrulanmış şeyleri söyler. "Kart bilgisi
     * gerekmez" kayıt formuna bakılarak yazıldı (ödeme sormuyor) ve yalnız
     * ücretsiz paket varsa gösterilir. Aylık abonelik ve "istediğin zaman
     * iptal" kullanım koşullarındaki maddelerle aynı.
     */
    $faqs = [
        ['q' => $t('34Pazar ne işe yarar?', 'What does 34Pazar do?'), 'a' => $t('Birden fazla kanalda satış yapıyorsan stoğunu, ürünlerini, fiyatlarını ve siparişlerini tek panelden yönetmeni sağlar. En önemlisi: bir kanalda satılan ürünün stoğu diğer kanallarda da düşer.', 'If you sell on more than one channel, it lets you manage stock, products, prices and orders from one panel. Most importantly: an item sold on one channel is deducted on all the others.')],
    ];
    if ($availableNames) {
        $faqs[] = ['q' => $t('Hangi kanallarla çalışıyor?', 'Which channels does it support?'), 'a' => $t("Şu an {$availableNames} bağlanabiliyor.", "You can connect {$availableNames} today.").($upcomingNames ? $t(" {$upcomingNames} için çalışıyoruz; hazır olduğunda bağlanabilir hâle gelecek.", " {$upcomingNames} are in progress and will become available when ready.") : '')];
    }
    if ($freePlan) {
        $faqs[] = ['q' => $t('Kart bilgisi vermeden başlayabilir miyim?', 'Can I start without a card?'), 'a' => $t("Evet. {$freePlan['name']} paketle kart bilgisi girmeden başlarsın. Bu pakette {$limit($freePlan['productLimit'], 'ürün')} ve {$limit($freePlan['channelLimit'], 'kanal')} hakkın var.", "Yes. The free plan needs no card and includes {$limit($freePlan['productLimit'], 'products')} and {$limit($freePlan['channelLimit'], 'channel(s)')}.")];
    }
    $faqs[] = ['q' => $t('Yıllık ödeme zorunlu mu?', 'Do I have to pay yearly?'), 'a' => $t('Hayır. Ücretli paketler aylık abonelikle çalışır. Aboneliğini istediğin zaman iptal edebilirsin; iptal, ödemesi yapılmış dönemin sonunda geçerli olur.', 'No. Paid plans are monthly subscriptions. You can cancel at any time; cancellation takes effect at the end of the paid period.')];
    $faqs[] = ['q' => $t('Bir kanalda satış olunca diğerlerinde ne olur?', 'What happens on other channels after a sale?'), 'a' => $t('Stok tek bir yerde tutulur. Ürün hangi kanalda satılırsa satılsın adet oradan düşer ve yeni adet bağlı bütün kanallara gönderilir.', 'Stock is kept in one place. Whichever channel the item sells on, the count is reduced there and the new number is sent to every connected channel.')];
    $faqs[] = ['q' => $t('Yine de fazla satış olabilir mi?', 'Can overselling still happen?'), 'a' => $t('Nadiren olabilir: iki kanalda neredeyse aynı anda satış olursa ya da bir kanal satışı geç bildirirse. Böyle bir durumda ürün panelinde kırmızıyla en üstte görünür; stoğu düzeltir ya da müşteriye haber verirsin.', 'Rarely: if two channels sell at almost the same moment or a channel reports a sale late. The item then shows in red at the top of your panel so you can fix stock or inform the customer.')];
    $faqs[] = ['q' => $t('Her kanalda farklı fiyat kullanabilir miyim?', 'Can I use different prices per channel?'), 'a' => $t('Evet. Kanal başına yüzde ya da tutar farkı ve yuvarlama tanımlayabilir, istersen bir ürüne kanal için elle fiyat girebilirsin. Alış maliyetini girdiysen maliyetin altına düşen fiyat kanala gönderilmez.', 'Yes. Set a percentage or fixed difference and rounding per channel, or enter a manual channel price for a product. If you entered a cost price, a price below cost is never sent.')];
    $faqs[] = ['q' => $t('Kanalın kendi panelinde fiyat değiştirirsem ne olur?', 'What if I change a price in the channel\'s own panel?'), 'a' => $t('Değişikliğini sessizce ezmeyiz. Farkı gördüğümüzde sana gösteririz; hangi fiyatın geçerli olacağına sen karar verirsin.', 'We never overwrite it silently. When we see the difference we show it to you, and you decide which price wins.')];
    $faqs[] = ['q' => $t('Teknik bilgi gerekiyor mu?', 'Do I need technical skills?'), 'a' => $t('Kod yazman gerekmez. Bir kanalı bağlamak için o kanalın satıcı panelinden API anahtarı gibi birkaç bilgiyi alıp bağlantı formuna yapıştırırsın; form hangi bilgiyi istediğini tek tek gösterir.', 'No coding. To connect a channel you copy a few details such as an API key from its seller panel into the connection form, which tells you exactly what it needs.')];
    $faqs[] = ['q' => $t('E-fatura kesebiliyor muyum?', 'Can I issue e-invoices?'), 'a' => $t('Evet, kendi Nilvera hesabından: alıcı e-fatura mükellefiyse e-fatura, değilse e-arşiv. Trendyol, Hepsiburada, n11 ve Shopify siparişlerinde fatura bilgisi kanaldan okunur.', 'Yes, from your own Nilvera account (Turkey): an e-invoice if the buyer is registered for it, otherwise an e-archive invoice. Billing details are read from Trendyol, Hepsiburada, n11 and Shopify orders.')];

    $description = $en
        ? 'Manage your marketplace and online store stock, orders and prices from one panel. A sale on one channel reduces stock on all the others.'
        : ($availableNames
            ? "{$availableNames} mağazalarını tek panelden yönet. Bir kanalda satılan ürünün stoğu diğerlerinde de düşer; siparişler tek listede."
            : 'Bütün satış kanallarını tek panelden yönet. Bir kanalda satılan ürünün stoğu diğerlerinde de düşer; siparişler tek listede.');

    $signupUrl = $isLoggedIn ? url('/panel') : route('register');
    $signupLabel = $isLoggedIn ? $t('Panele git', 'Open panel') : $t('Ücretsiz başla', 'Start free');
@endphp

@section('lang', $en ? 'en' : 'tr')
@section('og_locale', $en ? 'en_US' : 'tr_TR')
@section('title', $t('Pazaryeri entegrasyonu: stok, sipariş ve kargo tek panelde', 'Marketplace integration: stock, orders and shipping in one panel'))
@section('description', \Illuminate\Support\Str::limit($description, 157))

@push('jsonld')
    <link rel="alternate" hreflang="tr" href="{{ route('home') }}">
    <link rel="alternate" hreflang="en" href="{{ route('site.en.home') }}">
    <link rel="alternate" hreflang="x-default" href="{{ route('home') }}">
    @include('site.partials.seo.software', ['plans' => $plans])
    @include('site.partials.seo.faq', ['faqs' => $faqs])
@endpush

@section('content')
    {{-- ═══════════════════════════════════════ GİRİŞ --}}
    <section aria-labelledby="hero-baslik" class="home-hero">
        <div class="wrap grid items-center gap-10 pt-12 pb-12 lg:grid-cols-[13fr_12fr] lg:pt-20 lg:pb-16">
            <div>
                <h1 id="hero-baslik" class="home-hero__title">{{ $t("Trendyol'da satıldı, her kanalda stok düştü.", 'Sold on Trendyol, stock dropped on every channel.') }}</h1>
                <p class="home-hero__lead mt-5">
                    {{ $t("Hepsiburada'da, eBay'de, Shopify'da da. 34Pazar pazaryerlerini ve e-ticaret siteni tek stoğa bağlar: siparişi alır, stoğu bir kez düşer, yeni sayıyı bütün kanallara gönderir.", 'On Hepsiburada, eBay and Shopify too. 34Pazar ties your marketplaces and online store to one stock count: it takes the order, deducts stock once and sends the new number to every channel.') }}
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
                    <a href="{{ $signupUrl }}" class="btn btn-primary btn-lg">{{ $signupLabel }}</a>
                    <a href="#panel" class="home-hero__link">{{ $t('Panel nasıl görünüyor', 'See the panel') }} <span aria-hidden="true">→</span></a>
                </div>
            </div>
            <div>
                @include('site.partials.stock-diagram', ['lang' => $lang ?? 'tr'])
            </div>
        </div>
        <div class="home-strip">
            <ul class="wrap">
                @foreach ($capabilities as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ PANEL --}}
    <section id="panel" aria-labelledby="panel-baslik" class="section">
        <div class="wrap">
            <div class="section-head">
                <p class="eyebrow">{{ $t('Panel', 'Panel') }}</p>
                <h2 id="panel-baslik" class="heading-2">{{ $t('Her sabah açtığın tek ekran.', 'The one screen you open every morning.') }}</h2>
                <p class="lead">{{ $t('Görsel çizim değil, panelin kendisi (demo hesabı). Günün siparişleri, yapman gerekenler ve kanallarının durumu tek bakışta.', 'Not a drawing: the panel itself (demo account). Today\'s orders, your to-dos and the state of your channels at a glance.') }}</p>
            </div>
            <div class="mt-10">
                @include('site.partials.shot', [
                    'src' => 'images/site/panel-ana-sayfa.png',
                    'alt' => $t('34Pazar paneli: bugünkü siparişler ve satış, Yapman gerekenler listesi ve bağlı kanallar', '34Pazar panel: today\'s orders and sales, the to-do list and connected channels'),
                ])
            </div>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ ÇÖZÜMLER --}}
    <section id="cozumler" aria-labelledby="cozum-baslik" class="section bg-soft home-band">
        <div class="wrap">
            <div class="flex flex-wrap items-end justify-between gap-6">
                <div class="section-head">
                    <p class="eyebrow">{{ $t('Çözümler', 'Solutions') }}</p>
                    <h2 id="cozum-baslik" class="heading-2">{{ $t('Neler yapabilirsin?', 'What you can do') }}</h2>
                    <p class="lead">{{ $t('Bütün paketlerde aynı özellikler ve bütün kanallar; paketler ürün, kanal, kullanıcı ve fatura sayısıyla ayrılır.', 'Every plan has the same features and every channel; plans differ in products, channels, users and invoices.') }}</p>
                </div>
                <a href="{{ route('site.features') }}" class="btn btn-secondary">{{ $t('Bütün özellikler', 'All features') }}</a>
            </div>
            <ul class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($solutionMenu as $item)
                    <li>
                        <a href="{{ $item['url'] }}" class="card card-link flex h-full flex-col">
                            <span class="icon-box">@include('site.partials.icon', ['name' => $item['icon']])</span>
                            <h3 class="heading-3 mt-4">{{ $item['label'] }}</h3>
                            <p class="mt-2 muted">{{ $item['hint'] }}</p>
                            <span class="mt-auto pt-4 font-semibold text-brand-700">{{ $t('Detay', 'Details') }} <span aria-hidden="true">→</span></span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ NEDEN --}}
    <section aria-labelledby="neden-baslik" class="section">
        <div class="wrap">
            <div class="section-head">
                <p class="eyebrow">{{ $t('Neden 34Pazar', 'Why 34Pazar') }}</p>
                <h2 id="neden-baslik" class="heading-2">{{ $t('Satıcıyı bağlamayan bir entegrasyon.', 'An integration that doesn\'t lock you in.') }}</h2>
            </div>
            <ul class="home-grid mt-10 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($why as [$icon, $title, $body])
                    <li>
                        <span class="icon-box">@include('site.partials.icon', ['name' => $icon])</span>
                        <h3 class="heading-3 mt-4">{{ $title }}</h3>
                        <p class="mt-2 muted">{{ $body }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ KANAL TABLOSU --}}
    @if (count($channelGroups))
        <section id="kanallar" aria-labelledby="kanal-baslik" class="section bg-soft home-band">
            <div class="wrap">
                <div class="flex flex-wrap items-end justify-between gap-6">
                    <div class="section-head">
                        <p class="eyebrow">{{ $t('Entegrasyonlar', 'Integrations') }}</p>
                        <h2 id="kanal-baslik" class="heading-2">{{ $t('Bağlanabilen kanallar', 'Channels you can connect') }}</h2>
                        <p class="lead">{{ $t('Her kanalda neyin çalıştığını açıkça yazıyoruz.', 'We state exactly what works on each channel.') }}</p>
                    </div>
                    <a href="{{ route('site.channels') }}" class="link">{{ $t('Tüm entegrasyonlar', 'All integrations') }}</a>
                </div>

                <div class="home-table mt-10">
                    <table>
                        <caption class="sr-only">{{ $t('Kanallara göre çalışan özellikler', 'Features by channel') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ $t('Kanal', 'Channel') }}</th>
                                <th scope="col">{{ $t('Stok', 'Stock') }}</th>
                                <th scope="col">{{ $t('Sipariş', 'Orders') }}</th>
                                <th scope="col">{{ $t('Kargo bildirimi', 'Tracking upload') }}<span aria-hidden="true">*</span></th>
                            </tr>
                        </thead>
                        @foreach ($channelGroups as $group => $rows)
                            <tbody>
                                <tr class="home-table__group"><th scope="rowgroup" colspan="4">{{ $group }}</th></tr>
                                @foreach ($rows as $ch)
                                    <tr>
                                        <th scope="row">
                                            <a href="{{ route('site.channel', $ch['code']) }}" class="flex items-center gap-3 font-semibold text-ink hover:text-brand-700">
                                                @include('site.partials.channel-icon', ['ch' => $ch, 'size' => 'sm'])
                                                {{ $ch['name'] }}
                                            </a>
                                        </th>
                                        @foreach (['inventory', 'order_import'] as $cap)
                                            <td>
                                                @if ($ch['works'][$cap] ?? false)
                                                    <span class="text-ok" aria-hidden="true">✓</span><span class="sr-only">{{ $t('Var', 'Yes') }}</span>
                                                @else
                                                    <span class="muted" aria-hidden="true">—</span><span class="sr-only">{{ $t('Yok', 'No') }}</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td>
                                            @if ($ch['cargoPush'])
                                                <span class="text-ok" aria-hidden="true">✓</span><span class="sr-only">{{ $t('Var', 'Yes') }}</span>
                                            @else
                                                <span class="muted" aria-hidden="true">—</span><span class="sr-only">{{ $t('Kanal panelinden', 'In the channel\'s panel') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                    </table>
                </div>
                <p class="mt-4 text-sm muted">
                    * {{ $t('"—": takip numarası şimdilik kanalın kendi satıcı panelinden girilir; sipariş 34Pazar\'da yine görünür.', '"—": for now the tracking number is entered in the channel\'s own seller panel; the order still shows in 34Pazar.') }}
                </p>
            </div>
        </section>
    @endif

    {{-- ═══════════════════════════════════════ ADIMLAR --}}
    <section id="nasil-calisir" aria-labelledby="nasil-baslik" class="section">
        <div class="wrap">
            <div class="section-head">
                <p class="eyebrow">{{ $t('Kurulum', 'Setup') }}</p>
                <h2 id="nasil-baslik" class="heading-2">{{ $t('Üç adımda kurulur.', 'Set up in three steps.') }}</h2>
                <p class="lead">{{ $t('Kod yazmazsın; kanalın satıcı panelinden birkaç bilgiyi bağlantı formuna yapıştırırsın.', 'No code: you paste a few details from the channel\'s seller panel into the connection form.') }}</p>
            </div>
            <ol class="mt-10 grid gap-6 md:grid-cols-3">
                @foreach ($steps as [$title, $body])
                    <li class="step-card">
                        @if ($stepShots)
                            @include('site.partials.step-shot', ['shot' => $stepShots[$loop->index], 'n' => $loop->iteration, 'alt' => $stepAlts[$loop->index]])
                        @endif
                        <span class="home-step mt-5 block" aria-hidden="true">0{{ $loop->iteration }}</span>
                        <h3 class="heading-3 mt-4"><span class="sr-only">{{ $loop->iteration }}. {{ $t('adım', 'step') }}: </span>{{ $title }}</h3>
                        <p class="mt-2 muted">{{ $body }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ PAKETLER --}}
    @if (count($plans))
        <section id="fiyatlar" aria-labelledby="fiyat-baslik" class="section bg-soft home-band">
            <div class="wrap">
                <div class="section-head">
                    <p class="eyebrow">{{ $t('Fiyatlar', 'Pricing') }}</p>
                    <h2 id="fiyat-baslik" class="heading-2">{{ $t('Paketler', 'Plans') }}</h2>
                    <p class="lead">{{ $t('Fiyatlar aylıktır, Türk lirası. Paketini istediğin zaman değiştirirsin.', 'Prices are monthly, in Turkish lira. Change your plan at any time.') }}</p>
                </div>
                <div class="mt-10">
                    @include('site.partials.plans', ['plans' => $plans, 'isLoggedIn' => $isLoggedIn, 'lang' => $lang ?? 'tr'])
                </div>
                <p class="mt-8 text-center">
                    <a href="{{ $en ? route('site.en.pricing') : route('site.pricing') }}" class="link">{{ $t('Paketleri ayrıntılı karşılaştır', 'Compare plans in detail') }}</a>
                </p>
            </div>
        </section>
    @endif

    {{-- ═══════════════════════════════════════ SSS --}}
    <section id="sss" aria-labelledby="sss-baslik" class="section">
        <div class="wrap-narrow">
            <div class="section-head section-head--center">
                <p class="eyebrow">{{ $t('SSS', 'FAQ') }}</p>
                <h2 id="sss-baslik" class="heading-2">{{ $t('Sık sorulanlar', 'Frequently asked questions') }}</h2>
                <p class="muted">
                    {{ $t('Cevabını bulamadın mı?', 'Didn\'t find your answer?') }} <a href="{{ route('site.contact') }}" class="link">{{ $t('Bize yaz.', 'Write to us.') }}</a>
                </p>
            </div>
            <div class="mt-10">
                @include('site.partials.faq', ['faqs' => $faqs, 'openFirst' => true])
            </div>
        </div>
    </section>

    @include('site.partials.cta', [
        'ctaTone' => 'navy',
        'lang' => $lang ?? 'tr',
        'ctaTitle' => $freePlan ? $t('Ücretsiz paketle bugün başla.', 'Start today on the free plan.') : null,
    ])
@endsection
