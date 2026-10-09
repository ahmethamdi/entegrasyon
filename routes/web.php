<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CategoryMappingController;
use App\Http\Controllers\ChannelConnectionController;
use App\Http\Controllers\ChannelPricingController;
use App\Http\Controllers\ChannelSettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EbayOAuthController;
use App\Http\Controllers\EtsyOAuthController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductChannelController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\ShopifyInstallController;
use App\Http\Controllers\ShopifyOAuthController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SyncFailureController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

/*
| Panel rotaları.
|
| Mimari Karar Dokümanı v2.2 · §13 · faz 1.1.
|
| KİRACI BAĞLAMI AYRI ARA KATMANDA (`tenant`):
|   Giriş ve kayıt rotaları kiracısızdır; bağlam kurmaya çalışmak onları
|   kendi üzerlerine yönlendirirdi. Bağlam yalnızca panel rotalarında kurulur
|   ve istek bitince bırakılır.
*/

// ─────────────────────────────────────────────────────────── misafir

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store']);

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:register');

    // Parola sıfırlama (B4). Yanıt e-postanın kayıtlı olup olmadığını
    // SÖYLEMEZ — gerekçe denetleyicide.
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:password-reset')
        ->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:password-reset')
        ->name('password.update');
});

// ─────────────────────────────────────────────────────────── e-posta doğrulama
//
// Giriş yapmış ama doğrulamamış kullanıcı YALNIZ bu rotaları görür (B4).
Route::middleware('auth')->group(function (): void {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

// ─────────────────────────────────────────────────────────── tanıtım sitesi

// Herkese açık: reklamdan gelen satıcı önce ürünü görür, sonra kayıt olur.
// Giriş yapmış kullanıcı da görebilir (sayfada "Panele git" düğmesi var).
//
// BLADE İLE SUNUCUDA ÜRETİLİR, Inertia DEĞİL: arama motoru ve sosyal
// önizleme botu sayfayı JavaScript çalıştırmadan okur. Panel Inertia
// kalır; giriş arkasındaki ekranın dizine girmesi zaten istenmez.
// Panel dili (TR/EN) — giriş öncesi de seçilebilir (giriş/kayıt ekranları).
Route::post('/locale', LocaleController::class)->name('locale.update');

// Tanıtım sitesi Türkçe yazıldı; tarayıcı diline göre dil değiştirilseydi
// Türkçe metnin içinde İngilizce tarih/ay adı basılırdı (blog).
Route::controller(SiteController::class)->withoutMiddleware(SetLocale::class)->group(function (): void {
    Route::get('/', 'home')->name('home');
    Route::get('/ozellikler', 'features')->name('site.features');
    Route::get('/fiyatlar', 'pricing')->name('site.pricing');
    Route::get('/entegrasyonlar', 'channels')->name('site.channels');
    Route::get('/entegrasyonlar/{channel}', 'channel')->where('channel', '[a-z0-9-]+')->name('site.channel');
    Route::get('/hakkimizda', 'about')->name('site.about');
    Route::get('/iletisim', 'contact')->name('site.contact');
    Route::get('/blog', 'blogIndex')->name('site.blog');
    Route::get('/blog/{slug}', 'blogShow')->where('slug', '[a-z0-9-]+')->name('site.blog.show');
    Route::get('/yasal/{page}', 'legal')->where('page', '[a-z0-9-]+')->name('site.legal');
    // İngilizce yardım sayfaları — Shopify App Store listelemesi bunlara bağlanır.
    Route::get('/en/{page}', 'english')->where('page', '[a-z0-9-]+')->name('site.en');
    Route::get('/sitemap.xml', 'sitemap')->name('site.sitemap');
});

// ─────────────────────────────────────────────────────────── Shopify kurulumu
//
// Shopify'dan BAŞLAYAN kurulum (App URL). Oturum/kiracı İSTEMEZ: satıcının
// henüz hesabı olmayabilir. Gerekçe ve sıra `ShopifyInstallController`'da.
// Uygulama ayarlarında: App URL = https://APP_DOMAIN/shopify, izinli
// yönlendirmeler = /channels/shopify/callback VE /shopify/auth/callback.
Route::get('/shopify', [ShopifyInstallController::class, 'launch'])->name('shopify.install.launch');
Route::get('/shopify/auth/callback', [ShopifyInstallController::class, 'callback'])->name('shopify.install.callback');

// ─────────────────────────────────────────────────────────── panel

// `verified`: doğrulanmamış hesap panele giremez (B4) — gerekçe
// EmailVerificationController'da.
// SÜPER ADMIN — platformu işleten. `tenant` ara katmanı YOK (yönetici tüm
// kiracıların üstünde); yetki `can:superAdmin` (sunucu ayarındaki e-posta
// listesi, `SuperAdmin`). Yetkisiz kullanıcı 403 alır.
Route::middleware(['auth', 'verified', 'can:superAdmin'])->prefix('admin')->group(function (): void {
    Route::get('/', [AdminController::class, 'dashboard'])->name('admin.dashboard');
    Route::get('/tenants', [AdminController::class, 'tenants'])->name('admin.tenants');
    Route::get('/tenants/{tenant}', [AdminController::class, 'showTenant'])->name('admin.tenants.show');
    Route::post('/tenants/{tenant}/plan', [AdminController::class, 'assignPlan'])->name('admin.tenants.plan');
    Route::get('/plans', [AdminController::class, 'plans'])->name('admin.plans');
    Route::post('/plans', [AdminController::class, 'storePlan'])->name('admin.plans.store');
});

Route::middleware(['auth', 'verified', 'tenant'])->group(function (): void {
    // Panel `/panel`'de; `/` herkese açık tanıtım sitesidir (SiteController).
    Route::get('/panel', DashboardController::class)->name('dashboard');

    // Kanal bağlama akışı (§13 · faz 1.4). Sağlık kontrolü POST'tur:
    // yan etkisi var (durum yazar) ve GET olsaydı tarayıcı ön yüklemesi
    // kanala habersiz istek atardı.
    Route::get('/channels', [ChannelConnectionController::class, 'index'])->name('channels.index');
    Route::get('/channels/create', [ChannelConnectionController::class, 'create'])->name('channels.create');
    Route::post('/channels', [ChannelConnectionController::class, 'store'])->name('channels.store');
    Route::post('/channels/{connection}/health', [ChannelConnectionController::class, 'health'])
        ->name('channels.health');

    // Bağlantı SONRASI kanal ayarları (Etsy beyanları, kargo profili). GET
    // ekranı kanaldan seçenek okur — yan etkisi yoktur, yalnızca okur.
    Route::get('/channels/{connection}/settings', [ChannelSettingsController::class, 'edit'])
        ->name('channels.settings.edit');
    Route::put('/channels/{connection}/settings', [ChannelSettingsController::class, 'update'])
        ->name('channels.settings.update');
    Route::get('/channels/{connection}/pricing', [ChannelPricingController::class, 'edit'])
        ->name('channels.pricing.edit');
    Route::put('/channels/{connection}/pricing', [ChannelPricingController::class, 'update'])
        ->name('channels.pricing.update');

    // ETSY OAUTH 2 + PKCE (V3.0 · §11.2 · §19 · P0-10) — projede İLK
    // OAuth akışı ve BİLİNÇLİ olarak `web` grubundadır: webhook
    // rotalarının aksine OTURUM ZORUNLUDUR, çünkü `state` ve
    // `code_verifier` oradan okunur ve kullanıcı kimliği bilinmelidir.
    //
    // Yönlendirme POST'tur: yan etkisi vardır (oturuma tek kullanımlık
    // sır yazar) ve GET olsaydı tarayıcı ön yüklemesi el sıkışmayı
    // habersiz başlatır, satıcının gerçek denemesindeki `state`'i
    // EZERDİ — sağlık kontrolünün POST olma gerekçesinin aynısı.
    Route::post('/channels/{connection}/etsy/authorize', [EtsyOAuthController::class, 'redirect'])
        ->name('channels.etsy.authorize');

    // Callback GET'tir çünkü Etsy satıcının TARAYICISINI buraya yollar;
    // biçimi kanal belirler, biz değil. `state` doğrulaması CSRF'in
    // yerini tutar (P0-10).
    Route::get('/channels/etsy/callback', [EtsyOAuthController::class, 'callback'])
        ->name('channels.etsy.callback');

    // eBay OAUTH 2 (V3.0 · §13.3 · §24 · P0-10) — projedeki İKİNCİ OAuth
    // akışı. İskelet Etsy ile aynıdır ve AYNI gerekçelerle `web`
    // grubundadır; içerik ise dört noktada ayrılır (PKCE YOK · istemci
    // kimliği Basic BAŞLIKTA · gövde form-encoded · `client_id` kasada).
    //
    // ⚠️ CALLBACK ADRESİ eBay'E BU HÂLİYLE VERİLMEZ: kanal `redirect_uri`
    // yerine "RuName" bekler (§13.3) ve gerçek adres eBay panelinde onun
    // altında saklanır. Yani bu rota eBay'in ÇÖZDÜĞÜ hedeftir, gönderdiğimiz
    // değer değildir.
    Route::post('/channels/{connection}/ebay/authorize', [EbayOAuthController::class, 'redirect'])
        ->name('channels.ebay.authorize');

    Route::get('/channels/ebay/callback', [EbayOAuthController::class, 'callback'])
        ->name('channels.ebay.callback');

    // Shopify — 34Pazar uygulaması (OAuth, authorization code grant).
    // Etsy ile aynı iskelet ve aynı gerekçeler (POST başlatma, GET dönüş,
    // `state` CSRF'in yerine). Dönüş adresi Shopify uygulamasında İZİNLİ
    // yönlendirme olarak kayıtlı olmalı: https://APP_DOMAIN/channels/shopify/callback
    Route::post('/channels/{connection}/shopify/authorize', [ShopifyOAuthController::class, 'redirect'])
        ->name('channels.shopify.authorize');

    Route::get('/channels/shopify/callback', [ShopifyOAuthController::class, 'callback'])
        ->name('channels.shopify.callback');

    // Shopify'dan başlayan kurulumun son adımı: kiracı bağlamında bağlantı.
    Route::get('/shopify/finish', [ShopifyInstallController::class, 'finish'])->name('shopify.install.finish');

    // Birden fazla depolu mağazada stok konumu seçimi.
    Route::post('/channels/{connection}/shopify/location', [ShopifyOAuthController::class, 'chooseLocation'])
        ->name('channels.shopify.location');

    // Ürün yönetimi (§13 · faz 1.2 · "panelde ürün oluşturma, düzenleme").
    // Açılış stoğu ledger üzerinden girer; içerik düzenlemesi stoğa dokunmaz.
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');

    // Toplu içe aktarma (§13 · Faz 3). Yükleme dosyayı KAYDEDER ve
    // `listing:bulk` kuyruğuna iş atar — istekte işlenseydi 500 satırlık
    // dosya zaman aşımına uğrar, kullanıcı yeniler ve dosya İKİ KEZ
    // işlenirdi.
    //
    // BU ROTA `/products/{product}/edit`'TEN ÖNCE GELİR: sonra gelseydi
    // Laravel `import` kelimesini bir ürün kimliği sanar ve ekran 404
    // verirdi.
    Route::get('/products/import', [ProductImportController::class, 'index'])
        ->name('products.import.index');
    Route::post('/products/import', [ProductImportController::class, 'store'])
        ->name('products.import.store');
    // Kanaldan ürün çekme (§13 · Faz 3 · madde 5). Aynı ekranın ikinci
    // kaynağı; AYNI durum satırını ve raporu kullanır.
    Route::post('/products/import/channel', [ProductImportController::class, 'storeFromChannel'])
        ->name('products.import.channel');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::put('/products/{product}/variants/{variant}/cost', [ProductController::class, 'updateCost'])
        ->name('products.variants.cost');

    // Kanala gönderme akışı (§13 · faz 1.5). Gönderme POST'tur: yan etkisi
    // var (listing satırı ve senkron operasyonu yaratır) ve GET olsaydı
    // tarayıcı ön yüklemesi ürünü habersiz kanala gönderirdi.
    Route::get('/products/{product}/channels', [ProductChannelController::class, 'index'])
        ->name('products.channels.index');
    Route::put('/products/{product}/listings/{listing}/price', [ProductChannelController::class, 'updatePrice'])
        ->name('products.listings.price');
    Route::post('/products/{product}/channels', [ProductChannelController::class, 'store'])
        ->name('products.channels.store');
    Route::post('/products/{product}/images/{image}/channels', [ProductChannelController::class, 'updateImageChannel'])
        ->name('products.images.channels');

    // Kategori ve öznitelik eşleştirme (§13 · Faz 2). Katalog aktarımının
    // ön koşulu: §14'ün `PrerequisiteGate`'i buradaki kararları okur.
    // Kaydetme POST'tur — yan etkisi var ve GET olsaydı tarayıcı ön
    // yüklemesi satıcı adına eşleştirme kaydederdi.
    Route::get('/mappings', [CategoryMappingController::class, 'index'])->name('mappings.index');
    Route::post('/mappings/category', [CategoryMappingController::class, 'storeCategory'])
        ->name('mappings.category.store');
    Route::post('/mappings/attribute', [CategoryMappingController::class, 'storeAttribute'])
        ->name('mappings.attribute.store');
    Route::post('/mappings/attribute-value', [CategoryMappingController::class, 'storeAttributeValue'])
        ->name('mappings.attribute-value.store');

    // Ürün/stok listesi (§13 · faz 1.2 · panel). Düzeltme POST'tur ve
    // ledger'a yazar: fazla satışın "düzeltme yolu" (§17 · P0).
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');

    // Sipariş listesi (§13 · faz 1.6 · "panelde sipariş listesi ve fazla
    // satış uyarısı"). Salt okunur: sipariş kanaldan gelir ve panelden
    // yaratılmaz. Ayrıntı rotası model bağlamasını kiracı scope'u altında
    // çözer; başka kiracının siparişi 404 verir.
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');

    // Kargo bildirimi — satıcı takip numarasını TEK yerden girer, sipariş
    // geldiği kanala gönderilir (Shopify, Woo). Siparişin kendisi yine
    // salt okunurdur; bu yalnız kargo satırı yazar.
    Route::post('/orders/{order}/shipments', [OrderController::class, 'ship'])->name('orders.ship');
    Route::post('/orders/{order}/shipments/{fulfillment}/retry', [OrderController::class, 'retryShipment'])
        ->name('orders.ship.retry');

    // Onay durumu ekranı (§13 · Faz 4, §14 · onay süreci). SALT OKUNUR:
    // onay kararını KANAL verir ve biz yalnızca okuruz (`approval:track`,
    // saatlik). Panelden "onayla" düğmesi koymak, kanalın kararını bizim
    // verebileceğimiz izlenimi yaratırdı.
    //
    // Ürün-kanal ekranının KOPYASI DEĞİLDİR: orası TEK ÜRÜN için "hangi
    // kanallarda ne durumda" der, burası TERSİNİ sorar — "kaç ürünüm onay
    // bekliyor, hangileri reddedildi". Yüz ürün gönderen satıcı reddedilen
    // üçünü bulmak için yüz kanal sekmesi açamaz.
    Route::get('/approvals', [ApprovalController::class, 'index'])
        ->name('approvals.index');

    // Mutabakat ekranı (§13 · Faz 4 · panel, §10). SALT OKUNUR: sürüklenme
    // tespiti ve onarımı zamanlanmış turların işidir ve panelden
    // tetiklenmez. Ekranın işi GÖRÜNÜRLÜK — §17'ye göre destek yükünü
    // belirleyen tek ekran budur.
    Route::get('/reconciliation', [ReconciliationController::class, 'index'])
        ->name('reconciliation.index');

    // §9 · FİYAT ÇAKIŞMASI KARARI — ekranın TEK yazma yolu.
    //
    // "SALT OKUNUR" KURALININ BİLİNÇLİ İSTİSNASI ve kural aslında
    // çiğnenmiyor: o kural sürüklenme TESPİTİ ve ONARIMI içindir (ikisi de
    // zamanlanmış turların işi). Burada tetiklenen şey bir tarama değil,
    // §9'un AÇIKÇA kullanıcıya bıraktığı karardır — "üzerine yazma,
    // KULLANICI SEÇER". Panelden gelmeyen bir karar hiçbir yerden gelemez.
    //
    // POST'tur: yan etkisi var (`price_overrides` satırı yazar veya outbox
    // olayı üretir). GET olsaydı tarayıcı ön yüklemesi kullanıcı adına
    // fiyat kararı verirdi.
    //
    // ROTA MODEL BAĞLAMASI KULLANILMAZ — `SubstituteBindings` `tenant` ara
    // katmanından ÖNCE çalışır ve sorgu bağlamsız atılırdı.
    Route::post('/reconciliation/price-conflict', [ReconciliationController::class, 'resolvePriceConflict'])
        ->name('reconciliation.price-conflict');

    // Ölü mektup ekranı (§12 · adım 4 ve 5, §13 · Faz 3 · madde 3+4).
    // İlk üç adım (operasyon `dead`, sync state `error_*`, `failed_jobs`)
    // zaten çalışıyordu; eksik olan panel ve butondu. Onlar olmadan ölü
    // satır SONSUZA KADAR ölü kalır: `error_permanent` mutabakatta asla
    // aday değildir ve o satıra başka hiçbir mekanizma dokunmaz.
    //
    // Yeniden deneme POST'tur: yan etkisi var (outbox olayı ve yeni
    // operasyon üretir) ve GET olsaydı tarayıcı ön yüklemesi kullanıcı
    // adına tüm başarısız işlemleri yeniden kuyruğa alırdı.
    //
    // ROTA MODEL BAĞLAMASI KULLANILMAZ: `SubstituteBindings` `tenant` ara
    // katmanından ÖNCE çalışır ve sorgu bağlamsız atılırdı. Kimlik gövdede
    // `string` taşınır ve kontrolcüde kiracı scope'u altında aranır.
    // Metrik ekranı (§11, §13 · Faz 3 · madde 2). SALT OKUNUR: ölçüm
    // saatlik `metrics:capture` turunun işidir ve panelden tetiklenmez.
    // §17 bu maddeyi P0'a koyuyor — ölçülmeyen güvenilirlik iddia
    // edilemez ve ölçülüp gösterilmeyen de aynı kapıya çıkar.
    Route::get('/metrics', [MetricsController::class, 'index'])->name('metrics.index');

    // Abonelik ve ödeme (§13 · Faz 4). Ödeme başlatma POST'tur: yan
    // etkisi var (Stripe'ta oturum açar) ve GET olsaydı tarayıcı ön
    // yüklemesi kullanıcı adına ödeme sayfası açardı.
    //
    // ABONELİK BURADA YAZILMAZ — webhook yazar (`/webhooks/stripe`).
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    // Shopify abonelik onayından dönüş — durum Shopify'dan okunur.
    Route::get('/billing/shopify/return', [BillingController::class, 'shopifyReturn'])->name('billing.shopify.return');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])
        ->name('billing.checkout');

    Route::get('/failures', [SyncFailureController::class, 'index'])
        ->name('failures.index');
    Route::post('/failures/retry', [SyncFailureController::class, 'retry'])
        ->name('failures.retry');

    // Yardım (§13 · Faz 4). Kiracı verisi OKUMAZ ama panelin parçasıdır
    // ve gezinme şeridiyle birlikte görünmesi için grubun içindedir.
    Route::get('/help', HelpController::class)->name('help');
});

Route::post('/logout', [SessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
