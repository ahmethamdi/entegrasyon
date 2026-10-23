<?php

declare(strict_types=1);

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\Models\AiSuggestion;
use App\Domain\Ai\Support\ChannelContentRules;
use App\Domain\Catalog\Actions\SaveProductChannelAttributeValue;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductChannelContent;
use App\Domain\Channels\Actions\SaveCategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satıcının onayı — öneri MEVCUT yazma yollarından geçer (S8).
 *
 * İçerik: `product_channel_contents` yazılır ve ürünün `content_version`'ı
 * artar (kanala yansıması satıcının "yeniden gönder"iyle, diğer içerik
 * değişiklikleri gibi). Satıcının düzenlediği metin de kanal kuralından
 * YENİDEN geçer — onay kuralı atlatmanın yolu değildir.
 *
 * Öznitelik: `SaveProductChannelAttributeValue` (liste dışı değer orada
 * da reddedilir). Kategori: `SaveCategoryMapping` (`mapped_by = ai`).
 */
final class ApplySuggestion
{
    public function __construct(
        private readonly SaveProductChannelAttributeValue $saveAttribute,
        private readonly SaveCategoryMapping $saveMapping,
    ) {}

    /**
     * @param  array<string, mixed>  $input  satıcının düzenlemesi
     */
    public function run(AiSuggestion $suggestion, array $input = []): void
    {
        if (! $suggestion->isOpen()) {
            throw ValidationException::withMessages(['suggestion' => __('Bu öneri zaten kapatılmış.')]);
        }

        $product = Product::query()->findOrFail($suggestion->product_id);

        DB::transaction(function () use ($suggestion, $product, $input): void {
            match ($suggestion->kind) {
                AiSuggestion::KIND_CONTENT => $this->content($suggestion, $product, $input),
                AiSuggestion::KIND_ATTRIBUTES => $this->attributes($suggestion, $product, $input),
                AiSuggestion::KIND_CATEGORY => $this->category($suggestion, $product),
            };

            $suggestion->update([
                'status' => AiSuggestion::STATUS_APPLIED,
                'decided_by' => Auth::id(),
                'decided_at' => now(),
            ]);
        });
    }

    public function dismiss(AiSuggestion $suggestion): void
    {
        if ($suggestion->isOpen()) {
            $suggestion->update([
                'status' => AiSuggestion::STATUS_DISMISSED,
                'decided_by' => Auth::id(),
                'decided_at' => now(),
            ]);
        }
    }

    /** @param  array<string, mixed>  $input */
    private function content(AiSuggestion $suggestion, Product $product, array $input): void
    {
        $title = trim((string) ($input['title'] ?? $suggestion->payload['title'] ?? ''));
        $description = SuggestContent::cleanDescription((string) ($input['description'] ?? $suggestion->payload['description'] ?? ''));

        $violations = ChannelContentRules::violations($suggestion->channel_type_code, $title, $description);

        if ($violations !== []) {
            throw ValidationException::withMessages(['title' => $violations]);
        }

        ProductChannelContent::query()->updateOrCreate(
            ['product_id' => $product->id, 'channel_type_code' => $suggestion->channel_type_code],
            ['title' => $title, 'description' => $description === '' ? null : $description, 'source_suggestion_id' => $suggestion->id],
        );

        // Senkron kapısı sürümden beslenir; artmazsa değişiklik kanala gitmez.
        $product->increment('content_version');
    }

    /** @param  array<string, mixed>  $input */
    private function attributes(AiSuggestion $suggestion, Product $product, array $input): void
    {
        $items = collect((array) ($suggestion->payload['items'] ?? []))->keyBy('attributeId');

        // Satıcı tikleri kaldırabilir; işaretlenmeyen öznitelik yazılmaz.
        $selected = isset($input['attributes']) ? array_map('strval', (array) $input['attributes']) : $items->keys()->all();

        foreach ($selected as $attributeId) {
            $item = $items->get($attributeId);

            if ($item === null) {
                continue;
            }

            $this->saveAttribute->run(
                $product,
                (string) $suggestion->channel_category_id,
                (string) $attributeId,
                $item['valueId'] ?? null,
                $item['customValue'] ?? null,
            );
        }
    }

    private function category(AiSuggestion $suggestion, Product $product): void
    {
        if ($product->internal_category_id === null || trim($product->internal_category_id) === '') {
            throw ValidationException::withMessages(['category' => __('Ürünün iç kategorisi boş; önce ürüne bir kategori adı ver.')]);
        }

        $category = ChannelCategory::query()->find($suggestion->payload['categoryId'] ?? null);

        if ($category === null) {
            throw ValidationException::withMessages(['category' => __('Önerilen kategori artık kanal ağacında yok.')]);
        }

        $this->saveMapping->run($product->internal_category_id, $category, 'ai', (int) ($suggestion->payload['confidence'] ?? 0));
    }
}
