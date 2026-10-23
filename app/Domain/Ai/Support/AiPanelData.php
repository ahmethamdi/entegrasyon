<?php

declare(strict_types=1);

namespace App\Domain\Ai\Support;

use App\Domain\Ai\Models\AiSuggestion;
use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductChannelContent;

/**
 * Ürün → kanallar ekranının AI bölümü (S8). Özellik kapalıyken NULL:
 * panel bölümü hiç çizmez.
 */
final class AiPanelData
{
    public function __construct(private readonly EnforceQuota $quota) {}

    /**
     * @param  list<string>  $channelCodes  ekrandaki kanal türleri
     * @return array<string, mixed>|null
     */
    public function for(Product $product, array $channelCodes): ?array
    {
        if (! AiFeature::enabled()) {
            return null;
        }

        $hash = ProductBrief::hash($product);

        // Kanal türü + tür başına EN SON açık öneri; eskileri ekranda gürültü.
        $open = AiSuggestion::query()
            ->where('product_id', $product->id)
            ->whereIn('status', [AiSuggestion::STATUS_PENDING, AiSuggestion::STATUS_INVALID])
            ->orderByDesc('created_at')
            ->get()
            ->unique(fn (AiSuggestion $s): string => $s->kind.':'.$s->channel_type_code)
            ->map(fn (AiSuggestion $s): array => [
                'id' => $s->id,
                'kind' => $s->kind,
                'channelCode' => $s->channel_type_code,
                'status' => $s->status,
                'payload' => $s->payload,
                'warnings' => $s->warnings,
                // Ürün öneriden sonra değiştiyse öneri eski içeriğe göredir.
                'stale' => $s->input_hash !== $hash,
                'createdAt' => $s->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $contents = ProductChannelContent::query()
            ->where('product_id', $product->id)
            ->get()
            ->mapWithKeys(fn (ProductChannelContent $c): array => [$c->channel_type_code => [
                'title' => $c->title,
                'description' => $c->description,
            ]])
            ->all();

        $rules = [];

        foreach (array_unique($channelCodes) as $code) {
            $rules[$code] = ChannelContentRules::summary($code);
        }

        return [
            'suggestions' => $open,
            'contents' => $contents,
            'rules' => $rules,
            'remaining' => $this->quota->remaining(QuotaMetric::AI_SUGGESTIONS),
        ];
    }
}
