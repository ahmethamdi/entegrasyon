<?php

declare(strict_types=1);

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
| Kanal webhook uç noktaları.
|
| Mimari Karar Dokümanı v2.2 · §6 · Inbox HTTP katmanı, §11 · Güvenlik.
|
| BU ROTALAR web GRUBUNDA DEĞİLDİR:
|   - CSRF muaf: dış sistem token taşıyamaz. Muafiyetin bedeli imza
|     doğrulamasıyla ödenir ve o ZORUNLUDUR (§11).
|   - Oturum yok: her istek kendi başına doğrulanır, durum taşınmaz.
|
| Kimlik doğrulama HMAC iledir ve controller'da HAM GÖVDE üzerinden yapılır.
*/
Route::post('/webhooks/{connectionId}', WebhookController::class)
    ->name('webhooks.receive')
    ->where('connectionId', '[0-9a-fA-F-]{36}');
