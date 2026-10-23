<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Jobs;

use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Shipping\Exceptions\CarrierException;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Models\CarrierShipment;
use App\Domain\Shipping\Support\Carriers;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Support\RetryPolicy;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Gönderiyi firmada iptal eder. İptal TEKRAR GÜVENLİDİR: zaten iptal
 * edilmiş gönderide firma "yok / daha önce iptal" der ve sağlayıcı bunu
 * başarı sayar (ölçüldü). Firma reddederse (ör. kargo teslim almış)
 * gönderi `created`'a döner ve firmanın metni gösterilir — sessizce
 * "iptal edildi" denmez.
 */
final class CancelCarrierShipmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $shipmentId,
        public readonly string $tenantId,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(Carriers $carriers): void
    {
        TenantContext::set($this->tenantId);

        try {
            $this->cancel($carriers);
        } finally {
            TenantContext::clear();
        }
    }

    public function failed(?Throwable $e): void
    {
        TenantContext::set($this->tenantId);

        try {
            $this->revert(FulfillmentReason::CARRIER_UNAVAILABLE, $e?->getMessage());
        } finally {
            TenantContext::clear();
        }
    }

    private function cancel(Carriers $carriers): void
    {
        $shipment = CarrierShipment::query()->find($this->shipmentId);

        if ($shipment === null || $shipment->status !== CarrierShipment::STATUS_CANCELLING) {
            return;
        }

        $account = CarrierAccount::query()->where('provider', $shipment->provider)->first();

        if ($account === null || ! $account->isUsable()) {
            $this->revert(FulfillmentReason::CARRIER_ACCOUNT_MISSING);

            return;
        }

        try {
            $carriers->for($shipment->provider)->cancel($account, $shipment->cargo_key);
        } catch (CarrierException $e) {
            if ($e->class === ErrorClass::AUTHENTICATION) {
                $account->forceFill(['status' => CarrierAccount::STATUS_REVOKED, 'last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
                $this->revert(FulfillmentReason::CARRIER_AUTH_FAILED, $e->getMessage());

                return;
            }

            if ($e->class->isPermanent()) {
                $this->revert(FulfillmentReason::CARRIER_REJECTED, $e->getMessage());

                return;
            }

            $shipment->increment('attempts');
            $delay = RetryPolicy::delayFor($e->class, $shipment->attempts);

            if ($delay === null) {
                $this->revert(FulfillmentReason::CARRIER_UNAVAILABLE, $e->getMessage());

                return;
            }

            $this->release($delay);

            return;
        }

        $shipment->forceFill([
            'status' => CarrierShipment::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'attempts' => 0,
            'reason_code' => null,
            'error_detail' => null,
        ])->save();
    }

    /** İptal olmadı: gönderi firmada yaşıyor, `created`'a döner; sebep görünür. */
    private function revert(FulfillmentReason $reason, ?string $detail = null): void
    {
        CarrierShipment::query()
            ->whereKey($this->shipmentId)
            ->where('status', CarrierShipment::STATUS_CANCELLING)
            ->update([
                'status' => CarrierShipment::STATUS_CREATED,
                'reason_code' => $reason->value,
                'error_detail' => $detail === null ? null : mb_substr($detail, 0, 500),
                'attempts' => 0,
                'updated_at' => now(),
            ]);
    }
}
