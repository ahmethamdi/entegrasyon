{{--
    /entegrasyonlar/{kod} — tek şablon, `$channel` ile sürülür.

    6 Kas 2026 · site sprint 1 — TAM KALIP: ne çalışır (matristen, dürüst) →
    bağlantı adımları (panelin GERÇEK formundan) → SSS → diğer kanallar.

    DURUM ELLE YAZILMAZ: her satırın rozeti S15 sertifikasyon matrisinden
    (`$channel['status']`, `CertificationStatus::publicLabel()`). Gerçek
    hesapta denenmemiş iş "Çalışır" değil "Test ediliyor" der. Satır
    metni kodda olan işi anlatır; rozet ne kadar doğrulandığını söyler.

    BAĞLANTI METNİ ELLE YAZILMAZ: alanlar, ipuçları ve "nereden alınır"
    `ChannelConnectForm::present()`'ten — panelde satıcının gördüğünün
    aynısı (/yardim rehberiyle aynı kaynak). Önceki elle yazılmış metin
    Shopify'da eski Admin API anahtarını anlatıyordu (ADR-012'den önce).

    KAPALI KANAL: "panelde henüz açık değil" der; durum tablosu yine
    matristen gösterilir (hepsi "Test ediliyor" ya da altı), bağlanma
    adımları "açıldığında istenecek bilgiler" olarak kalır.
--}}
@extends('site.layout')

@php
    $code = $channel['code'];
    $name = $channel['name'];
    $isOn = $channel['available'];

    // Bulunma eki elle tutulur: yabancı marka adının okunuşu yazılışından
    // tahmin edilemez ("Shopify" i ile biter, a ile okunur). Sözlükte yoksa nötr kalıp.
    $locative = [
        'trendyol' => "Trendyol'da", 'shopify' => "Shopify'da", 'woocommerce' => "WooCommerce'te",
        'etsy' => "Etsy'de", 'ebay' => "eBay'de", 'hepsiburada' => "Hepsiburada'da",
        'kaufland' => "Kaufland'da", 'idefix' => "İdefix'te",
    ];
    $loc = $locative[$code] ?? "{$name} mağazanda";
    $kind = $channel['marketplace'] ? 'Pazaryeri' : 'E-ticaret sitesi';

    $w = $channel['works'] ?? [];
    $st = $channel['status'] ?? [];
    // [başlık, açıklama, durum] — açıklama kodda olanı anlatır, durum matristen.
    $caps = [
        ['Stok', ($w['inventory'] ?? false)
            ? "Başka bir kanalda satış olunca {$loc}ki stok da düşer. Adedi panelde düzeltirsen yeni sayı buraya da gider."
            : "Stoğu {$loc}, kanalın kendi panelinde yönetirsin.", $st['inventory'] ?? null],
        ['Siparişler', ($w['order_import'] ?? false)
            ? "{$name} siparişleri diğer kanallarının siparişleriyle aynı listeye düşer."
            : "{$name} siparişlerini kanalın kendi panelinde görürsün.", $st['order_import'] ?? null],
        ['Ürün yayınlama', ($w['publishing'] ?? false)
            ? 'Ürünü panelden bu kanala gönderirsin. Kanal reddederse sebebi panelde yazar; düzeltip tekrar gönderirsin.'
            : "Yeni ürünü {$loc}, kanalın kendi panelinde açarsın; 34Pazar mevcut ilanlarının stok ve fiyatını yönetir.", $st['publishing'] ?? null],
        ['Fiyat', ($w['price'] ?? false)
            ? "Panelde değiştirdiğin fiyat bu kanala da gider; kanal için fiyat kuralı tanımlayabilirsin. Fiyatı {$loc} kendin değiştirirsen sessizce üzerine yazmayız; hangisinin geçerli olacağına sen karar verirsin."
            : "Fiyatı {$loc}, kanalın kendi panelinde değiştirirsin.", $st['price'] ?? null],
        ['Kargo takip numarası', ($w['shipment'] ?? false)
            ? 'Numarayı panelde siparişin içine yazarsın; numara bu kanala iletilir ve sipariş orada da kargolandı olarak işaretlenir.'
            : "Numarayı {$loc}, kanalın kendi panelinde girersin. Panel bu siparişi yine de listede gösterir.", $st['shipment'] ?? null],
    ];
    $level = $channel['level'] ?? null;

    $fields = $form ? array_merge($form['secretFields'] ?? [], $form['identityFields'] ?? []) : [];
    $required = array_values(array_filter($fields, fn ($f) => ! ($f['optional'] ?? false)));
    $connectable = $form && ! ($form['appMissing'] ?? false) && ($form['connectable'] ?? true);

    // Kanala özgü sorular — cevaplar bağlantı formunun tanımından üretilir.
    $faqs = [];
    if ($required !== []) {
        $faqs[] = ['q' => "{$name} bağlamak için hangi bilgiler gerekiyor?", 'a' => 'Zorunlu olanlar: '.implode(', ', array_column($required, 'label')).'.'.(count($fields) > count($required) ? ' Diğer alanlar isteğe bağlıdır; boş bırakırsan varsayılan değer kullanılır.' : '')];
    }
    if ($form['help'] ?? null) {
        $faqs[] = ['q' => "Bu bilgileri {$name} tarafında nerede bulurum?", 'a' => $form['help']];
    }
    if ($form) {
        $faqs[] = ['q' => 'Bağlayınca kanaldaki ürünlerim hemen değişir mi?', 'a' => ($form['writesOnConnect'] ?? false)
            ? "{$name} bağlantısında stok ve fiyat gönderimi açık başlar; ürün yayını kapalı kalır. İzinleri Kanallar ekranında bağlantının kartından değiştirirsin."
            : 'Hayır. Yeni bağlantıda ürün yayını, fiyat ve stok gönderimi kapalı başlar; bağlantı kurmak kanala tek bir şey yazmaz. İzinleri hazır olduğunda kanal kanal sen açarsın.'];
    }
    foreach ($fields as $f) {
        if (($f['optional'] ?? false) && ($f['hint'] ?? '') !== '') {
            $label = trim(str_replace('(isteğe bağlı)', '', $f['label']));
            $faqs[] = ['q' => "\"{$label}\" alanına ne yazmalıyım?", 'a' => $f['hint']];
        }
    }
    $faqs = array_merge($faqs, $channelFaqs ?? []);

    $others = array_values(array_filter($channels, fn ($c) => $c['code'] !== $code));
    $availableOthers = array_column(array_filter($others, fn ($c) => $c['available']), 'name');
    $othersText = count($availableOthers) > 1
        ? implode(', ', array_slice($availableOthers, 0, -1)).' ve '.end($availableOthers)
        : implode('', $availableOthers);

    $primaryHref = $isLoggedIn ? url('/panel') : route('register');
    $primaryLabel = $isLoggedIn ? 'Panele git' : 'Ücretsiz dene';

    $description = $isOn
        ? "{$name} entegrasyonu: {$loc}ki stok, fiyat, sipariş ve ürünlerini diğer kanallarınla birlikte tek panelden yönet. Neyin ne kadar doğrulandığı tek tek."
        : "{$name} entegrasyonu hazırlanıyor; panelde henüz açık değil. Hangi işin ne kadar hazır olduğu ve bağlanırken istenecek bilgiler bu sayfada.";
