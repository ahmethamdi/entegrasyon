<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Orders\Enums\OrderEventType;
use App\Domain\Orders\Jobs\PushFulfillment;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Satıcının panelden girdiği kargo bildirimini kaydeder ve kanala yollar.
 *
 * Satıcı çok kanallıdır; takip numarasını her kanalın kendi panelinde ayrı
 * ayrı girmek zorunda kalmasın diye TEK yerden girer. Kayıt önce bizde
 * yazılır, gönderim kuyrukta yapılır: kanal o an cevap vermese de bildirim
 * KAYBOLMAZ ve satıcı durumunu sipariş ekranında görür.
 *
 * DEĞİŞMEZ KURAL — KARGO STOK HAREKETİ ÜRETMEZ (`UpdateFulfillment` ile
 * aynı kural): mal satışta düşülmüştür.
 *
 * DEĞİŞMEZ KURAL — İŞ COMMIT'TEN SONRA ATILIR: işlem geri alınırsa kuyruğa
 * düşmüş iş var olmayan satırı arar ve sessizce biter.
 */
final class RecordPanelShipment
{
    public function run(Order $order, ?string $carrier, string $trackingNumber, ?string $actorId = null): Fulfillment
    {
        $tenantId = TenantContext::idOrFail();

        $fulfillment = DB::transaction(function () use ($order, $carrier, $trackingNumber, $actorId, $tenantId): Fulfillment {
            $fulfillment = Fulfillment::query()->create([
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'carrier' => $carrier,
                'tracking_number' => $trackingNumber,
                'status' => 'shipped',
                'shipped_at' => now(),
                'source' => Fulfillment::SOURCE_PANEL,
                'push_status' => Fulfillment::PUSH_PENDING,
            ]);

            OrderEvent::create([
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'type' => OrderEventType::FULFILLED,
                'external_ref' => null,
                'payload' => array_filter([
                    'fulfillment_id' => $fulfillment->id,
                    'carrier' => $carrier,
                    'tracking_number' => $trackingNumber,
                    'actor_id' => $actorId,
                ], static fn (mixed $v): bool => $v !== null),
                'occurred_at' => now(),
                'source' => 'panel',
            ]);

            return $fulfillment;
        });

        PushFulfillment::dispatch($fulfillment->id, $tenantId)->onQueue('orders:high');

        return $fulfillment;
    }

    /**
     * Gönderilemeyen bildirimi yeniden kuyruğa koyar.
     *
     * Satıcı numarayı düzeltmiş olabilir (çoğu VALIDATION hatası yanlış
     * numaradır); yeni değerler verildiyse yazılır. Bütçe sıfırlanır: bu
     * satıcının bilinçli yeni denemesidir.
     */
    public function retry(Fulfillment $fulfillment, ?string $carrier = null, ?string $trackingNumber = null): void
    {
        $fulfillment->forceFill(array_filter([
            'carrier' => $carrier,
            'tracking_number' => $trackingNumber,
        ], static fn (mixed $v): bool => $v !== null && $v !== ''))
            ->forceFill([
                'push_status' => Fulfillment::PUSH_PENDING,
                'push_attempts' => 0,
                'push_error' => null,
            ])
            ->save();

        PushFulfillment::dispatch($fulfillment->id, TenantContext::idOrFail())->onQueue('orders:high');
    }
}
