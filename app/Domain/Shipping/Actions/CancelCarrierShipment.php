<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Actions;

use App\Domain\Shipping\Jobs\CancelCarrierShipmentJob;
use App\Domain\Shipping\Models\CarrierShipment;
use App\Support\Tenancy\TenantContext;

/**
 * Kargoya VERİLMEMİŞ gönderiyi firmada iptal eder (satıcının açık kararı).
 *
 * Kargoya verilmiş gönderi buradan iptal edilmez: firma paketi taşıyordur;
 * iade/geri çağırma firmanın kendi sürecidir.
 *
 * Koşullu geçiş (`created → cancelling`): çift tıklama iki iş kuyruğa koymaz.
 */
final class CancelCarrierShipment
{
    public function run(CarrierShipment $shipment): bool
    {
        $claimed = CarrierShipment::query()
            ->whereKey($shipment->id)
            ->where('status', CarrierShipment::STATUS_CREATED)
            ->whereNull('fulfillment_id')
            ->update([
                'status' => CarrierShipment::STATUS_CANCELLING,
                'cancel_requested_at' => now(),
                'reason_code' => null,
                'error_detail' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        CancelCarrierShipmentJob::dispatch($shipment->id, TenantContext::idOrFail())->onQueue('orders:high')->afterCommit();

        return true;
    }
}
