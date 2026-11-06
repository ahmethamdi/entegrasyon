{{--
    /guvenlik — Güvenlik ve KVKK (6 Kas 2026 · site sprint 1).

    KURAL: YALNIZ kodda ya da altyapıda DOĞRULANAN olgu yazılır; her
    maddenin kaynağı yanındaki yorumda. Doğrulanamayan şey (sertifika,
    sızma testi, çalışma süresi yüzdesi, otomatik hesap silme, tam veri
    dışa aktarma) YAZILMAZ — kodda yok.

    Kaynaklar (6 Kas 2026'da bakıldı):
      sunucu      whois 212.227.142.108 → IONOS SE, country DE; RUNBOOK.md
      gece yedeği routes/console.php (backup:offsite 02:00 UTC, backup:verify
                  Pazar 02:40), Support/Backup/OffsiteBackup.php (age şifreleme),
                  RetentionPolicy.php (7 gün + 4 hafta + 6 ay), RUNBOOK §2.1
                  (IONOS Object Storage eu-central-3 Berlin), YEDEK-GERI-YUKLEME.md
      kasa        Domain/Channels/Support/CredentialVault.php (Laravel Crypt,
                  config/app.php cipher AES-256-CBC; "hiçbir koşulda loglanmaz")
      maskeleme   Support/Logging/PayloadRedactor.php, Support/Privacy/PersonalDataMask.php
                  (IngestInboxMessage gövdeyi saklamadan önce maskeler)
      kiracı      Support/Tenancy/BelongsToTenant.php (global scope, bağlam yoksa
                  istisna), tests/Feature/Tenancy/TenantScopeContractTest + TenantIsolationTest
      yetki       Identity/Enums/TenantRole.php, `tenant.can` (RoutePermissions; tabloda
                  olmayan rota 403)
      yazma izni  ADR-010 (yeni bağlantıda ürün/fiyat/stok gönderimi kapalı başlar)
      HTTPS       AppServiceProvider (üretimde URL::forceScheme('https'))
      ödeme       BillingController → Stripe Checkout; kart bize gelmez (gizlilik 2.4)
      bot kalkanı Support/Security/FormShield.php (kayıt + iletişim formu)
      sayaç       Support/Analytics/VisitStats.php (çerez yok, IP saklanmaz, 90 gün)
      dışa aktarma rotası products.bulk-edit.export (Excel fiyat/stok listesi)
--}}
@extends('site.layout')

@php
    $facts = [
        'Verin nerede duruyor' => [
            ['Sunucu Almanya\'da', '34Pazar, IONOS\'un Almanya\'daki sunucusunda çalışır. Veritabanı da aynı sunucudadır.'],
            ['Her gece şifreli yedek', 'Veritabanının tam yedeği her gece alınır, sunucudan çıkmadan önce şifrelenir ve sunucudan ayrı bir veri merkezinde (IONOS, Berlin) saklanır. Son 7 gün, son 4 hafta ve son 6 ayın birer yedeği tutulur.'],
            ['Geri yükleme her hafta denenir', 'Her Pazar en yeni yedek otomatik olarak geçici bir veritabanına açılır ve kontrol edilir. Yedeğin alındığını değil, geri açılabildiğini sınar.'],
        ],
        'Kanal anahtarların' => [
            ['Şifreli saklanır', 'Pazaryeri ve mağaza bağlantısı için girdiğin API anahtarları ve gizli anahtarlar veritabanına şifrelenerek (AES-256) yazılır ve panelde bir daha gösterilmez.'],
            ['Kayıtlara yazılmaz', 'Kanala giden isteklerin kayıtlarında anahtarlar ve gizli değerler maskelenir; bir hata mesajının içinde geçse bile.'],
            ['Kanala yazma izni senin elinde', 'Yeni bağlantıda ürün gönderme, fiyat ve stok gönderimi kapalı başlar. Bağlantı kurmak kanala tek bir şey yazmaz; izinleri kanal kanal sen açarsın.'],
        ],
        'Hesabın ve ekibin' => [
            ['Her firma ayrı', 'Firma verileri veritabanında firma kimliğiyle ayrılır; uygulama okurken otomatik olarak yalnız senin firmanı süzer, firma bağlamı yoksa okumayı reddeder. Bu ayrım her kod değişikliğinde otomatik testlerle denetlenir.'],
            ['Roller', 'Ekibe davet ettiğin kişiye sahip, yönetici, depo ya da salt okunur rolü verirsin. Yetkisi olmayan ekran açılmaz.'],
            ['Bağlantı şifreli', 'Site ve panel HTTPS üzerinden sunulur; uygulama canlı ortamda bütün bağlantıları HTTPS olarak üretir.'],
            ['Kart bilgisi bizde değil', 'Ödeme Stripe\'ın ödeme sayfasında alınır; kart bilgilerin sunucumuza gelmez.'],
        ],
        'Kişisel veriler (KVKK ve GDPR)' => [
            ['Alıcı bilgisi saklanmaz', 'Kanallardan gelen siparişlerde alıcının adı, telefonu, e-postası ve adresi sipariş kaydında tutulmaz; ham bildirimler saklanmadan önce bu alanlar maskelenir.'],
            ['Çerezsiz ziyaret sayacı', 'Sitede analitik çerezi ya da üçüncü taraf takip aracı yok. Ziyaretler yalnız günlük toplam olarak sayılır; IP adresi saklanmaz, kayıtlar 90 gün sonra silinir.'],
            ['Bot kalkanı', 'Kayıt ve iletişim formları otomatik doldurulan sahte gönderimlere karşı korunur; başkasının e-posta adresine bizim adımıza posta gitmesin diye.'],
            ['Fiyat ve stok listen senin', 'Fiyat ve stok listeni panelden istediğin zaman Excel olarak indirirsin.'],
        ],
    ];
@endphp

@section('title', 'Güvenlik ve KVKK')
@section('description', '34Pazar verini nasıl korur: sunucu Almanya\'da, her gece şifreli yedek, kanal anahtarları şifreli, her firma ayrı, alıcı kişisel verisi saklanmaz.')

@push('jsonld')
    @include('site.partials.seo.breadcrumb', ['items' => [
        ['name' => 'Ana sayfa', 'url' => route('home')],
        ['name' => 'Güvenlik ve KVKK', 'url' => route('site.security')],
    ]])
@endpush

@section('content')
    <section class="border-b border-line bg-soft" aria-labelledby="guvenlik-h1">
        <div class="wrap pt-12 pb-12 lg:pt-16 lg:pb-14">
            <h1 id="guvenlik-h1" class="h-page max-w-[24ch]">Güvenlik ve KVKK</h1>
            <p class="lead mt-5 max-w-[60ch]">
                Bu sayfada yalnız ürünün kodunda ve sunucusunda doğrulayabildiğimiz şeyleri yazıyoruz. Sertifika, rozet ya da ölçmediğimiz bir oran yok.
            </p>
        </div>
    </section>

    <div class="wrap py-12 lg:py-16">
        @foreach ($facts as $group => $items)
            <section class="grid gap-8 py-8 lg:grid-cols-12 {{ $loop->first ? '' : 'border-t border-line' }}" aria-labelledby="g-{{ $loop->index }}">
                <h2 id="g-{{ $loop->index }}" class="heading-2 lg:col-span-4">{{ $group }}</h2>
                <dl class="grid gap-6 lg:col-span-8">
                    @foreach ($items as [$title, $body])
                        <div>
                            <dt class="heading-3">{{ $title }}</dt>
                            <dd class="mt-1 muted">{{ $body }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach

        <section class="notice mt-8 max-w-3xl" aria-labelledby="haklar-h2">
            <h2 id="haklar-h2" class="font-semibold text-ink">Haklarını kullanmak için</h2>
            <p class="mt-1 muted">
                Hangi verini neden işlediğimiz, ne kadar sakladığımız ve KVKK / GDPR kapsamındaki haklarını nasıl kullanacağın
                <a href="{{ route('site.legal', 'gizlilik') }}" class="plain-link">gizlilik ve KVKK aydınlatma metninde</a> yazıyor.
                Sorun için <a href="{{ route('site.contact', ['konu' => 'destek']) }}#form" class="plain-link">bize yaz</a>.
            </p>
        </section>
    </div>

    @include('site.partials.cta')
@endsection
