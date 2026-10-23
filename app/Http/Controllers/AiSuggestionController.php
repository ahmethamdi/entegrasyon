<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ai\Actions\ApplySuggestion;
use App\Domain\Ai\Actions\SuggestAttributes;
use App\Domain\Ai\Actions\SuggestCategory;
use App\Domain\Ai\Actions\SuggestContent;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Models\AiSuggestion;
use App\Domain\Ai\Support\AiFeature;
use App\Domain\Billing\Exceptions\QuotaExceededException;
use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Contracts\ValidatesPublication;
use App\Domain\Channels\Models\CategoryMapping;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * S8 · AI öneri iste / onayla / vazgeç — ürün → kanallar ekranından.
 *
 * Anahtar yoksa rotalar 404 döner (`AiFeature`): düğme çizilmediği gibi
 * elle atılan istek de bir şey yapmaz. Yetki `catalog.manage`
 * (`RoutePermissions`); depo ve salt okunur üye bu rotalara giremez.
 *
 * Bağlantı kimliği kiracı scope'unda aranır — başka kiracının bağlantısı
 * 404. Öneri de kiracı scope'undadır ve URL'deki ürüne ait olmalıdır.
 */
final class AiSuggestionController extends Controller
{
    public function store(
        Request $request,
        string $product,
        AdapterRegistry $registry,
        SuggestContent $content,
        SuggestAttributes $attributes,
        SuggestCategory $category,
    ): RedirectResponse {
        abort_unless(AiFeature::enabled(), 404);

        $model = Product::query()->findOrFail($product);

        $data = $request->validate([
            'kind' => ['required', Rule::in([AiSuggestion::KIND_CONTENT, AiSuggestion::KIND_ATTRIBUTES, AiSuggestion::KIND_CATEGORY])],
            'connection_id' => ['required', 'string', 'max:64'],
        ]);

        $connection = ChannelConnection::query()->where('status', 'active')->findOrFail($data['connection_id']);
        $code = $connection->channel_type_code;
        $back = redirect("/products/{$model->id}/channels");

        try {
            $suggestion = match ($data['kind']) {
                AiSuggestion::KIND_CONTENT => $content->run($model, $code),
                AiSuggestion::KIND_CATEGORY => $category->run($model, $code),
                AiSuggestion::KIND_ATTRIBUTES => $attributes->run(
                    $model,
                    $code,
                    $this->mappedCategory($model, $code),
                    $this->autoFilled($registry, $connection),
                ),
            };
        } catch (QuotaExceededException $e) {
            return $back->withErrors(['ai' => $e->userMessage()]);
        } catch (AiUnavailable $e) {
            return $back->withErrors(['ai' => $e->getMessage()]);
        }

        return $suggestion->status === AiSuggestion::STATUS_PENDING
            ? $back->with('success', __('AI önerisi hazır — kontrol edip onayla.'))
            : $back->with('warning', __('AI önerisi kurallardan geçmedi; uyarılara bak.'));
    }

    public function apply(Request $request, string $product, string $suggestion, ApplySuggestion $apply): RedirectResponse
    {
        abort_unless(AiFeature::enabled(), 404);

        $row = $this->suggestion($product, $suggestion);

        $input = $request->validate([
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:500000'],
            'attributes' => ['nullable', 'array', 'max:500'],
            'attributes.*' => ['string', 'max:255'],
        ]);

        $apply->run($row, $input);

        return redirect("/products/{$row->product_id}/channels")->with('success', match ($row->kind) {
            AiSuggestion::KIND_CATEGORY => __('Kategori eşlemesi kaydedildi. Aynı iç kategorideki tüm ürünler bu kategoriye gider.'),
            default => __('Öneri kaydedildi. Kanala yansıması için ürünü yeniden gönder.'),
        });
    }

    public function dismiss(string $product, string $suggestion, ApplySuggestion $apply): RedirectResponse
    {
        abort_unless(AiFeature::enabled(), 404);

        $row = $this->suggestion($product, $suggestion);
        $apply->dismiss($row);

        return redirect("/products/{$row->product_id}/channels");
    }

    private function suggestion(string $product, string $suggestion): AiSuggestion
    {
        return AiSuggestion::query()->where('product_id', $product)->findOrFail($suggestion);
    }

    private function mappedCategory(Product $product, string $code): ChannelCategory
    {
        $mapping = $product->internal_category_id === null ? null : CategoryMapping::query()
            ->where('internal_category_id', $product->internal_category_id)
            ->where('channel_type_code', $code)
            ->first();

        $category = $mapping === null ? null : ChannelCategory::query()->find($mapping->channel_category_id);

        abort_if($category === null, 422, __('Ürünün kategorisi bu kanalda eşleştirilmemiş.'));

        return $category;
    }

    /** @return list<string> */
    private function autoFilled(AdapterRegistry $registry, ChannelConnection $connection): array
    {
        try {
            $adapter = $registry->for($connection);
        } catch (Throwable) {
            return [];
        }

        return $adapter instanceof ValidatesPublication ? $adapter->autoFilledAttributes() : [];
    }
}
