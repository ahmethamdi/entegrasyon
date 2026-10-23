<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Actions;

use App\Domain\Orders\Actions\RecordPanelShipment;
use App\Domain\Shipping\Models\CarrierShipment;
use Throwable;

/**
 * Gönderi kargoya verildi: takip numarası siparişe yazılır ve MEVCUT yolla
 * (`RecordPanelShipment` → `PushFulfillment`) kanala bildirilir.
 *
 * İki tetikleyici: firmanın durum sorgusu "teslim aldı" der (Yurtiçi) ya da
 * satıcı "kargoya verdim" der (durum sorgusu olmayan Aras için asıl yol).
 *
 * BİR KEZ: `shipped_at` koşullu güncellemeyle sahiplenilir; iki işçi ya da
 * yoklama + düğme aynı anda gelirse yalnız biri kanala bildirir.
 *
 * Takip numarası YOKSA bildirilmez (`false`): kanala numarasız "kargolandı"
 * gitmesi müşteriye takip edilemeyen sipariş demektir.
 */
final class HandOverCarrierShipment
{
    public function __construct(private readonly RecordPanelShipment $record) {}

    public function run(CarrierShipment $shipment, ?string $actorId = null): bool
    {
        if ($shipment->fulfillment_id !== null) {
            return true;
        }

        if ($shipment->tracking_number === null || $shipment->tracking_number === '') {
            return false;
        }

        if (! in_array($shipment->status, CarrierShipment::TRACKED, true)) {
            return false;
        }

        $shipment->loadMissing('order');
        $order = $shipment->order;

        if ($order === null) {
            return false;
        }

        // Sahiplenme (transaction DIŞINDA, koşullu): `RecordPanelShipment`
        // kanal işini commit'ten hemen sonra kuyruğa koyar; dış bir
        // transaction içinde çağrılsaydı işçi satırı henüz göremez ve
        // bildirim sessizce kaybolurdu.
        $claimed = CarrierShipment::query()
            ->whereKey($shipment->id)
            ->whereNull('fulfillment_id')
            ->whereNull('shipped_at')
            ->whereIn('status', CarrierShipment::TRACKED)
            ->update(['shipped_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return true;
        }

        try {
            $fulfillment = $this->record->run($order, $shipment->carrierName(), (string) $shipment->tracking_number, $actorId);
        } catch (Throwable $e) {
            CarrierShipment::query()->whereKey($shipment->id)->whereNull('fulfillment_id')->update(['shipped_at' => null]);

            throw $e;
        }

        CarrierShipment::query()->whereKey($shipment->id)->update([
            'fulfillment_id' => $fulfillment->id,
            'status' => CarrierShipment::STATUS_SHIPPED,
            'updated_at' => now(),
        ]);

        $shipment->refresh();

        return true;
    }
}
