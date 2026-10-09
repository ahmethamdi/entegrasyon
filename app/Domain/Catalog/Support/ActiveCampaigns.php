<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\PriceCampaign;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Şu an yürürlükteki kampanyalar — kiracı başına, iş/istek ömrü boyunca önbellekli.
 *
 * `ChannelPriceRules` ile aynı gerekçe: `effectivePrice()` yükteki her
 * listing için çağrılır; `scoped` bağlanır ki Horizon işçisi bitmiş ya da
 * iptal edilmiş kampanyayı bir sonraki işte de uygulamasın.
 *
 * Kiracının TÜM aktif kampanyaları tek seferde yüklenir: tipik satıcıda
 * aynı anda birkaç kampanya vardır, listing başına sorgu yükü katlardı.
 */
final class ActiveCampaigns
{
    /** @var array<string, array<string, list<PriceCampaign>>> kiracı → "bağlantı|varyant" → kampanyalar */
    private array $index = [];

    /** @return list<PriceCampaign> */
    public function for(string $tenantId, string $connectionId, string $variantId): array
    {
        if (! array_key_exists($tenantId, $this->index)) {
            $this->index[$tenantId] = $this->load($tenantId);
        }

        return $this->index[$tenantId]["{$connectionId}|{$variantId}"] ?? [];
    }

    /** Kampanya yazıldıktan/iptal edildikten sonra aynı istekte eski hâl okunmasın. */
    public function forget(string $tenantId): void
    {
        unset($this->index[$tenantId]);
    }

    /** @return array<string, list<PriceCampaign>> */
    private function load(string $tenantId): array
    {
        return TenantContext::runAsSystem(function () use ($tenantId): array {
            $campaigns = PriceCampaign::query()
                ->where('tenant_id', $tenantId)
                ->activeAt(now())
                ->get()
                ->keyBy('id');

            if ($campaigns->isEmpty()) {
                return [];
            }

            $ids = $campaigns->keys()->all();

            $variants = DB::table('price_campaign_variants')->whereIn('price_campaign_id', $ids)->get(['price_campaign_id', 'variant_id'])->groupBy('price_campaign_id');
            $connections = DB::table('price_campaign_connections')->whereIn('price_campaign_id', $ids)->get(['price_campaign_id', 'channel_connection_id'])->groupBy('price_campaign_id');

            $index = [];

            foreach ($campaigns as $id => $campaign) {
                foreach ($connections->get($id, collect()) as $connection) {
                    foreach ($variants->get($id, collect()) as $variant) {
                        $index["{$connection->channel_connection_id}|{$variant->variant_id}"][] = $campaign;
                    }
                }
            }

            return $index;
        });
    }
}
