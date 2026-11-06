{{--
    34pazar.com ana sayfası. TR (/) ve EN (/en) aynı şablon; `$lang` metni seçer.

    6 Kas 2026 · site sprint 1 — kullanıcının kesin tasarım kuralları:
      - Giriş AÇIK ZEMİNDE; sağda panelin GERÇEK ekranı (telefonda panelin
        kendi mobil görüntüsü). Lacivert blok ve çizgili logo diyagramı kalktı.
      - Gölge, parıltı, cam efekti, kayan yazı YOK; bölümler 1px çizgi + zemin
        tonuyla ayrılır. İkonlu renkli kart yağmuru yerine düz liste/tablo.
      - Şeritte YALNIZ doğru iddialar. "Nilvera ile e-fatura" kalktı:
        sertifikasyon matrisinde yalnız sahte testli.

    Akış: giriş (+ şerit) → iş sorununa göre çözümler → kanal tablosu
    (doğrulama düzeyiyle) → neden 34Pazar → 3 adım → hizmetler → paketler →
    SSS (kısa; tamamı /sss) → son çağrı.

    DEĞİŞMEZ KURAL — SAYFA VERİ UYDURMAZ (SiteController ile aynı kural):
      Fiyat ve kanal listesi denetleyiciden; kanal/kargo/e-fatura durumu
      `SiteProof`'tan (S15 matrisi). Müşteri sayısı, yorum, puan, "bilmem
      kaç satıcı" gibi sahip olmadığımız hiçbir rakam yok.

    "HİÇ FAZLA SATMAZSIN" DENMEZ: iki kanalda neredeyse aynı anda satış
      olursa ya da kanal satışı geç bildirirse fazla satış yine olabilir.
--}}
@extends('site.layout')