@endphp

@section('title', "{$name} entegrasyonu".($isOn ? ': stok, sipariş ve fiyat tek panelde' : ' (yakında)'))
@section('description', \Illuminate\Support\Str::limit($description, 157))

@push('jsonld')
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Entegrasyonlar', 'url' => route('site.channels')],
        ['name' => $name, 'url' => route('site.channel', $code)],
    ]])
    @if (count($faqs))
        @include('site.partials.seo.faq', ['faqs' => $faqs])
    @endif
@endpush

@section('content')
    <section class="border-b border-line bg-soft" aria-labelledby="kanal-h1">
        <div class="wrap pt-8 pb-12 lg:pt-10 lg:pb-14">
            <nav aria-label="Konum" class="text-sm muted">
                <ol class="flex flex-wrap items-center gap-2">
                    <li><a href="{{ route('home') }}" class="link-u hover:text-ink">Ana sayfa</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('site.channels') }}" class="link-u hover:text-ink">Entegrasyonlar</a></li>
                    <li aria-hidden="true">/</li>
                    <li aria-current="page" class="font-semibold text-ink">{{ $name }}</li>
                </ol>
            </nav>

            <div class="mt-8 flex flex-wrap items-center gap-3">
                <span class="text-sm font-semibold muted">{{ $kind }}</span>
                @if (! $isOn)
                    <span class="badge badge--soon">Yakında</span>
                @elseif ($level)
                    <span class="badge badge--{{ $level->publicTone() }}" data-level="{{ $level->value }}">{{ $level->publicLabel() }}</span>
                @endif
            </div>

            @if ($channel['logo'] ?? null)
                <img src="{{ $channel['logo'] }}" alt="{{ $name }} logosu" class="mt-6 h-10 w-auto max-w-[12rem] object-contain object-left">
            @endif
            <h1 id="kanal-h1" class="h-page mt-3 [overflow-wrap:anywhere]">{{ $name }} entegrasyonu</h1>

            @if ($isOn)
                <p class="lead mt-4 max-w-[60ch]">
                    {{ $name }} mağazanı 34Pazar'a bağla. {{ $loc }} satılan ürünün stoğu diğer kanallarında da düşer; siparişlerin tek listede durur. Aşağıda her işin gerçek hesapta mı yoksa yalnız testte mi doğrulandığını yazıyoruz.
                </p>
            @else
                <p class="lead mt-4 max-w-[60ch]">
                    <strong class="font-semibold text-ink">Bu entegrasyon hazırlanıyor.</strong>
                    Şu an {{ $name }} mağazanı 34Pazar'a bağlayamazsın; panelde açıldığında bu sayfada yazacak.
                    @if ($othersText) Bu arada {{ $othersText }} ile başlayabilirsin. @endif
                </p>
            @endif
            <div class="mt-7 flex flex-wrap gap-3">
                <a href="{{ $primaryHref }}" class="btn btn-primary">{{ $primaryLabel }}</a>
                <a href="{{ route('site.contact', ['konu' => 'gorusme']) }}#form" class="btn btn-secondary">Görüşme planla</a>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════════════════ NE ÇALIŞIR (matristen) --}}
    <section class="section" aria-labelledby="calisan-h2">
        <div class="wrap">
            <div class="section-head">
                <h2 id="calisan-h2" class="heading-2">{{ $isOn ? "{$loc} neler çalışır." : "{$name} için neler hazır." }}</h2>
                <p class="lead">Satır satır. Çalışmayanı ve henüz gerçek hesapta denenmeyeni de yazıyoruz.</p>
            </div>
            <dl class="mt-8 overflow-hidden rounded-xl border border-line">
                @foreach ($caps as [$title, $body, $status])
                    <div class="grid gap-2 px-5 py-5 lg:grid-cols-12 lg:items-center lg:gap-6 {{ ! $loop->first ? 'border-t border-line' : '' }}">
                        <dt class="heading-3 lg:col-span-3">{{ $title }}</dt>
                        <dd class="muted lg:col-span-6">{{ $body }}</dd>
                        <dd class="lg:col-span-3 lg:text-right">
                            @if ($status)
                                <span class="badge badge--{{ $status->publicTone() }}" data-status="{{ $status->value }}">{{ $status->publicLabel() }}</span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
            @include('site.partials.proof-legend')
        </div>
    </section>

    {{-- ═══════════════════════════════════ BAĞLANTI (panelin gerçek formundan) --}}
    <section class="section bg-soft home-band" aria-labelledby="baglanti-h2">
        <div class="wrap grid gap-10 lg:grid-cols-12">
            <div class="lg:col-span-6">
                <h2 id="baglanti-h2" class="heading-2">{{ $isOn ? 'Nasıl bağlanır?' : 'Açıldığında nasıl bağlanacak?' }}</h2>

                @if ($form === null)
                    <p class="mt-4 muted">{{ $name }} için panelde henüz bir bağlantı formu yok. Hazır olduğunda adımları burada yazacağız.</p>
                @elseif (! $connectable && $isOn)
                    <p class="mt-4 muted">{{ $name }} için 34Pazar uygulamasının kurulumu tamamlanmadı; panel şu an bağlamaya izin vermiyor. Beklemek istemiyorsan <a href="{{ route('site.contact', ['konu' => 'destek']) }}#form" class="plain-link">bize yaz</a>.</p>
                @else
                    <ol class="plain-list mt-6">
                        <li>
                            <p class="font-semibold text-ink">1. {{ $name }} tarafında hazırlık</p>
                            <p class="mt-1 muted">{{ $form['help'] ?? "{$name} satıcı panelinden API bilgilerini al; aşağıdaki alanlar panelde aynı adlarla sorulur." }}</p>
                            @if (($form['steps'] ?? []) !== [])
                                <ol class="mt-3 list-decimal space-y-1 pl-5 muted [overflow-wrap:anywhere]">
                                    @foreach ($form['steps'] as $step)
                                        <li>{{ $step }}</li>
                                    @endforeach
                                </ol>
                            @endif
                        </li>
                        <li>
                            <p class="font-semibold text-ink">2. 34Pazar'da bağla</p>
                            <p class="mt-1 muted">Panelde <strong class="font-semibold text-ink">Kanallar → Mağaza bağla → {{ $name }}</strong>.
                                @if ($form['oauth'] ?? false)
                                    Bilgileri gir; kaydedince {{ $name }}'ın izin ekranına yönlendirilirsin ve izni orada verirsin.
                                @else
                                    Bilgileri gir ve kaydet; 34Pazar bağlantıyı {{ $name }}'da dener ve sonucu Kanallar ekranında gösterir.
                                @endif
                            </p>
                        </li>
                        <li>
                            <p class="font-semibold text-ink">3. Gönderim izinlerini aç</p>
                            @if ($form['writesOnConnect'] ?? false)
                                <p class="mt-1 muted">{{ $name }} bağlantısında stok ve fiyat gönderimi açık başlar; ürün yayını kapalı kalır. İzinleri Kanallar ekranında bağlantının kartından değiştirirsin.</p>
                            @else
                                <p class="mt-1 muted">Yeni bağlantıda ürün yayını, fiyat ve stok gönderimi kapalı başlar. Hazır olduğunda Kanallar ekranında bağlantının kartındaki "Kanala yazma izinleri" bölümünden açarsın.</p>
                            @endif
                        </li>
                    </ol>
                    @if ($hasGuide)
                        <p class="mt-6"><a href="{{ route('site.help.channel', $code) }}" class="plain-link">{{ $name }} bağlama rehberi (ekranlarla)</a></p>
                    @endif
                @endif
            </div>

            <div class="lg:col-span-6">
                @if ($fields !== [])
                    <h3 class="heading-3">Panelde istenen bilgiler</h3>
                    <dl class="mt-4 divide-y divide-line rounded-xl border border-line bg-white">
                        @foreach ($fields as $field)
                            <div class="p-4">
                                <dt class="flex flex-wrap items-center gap-2 font-semibold text-ink">
                                    {{ $field['label'] }}
                                    @if (($field['optional'] ?? false) && ! str_contains($field['label'], 'isteğe bağlı'))
                                        <span class="text-sm font-normal muted">· isteğe bağlı</span>
                                    @endif
                                    @if ($field['masked'] ?? false)
                                        <span class="text-sm font-normal muted">· şifreli saklanır</span>
                                    @endif
                                </dt>
                                @if (($field['hint'] ?? '') !== '')
                                    <dd class="mt-1 text-sm muted">{{ $field['hint'] }}</dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>
                    <p class="mt-3 text-sm muted">Gizli anahtarlar şifreli saklanır ve panelde bir daha gösterilmez (<a href="{{ route('site.security') }}" class="plain-link">güvenlik</a>).</p>
                @endif
                @php $connectShot = \App\Support\Site\StepShots::get('kanal-bagla'); @endphp
                @if ($connectShot)
                    <div class="mt-8">
                        @include('site.partials.step-shot', ['shot' => $connectShot, 'n' => 2, 'alt' => 'Panelde kanal bağlama ekranı: kanal seçimi ve bağlantı bilgileri formu'])
                        <p class="mt-2 text-xs muted">Panelin kanal bağlama ekranı (gerçek ekran görüntüsü, demo mağaza).</p>
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- ═══════════════════════════════════ SSS --}}
    @if (count($faqs))
        <section class="section" aria-labelledby="kanal-sss-h2">
            <div class="wrap-narrow">
                <h2 id="kanal-sss-h2" class="heading-2">{{ $name }} hakkında sık sorulanlar</h2>
                <div class="mt-8">
                    @include('site.partials.faq', ['faqs' => $faqs, 'openFirst' => true])
                </div>
            </div>
        </section>
    @endif

    {{-- ═══════════════════════════════════ DİĞER KANALLAR --}}
    @if (count($others))
        <section class="section bg-soft home-band" aria-labelledby="diger-kanal-h2">
            <div class="wrap">
                <h2 id="diger-kanal-h2" class="heading-2">Diğer kanallar</h2>
                <div class="mt-8">
                    @include('site.partials.channel-list', ['channels' => $others])
                </div>
            </div>
        </section>
    @endif

    @include('site.partials.cta')
@endsection
