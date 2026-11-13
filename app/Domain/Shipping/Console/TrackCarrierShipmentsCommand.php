<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Console;

use App\Domain\Shipping\Jobs\TrackCarrierShipment;
use App\Domain\Shipping\Models\CarrierShipment;
use App\Domain\Shipping\Support\Carriers;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Firmada yaşayan gönderilerin durumunu yoklar (S6 · Kargo II).
 *
 * Yalnız durum sorgusu olan firmaların (Yurtiçi, HepsiJet) gönderileri işe dönüşür;
 * iş kendisi `supportsTracking()`'e bakar. Gönderi açıldıktan sonra
 * `track_days` gün izlenir; daha eskisi listeye girmez (sonsuz yoklama yok).
 * Tur başına en çok `LIMIT` gönderi, en uzun süredir bakılmayan önce.
 */
final class TrackCarrierShipmentsCommand extends Command
{
    public const LIMIT = 500;

    /** Aynı gönderiye bundan sık bakılmaz. */
    public const MIN_INTERVAL_MINUTES = 25;

    protected $signature = 'shipping:track';

    protected $description = 'Kargo firmasındaki gönderilerin durumunu yoklar, kargoya verileni kanala bildirir';

    public function handle(Carriers $carriers): int
    {
        if (! config('shipping.enabled')) {
            $this->info('Kargo entegrasyonu kapalı (CARRIER_SHIPPING_ENABLED=false).');

            return self::SUCCESS;
        }

        $providers = $carriers->trackingProviders();

        $rows = TenantContext::runAsSystem(fn () => CarrierShipment::query()
            ->whereIn('status', CarrierShipment::TRACKED)
            ->whereIn('provider', $providers)
            ->where('confirmed_at', '>=', now()->subDays((int) config('shipping.track_days', 30)))
            ->where(fn ($q) => $q->whereNull('last_tracked_at')->orWhere('last_tracked_at', '<', now()->subMinutes(self::MIN_INTERVAL_MINUTES)))
            ->orderByRaw('last_tracked_at NULLS FIRST')
            ->limit(self::LIMIT)
            ->get(['id', 'tenant_id']));

        foreach ($rows as $row) {
            TrackCarrierShipment::dispatch($row->id, $row->tenant_id)->onQueue('maintenance');
        }

        $this->info("Kuyruğa alınan gönderi: {$rows->count()}");

        return self::SUCCESS;
    }
}
