<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\PriceCampaign;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Support\ActiveCampaigns;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Kampanyayı yazar; başlangıcı geldiyse fiyatları hemen yeniden gönderir.
 *
 * Ürün seçimi VARYANTLARA açılır: kampanya fiyatı listing başınadır ve
 * listing varyanta bağlıdır. Sonradan ürüne eklenen varyant kampanyaya
 * GİRMEZ — satıcının seçtiği an dondurulur.
 */
final class CreatePriceCampaign
{
    public function __construct(
        private readonly PushCampaignPrices $push,
        private readonly RecordAuditLog $audit,
        private readonly ActiveCampaigns $active,
    ) {}

    /**
     * @param  list<string>  $productIds
     * @param  list<string>  $connectionIds
     */
    public function run(
        string $tenantId,
        string $name,
        string $discountType,
        string $discountValue,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        bool $showCompareAt,
        array $productIds,
        array $connectionIds,
        ?string $userId = null,
    ): PriceCampaign {
        $campaign = DB::transaction(function () use ($tenantId, $name, $discountType, $discountValue, $startsAt, $endsAt, $showCompareAt, $productIds, $connectionIds, $userId): PriceCampaign {
            $campaign = PriceCampaign::query()->create([
                'tenant_id' => $tenantId,
                'name' => $name,
                'discount_type' => $discountType,
                'discount_value' => number_format((float) $discountValue, 2, '.', ''),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'show_compare_at' => $showCompareAt,
                'created_by' => $userId,
            ]);

            $variantIds = Variant::query()->whereIn('product_id', $productIds)->pluck('id');

            DB::table('price_campaign_variants')->insert($variantIds->map(fn (string $id): array => [
                'price_campaign_id' => $campaign->id,
                'variant_id' => $id,
                'tenant_id' => $tenantId,
            ])->all());

            DB::table('price_campaign_connections')->insert(array_map(fn (string $id): array => [
                'price_campaign_id' => $campaign->id,
                'channel_connection_id' => $id,
                'tenant_id' => $tenantId,
            ], array_values(array_unique($connectionIds))));

            $this->audit->run(
                action: AuditAction::PRICE_CAMPAIGN_CREATED,
                subjectType: 'price_campaigns',
                subjectId: $campaign->id,
                changes: [
                    'name' => $name,
                    'discount' => "{$discountType} {$discountValue}",
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                    'variants' => $variantIds->count(),
                    'connections' => count($connectionIds),
                ],
                userId: $userId,
            );

            return $campaign;
        });

        $this->active->forget($tenantId);

        // Başlangıcı geçmişse zamanlayıcıyı bekleme: satıcı "şimdi başlat"
        // dedi ve bir dakikalık gecikme bile kafa karıştırır.
        if ($campaign->status() === PriceCampaign::STATUS_ACTIVE) {
            $this->push->start($campaign);
        }

        return $campaign;
    }
}
