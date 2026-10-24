{{--
    /fiyatlar (TR) ve /en/pricing (EN) — paketler `$plans`'tan (plans tablosu); elle fiyat yok.

    KDV hakkında BİLEREK bir şey yazılmaz (kullanıcı kararı): vergi
    durumu kesinleşmeden "KDV dahil/hariç" demek yanlış bilgi olurdu.

    Kota gerçeği (QuotaMetric, 24 Ekim 2026): paketler ürün, kanal,
    kullanıcı ve aylık e-fatura sayısıyla ayrılır; AI öneri hakkı yalnız
    özellik açıkken sayılır ve gösterilir. Özellikler bütün paketlerde
    aynıdır; tek fark kurulum yardımı ve iade (ücretli paketler).
    "Kanal sayısına göre kısıtlama yok" DENMEZ: paketlerin kanal sınırı var.

    YALNIZ DOĞRU OLAN YAZILIR (24 Ekim 2026 ayrıntılı sayfa):
      - Destek, kurulum ve iade: kullanıcı kararı, `config/site.php`.
      - Kanala özgü satırlar adaptör yeteneğinden doğrulandı (soru-cevap
        ve ortak etiket yalnız Trendyol; iade kaydı Trendyol + Hepsiburada).
      - Abonelik bitince: kiracı ücretsiz pakete düşer (EnforceQuota
        DEFAULT_PLAN_CODE), veri silinmez, stok/sipariş akışına kota dokunmaz.
      - Sunucu IONOS Almanya (whois DE), gece 03:30 pg_dump, 14 gün saklama
        (/etc/cron.d/34pazar-yedek) — ölçüldü 24 Ekim 2026.
      - Çalışma süresi yüzdesi, müşteri sayısı, logo, yorum YAZILMAZ: ölçülmüş
        veri yok. Rakip adı geçmez.
--}}
@extends('site.layout')

