{{--
    /fiyatlar — karşılaştırma `$plans`'tan (plans tablosu); elle fiyat yok.

    KDV hakkında BİLEREK bir şey yazılmaz (kullanıcı kararı): vergi
    durumu kesinleşmeden "KDV dahil/hariç" demek yanlış bilgi olurdu.

    Kota gerçeği (QuotaMetric): paketler YALNIZ ürün sayısı ve kanal
    bağlantısı sayısıyla ayrılır. Sınır yeni ekleme anında uygulanır
    (ürün ekleme, içe aktarma, kanal bağlama) — sayfa da bunu söyler.
--}}
@extends('site.layout')

@php
    $freePlan = collect($plans)->first(fn ($p) => (float) $p['priceMonthly'] === 0.0);
    $limit = fn (?int $v, string $unit): string => $v === null ? "sınırsız {$unit}" : number_format($v, 0, ',', '.')." {$unit}";

    $faqs = [];
    if ($freePlan) {
        $faqs[] = ['q' => 'Kart bilgisi gerekiyor mu?', 'a' => "{$freePlan['name']} paketle başlarken hayır. Kayıt formu ödeme bilgisi sormaz; {$limit($freePlan['productLimit'], 'ürün')} ve {$limit($freePlan['channelLimit'], 'kanal')} ile deneyebilirsin."];
    }
    $faqs[] = ['q' => 'Ürün sayısı neye göre hesaplanır?', 'a' => 'Kataloğundaki ürün sayısına göre. Aynı ürün kaç kanalda satılırsa satılsın bir kez sayılır.'];
    $faqs[] = ['q' => 'Kanal sayısı neye göre hesaplanır?', 'a' => 'Bağladığın her mağaza bir kanaldır. İki ayrı Trendyol mağazası bağlarsan iki kanal sayılır.'];
    $faqs[] = ['q' => 'Sınıra gelince ne olur?', 'a' => 'Yeni ürün ekleyemez ya da yeni kanal bağlayamazsın; panel paketini yükseltmeni söyler. Sınır yeni ekleme anında uygulanır.'];
    $faqs[] = ['q' => 'Paketler arasında özellik farkı var mı?', 'a' => 'Paketler ürün ve kanal sayısıyla ayrılır. Stok, sipariş, kargo ve fiyat ekranları hepsinde aynı.'];
    $faqs[] = ['q' => 'Fiyatlar aylık mı?', 'a' => 'Evet, sayfadaki bütün fiyatlar aylıktır.'];
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
    <section class="wrap pt-14 pb-14 sm:pt-20 lg:pt-28 lg:pb-20" aria-labelledby="fiyat-h1">
        <p class="eyebrow">Fiyatlar</p>
        <div class="mt-6 grid gap-10 lg:grid-cols-12 lg:items-end">
            <h1 id="fiyat-h1" class="display t-hero lg:col-span-8">Büyüdükçe <span class="accent">öde.</span></h1>
            <div class="lg:col-span-4">
                <p class="lead">Paketler yalnız ürün ve kanal sayısıyla ayrılır. Ekranlar hepsinde aynı.</p>
                <p class="mt-3 font-semibold">Fiyatlar aylıktır.</p>
            </div>
        </div>
    </section>

    <section class="wrap pb-24 lg:pb-36" aria-label="Paket karşılaştırması">
        @include('site.partials.plans', ['plans' => $plans, 'isLoggedIn' => $isLoggedIn])
    </section>

    {{-- Ne sayılır — sürpriz olmasın diye düz cümlelerle. --}}
    <section class="on-dark" aria-labelledby="sayim-h2">
        <div class="wrap grid gap-14 py-24 lg:grid-cols-12 lg:py-32">
            <h2 id="sayim-h2" class="display t-1 lg:col-span-5">Ne sayılır, ne sayılmaz.</h2>
            <dl class="border-t border-line-dark lg:col-span-6 lg:col-start-7">
                @foreach ([
                    ['Ürün', 'Kataloğundaki her ürün bir kez sayılır. Üç kanalda satılan ürün yine bir üründür.'],
                    ['Kanal', 'Bağladığın her mağaza bir kanaldır. Aynı pazaryerinde iki mağazan varsa iki kanal sayılır.'],
                    ['Sipariş', 'Paketlerde sipariş sayısına bağlı bir sınır yok.'],
                ] as $n => [$term, $desc])
                    <div class="grid gap-2 border-b border-line-dark py-8 sm:grid-cols-[10rem_1fr] sm:gap-8" data-reveal>
                        <dt class="display t-3"><span class="mr-3 text-sm text-[#ff6a47] tabular-nums">{{ sprintf('%02d', $n + 1) }}</span>{{ $term }}</dt>
                        <dd class="muted">{{ $desc }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    <section class="bg-sand" aria-labelledby="fiyat-sss">
        <div class="wrap py-24 lg:py-32">
            <h2 id="fiyat-sss" class="display t-1">Fiyat soruları.</h2>
            <div class="mt-14 lg:mt-20 lg:ml-[33.333%]">
                @include('site.partials.faq', ['faqs' => $faqs])
            </div>
        </div>
    </section>
@endsection
