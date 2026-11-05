<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Actions\RevokeChannelAccess;
use App\Domain\Channels\Adapters\Shopify\ShopifyAuth;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\CredentialVault;
use App\Support\Privacy\SealedJson;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    public function __construct(private readonly CredentialVault $vault) {}

    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();
        $given = $request->header('X-Shopify-Hmac-Sha256');
        $given = is_string($given) ? $given : null;

        $topic = (string) $request->header('X-Shopify-Topic');
        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : [];
        $shop = (string) ($body['shop_domain'] ?? $request->header('X-Shopify-Shop-Domain'));

        $connections = TenantContext::runAsSystem(fn () => ChannelConnection::query()
            ->where('channel_type_code', 'shopify')
            ->where('external_account_id', $shop)
            ->get());

        // İMZA KARAR VERİR; MAĞAZA ADI YALNIZ HANGİ SIRRIN DENENECEĞİNİ SEÇER.
        //
        // İki imzacı olabilir: 34Pazar uygulaması (global sır) ya da
        // SATICININ KENDİ UYGULAMASI (o mağazanın bağlantısındaki sır,
        // ADR-012). Mağaza adı imzasız gövdeden okunur ama yalnız hangi
        // sırların DENENECEĞİNİ seçer: başka mağazanın adını yazan biri o
        // mağazanın sırrını bilmeden geçemez. Hiçbiri tutmazsa 401 —
        // mağaza bizde yoksa da (imzayı doğrulayacak sır yok).
        if (! $this->signedByKnownApp($raw, $given, $connections)) {
            Log::warning('shopify.compliance.signature_invalid');

            return response()->noContent(401);
        }

        $connectionIds = $connections->pluck('id')->all();

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

    /** @param Collection<int, ChannelConnection> $connections */
    private function signedByKnownApp(string $raw, ?string $given, Collection $connections): bool
    {
        if (ShopifyAuth::webhookHmacValid($raw, $given)) {
            return true;
        }

        foreach ($connections as $connection) {
            $own = TenantContext::runAsSystem(function () use ($connection): ?string {
                try {
                    return ShopifyAuth::ownAppSecret($this->vault->read($connection));
                } catch (Throwable) {
                    return null;   // kasa okunamadı: bu sır DENENMEZ, geçmiş sayılmaz
                }
            });

            if (ShopifyAuth::webhookHmacValidWith($raw, $given, $own)) {
                return true;
            }
        }

        return false;
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

                // Ham webhook gövdesi: Shopify sipariş gövdesinde kimlik `id`, gelişte
                // `resource_id`'ye yazılır (gövde şifreli, içinde sorgulanamaz).
                // İSTENEN kimliklerle eşlenir, bulunan siparişlerle değil:
                // henüz işlenmemiş sipariş yalnız burada durur.
                DB::table('inbox_messages')
                    ->whereIn('channel_connection_id', $connectionIds)
                    ->when($externalOrderIds !== null, fn ($q) => $q->whereIn('resource_id', $externalOrderIds))
                    ->update(['payload' => SealedJson::seal(['redacted' => true])]);
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
