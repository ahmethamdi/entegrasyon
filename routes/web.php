<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\ChannelConnectionController;
use App\Http\Controllers\DashboardController;
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
});

Route::post('/logout', [SessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
