<?php

declare(strict_types=1);

use App\Http\Controllers\ShopifyComplianceController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WooAuthController;
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

// Shopify zorunlu gizlilik webhook'ları — uygulama ayarlarında TEK adres.
// Kanal rotasından önce: `shopify/compliance` uuid değil ama sıra kalıbı korunur.
Route::post('/webhooks/shopify/compliance', ShopifyComplianceController::class)
    ->name('webhooks.shopify.compliance');

/*
| S5 · WooCommerce anahtar teslimi (`/wc-auth/v1/authorize` callback'i).
| Mağaza sunucusu JSON POST eder; kimlik doğrulaması tek kullanımlık,
| süreli `user_id` nonce'udur (yalnız özeti saklanır). 200 dışı cevapta Woo
| anahtarı siler. Kanal rotasından ÖNCE (bkz. Stripe notu).
*/
Route::post('/webhooks/woocommerce/auth', [WooAuthController::class, 'callback'])
    ->name('webhooks.woocommerce.auth');

Route::post('/webhooks/{connectionId}', WebhookController::class)
    ->name('webhooks.receive')
    ->where('connectionId', '[0-9a-fA-F-]{36}');