@php
    $en = ($lang ?? 'tr') === 'en';
    $t = fn (string $tr, string $enText): string => $en ? $enText : $tr;

    $freePlan = collect($plans)->first(fn ($p) => (float) $p['priceMonthly'] === 0.0);
    $limit = fn (?int $v, string $unit): string => $v === null ? $t('sınırsız', 'unlimited')." {$unit}" : number_format($v, 0, ',', '.')." {$unit}";
    $count = fn (?int $v): string => $v === null ? $t('Sınırsız', 'Unlimited') : number_format($v, 0, ',', '.');
    $price = function (array $p) use ($t): string {
        $amount = (float) $p['priceMonthly'];
        if ($amount === 0.0) {
            return $t('Ücretsiz', 'Free');
        }
        $formatted = number_format($amount, floor($amount) === $amount ? 0 : 2, ',', '.');

        return ($p['currency'] ?? 'TRY') === 'TRY' ? $formatted.' ₺' : $formatted.' '.$p['currency'];
    };
    $isFree = fn (array $p): bool => (float) $p['priceMonthly'] === 0.0;

    $anyInvoice = collect($plans)->contains(fn ($p) => $p['hasInvoiceLimit'] ?? false);
    $anyUsers = collect($plans)->contains(fn ($p) => $p['hasUserLimit'] ?? false);
    $anyAi = collect($plans)->contains(fn ($p) => $p['showAi'] ?? false);

    $available = array_values(array_filter($channels, fn ($c) => $c['available']));

    $hours = config('site.support_hours');
    $refundDays = (int) config('site.refund_days');
    $email = config('site.contact_email');
    $waUrl = 'https://wa.me/'.config('site.whatsapp');
    $waDisplay = config('site.whatsapp_display');
    $privacyUrl = route('site.legal', $en ? 'privacy' : 'gizlilik');

    // Ayrıntılı karşılaştırma. `null` = her pakette var; 'paid' = yalnız ücretli paketlerde.
    $groups = [
        $t('Stok ve senkron', 'Stock and sync') => [
            [$t('Tek stok: bir kanalda satılınca bütün kanallarda düşer', 'One stock count: a sale on one channel lowers it on all of them'), null],
            [$t('Kanal kontrolü: kanaldaki stok ve fiyat farkını bulur, onarır', 'Channel check: finds and repairs stock and price drift on the channel'), null],
            [$t('Sipariş sayısına sınır yok', 'No limit on orders'), null],
        ],
        $t('Sipariş', 'Orders') => [
            [$t('Bütün kanalların siparişi tek listede', 'Orders from every channel in one list'), null],
            [$t('Toplu işlem ve toplama listesi', 'Bulk actions and picking list'), null],
            [$t('Trendyol ortak kargo etiketi', 'Trendyol shared shipping label'), null],
            [$t('İade takibi (Trendyol, Hepsiburada)', 'Return tracking (Trendyol, Hepsiburada)'), null],
            [$t('Müşteri sorularını panelden cevaplama (Trendyol)', 'Answer customer questions from the panel (Trendyol)'), null],
        ],
        $t('Fiyat ve kampanya', 'Prices and campaigns') => [
            [$t('Kanal başına fiyat kuralı ve yuvarlama', 'Per-channel price rules and rounding'), null],
            [$t('Zarar koruması: maliyetin altındaki fiyat kanala gitmez', 'Loss guard: a price below cost is never sent'), null],
            [$t('Süreli kampanyalar', 'Scheduled campaigns'), null],
            [$t('Otomatik kur çevirisi (TCMB kuru, yurt dışı kanallar)', 'Automatic currency conversion (CBRT rate, international channels)'), null],
        ],
        $t('Ürün ve listeleme', 'Products and listings') => array_values(array_filter([
            [$t('Kanaldan ve CSV dosyasından ürün içe aktarma', 'Import products from a channel or a CSV file'), null],
            [$t('Set / paket ürün: stok içindeki ürünlerden hesaplanır, satışta onlardan düşer', 'Bundles: stock calculated from the products inside, deducted from them on sale'), null],
            [$t('Excel ile toplu fiyat ve stok düzenleme', 'Bulk price and stock editing in Excel'), null],
            [$t('Tedarikçi XML listesinden ürün, stok ve fiyat', 'Products, stock and prices from a supplier XML feed'), null],
            [$t('Pazaryerine ürün gönderme, kategori eşleme ve onay takibi', 'Publish products, map categories and track approval'), null],
            $anyAi ? [$t('AI ile başlık, açıklama, öznitelik ve kategori önerisi', 'AI suggestions for titles, descriptions, attributes and categories'), null] : null,
        ])),
        $t('Kargo', 'Shipping') => [
            [$t('Takip numarasını kanala bildirme (destekleyen kanallarda)', 'Send tracking numbers to the channel (where supported)'), null],
            [$t('Yurtiçi ve Aras gönderisi, kendi sözleşmenle (WooCommerce siparişlerinde)', 'Yurtiçi and Aras shipments on your own contract (WooCommerce orders)'), null],
        ],
        $t('E-fatura', 'E-invoices') => [
            [$t('Kendi Nilvera hesabınla e-fatura ve e-arşiv', 'E-invoices and e-archive invoices with your own Nilvera account'), null],
            [$t('Fatura bilgisi Trendyol, Hepsiburada, n11 ve Shopify siparişinden okunur', 'Billing details read from Trendyol, Hepsiburada, n11 and Shopify orders'), null],
        ],
        $t('Ekip ve roller', 'Team and roles') => [
            [$t('Roller: sahip, yönetici, depo, salt okunur', 'Roles: owner, admin, warehouse, read-only'), null],
            [$t('E-postayla ekip daveti', 'Invite your team by email'), null],
        ],
        $t('Raporlar', 'Reports') => [
            [$t('Sipariş başına kâr ve katkı raporu', 'Profit and contribution per order'), null],
            [$t('Fazla satış koruması raporu', 'Overselling protection report'), null],
        ],
        $t('Destek ve kurulum', 'Support and setup') => [
            [$t("WhatsApp ve e-posta destek, hafta sonu dahil her gün {$hours}", "WhatsApp and email support, every day including weekends, {$hours} (Turkey time)"), null],
            [$t('Her kanal için bağlama rehberi', 'A connection guide for every channel'), null],
            [$t('Kurulum ve ilk ürün aktarımı birlikte, ücretsiz', 'Free setup and first product import, done together'), 'paid'],
            [$t("İlk ödemeden {$refundDays} gün içinde tam iade", "Full refund within {$refundDays} days of your first payment"), 'paid'],
        ],
        $t('Güvenlik ve veri', 'Security and data') => [
            [$t("Sunucular Almanya'da (IONOS), veritabanı her gece yedeklenir", 'Servers in Germany (IONOS), database backed up every night'), null],
            [$t('Fiyat ve stok listesini Excel olarak indirme', 'Download your price and stock list as Excel'), null],
            [$t('Abonelik bitince verilerin silinmez', 'Your data is not deleted when a subscription ends'), null],
        ],
    ];

    $trust = [
        ['mail', $t('Her gün destek', 'Support every day'), $t("WhatsApp ve e-posta, hafta sonu dahil her gün {$hours} (Türkiye saati). Kampanya günlerinde de buradayız.", "WhatsApp and email, every day including weekends, {$hours} Turkey time. Campaign days included.")],
        ['link', $t('Kurulumu birlikte yaparız', 'We set it up with you'), $t('Ücretli paketlerde ilk kanalı birlikte bağlar, ilk ürün aktarımını birlikte yaparız. Ayrı kurulum ücreti yok.', 'On paid plans we connect your first channel and run the first product import together. No setup fee.')],
        ['calendar', $t("{$refundDays} gün içinde tam iade", "Full refund within {$refundDays} days"), $t("İlk ödemeden sonraki {$refundDays} gün içinde vazgeçersen ödediğinin tamamını iade ederiz. Sonrasında istediğin zaman iptal edersin; ödediğin dönemin sonuna kadar kullanırsın.", "Change your mind within {$refundDays} days of your first payment and we refund it in full. After that, cancel any time and keep using it until the end of the paid period.")],
        ['price', $t('Aylık, taahhütsüz', 'Monthly, no contract'), $t('Yıllık peşin lisans istemiyoruz. Ücretli paketler aylık abonelikle çalışır; paketini istediğin ay büyütür ya da küçültürsün.', 'No annual upfront licence. Paid plans are monthly subscriptions; move up or down whenever you like.')],
        ['stock', $t('Veri senin', 'Your data stays yours'), $t('Fiyat ve stok listeni Excel olarak indirebilirsin. Abonelik biterse hesabın silinmez, ücretsiz pakete geçer; siparişler ve stok akışı durmaz.', 'Download your price and stock list as Excel. If a subscription ends, your account is not deleted but moves to the free plan; orders and stock keep flowing.')],
        ['shield', $t("Almanya'da barındırma", 'Hosted in Germany'), $t('Sunucular IONOS üzerinde, Almanya\'da. Veritabanı her gece yedeklenir, yedekler 14 gün saklanır. Kişisel veriler KVKK ve GDPR\'a göre işlenir.', 'Servers run on IONOS in Germany. The database is backed up every night and kept for 14 days. Personal data is processed under KVKK and GDPR.')],
    ];

    $faqs = [];
    if ($freePlan) {
        $faqs[] = ['q' => $t('Kart bilgisi gerekiyor mu?', 'Do I need a card?'), 'a' => $t("{$freePlan['name']} paketle başlarken hayır. Kayıt formu ödeme bilgisi sormaz; {$limit($freePlan['productLimit'], 'ürün')} ve {$limit($freePlan['channelLimit'], 'kanal')} ile deneyebilirsin.", "Not on the free plan. Sign-up asks for no payment details; try it with {$limit($freePlan['productLimit'], 'products')} and {$limit($freePlan['channelLimit'], 'channel')}.")];
    }
    $faqs[] = ['q' => $t('Fiyatlar aylık mı? Yıllık ödemek zorunda mıyım?', 'Are prices monthly? Do I have to pay yearly?'), 'a' => $t('Sayfadaki bütün fiyatlar aylıktır ve ücretli paketler aylık abonelikle çalışır. Yıllık peşin ödeme istenmez.', 'All prices on this page are monthly and paid plans are monthly subscriptions. No annual prepayment.')];
    $faqs[] = ['q' => $t('Memnun kalmazsam paramı geri alabilir miyim?', 'Can I get my money back?'), 'a' => $t("Evet. İlk ücretli ödemenden sonraki {$refundDays} gün içinde {$email} adresine ya da WhatsApp'tan yazman yeter; ödediğin tutarın tamamını iade ederiz; paketin hemen ücretsiz plana geçer, verilerin silinmez. Bu güvence hesap başına bir kez, ilk ücretli abonelikte geçerlidir; sonrasında iade yerine iptal geçerlidir.", "Yes. Within {$refundDays} days of your first paid payment, write to {$email} or on WhatsApp and we refund the full amount; your account moves to the free plan right away and nothing is deleted. The guarantee applies once per account, on the first paid subscription; after that, cancellation applies instead of a refund.")];
    $faqs[] = ['q' => $t('Kartım reddedildi, ne yapmalıyım?', 'My card was declined. What should I do?'), 'a' => $t("Ödeme Almanya'daki hizmet sağlayıcımız üzerinden alınır; Türk bankaları bunu yurt dışı alışveriş sayar ve çoğu kartta bu izin kapalı gelir. Bankanın mobil uygulamasından kartının yurt dışı ve internet alışverişi iznini açıp tekrar dene. Sorun sürerse WhatsApp'tan yaz.", "Payments are taken through our provider in Germany, which Turkish banks treat as a foreign purchase; on many cards this is turned off by default. Turn on foreign and online purchases for your card in your bank's app and try again. If it still fails, message us on WhatsApp.")];
    $faqs[] = ['q' => $t('Aboneliğimi iptal edebilir miyim?', 'Can I cancel?'), 'a' => $t('Evet, istediğin zaman: panelde Abonelik → Ücretsiz plana geç. İptal, ödemesi yapılmış dönemin sonunda geçerli olur; o tarihe kadar paketini kullanmaya devam edersin, verilerin silinmez.', 'Yes, any time: in the panel go to Subscription → Switch to free. Cancellation takes effect at the end of the paid period; you keep your plan until then and nothing is deleted.')];
    $faqs[] = ['q' => $t('İptal edersem verilerime ne olur?', 'What happens to my data if I cancel?'), 'a' => $t('Hesabın silinmez, ücretsiz pakete geçer. Ürünlerin, siparişlerin ve ayarların durur; stok ve sipariş akışı kesilmez. Ücretsiz paketin sınırını aşan yeni ekleme yapılamaz. Hesabın tamamen kapatılmasını istersen bize yazman yeter.', 'Your account is not deleted; it moves to the free plan. Products, orders and settings stay, and stock and orders keep flowing. New additions beyond the free plan\'s limits are blocked. Write to us if you want the account closed entirely.')];
    $faqs[] = ['q' => $t('Kurulum için ayrı ücret var mı?', 'Is there a setup fee?'), 'a' => $t('Hayır. Ücretli paketlerde ilk kanalı bağlamayı ve ilk ürün aktarımını seninle birlikte, ücretsiz yaparız. Ücretsiz pakette bağlama rehberleri ve destek her zaman açık.', 'No. On paid plans we connect your first channel and run the first product import with you, free of charge. On the free plan the connection guides and support are always available.')];
    $faqs[] = ['q' => $t('Destek ne zaman ulaşılabilir?', 'When is support available?'), 'a' => $t("Hafta sonu dahil her gün {$hours} (Türkiye saati), WhatsApp ({$waDisplay}) ve e-postayla ({$email}).", "Every day including weekends, {$hours} Turkey time, on WhatsApp ({$waDisplay}) and email ({$email}).")];
    $faqs[] = ['q' => $t('Pazaryeri başına ek ücret var mı?', 'Do you charge per marketplace?'), 'a' => $t('Hayır. Bütün kanallar her pakette açık. Paket yalnız bağlayabileceğin mağaza sayısını belirler; hangi pazaryeri olduğu fark etmez.', 'No. Every channel is open on every plan. The plan only sets how many stores you can connect, whichever marketplaces they are.')];
    $faqs[] = ['q' => $t('Ürün ve kanal sayısı neye göre hesaplanır?', 'How are products and channels counted?'), 'a' => $t('Kataloğundaki her ürün bir kez sayılır; aynı ürün kaç kanalda satılırsa satılsın. Bağladığın her mağaza bir kanaldır; iki ayrı Trendyol mağazası iki kanal sayılır.', 'Each product in your catalogue counts once, however many channels it sells on. Each connected store is one channel; two Trendyol stores count as two.')];
    $faqs[] = ['q' => $t('Sınıra gelince ne olur?', 'What happens at a limit?'), 'a' => $t('Yeni ürün ekleyemez ya da yeni kanal bağlayamazsın; panel paketini yükseltmeni söyler. Sınır yeni ekleme anında uygulanır, mevcut stok ve sipariş akışı durmaz.', 'You cannot add new products or connect new channels; the panel asks you to upgrade. The limit applies when adding, and existing stock and orders keep flowing.')];
    if ($anyUsers) {
        $faqs[] = ['q' => $t('Kullanıcı sayısı neye göre hesaplanır?', 'How are users counted?'), 'a' => $t('Hesap sahibi dahil, ekipteki her kişi bir kullanıcıdır. Gönderilmiş ama henüz kabul edilmemiş davet de yer tutar.', 'Everyone on the team, including the owner, is a user. A sent but not yet accepted invite also takes a seat.')];
    }

    $pricingTr = route('site.pricing');
    $pricingEn = route('site.en.pricing');