@php
    $en = ($lang ?? 'tr') === 'en';
    $t = fn (string $tr, string $enText): string => $en ? $enText : $tr;
    $proof = app(\App\Support\Site\SiteProof::class);

    $available = array_values(array_filter($channels, fn ($c) => $c['available']));

    $join = function (array $names) use ($t): string {
        if (count($names) <= 1) {
            return implode('', $names);
        }

        return implode(', ', array_slice($names, 0, -1)).$t(' ve ', ' and ').$names[count($names) - 1];
    };
    $availableNames = $join(array_column($available, 'name'));
    $liveNames = $join($proof->liveChannelNames($channels));

    $freePlan = collect($plans)->first(fn ($p) => (float) $p['priceMonthly'] === 0.0);
    $refundDays = (int) config('site.refund_days');

    // Kanal tablosu grupları — yalnız AÇIK kanallar; başlıktaki mega menüyle aynı ayrım.
    $turkeyCodes = ['trendyol', 'hepsiburada', 'n11', 'pazarama', 'ciceksepeti', 'idefix'];
    $channelGroups = array_filter([
        $t('Türkiye pazaryerleri', 'Turkish marketplaces') => array_values(array_filter($available, fn ($c) => $c['marketplace'] && in_array($c['code'], $turkeyCodes, true))),
        $t('Yurtdışı pazaryerleri', 'International marketplaces') => array_values(array_filter($available, fn ($c) => $c['marketplace'] && ! in_array($c['code'], $turkeyCodes, true))),
        $t('E-ticaret siteleri', 'Online stores') => array_values(array_filter($available, fn ($c) => ! $c['marketplace'])),
    ]);

    // Şerit: YALNIZ doğrulanabilir iddialar. Canlı kanal adı matristen gelir;
    // iade süresi ve aylık ödeme config/kullanım koşullarından; sunucu yeri
    // ve gece yedeği /guvenlik sayfasındaki kaynaklardan (RUNBOOK, zamanlayıcı).
    $strip = array_values(array_filter([
        $liveNames !== '' ? $t("Canlı hesapta doğrulandı: {$liveNames}", "Verified on a live account: {$liveNames}") : null,
        $t('Aylık ödeme, taahhüt yok', 'Monthly billing, no contract'),
        $t("Ücretli pakette {$refundDays} gün içinde tam iade", "{$refundDays}-day full refund on paid plans"),
        $t('Sunucular Almanya\'da, her gece yedek', 'Servers in Germany, nightly backups'),
    ]));

    // Yalnız koddan doğrulanmış iddialar (B2 incelemesi). İkon yok: düz liste.
    $why = [
        [$t('Aylık öde, yıllık peşin yok', 'Pay monthly, no annual prepayment'), $t('Ücretli paketler aylık abonelik. İstediğin zaman değiştirir ya da iptal edersin.', 'Paid plans are monthly subscriptions. Change or cancel whenever you like.').($freePlan ? $t(' Ücretsiz paket kart bilgisi istemez.', ' The free plan needs no card.') : '')],
        [$t('Fazla satışa karşı tek stok', 'One stock count against overselling'), $t('Stok tek yerde tutulur, her satış bir kez düşülür. Kanallara eksi sayı gitmez; fazla satış panelde en üstte görünür.', 'Stock lives in one place and each sale is deducted once. Channels never get a negative number; overselling shows up first in the panel.')],
        [$t('Çalışmayanı da yazıyoruz', 'We list what doesn\'t work too'), $t('Her kanalın sayfasında hangi işin gerçek hesapta doğrulandığını, hangisinin hâlâ test edildiğini satır satır yazıyoruz.', 'Every channel page states, line by line, what is verified on a real account and what is still being tested.')],
        [$t('Yurtdışı ve kur koruması', 'International, currency-safe'), $t('Yurtdışı pazaryerleri aynı stokla çalışır. Kanalın para birimi farklıysa TL fiyat yanlışlıkla euro olarak gitmez.', 'International marketplaces share the same stock. If a channel uses another currency, a lira price is never sent as euros by mistake.')],
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

    // SSS — tek kaynak (`Faq`); ana sayfada ilk beşi, tamamı /sss'te.
    $allFaqs = \App\Support\Site\Faq::general($plans, $channels, $en ? 'en' : 'tr');
    $faqs = array_slice($allFaqs, 0, 5);

    $description = $en
        ? 'Manage your marketplace and online store stock, orders and prices from one panel. A sale on one channel reduces stock on all the others.'
        : ($availableNames
            ? "{$availableNames} mağazalarını tek panelden yönet. Bir kanalda satılan ürünün stoğu diğerlerinde de düşer; siparişler tek listede."
            : 'Bütün satış kanallarını tek panelden yönet. Bir kanalda satılan ürünün stoğu diğerlerinde de düşer; siparişler tek listede.');

    $signupUrl = $isLoggedIn ? url('/panel') : route('register');
    $signupLabel = $isLoggedIn ? $t('Panele git', 'Open panel') : $t('Ücretsiz başla', 'Start free');
    $statusCell = fn (\App\Domain\Channels\Certification\CertificationStatus $s): string => '<span class="badge badge--'.$s->publicTone().'" data-status="'.$s->value.'">'.e($en ? match ($s->publicTone()) { 'live' => 'Verified live', 'sandbox' => 'Verified in sandbox', 'testing' => 'Being tested', default => 'In channel panel' } : $s->publicLabel()).'</span>';
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
@endpush

@section('content')
    {{-- ═══════════════════════════════════════ GİRİŞ (açık zemin, gerçek panel) --}}
    <section aria-labelledby="hero-baslik" class="home-hero">
        <div class="wrap grid items-center gap-10 pt-10 pb-12 lg:grid-cols-[11fr_14fr] lg:gap-12 lg:pt-16 lg:pb-16">
            <div>
                <h1 id="hero-baslik" class="home-hero__title">{{ $t("Trendyol'da satıldı, her kanalda stok düştü.", 'Sold on Trendyol, stock dropped on every channel.') }}</h1>
                <p class="home-hero__lead mt-5">
                    {{ $t("34Pazar pazaryerlerini ve e-ticaret siteni tek stoğa bağlar: siparişi alır, stoğu bir kez düşer, yeni sayıyı bağlı bütün kanallara gönderir.", '34Pazar ties your marketplaces and online store to one stock count: it takes the order, deducts stock once and sends the new number to every connected channel.') }}
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
                    <a href="{{ $signupUrl }}" class="btn btn-primary btn-lg">{{ $signupLabel }}</a>
                    @if ($demoAvailable ?? false)
                        <a href="{{ route('demo') }}" class="home-hero__link" rel="nofollow">{{ $t('Demo hesabı incele', 'Explore the demo account') }} <span aria-hidden="true">→</span></a>
                    @else
                        <a href="#kanallar" class="home-hero__link">{{ $t('Hangi kanalda ne çalışır', 'What works on each channel') }} <span aria-hidden="true">→</span></a>
                    @endif
                </div>
            </div>
            <div id="panel">
                @include('site.partials.shot', [
                    'src' => 'images/site/panel-ana-sayfa.png',
                    'mobileSrc' => 'images/site/panel-mobil.png',
                    'alt' => $t('34Pazar paneli: bugünkü siparişler ve satış, Yapman gerekenler listesi ve bağlı kanallar', '34Pazar panel: today\'s orders and sales, the to-do list and connected channels'),
                    'mobileAlt' => $t('34Pazar paneli telefonda: bugünkü siparişler ve yapman gerekenler', '34Pazar panel on a phone: today\'s orders and to-dos'),
                    'eager' => true,
                ])
                <p class="mt-2 text-xs muted">{{ $t('Panelden gerçek ekran görüntüsü (demo hesabı).', 'Real screenshot from the panel (demo account).') }}</p>
            </div>
        </div>
        <div class="home-strip">
            <ul class="wrap">
                @foreach ($strip as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ ÇÖZÜMLER (iş sorununa göre) --}}
    @unless ($en)
        <section id="cozumler" aria-labelledby="cozum-baslik" class="section">
            <div class="wrap grid gap-10 lg:grid-cols-12">
                <div class="lg:col-span-4">
                    <h2 id="cozum-baslik" class="heading-2">Hangi derde çözüm?</h2>
                    <p class="mt-4 muted">Bütün paketlerde aynı özellikler; paketler ürün, kanal, kullanıcı ve fatura sayısıyla ayrılır.</p>
                    <p class="mt-4"><a href="{{ route('site.features') }}" class="plain-link">Bütün özellikler</a></p>
                </div>
                <ul class="plain-list lg:col-span-8">
                    @foreach ($problemMenu as $item)
                        <li>
                            <a href="{{ $item['url'] }}" class="plain-link text-lg">{{ $item['label'] }}</a>
                            <p class="mt-1 muted">{{ $item['hint'] }}.</p>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endunless

    {{-- ═══════════════════════════════════════ KANAL TABLOSU (matristen) --}}
    @if (count($channelGroups))
        <section id="kanallar" aria-labelledby="kanal-baslik" class="section bg-soft home-band">
            <div class="wrap">
                <div class="flex flex-wrap items-end justify-between gap-6">
                    <div class="section-head">
                        <h2 id="kanal-baslik" class="heading-2">{{ $t('Hangi kanalda ne doğrulandı', 'What is verified on each channel') }}</h2>
                        <p class="lead">{{ $t('Paneldeki her kanal aşağıda. Gerçek hesapta denenmemiş olanı "çalışır" diye yazmıyoruz.', 'Every channel in the panel is listed. We never call something "working" before it was tried on a real account.') }}</p>
                    </div>
                    <a href="{{ route('site.channels') }}" class="plain-link">{{ $t('Tüm entegrasyonlar', 'All integrations') }}</a>
                </div>

                <div class="home-table mt-10">
                    <table>
                        <caption class="sr-only">{{ $t('Kanallara göre doğrulama durumu', 'Verification status by channel') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ $t('Kanal', 'Channel') }}</th>
                                <th scope="col">{{ $t('Stok', 'Stock') }}</th>
                                <th scope="col">{{ $t('Sipariş', 'Orders') }}</th>
                                <th scope="col">{{ $t('Kargo bildirimi', 'Tracking upload') }}</th>
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
                                        @foreach (['inventory', 'order_import', 'shipment'] as $cap)
                                            <td>{!! $statusCell($ch['status'][$cap]) !!}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                    </table>
                </div>
                @include('site.partials.proof-legend', ['en' => $en ?? false])
            </div>
        </section>
    @endif

    {{-- ═══════════════════════════════════════ NEDEN (düz liste) --}}
    <section aria-labelledby="neden-baslik" class="section">
        <div class="wrap grid gap-10 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <h2 id="neden-baslik" class="heading-2">{{ $t('Satıcıyı bağlamayan bir entegrasyon.', 'An integration that doesn\'t lock you in.') }}</h2>
            </div>
            <ul class="plain-list lg:col-span-8">
                @foreach ($why as [$title, $body])
                    <li>
                        <h3 class="heading-3">{{ $title }}</h3>
                        <p class="mt-1 muted">{{ $body }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ ADIMLAR --}}
    <section id="nasil-calisir" aria-labelledby="nasil-baslik" class="section bg-soft home-band">
        <div class="wrap">
            <div class="section-head">
                <h2 id="nasil-baslik" class="heading-2">{{ $t('Üç adımda kurulur.', 'Set up in three steps.') }}</h2>
                <p class="lead">{{ $t('Kod yazmazsın; kanalın satıcı panelinden birkaç bilgiyi bağlantı formuna yapıştırırsın.', 'No code: you paste a few details from the channel\'s seller panel into the connection form.') }}</p>
            </div>
            <ol class="mt-10 grid gap-6 md:grid-cols-3">
                @foreach ($steps as [$title, $body])
                    <li class="step-card">
                        @if ($stepShots)
                            @include('site.partials.step-shot', ['shot' => $stepShots[$loop->index], 'n' => $loop->iteration, 'alt' => $stepAlts[$loop->index]])
                        @endif
                        <h3 class="heading-3 mt-5"><span class="muted">{{ $loop->iteration }}.</span> {{ $title }}</h3>
                        <p class="mt-2 muted">{{ $body }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ HİZMETLER (kısa liste) --}}
    @unless ($en)
        <section id="hizmetler" aria-labelledby="hizmet-baslik" class="section">
            <div class="wrap grid gap-10 lg:grid-cols-12">
                <div class="lg:col-span-4">
                    <h2 id="hizmet-baslik" class="heading-2">Kurulumu birlikte yapalım.</h2>
                    <p class="mt-4 muted">34Pazar'ı geliştiren 34Devs bir Shopify Partner ajansıdır. Paneli tek başına kurmak istemezsen bu işleri biz yaparız; fiyatı işin kapsamına göre teklifle belirlenir.</p>
                    <p class="mt-4"><a href="{{ route('site.services') }}" class="plain-link">Bütün hizmetler</a></p>
                </div>
                <ul class="plain-list lg:col-span-8">
                    @foreach ($serviceMenu as $item)
                        <li class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                            <div>
                                <a href="{{ $item['url'] }}" class="plain-link text-lg">{{ $item['label'] }}</a>
                                <p class="mt-1 muted">{{ $item['hint'] }}</p>
                            </div>
                            <a href="{{ \App\Support\Site\Services::offerUrl($item['slug']) }}" class="text-sm font-semibold text-ink underline underline-offset-4">Teklif al<span class="sr-only">: {{ $item['label'] }}</span></a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endunless

    {{-- ═══════════════════════════════════════ PAKETLER --}}
    @if (count($plans))
        <section id="fiyatlar" aria-labelledby="fiyat-baslik" class="section bg-soft home-band">
            <div class="wrap">
                <div class="section-head">
                    <h2 id="fiyat-baslik" class="heading-2">{{ $t('Paketler', 'Plans') }}</h2>
                    <p class="lead">{{ $t('Fiyatlar aylıktır, Türk lirası. Paketini istediğin zaman değiştirirsin.', 'Prices are monthly, in Turkish lira. Change your plan at any time.') }}</p>
                </div>
                <div class="mt-10">
                    @include('site.partials.plans', ['plans' => $plans, 'isLoggedIn' => $isLoggedIn, 'lang' => $lang ?? 'tr'])
                </div>
                <p class="mt-8 text-center">
                    <a href="{{ $en ? route('site.en.pricing') : route('site.pricing') }}" class="plain-link">{{ $t('Paketleri ayrıntılı karşılaştır', 'Compare plans in detail') }}</a>
                </p>
            </div>
        </section>
    @endif

    {{-- ═══════════════════════════════════════ SSS (kısa; tamamı /sss) --}}
    <section id="sss" aria-labelledby="sss-baslik" class="section">
        <div class="wrap-narrow">
            <div class="section-head section-head--center">
                <h2 id="sss-baslik" class="heading-2">{{ $t('Sık sorulanlar', 'Frequently asked questions') }}</h2>
                <p class="muted">
                    @unless ($en)
                        <a href="{{ route('site.faq') }}" class="plain-link">Bütün sorular</a> ·
                    @endunless
                    {{ $t('Cevabını bulamadın mı?', 'Didn\'t find your answer?') }} <a href="{{ route('site.contact') }}" class="plain-link">{{ $t('Bize yaz.', 'Write to us.') }}</a>
                </p>
            </div>
            <div class="mt-10">
                @include('site.partials.faq', ['faqs' => $faqs, 'openFirst' => true])
            </div>
        </div>
    </section>

    @include('site.partials.cta', [
        'lang' => $lang ?? 'tr',
        'ctaTitle' => $freePlan ? $t('Ücretsiz paketle bugün başla.', 'Start today on the free plan.') : null,
    ])
@endsection
