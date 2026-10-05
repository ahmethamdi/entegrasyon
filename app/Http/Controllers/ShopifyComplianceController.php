<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Actions\RevokeChannelAccess;
use App\Domain\Channels\Adapters\Shopify\ShopifyAuth;
use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shopify'ın ZORUNLU gizlilik webhook'ları — herkese açık uygulamada şart.
 *
 * Adres uygulama ayarlarında TEK satırdır (mağaza başına abonelik değil):
 * https://APP_DOMAIN/webhooks/shopify/compliance — konu `X-Shopify-Topic`
 * başlığındadır. İnceleme iki şeyi sınar: geçersiz imzaya 401, geçerliye 2xx.
 *
 * NE TUTUYORUZ (kişisel veri): `orders.customer_ref`, ham webhook gövdesi
 * (`inbox_messages.payload`) ve sipariş olay gövdesi (`order_events.payload`).
 * Stok/ürün verisi kişisel değildir, dokunulmaz.
 *
 * - customers/data_request : veri satıcıya 30 gün içinde ELLE verilir
 *   (kayıt düşülür; otomatik e-posta yok — kimin isteği olduğu Shopify'da).
 * - customers/redact       : o müşterinin listelenen siparişlerinde kişisel
 *   alanlar silinir. Sipariş kaydı KALIR (stok hareketi ona bağlı).
 * - shop/redact            : uygulama kaldırıldıktan 48 saat sonra gelir;
 *   mağazanın TÜM siparişlerinde kişisel alanlar silinir, erişim kapatılır.
 */
final class ShopifyComplianceController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();

        if (! ShopifyAuth::webhookHmacValid($raw, $request->header('X-Shopify-Hmac-Sha256'))) {
            Log::warning('shopify.compliance.signature_invalid');

            return response()->noContent(401);
        }

        $topic = (string) $request->header('X-Shopify-Topic');
        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : [];
        $shop = (string) ($body['shop_domain'] ?? $request->header('X-Shopify-Shop-Domain'));

        $connectionIds = TenantContext::runAsSystem(fn () => ChannelConnection::query()
            ->where('channel_type_code', 'shopify')
            ->where('external_account_id', $shop)
            ->pluck('id')
            ->all());

        Log::info('shopify.compliance.received', [
            'topic' => $topic,
            'shop' => $shop,
            'connections' => count($connectionIds),
            // Shopify'ın istek kimliği — satıcıya veri verirken eşleştirme için.
            'data_request_id' => $body['data_request']['id'] ?? null,
        ]);

        if ($connectionIds === []) {
            return response()->noContent(200);   // mağaza bizde yok: tutulan veri de yok
        }

        match ($topic) {
            'customers/redact' => $this->redactOrders(
                $connectionIds,
                array_map('strval', (array) ($body['orders_to_redact'] ?? [])),
            ),
            'shop/redact' => $this->redactShop($connectionIds),
            default => null,   // customers/data_request: kayıt yeterli
        };

        return response()->noContent(200);
    }

    /**
     * @param  list<string>  $connectionIds
     * @param  list<string>|null  $externalOrderIds  null = mağazanın TÜM siparişleri
     */
    private function redactOrders(array $connectionIds, ?array $externalOrderIds): void
    {
        if ($externalOrderIds === []) {
            return;
        }

        TenantContext::runAsSystem(function () use ($connectionIds, $externalOrderIds): void {
            DB::transaction(function () use ($connectionIds, $externalOrderIds): void {
                $orders = DB::table('orders')
                    ->whereIn('channel_connection_id', $connectionIds)
                    ->when($externalOrderIds !== null, fn ($q) => $q->whereIn('external_id', $externalOrderIds));

                $orderIds = (clone $orders)->pluck('id')->all();

                (clone $orders)->update(['customer_ref' => null]);

                if ($orderIds !== []) {
                    DB::table('order_events')->whereIn('order_id', $orderIds)->update(['payload' => null]);
                }

                // Ham webhook gövdesi: Shopify sipariş gövdesinde kimlik `id`.
                // İSTENEN kimliklerle eşlenir, bulunan siparişlerle değil:
                // henüz işlenmemiş sipariş yalnız burada durur.
                DB::table('inbox_messages')
                    ->whereIn('channel_connection_id', $connectionIds)
                    ->when($externalOrderIds !== null, fn ($q) => $q->whereIn(DB::raw("payload->>'id'"), $externalOrderIds))
                    ->update(['payload' => json_encode(['redacted' => true])]);
            });
        });
    }

    /** @param list<string> $connectionIds */
    private function redactShop(array $connectionIds): void
    {
        $this->redactOrders($connectionIds, null);

        TenantContext::runAsSystem(function () use ($connectionIds): void {
            foreach (ChannelConnection::query()->whereIn('id', $connectionIds)->get() as $connection) {
                app(RevokeChannelAccess::class)->run($connection, 'Shopify mağaza verisinin silinmesini istedi (shop/redact).');
            }
        });
    }
}
