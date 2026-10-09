{{--
    /fiyatlar — paketler `$plans`'tan (plans tablosu); elle fiyat yok.

    KDV hakkında BİLEREK bir şey yazılmaz (kullanıcı kararı): vergi
    durumu kesinleşmeden "KDV dahil/hariç" demek yanlış bilgi olurdu.

    Kota gerçeği (QuotaMetric): paketler YALNIZ ürün sayısı ve kanal
    bağlantısı sayısıyla ayrılır. Sınır yeni ekleme anında uygulanır
    (ürün ekleme, içe aktarma, kanal bağlama) — sayfa da bunu söyler.
    "Kanal sayısına göre kısıtlama yok" DENMEZ: paketlerin kanal sınırı var.

    Ödeme gerçeği: abonelik aylıktır (Stripe `interval=month`, Shopify
    `EVERY_30_DAYS`), iptal her zaman yapılabilir ve dönem sonunda geçerli
    olur (kullanım koşulları · iptal maddesi). Rakip adı geçmez.
--}}
@extends('site.layout')

@php
    $freePlan = collect($plans)->first(fn ($p) => (float) $p['priceMonthly'] === 0.0);
    $limit = fn (?int $v, string $unit): string => $v === null ? "sınırsız {$unit}" : number_format($v, 0, ',', '.')." {$unit}";
    $count = fn (?int $v): string => $v === null ? 'Sınırsız' : number_format($v, 0, ',', '.');
    $price = function (array $p): string {
        $amount = (float) $p['priceMonthly'];
        if ($amount === 0.0) {
            return 'Ücretsiz';
        }
        $formatted = number_format($amount, floor($amount) === $amount ? 0 : 2, ',', '.');

        return ($p['currency'] ?? 'TRY') === 'TRY' ? $formatted.' ₺' : $formatted.' '.$p['currency'];
    };

    // Bütün paketlerde aynı olan özellikler (paket farkı yalnız ürün/kanal sayısı).
    $included = [
        'Tek stok ve kanallara stok gönderimi',
        'Bütün kanalların siparişi tek listede',
        'Kanal başına fiyat ve fiyat kuralları',
        'Zarar koruması',
        'Süreli kampanyalar',
        'Kargo takip numarası bildirimi (destekleyen kanallarda)',
        'Toplu ürün aktarımı (CSV ve kanaldan)',
        'Sipariş sayısına sınır yok',
    ];

    $faqs = [];
    if ($freePlan) {
        $faqs[] = ['q' => 'Kart bilgisi gerekiyor mu?', 'a' => "{$freePlan['name']} paketle başlarken hayır. Kayıt formu ödeme bilgisi sormaz; {$limit($freePlan['productLimit'], 'ürün')} ve {$limit($freePlan['channelLimit'], 'kanal')} ile deneyebilirsin."];
    }
    $faqs[] = ['q' => 'Fiyatlar aylık mı? Yıllık ödemek zorunda mıyım?', 'a' => 'Sayfadaki bütün fiyatlar aylıktır ve ücretli paketler aylık abonelikle çalışır. Yıllık peşin ödeme istenmez.'];
    $faqs[] = ['q' => 'Aboneliğimi iptal edebilir miyim?', 'a' => 'Evet, istediğin zaman. İptal, ödemesi yapılmış dönemin sonunda geçerli olur; o tarihe kadar paketini kullanmaya devam edersin.'];
    $faqs[] = ['q' => 'Ürün sayısı neye göre hesaplanır?', 'a' => 'Kataloğundaki ürün sayısına göre. Aynı ürün kaç kanalda satılırsa satılsın bir kez sayılır.'];
    $faqs[] = ['q' => 'Kanal sayısı neye göre hesaplanır?', 'a' => 'Bağladığın her mağaza bir kanaldır. İki ayrı Trendyol mağazası bağlarsan iki kanal sayılır.'];
    $faqs[] = ['q' => 'Sınıra gelince ne olur?', 'a' => 'Yeni ürün ekleyemez ya da yeni kanal bağlayamazsın; panel paketini yükseltmeni söyler. Sınır yeni ekleme anında uygulanır.'];
    $faqs[] = ['q' => 'Paketler arasında özellik farkı var mı?', 'a' => 'Hayır. Paketler yalnız ürün ve kanal sayısıyla ayrılır. Stok, sipariş, fiyat kuralları, kampanyalar ve kargo ekranları hepsinde aynı.'];
@endphp

@section('title', 'Fiyatlar: ürün ve kanal sayına göre aylık paketler')
@section('description', 'Paketler ürün ve kanal sayısıyla ayrılır; fiyatlar aylıktır. Ücretsiz paketle kart bilgisi girmeden başla, büyüdükçe yükselt.')

@push('jsonld')
    @include('site.partials.seo.faq', ['faqs' => $faqs])
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Fiyatlar', 'url' => route('site.pricing')],
    ]])
@endpush

