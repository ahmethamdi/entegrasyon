<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Jobs;

use App\Domain\Shipping\Actions\HandOverCarrierShipment;
use App\Domain\Shipping\Exceptions\CarrierException;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Models\CarrierShipment;
use App\Domain\Shipping\Support\Carriers;
use App\Domain\Shipping\Support\TrackingResult;
use App\Domain\Sync\Enums\ErrorClass;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Bir gönderinin firmadaki durumunu okur ve çekirdeğe işler.
 *
 *   waiting   → yalnız firma durumu yazılır
 *   shipped   → takip numarası yazılır; varsa kanala bildirilir (BİR KEZ)
 *   delivered → aynısı + `delivered`
 *   cancelled → firmada iptal edilmiş (satıcı firmanın panelinden iptal etti)
 *   unknown   → HİÇBİR ŞEY değiştirilmez; ham kod ekranda görünür
 *
 * Hata gönderiyi BOZMAZ: geçici hata yalnız bir sonraki tura kalır; kimlik
 * hatası hesabı "koptu"ya düşürür; "firmada yok" metni ekrana yazılır ama
 * durum değiştirilmez (tahminle "iptal" denmez).
 */
final class TrackCarrierShipment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $shipmentId,
        public readonly string $tenantId,
    ) {}

    public function handle(Carriers $carriers, HandOverCarrierShipment $handOver): void
    {
        TenantContext::set($this->tenantId);

        try {
            $this->track($carriers, $handOver);
        } finally {
            TenantContext::clear();
        }
    }

    private function track(Carriers $carriers, HandOverCarrierShipment $handOver): void
    {
        $shipment = CarrierShipment::query()->find($this->shipmentId);

        if ($shipment === null || ! in_array($shipment->status, CarrierShipment::TRACKED, true)) {
            return;
        }

        $carrier = $carriers->for($shipment->provider);
        $account = CarrierAccount::query()->where('provider', $shipment->provider)->first();

        if (! $carrier->supportsTracking() || $account === null || ! $account->isUsable()) {
            return;
        }

        try {
            $result = $carrier->track($account, $shipment->cargo_key);
        } catch (CarrierException $e) {
            if ($e->class === ErrorClass::AUTHENTICATION) {
                $account->forceFill(['status' => CarrierAccount::STATUS_REVOKED, 'last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            }

            $shipment->forceFill([
                'last_tracked_at' => now(),
                'carrier_status_text' => $e->class === ErrorClass::NOT_FOUND ? mb_substr($e->getMessage(), 0, 255) : $shipment->carrier_status_text,
            ])->save();

            return;
        }

        $shipment->forceFill(array_filter([
            'last_tracked_at' => now(),
            'carrier_status' => $result->carrierStatus,
            'carrier_status_text' => $result->carrierText === null ? null : mb_substr($result->carrierText, 0, 255),
            'tracking_number' => $shipment->tracking_number ?? $result->trackingNumber,
            'tracking_url' => $result->trackingUrl ?? $shipment->tracking_url,
        ], static fn (mixed $v): bool => $v !== null))->save();

        if ($result->state === TrackingResult::CANCELLED) {
            if ($shipment->status === CarrierShipment::STATUS_CREATED) {
                $shipment->forceFill(['status' => CarrierShipment::STATUS_CANCELLED, 'cancelled_at' => now()])->save();
            }

            return;
        }

        if (in_array($result->state, [TrackingResult::SHIPPED, TrackingResult::DELIVERED], true)) {
            $handOver->run($shipment);

            if ($result->state === TrackingResult::DELIVERED && $shipment->fulfillment_id !== null) {
                $shipment->forceFill(['status' => CarrierShipment::STATUS_DELIVERED, 'delivered_at' => now()])->save();
            }
        }
    }
}
