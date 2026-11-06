{{--
    /hizmetler/kurulum-ve-veri-tasima (6 Kas 2026 · site sprint 1).

    Anlatılan her adım panelde VAR olan ekrana dayanır: CSV içe aktarma
    (ProductImportController), kanaldan ürün içe aktarma (SupportsCatalogImport;
    durumu kanal sayfasında), stok koduyla eşleme, bağlantıdaki yazma
    izinleri (ADR-010: yeni bağlantıda kapalı başlar). "Rakip sistemden tek
    tıkla taşıma" gibi olmayan bir araç YAZILMAZ: eski sistemin dışa
    aktardığı dosya uyarlanır. Fiyat yok → Teklif al.
--}}
@extends('site.layout')

@php
    $steps = [
        ['Ön görüşme', 'Hangi kanallarda sattığını, kaç ürünün olduğunu, stok kodlarını (SKU) nasıl tuttuğunu ve şu an hangi sistemi kullandığını konuşuruz. Taşınacak verinin listesini ve sırasını birlikte çıkarırız.'],
        ['Ürün verisinin taşınması', 'Ürünlerin kanallarda zaten açıksa onları kanaldan içe aktarırız. Başka bir entegratörden ya da Excel tablosundan geliyorsan, eski sisteminden aldığın dosyayı 34Pazar\'ın içe aktarma şablonuna uyarlarız. Ürünler stok koduyla eşleşir; aynı ürün iki kez açılmaz.'],
        ['Kanalları birlikte bağlama', 'Her kanalın satıcı panelinden alınacak bilgileri ekran başında birlikte buluruz; anahtarları kendi hesabından sen girersin, şifreni bizimle paylaşman gerekmez. Bağlantının çalıştığını Kanallar ekranında birlikte kontrol ederiz.'],
        ['Gönderimi kontrollü açma', 'Yeni bağlantıda stok, fiyat ve ürün gönderimi kapalı başlar. Stok sayılarını birlikte kontrol ettikten sonra izinleri kanal kanal açarız; böylece yanlış bir sayı kanala gitmez.'],
        ['İlk siparişe kadar eşlik', 'İlk sipariş panele düşene, stok diğer kanallarda düşene ve takip numarası girilene kadar seninle birlikte izleriz. Takıldığın yerde WhatsApp ve e-postayla yanındayız.'],
    ];
    $needs = [
        'Satış yaptığın kanalların satıcı paneline erişim',
        'Varsa eski sisteminden ya da Excel\'den ürün listesi (stok kodu, barkod, fiyat, stok)',
        'Ürünlerin stok kodlarının (SKU) kanallarda aynı olup olmadığı bilgisi',
    ];
    $faqs = [
        ['q' => 'Başka bir entegratörden geçersem ürünlerim kaybolur mu?', 'a' => 'Hayır. Ürünlerin kanallarda olduğu gibi durur; 34Pazar onları kanaldan okur ya da eski sisteminden aldığın dosyadan içe aktarır. Eski sistemi kapatmadan önce stok sayılarını birlikte karşılaştırırız.'],
        ['q' => 'Excel\'de tuttuğum stok listesi yeterli mi?', 'a' => 'Çoğu zaman evet. Dosyayı 34Pazar\'ın içe aktarma şablonuna uyarlarız; eksik sütun (ör. barkod) varsa önceden söyleriz.'],
        ['q' => 'Ücretsiz kurulum desteğinden farkı ne?', 'a' => 'Ücretli paketlerde ilk kanalı bağlamak ve ilk ürün aktarımı zaten ücretsiz. Bu hizmet; bütün kanalların bağlanmasını, eski sistemden tam veri taşımayı ve ilk siparişe kadar eşliği kapsar.'],
        ['q' => 'Ne kadar tutar?', 'a' => 'Ürün sayısına, kanal sayısına ve eski verinin düzenine göre değişir. Ön görüşmeden sonra yazılı teklif veririz.'],
    ];
@endphp

