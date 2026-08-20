<?php

declare(strict_types=1);

use App\Http\Controllers\StripeWebhookController;
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
/*
| Ödeme sağlayıcısı webhook'u (§13 · Faz 4).
|
| KANAL ROTASINDAN ÖNCE GELİR: kanal rotası `{connectionId}` yakalıyor
| ve `stripe` bir uuid'e benzemese de rota sırası bilinçli tutulur —
| yeni bir sağlayıcı eklendiğinde aynı kalıp korunur.
|
| Abonelik durumunun TEK gerçek kaynağı burasıdır: ödeme alındı mı,
| dönem ne zaman bitiyor, iptal edildi mi — bunları Stripe bilir.
*/
Route::post('/webhooks/stripe', StripeWebhookController::class)
    ->name('webhooks.stripe');

Route::post('/webhooks/{connectionId}', WebhookController::class)
    ->name('webhooks.receive')
    ->where('connectionId', '[0-9a-fA-F-]{36}');