@endphp

@section('lang', $en ? 'en' : 'tr')
@section('og_locale', $en ? 'en_US' : 'tr_TR')
@section('title', $t('Fiyatlar: aylık paketler, bütün kanallar her pakette', 'Pricing: monthly plans, every channel on every plan'))
@section('description', $t('Aylık ödeme, taahhüt yok. Bütün kanallar her pakette; ücretli paketlerde ücretsiz kurulum ve 14 gün iade. Her gün WhatsApp ve e-posta destek.', 'Monthly billing, no contract. Every channel on every plan; free setup and a 14-day refund on paid plans. WhatsApp and email support every day.'))

@push('jsonld')
    <link rel="alternate" hreflang="tr" href="{{ $pricingTr }}">
    <link rel="alternate" hreflang="en" href="{{ $pricingEn }}">
    <link rel="alternate" hreflang="x-default" href="{{ $pricingTr }}">
    @include('site.partials.seo.software', ['plans' => $plans])
    @include('site.partials.seo.faq', ['faqs' => $faqs])
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => $t('Ana sayfa', 'Home'), 'url' => $en ? route('site.en.home') : route('home')],
        ['name' => $t('Fiyatlar', 'Pricing'), 'url' => $en ? $pricingEn : $pricingTr],
    ]])