@section('title', 'Kurulum ve veri taşıma: Excel\'den ya da başka entegratörden 34Pazar\'a')
@section('description', 'Ürünlerini, stok kodlarını ve kanal bağlantılarını 34Pazar\'a birlikte taşıyalım: Excel ya da başka entegratörden geçiş, kanalları birlikte bağlama, ilk siparişe kadar eşlik.')

@push('jsonld')
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Hizmetler', 'url' => route('site.services')],
        ['name' => $service['title'], 'url' => route('site.service', $service['slug'])],
    ]])
    @include('site.partials.seo.faq', ['faqs' => $faqs])
@endpush

@section('content')
    <section class="border-b border-line bg-soft" aria-labelledby="kurulum-h1">
        <div class="wrap grid items-center gap-10 pt-10 pb-12 lg:grid-cols-12 lg:pt-14 lg:pb-14">
            <div class="lg:col-span-6">
                <nav aria-label="Konum" class="text-sm muted">
                    <ol class="flex flex-wrap items-center gap-2">
                        <li><a href="{{ route('home') }}" class="link-u hover:text-ink">Ana sayfa</a></li>
                        <li aria-hidden="true">/</li>
                        <li><a href="{{ route('site.services') }}" class="link-u hover:text-ink">Hizmetler</a></li>
                    </ol>
                </nav>
                <h1 id="kurulum-h1" class="h-page mt-6">Excel'den ya da eski entegratöründen, birlikte geçelim.</h1>
                <p class="lead mt-5 max-w-[56ch]">{{ $service['summary'] }}</p>
                <div class="mt-7 flex flex-wrap gap-3">
                    <a href="{{ \App\Support\Site\Services::offerUrl($service['slug']) }}" class="btn btn-primary">Teklif al</a>
                    <a href="{{ route('site.contact', ['konu' => 'gorusme']) }}#form" class="btn btn-secondary">Görüşme planla</a>
                </div>
            </div>
            <div class="lg:col-span-6">
                @include('site.partials.step-shot', ['shot' => \App\Support\Site\StepShots::get('ice-aktar'), 'n' => 2, 'alt' => 'Panelde toplu içe aktarma ekranı: CSV yükleme ve geçmiş içe aktarmalar'])
                <p class="mt-2 text-xs muted">Panelin içe aktarma ekranı (gerçek ekran görüntüsü, demo mağaza).</p>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="adim-h2">
        <div class="wrap grid gap-10 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <h2 id="adim-h2" class="heading-2">Nasıl ilerliyoruz?</h2>
                <p class="mt-4 muted">Beş adım. Sıra senin düzenine göre değişebilir; ilk siparişe kadar yanındayız.</p>
            </div>
            <ol class="plain-list lg:col-span-8">
                @foreach ($steps as [$title, $body])
                    <li>
                        <h3 class="heading-3"><span class="muted">{{ $loop->iteration }}.</span> {{ $title }}</h3>
                        <p class="mt-1 muted">{{ $body }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    <section class="section bg-soft home-band" aria-labelledby="gerek-h2">
        <div class="wrap grid gap-10 lg:grid-cols-12">
            <div class="lg:col-span-4">
                <h2 id="gerek-h2" class="heading-2">Senden ne gerekir?</h2>
            </div>
            <ul class="plain-list lg:col-span-8">
                @foreach ($needs as $need)
                    <li>{{ $need }}</li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="section" aria-labelledby="sss-h2">
        <div class="wrap-narrow">
            <h2 id="sss-h2" class="heading-2">Sık sorulanlar</h2>
            <div class="mt-8">
                @include('site.partials.faq', ['faqs' => $faqs, 'openFirst' => true])
            </div>
        </div>
    </section>

    @include('site.partials.cta', [
        'ctaTitle' => 'Geçişi birlikte planlayalım.',
        'ctaText' => 'Kanallarını ve ürün sayını yaz; kapsamı ve fiyatı yazılı teklifle gönderelim.',
    ])
@endsection
