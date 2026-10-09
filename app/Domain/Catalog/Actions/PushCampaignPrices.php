<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\PriceCampaign;
use App\Domain\Catalog\Support\ActiveCampaigns;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Sync\Actions\RequestResync;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * Kampanya başlayınca / bitince / iptal edilince ilgili canlı ilanların
 * fiyatını yeniden gönderir.
 *
 * Fiyat zaten anlık hesaplanır (`Listing::effectivePrice`); bu sınıfın tek
 * işi kanala "şimdi değişti" demektir. Hiçbir varyantın sürümü değişmediği
 * için `RequestResync` şarttır (fiyat kuralıyla aynı gerekçe).
 *
 * ⚠️ İŞARET KOŞULLU GÜNCELLEMEYLE ALINIR. Zamanlayıcı iki sunucuda ya da üst
 * üste koşarsa aynı kampanya iki kez itilirdi; `whereNull(...)->update()`
 * satırı yalnız bir tura verir.
 */
final class PushCampaignPrices
{
    public function __construct(
        private readonly RequestResync $requestResync,
        private readonly ActiveCampaigns $active,
        private readonly RecordAuditLog $audit,
    ) {}

    /** @return int Yeniden gönderilen ilan sayısı */
    public function start(PriceCampaign $campaign): int
    {
        return $this->claim($campaign, 'start_pushed_at') ? $this->resync($campaign) : 0;
    }

    /** @return int Yeniden gönderilen ilan sayısı */
    public function end(PriceCampaign $campaign): int
    {
        return $this->claim($campaign, 'end_pushed_at') ? $this->resync($campaign) : 0;
    }

    /**
     * İptal: başlamışsa fiyatlar normale döner. Başlamamış kampanya kanala
     * hiç gitmedi; göndermeye gerek yok.
     */
    public function cancel(PriceCampaign $campaign, ?string $userId = null): int
    {
        $wasActive = $campaign->status() === PriceCampaign::STATUS_ACTIVE;

        $campaign->forceFill(['cancelled_at' => now()])->save();

        $this->audit->run(
            action: AuditAction::PRICE_CAMPAIGN_CANCELLED,
            subjectType: 'price_campaigns',
            subjectId: $campaign->id,
            changes: ['name' => $campaign->name, 'was_active' => $wasActive],
            userId: $userId,
        );
        $this->active->forget($campaign->tenant_id);

        return $wasActive && $this->claim($campaign, 'end_pushed_at') ? $this->resync($campaign) : 0;
    }

    private function claim(PriceCampaign $campaign, string $column): bool
    {
        $claimed = PriceCampaign::query()
            ->whereKey($campaign->id)
            ->whereNull($column)
            ->update([$column => now()]) === 1;

        $this->active->forget($campaign->tenant_id);

        return $claimed;
    }

    private function resync(PriceCampaign $campaign): int
    {
        $count = 0;

        Listing::query()
            ->where('lifecycle_status', 'live')
            ->whereIn('variant_id', DB::table('price_campaign_variants')->where('price_campaign_id', $campaign->id)->select('variant_id'))
            ->whereIn('channel_connection_id', DB::table('price_campaign_connections')->where('price_campaign_id', $campaign->id)->select('channel_connection_id'))
            ->chunkById(200, function ($listings) use (&$count): void {
                foreach ($listings as $listing) {
                    $this->requestResync->run($listing, SyncDomain::PRICE, RequestResync::REASON_CAMPAIGN_CHANGED);
                    $count++;
                }
            });

        return $count;
    }
}