@endpush

@section('content')
    <section class="bg-soft" aria-labelledby="fiyat-h1">
        <div class="wrap pt-12 pb-10 text-center lg:pt-16">
            <p class="eyebrow">{{ $t('Fiyatlar', 'Pricing') }}</p>
            <h1 id="fiyat-h1" class="h-page mx-auto mt-3 max-w-[24ch]">{{ $t('Aylık paketler. Bütün kanallar her pakette.', 'Monthly plans. Every channel on every plan.') }}</h1>
            <p class="lead mx-auto mt-5 max-w-[56ch]">{{ $t('Paketler ürün, kanal, kullanıcı ve e-fatura sayısıyla ayrılır. Pazaryeri seçimine göre paket yok; bütün özellikler her pakette açık.', 'Plans differ by products, channels, users and e-invoices. No marketplace tiers; every feature is on every plan.') }}</p>
            <ul class="mt-6 flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm muted">
                <li class="flex items-center gap-1.5"><span class="text-ok" aria-hidden="true">✓</span> {{ $t('Aylık ödeme, yıllık peşin yok', 'Monthly billing, no annual prepayment') }}</li>
                <li class="flex items-center gap-1.5"><span class="text-ok" aria-hidden="true">✓</span> {{ $t("{$refundDays} gün içinde tam iade", "{$refundDays}-day full refund") }}</li>
                <li class="flex items-center gap-1.5"><span class="text-ok" aria-hidden="true">✓</span> {{ $t('Ücretsiz kurulum desteği', 'Free setup help') }}</li>
                <li class="flex items-center gap-1.5"><span class="text-ok" aria-hidden="true">✓</span> {{ $t('Her gün WhatsApp destek', 'WhatsApp support every day') }}</li>
                @if ($freePlan)
                    <li class="flex items-center gap-1.5"><span class="text-ok" aria-hidden="true">✓</span> {{ $t('Ücretsiz paket, kart bilgisi gerekmez', 'Free plan, no card needed') }}</li>
                @endif
            </ul>
        </div>
        <div class="wrap pb-16 lg:pb-20" aria-label="{{ $t('Paketler', 'Plans') }}">
            @include('site.partials.plans', ['plans' => $plans, 'isLoggedIn' => $isLoggedIn, 'lang' => $lang ?? 'tr'])
            <p class="mt-6 text-center text-sm muted">{{ $t('Ödeme Stripe üzerinden kartla alınır; Shopify uygulamasından gelen satıcılarda Shopify faturasına eklenir.', 'Payment is taken by card via Stripe; for sellers who come through the Shopify app it is added to the Shopify bill.') }}</p>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ BÜTÜN KANALLAR --}}
    @if (count($available))
        <section class="section" aria-labelledby="kanallar-h2">
            <div class="wrap">
                <div class="section-head">
                    <h2 id="kanallar-h2" class="heading-2">{{ $t('Bütün kanallar, her pakette', 'Every channel, on every plan') }}</h2>
                    <p class="lead">{{ $t('Pazaryeri sayısına göre paket yok. Hangi paketi seçersen seç aşağıdaki kanalların hepsi açık; paket yalnız bağlayacağın mağaza sayısını belirler.', 'No marketplace tiers. Whichever plan you choose, all of these channels are open; the plan only sets how many stores you connect.') }}</p>
                </div>
                <div class="mt-8">
                    @include('site.partials.channel-list', ['channels' => $available, 'columns' => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5', 'lang' => $lang ?? 'tr'])
                </div>
            </div>
        </section>
    @endif

    {{-- ═══════════════════════════════════════ AYRINTILI KARŞILAŞTIRMA --}}
    @if (count($plans))
        <section class="section bg-soft" aria-labelledby="karsilastir-h2">
            <div class="wrap">
                <div class="section-head">
                    <h2 id="karsilastir-h2" class="heading-2">{{ $t('Paketleri ayrıntılı karşılaştır', 'Compare plans in detail') }}</h2>
                    <p class="lead">{{ $t('Fark sayı satırlarında ve ücretli paketlerdeki kurulum ile iadede. Geri kalan her şey bütün paketlerde aynı.', 'The difference is in the number rows, plus setup help and refunds on paid plans. Everything else is the same on every plan.') }}</p>
                </div>
                <div class="relative mt-8 overflow-x-auto rounded-xl border border-line bg-white" tabindex="0" role="region" aria-labelledby="karsilastir-h2">
                    <table class="compare">
                        <caption class="sr-only">{{ $t('Paketlerin aylık fiyatı, sınırları ve özellikleri', 'Monthly price, limits and features of each plan') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="text-left">{{ $t('Paket', 'Plan') }}</th>
                                @foreach ($plans as $plan)
                                    <th scope="col" class="{{ $plan['code'] === 'pro' ? 'is-hi' : '' }}">{{ $plan['name'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <th scope="row">{{ $t('Aylık fiyat', 'Monthly price') }}</th>
                                @foreach ($plans as $plan)
                                    <td class="font-semibold tabular-nums {{ $plan['code'] === 'pro' ? 'is-hi' : '' }}">{{ $price($plan) }}</td>
                                @endforeach
                            </tr>
                            <tr>
                                <th scope="row">{{ $t('Ürün', 'Products') }}</th>
                                @foreach ($plans as $plan)
                                    <td class="font-semibold tabular-nums {{ $plan['code'] === 'pro' ? 'is-hi' : '' }}">{{ $count($plan['productLimit']) }}</td>
                                @endforeach
                            </tr>
                            <tr>
                                <th scope="row">{{ $t('Kanal (mağaza)', 'Channels (stores)') }}</th>
                                @foreach ($plans as $plan)
                                    <td class="font-semibold tabular-nums {{ $plan['code'] === 'pro' ? 'is-hi' : '' }}">{{ $count($plan['channelLimit']) }}</td>
                                @endforeach
                            </tr>
                            @if ($anyUsers)
                                <tr>
                                    <th scope="row">{{ $t('Kullanıcı', 'Users') }}</th>
                                    @foreach ($plans as $plan)
                                        <td class="font-semibold tabular-nums {{ $plan['code'] === 'pro' ? 'is-hi' : '' }}">{{ ($plan['hasUserLimit'] ?? false) ? $count($plan['userLimit']) : '—' }}</td>
                                    @endforeach
                                </tr>
                            @endif
                            @if ($anyInvoice)
                                <tr>
                                    <th scope="row">{{ $t('E-fatura / ay (Nilvera)', 'E-invoices / month (Nilvera)') }}</th>
                                    @foreach ($plans as $plan)
                                        <td class="font-semibold tabular-nums {{ $plan['code'] === 'pro' ? 'is-hi' : '' }}">{{ ($plan['hasInvoiceLimit'] ?? false) ? $count($plan['invoiceLimit']) : '—' }}</td>
                                    @endforeach
                                </tr>
                            @endif
                            @if ($anyAi)
                                <tr>
                                    <th scope="row">{{ $t('AI önerisi / ay', 'AI suggestions / month') }}</th>
                                    @foreach ($plans as $plan)
                                        <td class="font-semibold tabular-nums {{ $plan['code'] === 'pro' ? 'is-hi' : '' }}">{{ ($plan['showAi'] ?? false) ? $count($plan['aiLimit']) : '—' }}</td>
                                    @endforeach
                                </tr>
                            @endif
                            @foreach ($groups as $group => $rows)
                                <tr class="compare-group">
                                    <th scope="colgroup" colspan="{{ count($plans) + 1 }}">{{ $group }}</th>
                                </tr>
                                @foreach ($rows as [$feature, $scope])
                                    <tr>
                                        <th scope="row">{{ $feature }}</th>
                                        @foreach ($plans as $plan)
                                            @php $has = $scope === null || ! $isFree($plan); @endphp
                                            <td class="{{ $plan['code'] === 'pro' ? 'is-hi' : '' }}">
                                                @if ($has)
                                                    <span class="text-ok" aria-hidden="true">✓</span><span class="sr-only">{{ $t('Var', 'Included') }}</span>
                                                @else
                                                    <span class="muted" aria-hidden="true">—</span><span class="sr-only">{{ $t('Yok', 'Not included') }}</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif

    {{-- ═══════════════════════════════════════ GÜVEN --}}
    <section class="section" aria-labelledby="guven-h2">
        <div class="wrap">
            <div class="section-head">
                <h2 id="guven-h2" class="heading-2">{{ $t('Yanında olduğumuzu bil', 'What you can count on') }}</h2>
                <p class="lead">{{ $t('Yıllık peşin lisans yerine aylık abonelik; ücretli pakette kurulum ve iade güvencesi.', 'A monthly subscription instead of an annual upfront licence, with setup help and a refund promise on paid plans.') }}</p>
            </div>
            <ul class="mt-8 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($trust as [$icon, $title, $desc])
                    <li class="card">
                        <span class="icon-box">@include('site.partials.icon', ['name' => $icon])</span>
                        <h3 class="heading-3 mt-4">{{ $title }}</h3>
                        <p class="mt-2 muted">{{ $desc }}</p>
                    </li>
                @endforeach
            </ul>

            <div class="mt-10 grid gap-6 rounded-xl border border-line p-6 md:grid-cols-3">
                <div>
                    <h3 class="heading-3">{{ $t('Destek', 'Support') }}</h3>
                    <p class="mt-2 muted">{{ $t("Her gün {$hours} (Türkiye saati)", "Daily {$hours} (Turkey time)") }}</p>
                    <p class="mt-3"><a href="{{ $waUrl }}" class="link" rel="noopener">WhatsApp: {{ $waDisplay }}</a></p>
                    <p class="mt-1"><a href="mailto:{{ $email }}" class="link">{{ $email }}</a></p>
                </div>
                <div>
                    <h3 class="heading-3">{{ $t('Şirket', 'Company') }}</h3>
                    <p class="mt-2 muted">{{ $t('34Pazar, 34Devs tarafından geliştirilir ve işletilir. 34Devs bir Shopify Partner ajansıdır.', '34Pazar is built and run by 34Devs, a Shopify Partner agency.') }}</p>
                    <p class="mt-2 muted">{{ config('site.street') }}, {{ config('site.postal_code') }} {{ config('site.city') }}, {{ $t('Almanya', 'Germany') }}</p>
                </div>
                <div>
                    <h3 class="heading-3">{{ $t('Gizlilik ve sözleşmeler', 'Privacy and terms') }}</h3>
                    <ul class="mt-2 grid gap-1">
                        <li><a href="{{ $privacyUrl }}" class="link">{{ $t('Gizlilik ve KVKK aydınlatma metni', 'Privacy notice (GDPR and KVKK)') }}</a></li>
                        <li><a href="{{ route('site.legal', 'kullanim-kosullari') }}" class="link">{{ $t('Kullanım koşulları', 'Terms of use (Turkish)') }}</a></li>
                        <li><a href="{{ route('site.legal', 'mesafeli-satis') }}" class="link">{{ $t('Mesafeli hizmet sözleşmesi', 'Distance service contract (Turkish)') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- Ne sayılır — sürpriz olmasın diye düz cümlelerle. --}}
    <section class="section bg-soft" aria-labelledby="sayim-h2">
        <div class="wrap">
            <h2 id="sayim-h2" class="heading-2">{{ $t('Ne sayılır, ne sayılmaz?', 'What counts towards a limit?') }}</h2>
            <dl class="mt-8 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                @foreach (array_filter([
                    [$t('Ürün', 'Products'), $t('Kataloğundaki her ürün bir kez sayılır. Üç kanalda satılan ürün yine bir üründür.', 'Each product counts once. A product sold on three channels is still one product.')],
                    [$t('Kanal', 'Channels'), $t('Bağladığın her mağaza bir kanaldır. Aynı pazaryerinde iki mağazan varsa iki kanal sayılır.', 'Each connected store is a channel. Two stores on the same marketplace count as two.')],
                    $anyUsers ? [$t('Kullanıcı', 'Users'), $t('Hesap sahibi dahil ekipteki her kişi. Kabul edilmemiş davet de yer tutar.', 'Everyone on the team, owner included. A pending invite also takes a seat.')] : null,
                    [$t('Sipariş', 'Orders'), $t('Paketlerde sipariş sayısına bağlı bir sınır yok.', 'There is no limit on the number of orders.')],
                ]) as [$term, $desc])
                    <div class="card">
                        <dt class="heading-3">{{ $term }}</dt>
                        <dd class="mt-2 muted">{{ $desc }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    <section class="section" aria-labelledby="fiyat-sss">
        <div class="wrap grid gap-10 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <h2 id="fiyat-sss" class="heading-2">{{ $t('Fiyat soruları', 'Pricing questions') }}</h2>
                <p class="mt-4 muted">{{ $t('Başka bir sorun mu var?', 'Something else?') }} <a href="{{ $waUrl }}" class="link" rel="noopener">{{ $t("WhatsApp'tan yaz", 'Message us on WhatsApp') }}</a> {{ $t('ya da', 'or') }} <a href="mailto:{{ $email }}" class="link">{{ $t('e-posta gönder', 'send an email') }}</a>.</p>
            </div>
            <div class="lg:col-span-8">
                @include('site.partials.faq', ['faqs' => $faqs])
            </div>
        </div>
    </section>

    @include('site.partials.cta', ['lang' => $lang ?? 'tr'])
@endsection
