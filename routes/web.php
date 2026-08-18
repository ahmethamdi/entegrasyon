<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CategoryMappingController;
use App\Http\Controllers\ChannelConnectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductChannelController;
use App\Http\Controllers\ProductController;
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
    Route::post('/register', [RegisteredUserController::class, 'store']);
});

// ─────────────────────────────────────────────────────────── panel

Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    // Kanal bağlama akışı (§13 · faz 1.4). Sağlık kontrolü POST'tur:
    // yan etkisi var (durum yazar) ve GET olsaydı tarayıcı ön yüklemesi
    // kanala habersiz istek atardı.
    Route::get('/channels', [ChannelConnectionController::class, 'index'])->name('channels.index');
    Route::get('/channels/create', [ChannelConnectionController::class, 'create'])->name('channels.create');
    Route::post('/channels', [ChannelConnectionController::class, 'store'])->name('channels.store');
    Route::post('/channels/{connection}/health', [ChannelConnectionController::class, 'health'])
        ->name('channels.health');

    // Ürün yönetimi (§13 · faz 1.2 · "panelde ürün oluşturma, düzenleme").
    // Açılış stoğu ledger üzerinden girer; içerik düzenlemesi stoğa dokunmaz.
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');

    // Kanala gönderme akışı (§13 · faz 1.5). Gönderme POST'tur: yan etkisi
    // var (listing satırı ve senkron operasyonu yaratır) ve GET olsaydı
    // tarayıcı ön yüklemesi ürünü habersiz kanala gönderirdi.
    Route::get('/products/{product}/channels', [ProductChannelController::class, 'index'])
        ->name('products.channels.index');
    Route::post('/products/{product}/channels', [ProductChannelController::class, 'store'])
        ->name('products.channels.store');

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
});

Route::post('/logout', [SessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