@section('content')
    <section class="bg-soft" aria-labelledby="fiyat-h1">
        <div class="wrap pt-12 pb-10 text-center lg:pt-16">
            <p class="eyebrow">Fiyatlar</p>
            <h1 id="fiyat-h1" class="h-page mx-auto mt-3 max-w-[22ch]">Ürün ve kanal sayına göre, sade paketler.</h1>
            <p class="lead mx-auto mt-5 max-w-[52ch]">Paketler yalnız ürün ve kanal sayısıyla ayrılır; bütün özellikler her pakette var.</p>
            <p class="mt-4 font-semibold">Fiyatlar aylıktır.</p>
            <ul class="mt-6 flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm muted">
                <li class="flex items-center gap-1.5"><span class="text-ok" aria-hidden="true">✓</span> Yıllık peşin ödeme yok</li>
                <li class="flex items-center gap-1.5"><span class="text-ok" aria-hidden="true">✓</span> İstediğin zaman iptal</li>
                @if ($freePlan)
                    <li class="flex items-center gap-1.5"><span class="text-ok" aria-hidden="true">✓</span> Ücretsiz paket, kart bilgisi gerekmez</li>
                @endif
            </ul>
        </div>
        <div class="wrap pb-16 lg:pb-20" aria-label="Paketler">
            @include('site.partials.plans', ['plans' => $plans, 'isLoggedIn' => $isLoggedIn])
        </div>
    </section>

    {{-- ═══════════════════════════════════════ KARŞILAŞTIRMA TABLOSU --}}
    @if (count($plans))
        <section class="section" aria-labelledby="karsilastir-h2">
            <div class="wrap">
                <div class="section-head">
                    <h2 id="karsilastir-h2" class="heading-2">Paketleri karşılaştır</h2>
                    <p class="lead">Fark yalnız ilk iki satırda. Geri kalan her şey bütün paketlerde aynı.</p>
                </div>
                <div class="relative mt-8 overflow-x-auto rounded-xl border border-line" tabindex="0" role="region" aria-labelledby="karsilastir-h2">
                    <table class="compare">
                        <caption class="sr-only">Paketlerin ürün sınırı, kanal sınırı, aylık fiyatı ve özellikleri</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="text-left">Paket</th>
                                @foreach ($plans as $plan)
                                    <th scope="col" class="{{ $plan['code'] === 'pro' ? 'is-hi' : '' }}">{{ $plan['name'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <th scope="row">Aylık fiyat</th>
                                @foreach ($plans as $plan)
                                    <td class="font-semibold tabular-nums {{ $plan['code'] === 'pro' ? 'is-hi' : '' }}">{{ $price($plan) }}</td>
                                @endforeach
                            </tr>
                            <tr>
                                <th scope="row">Ürün</th>
                                @foreach ($plans as $plan)
                                    <td class="font-semibold tabular-nums {{ $plan['code'] === 'pro' ? 'is-hi' : '' }}">{{ $count($plan['productLimit']) }}</td>
                                @endforeach
                            </tr>
                            <tr>
                                <th scope="row">Kanal (mağaza)</th>
                                @foreach ($plans as $plan)
                                    <td class="font-semibold tabular-nums {{ $plan['code'] === 'pro' ? 'is-hi' : '' }}">{{ $count($plan['channelLimit']) }}</td>
                                @endforeach
                            </tr>
                            @foreach ($included as $feature)
                                <tr>
                                    <th scope="row">{{ $feature }}</th>
                                    @foreach ($plans as $plan)
                                        <td class="{{ $plan['code'] === 'pro' ? 'is-hi' : '' }}">
                                            <span class="text-ok" aria-hidden="true">✓</span><span class="sr-only">Var</span>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif

    {{-- Ne sayılır — sürpriz olmasın diye düz cümlelerle. --}}
    <section class="section bg-soft" aria-labelledby="sayim-h2">
        <div class="wrap">
            <h2 id="sayim-h2" class="heading-2">Ne sayılır, ne sayılmaz?</h2>
            <dl class="mt-8 grid gap-5 md:grid-cols-3">
                @foreach ([
                    ['Ürün', 'Kataloğundaki her ürün bir kez sayılır. Üç kanalda satılan ürün yine bir üründür.'],
                    ['Kanal', 'Bağladığın her mağaza bir kanaldır. Aynı pazaryerinde iki mağazan varsa iki kanal sayılır.'],
                    ['Sipariş', 'Paketlerde sipariş sayısına bağlı bir sınır yok.'],
                ] as [$term, $desc])
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
                <h2 id="fiyat-sss" class="heading-2">Fiyat soruları</h2>
                <p class="mt-4 muted">Başka bir sorun mu var? <a href="{{ route('site.contact') }}" class="link">Bize yaz.</a></p>
            </div>
            <div class="lg:col-span-8">
                @include('site.partials.faq', ['faqs' => $faqs])
            </div>
        </div>
    </section>

    @include('site.partials.cta')
@endsection
