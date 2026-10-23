<?php

declare(strict_types=1);

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\Models\AiSuggestion;
use App\Domain\Ai\Support\ProductBrief;
use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Models\ChannelCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Kanal kategorisi eşleme önerisi (S8) — kaliteli model.
 *
 * DEĞİŞMEZ KURAL — ADAYLAR BİZİM AĞACIMIZDAN GELİR:
 *   Model kategori ADI uydurmaz; kanalın en güncel taksonomisinden ürün
 *   sözcükleriyle süzülmüş YAPRAK kategoriler modele verilir, model
 *   bunlardan birinin kimliğini seçer. Aday dışı kimlik öneriye girmez.
 *   Hiç aday yoksa model ÇAĞRILMAZ (hak harcanmaz).
 *
 * Güven puanı düşükse (`ai.low_confidence`) öneri panelde işaretlenir.
 */
final class SuggestCategory
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'categoryId' => ['type' => 'string'],
            'confidence' => ['type' => 'integer'],
            'reason' => ['type' => 'string'],
        ],
        'required' => ['categoryId', 'confidence', 'reason'],
        'additionalProperties' => false,
    ];

    public function __construct(private readonly AskModel $ask) {}

    public function run(Product $product, string $channelCode): AiSuggestion
    {
        $candidates = $this->candidates($product, $channelCode);

        if ($candidates->isEmpty()) {
            return $this->store($product, $channelCode, 'n/a', null, [__('Bu ürün için kanal ağacında aday kategori bulunamadı; eşleştirme ekranından elle seçin.')]);
        }

        $list = $candidates->map(fn (ChannelCategory $c): string => "- {$c->id} · ".($c->path ?? $c->name))->implode("\n");

        [$reply, $usage] = $this->ask->run(
            AiSuggestion::KIND_CATEGORY,
            (string) config('entegrasyon.ai.model_quality'),
            <<<'TXT'
                Sen bir pazar yeri katalog uzmanısın. Görevin, ürün için verilen aday kategorilerden EN UYGUN olanı seçmek.
                - categoryId olarak yalnız aday listesindeki kimliklerden birini yaz.
                - confidence 0-100: adaylardan hiçbiri tam uymuyorsa düşük ver.
                - reason: tek cümle, Türkçe.
                TXT,
            [['type' => 'text', 'text' => ProductBrief::text($product)."\n\nAday kategoriler:\n{$list}"]],
            self::SCHEMA,
            'medium',
        );

        $chosen = $candidates->firstWhere('id', (string) ($reply->data['categoryId'] ?? ''));
        $confidence = max(0, min(100, (int) ($reply->data['confidence'] ?? 0)));
        $warnings = [];

        if ($chosen === null) {
            $warnings[] = $reply->data === null
                ? __('Model geçerli bir yanıt vermedi.')
                : __('Model aday listesi dışında bir kategori önerdi, alınmadı.');
        } elseif ($confidence < (int) config('entegrasyon.ai.low_confidence', 60)) {
            $warnings[] = __('Düşük güven (:confidence/100) — onaylamadan önce kategoriyi kontrol et.', ['confidence' => $confidence]);
        }

        $suggestion = $this->store($product, $channelCode, $usage->model, $chosen === null ? null : [
            'categoryId' => $chosen->id,
            'path' => $chosen->path ?? $chosen->name,
            'confidence' => $confidence,
            'reason' => mb_substr((string) ($reply->data['reason'] ?? ''), 0, 300),
        ], $warnings);

        $usage->update(['suggestion_id' => $suggestion->id, 'status' => $chosen === null ? 'invalid' : $usage->status]);

        return $suggestion;
    }

    /**
     * Ürün sözcükleriyle eşleşen yaprak kategoriler — eşleşen sözcük
     * sayısına göre sıralı.
     *
     * @return Collection<int, ChannelCategory>
     */
    public function candidates(Product $product, string $channelCode): Collection
    {
        $version = ChannelCategory::query()
            ->where('channel_type_code', $channelCode)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('taxonomy_version');

        $words = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($product->title.' '.$product->internal_category_id)) ?: [])
            ->filter(fn (string $w): bool => mb_strlen($w) >= 3)
            ->unique()
            ->take(12)
            ->values();

        if ($version === null || $words->isEmpty()) {
            return new Collection;
        }

        $score = $words->map(fn (): string => 'CASE WHEN lower(coalesce(path, name)) LIKE ? THEN 1 ELSE 0 END')->implode(' + ');
        $bindings = $words->map(fn (string $w): string => '%'.addcslashes($w, '%_\\').'%')->all();

        return ChannelCategory::query()
            ->where('channel_type_code', $channelCode)
            ->where('taxonomy_version', $version)
            ->where('is_leaf', true)
            ->selectRaw("channel_categories.*, ({$score}) AS ai_score", $bindings)
            ->whereRaw("({$score}) > 0", $bindings)
            ->orderByDesc('ai_score')
            ->orderBy('path')
            ->limit((int) config('entegrasyon.ai.category_candidates', 40))
            ->get();
    }

    /**
     * @param  array<string, mixed>|null  $choice
     * @param  list<string>  $warnings
     */
    private function store(Product $product, string $channelCode, string $model, ?array $choice, array $warnings): AiSuggestion
    {
        return AiSuggestion::query()->create([
            'product_id' => $product->id,
            'kind' => AiSuggestion::KIND_CATEGORY,
            'channel_type_code' => $channelCode,
            'channel_category_id' => $choice['categoryId'] ?? null,
            'model' => $model,
            'input_hash' => ProductBrief::hash($product),
            'status' => $choice === null ? AiSuggestion::STATUS_INVALID : AiSuggestion::STATUS_PENDING,
            'payload' => $choice ?? [],
            'warnings' => $warnings,
            'created_by' => Auth::id(),
        ]);
    }
}
