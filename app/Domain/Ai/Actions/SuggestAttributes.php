<?php

declare(strict_types=1);

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\Models\AiSuggestion;
use App\Domain\Ai\Support\ProductBrief;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductChannelAttributeValue;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Domain\Channels\Support\CatalogRequirements;
use Illuminate\Support\Facades\Auth;

/**
 * Kanal kategorisinin betimleyici öznitelikleri için değer önerisi (S8) —
 * kaliteli model, ürün metni + görseller.
 *
 * DEĞİŞMEZ KURAL — MODEL YALNIZ İZİNLİ LİSTEDEN SEÇER:
 *   Liste dışı değer kimliği, tanımsız öznitelik ya da serbest metin kabul
 *   etmeyen özniteliğe yazılmış metin öneriye GİRMEZ; reddedilenler
 *   satıcıya uyarı olarak gösterilir. Sessizce "en yakın değere" çevrilmez:
 *   yanlış değer kanalda yanlış ürün demektir.
 *
 * Varyant belirleyici öznitelikler (beden, renk) burada önerilmez — onlar
 * seçenek eşlemesinden gelir. Kanalın kendi alanlarıyla doldurduğu
 * öznitelikler (`$autoFilled`) de atlanır.
 */
final class SuggestAttributes
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'attributes' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'attributeId' => ['type' => 'string'],
                        'valueId' => ['type' => 'string'],
                        'customValue' => ['type' => 'string'],
                    ],
                    'required' => ['attributeId', 'valueId', 'customValue'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['attributes'],
        'additionalProperties' => false,
    ];

    public function __construct(private readonly AskModel $ask) {}

    /**
     * @param  list<string>  $autoFilled
     */
    public function run(Product $product, string $channelCode, ChannelCategory $category, array $autoFilled = []): AiSuggestion
    {
        $attributes = ChannelCategoryAttribute::query()
            ->where('channel_category_id', $category->id)
            ->where('is_variant_defining', false)
            ->whereNotIn('external_attribute_id', $autoFilled)
            ->orderByDesc('is_required')
            ->orderBy('name')
            ->get();

        $current = ProductChannelAttributeValue::query()
            ->where('product_id', $product->id)
            ->where('channel_category_id', $category->id)
            ->pluck('external_attribute_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        // Doldurulmuş öznitelik yeniden sorulmaz: satıcının kararını ezen
        // bir öneri gürültüdür.
        $open = $attributes->reject(fn (ChannelCategoryAttribute $a): bool => in_array((string) $a->external_attribute_id, $current, true))->values();

        $warnings = [];
        $limit = (int) config('entegrasyon.ai.max_values_per_attribute', 300);
        $lines = [];

        foreach ($open as $a) {
            $values = (array) $a->allowed_values;
            $shown = array_slice($values, 0, $limit);

            if (count($values) > $limit) {
                $warnings[] = __('":name" listesi uzun (:count değer); modele ilk :limit değer gösterildi.', ['name' => $a->name, 'count' => count($values), 'limit' => $limit]);
            }

            $lines[] = sprintf(
                '- attributeId=%s · %s%s · %s',
                $a->external_attribute_id,
                $a->name,
                $a->is_required ? ' (ZORUNLU)' : '',
                CatalogRequirements::acceptsFreeText($a) ? 'serbest metin kabul eder' : 'yalnız listeden',
            );

            if ($shown !== []) {
                $lines[] = '  değerler: '.implode(' | ', array_map(
                    static fn (array $v): string => ($v['id'] ?? '').'='.($v['label'] ?? $v['name'] ?? ''),
                    $shown,
                ));
            }
        }

        if ($open->isEmpty()) {
            return $this->store($product, $channelCode, $category, 'n/a', [], [__('Doldurulacak öznitelik kalmadı.')]);
        }

        $content = [
            ...ProductBrief::images($product),
            ['type' => 'text', 'text' => ProductBrief::text($product)."\n\nKanal kategorisi: ".($category->path ?? $category->name)."\nÖznitelikler:\n".implode("\n", $lines)],
        ];

        [$reply, $usage] = $this->ask->run(
            AiSuggestion::KIND_ATTRIBUTES,
            (string) config('entegrasyon.ai.model_quality'),
            $this->system(),
            $content,
            self::SCHEMA,
            'medium',
        );

        $items = [];

        if ($reply->data === null) {
            $warnings[] = __('Model geçerli bir yanıt vermedi.');
        }

        foreach ((array) ($reply->data['attributes'] ?? []) as $row) {
            $id = trim((string) ($row['attributeId'] ?? ''));
            $valueId = trim((string) ($row['valueId'] ?? ''));
            $custom = trim((string) ($row['customValue'] ?? ''));

            /** @var ChannelCategoryAttribute|null $attribute */
            $attribute = $open->first(fn (ChannelCategoryAttribute $a): bool => (string) $a->external_attribute_id === $id);

            if ($attribute === null || isset($items[$id]) || ($valueId === '' && $custom === '')) {
                continue;
            }

            if ($valueId !== '') {
                $match = collect((array) $attribute->allowed_values)->first(fn (array $v): bool => (string) ($v['id'] ?? '') === $valueId);

                if ($match === null) {
                    $warnings[] = __('":name" için liste dışı değer önerildi, alınmadı.', ['name' => $attribute->name]);

                    continue;
                }

                $items[$id] = $this->item($attribute, $valueId, (string) ($match['label'] ?? $match['name'] ?? $valueId), null);

                continue;
            }

            if (! CatalogRequirements::acceptsFreeText($attribute)) {
                $warnings[] = __('":name" yalnız listeden seçilir; serbest metin önerisi alınmadı.', ['name' => $attribute->name]);

                continue;
            }

            $items[$id] = $this->item($attribute, null, null, mb_substr($custom, 0, 500));
        }

        $suggestion = $this->store($product, $channelCode, $category, $usage->model, array_values($items), $warnings);

        $usage->update(['suggestion_id' => $suggestion->id, 'status' => $items === [] ? 'invalid' : $usage->status]);

        return $suggestion;
    }

    /** @return array<string, mixed> */
    private function item(ChannelCategoryAttribute $a, ?string $valueId, ?string $valueLabel, ?string $custom): array
    {
        return [
            'attributeId' => (string) $a->external_attribute_id,
            'name' => $a->name,
            'required' => (bool) $a->is_required,
            'valueId' => $valueId,
            'valueLabel' => $valueLabel,
            'customValue' => $custom,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $warnings
     */
    private function store(Product $product, string $channelCode, ChannelCategory $category, string $model, array $items, array $warnings): AiSuggestion
    {
        return AiSuggestion::query()->create([
            'product_id' => $product->id,
            'kind' => AiSuggestion::KIND_ATTRIBUTES,
            'channel_type_code' => $channelCode,
            'channel_category_id' => $category->id,
            'model' => $model,
            'input_hash' => ProductBrief::hash($product),
            'status' => $items === [] ? AiSuggestion::STATUS_INVALID : AiSuggestion::STATUS_PENDING,
            'payload' => ['items' => $items],
            'warnings' => $warnings,
            'created_by' => Auth::id(),
        ]);
    }

    private function system(): string
    {
        return <<<'TXT'
            Sen bir pazar yeri katalog uzmanısın. Görevin, ürün bilgisi ve görsellerinden kanal kategorisinin özniteliklerine değer atamak.

            Kesin kurallar:
            - "yalnız listeden" özniteliklerde valueId MUTLAKA verilen değerler listesindeki kimliklerden biri olmalı; customValue boş bırak.
            - "serbest metin kabul eder" özniteliklerde listede uygun değer yoksa customValue yaz, valueId boş bırak.
            - Ürün bilgisinden ya da görselden EMİN OLAMADIĞIN özniteliği hiç yazma. Tahmin etme, uydurma.
            - attributeId olarak yalnız verilen kimlikleri kullan.
            TXT;
    }
}
