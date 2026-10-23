<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Actions;

use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Fulfillment\Exceptions\SelectionInvalid;
use App\Domain\Fulfillment\Models\FulfillmentBatch;
use App\Domain\Fulfillment\Support\FulfillmentPolicy;
use App\Domain\Fulfillment\Support\InvoiceDocuments;
use App\Domain\Fulfillment\Support\ItemDecision;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Orders\Models\Order;
use App\Domain\Shipping\Actions\RequestCarrierShipment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * Sipariş listesinden seçilen siparişlere TOPLU depo işlemi başlatır.
 *
 * KİRACI: kimlikler tarayıcıdan gelir ve GÜVENİLMEZ — kiracı scope'u
 * altında çözülür; biri bile çözülemezse istek bütünüyle reddedilir
 * (`SelectionInvalid`) ve hiçbir yan etki oluşmaz.
 *
 * ÇİFT GÖNDERİM: form tek kullanımlık anahtar taşır; aynı anahtar ikinci
 * kez gelirse İLK toplu işlem döner (`fulfillment_batches_idempotency_unique`).
 * Anahtar yalnız kullanıcı isteğini korur; dış yan etkilerin asıl koruması
 * İŞ KİMLİĞİDİR (paket başına tek etiket, sipariş başına tek fatura).
 *
 * KALEM BAŞINA SONUÇ: her sipariş açık bir karar alır; biri başarısız
 * olsa da diğerleri işlenir. Ağ işi kuyrukta — tarayıcı isteği kanalı
 * beklemez.
 */
final class StartFulfillmentBatch
{
    public const MAX_ORDERS = 100;

    public function __construct(
        private readonly RequestShippingLabel $labels,
        private readonly InvoiceDocuments $invoices,
        private readonly RequestCarrierShipment $shipments,
    ) {}

    /** @param list<string> $orderIds */
    public function run(string $action, array $orderIds, string $idempotencyKey, ?string $userId): FulfillmentBatch
    {
        if (! in_array($action, FulfillmentBatch::ACTIONS, true)) {
            throw new InvalidArgumentException("Bilinmeyen toplu işlem: {$action}");
        }

        $ids = array_values(array_unique(array_filter($orderIds, 'is_string')));

        if ($ids === [] || count($ids) > self::MAX_ORDERS) {
            throw new SelectionInvalid;
        }

        $tenantId = TenantContext::idOrFail();

        return DB::transaction(function () use ($action, $ids, $idempotencyKey, $userId, $tenantId): FulfillmentBatch {
            $existing = FulfillmentBatch::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            // Kiracı scope'u ALTINDA çözülür (BelongsToTenant). UUID olmayan
            // kimlik de "bulunamadı" ile aynı cevabı alır.
            $valid = array_values(array_filter($ids, static fn (string $id): bool => preg_match('/^[0-9a-f-]{36}$/i', $id) === 1));
            $orders = Order::query()
                ->whereKey($valid)
                ->with(['connection.channelType:code,adapter_class', 'invoice'])
                ->get()
                ->keyBy('id');

            if ($orders->count() !== count($ids)) {
                throw new SelectionInvalid;
            }

            $batchId = (string) new UuidV7;
            $inserted = DB::table('fulfillment_batches')->insertOrIgnore([
                'id' => $batchId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'action' => $action,
                'idempotency_key' => $idempotencyKey,
                'total' => count($ids),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                // Eşzamanlı aynı gönderim — öteki kazandı.
                return FulfillmentBatch::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            }

            $account = $action === FulfillmentBatch::ACTION_ISSUE_INVOICES ? InvoiceAccount::query()->first() : null;
            $rows = [];

            foreach ($ids as $id) {
                $order = $orders[$id];
                $decision = $this->decide($action, $order, $account, $userId);

                $rows[] = [
                    'id' => (string) new UuidV7,
                    'tenant_id' => $tenantId,
                    'batch_id' => $batchId,
                    'order_id' => $order->id,
                    'outcome' => $decision->outcome,
                    'reason_code' => $decision->reason?->value,
                    'shipping_label_id' => $decision->labelId,
                    'carrier_shipment_id' => $decision->shipmentId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('fulfillment_batch_items')->insert($rows);

            return FulfillmentBatch::query()->findOrFail($batchId);
        });
    }

    private function decide(string $action, Order $order, ?InvoiceAccount $account, ?string $userId): ItemDecision
    {
        return match ($action) {
            FulfillmentBatch::ACTION_LABELS => $this->labels->run($order, $userId),
            FulfillmentBatch::ACTION_INVOICE_DOCUMENTS => $this->invoices->decide($order),
            FulfillmentBatch::ACTION_ISSUE_INVOICES => $this->invoices->issue($order, $account, $userId),
            FulfillmentBatch::ACTION_PICKING => FulfillmentPolicy::allows($order, FulfillmentPolicy::PICK)
                ? ItemDecision::succeeded()
                : ItemDecision::actionRequired(FulfillmentReason::INVALID_ORDER_STATE),
            FulfillmentBatch::ACTION_CARRIER_YURTICI,
            FulfillmentBatch::ACTION_CARRIER_ARAS => $this->shipments->run($order, FulfillmentBatch::CARRIER_ACTIONS[$action], $userId),
        };
    }
}
