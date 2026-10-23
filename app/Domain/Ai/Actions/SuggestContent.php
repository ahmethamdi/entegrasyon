<?php

declare(strict_types=1);

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\Models\AiSuggestion;
use App\Domain\Ai\Support\ChannelContentRules;
use App\Domain\Ai\Support\ProductBrief;
use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Facades\Auth;

/**
 * Kanala göre başlık + açıklama önerisi (S8) — hızlı model.
 *
 * Model çıktısı `ChannelContentRules` ile SUNUCUDA yeniden denetlenir.
 * İhlal varsa model BİR KEZ ihlal listesiyle yeniden istenir; yine
 * geçmezse öneri `invalid` kaydedilir ve satıcıya ihlaller gösterilir —
 * metin kırpılmaz, kendiliğinden düzeltilmez.
 */
final class SuggestContent
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
        ],
        'required' => ['title', 'description'],
        'additionalProperties' => false,
    ];

    public function __construct(private readonly AskModel $ask) {}

    public function run(Product $product, string $channelCode): AiSuggestion
    {
        $model = (string) config('entegrasyon.ai.model_fast');
        $system = $this->system($channelCode);
        $content = [['type' => 'text', 'text' => ProductBrief::text($product)]];

        [$reply, $usage] = $this->ask->run(AiSuggestion::KIND_CONTENT, $model, $system, $content, self::SCHEMA, 'low');

        $title = trim((string) ($reply->data['title'] ?? ''));
        $description = self::cleanDescription((string) ($reply->data['description'] ?? ''));
        $violations = $reply->data === null
            ? [__('Model geçerli bir yanıt vermedi.')]
            : ChannelContentRules::violations($channelCode, $title, $description);

        if ($violations !== [] && $reply->data !== null) {
            $usage->update(['status' => 'invalid']);

            $content[] = ['type' => 'text', 'text' => "Önceki önerin şu kuralları ihlal etti, düzelterek yeniden yaz:\n- "
                .implode("\n- ", $violations)."\nÖnceki başlık: {$title}"];

            [$reply, $usage] = $this->ask->run(AiSuggestion::KIND_CONTENT, $model, $system, $content, self::SCHEMA, 'low');

            if ($reply->data !== null) {
                $title = trim((string) ($reply->data['title'] ?? ''));
                $description = self::cleanDescription((string) ($reply->data['description'] ?? ''));
                $violations = ChannelContentRules::violations($channelCode, $title, $description);
            }
        }

        $suggestion = AiSuggestion::query()->create([
            'product_id' => $product->id,
            'kind' => AiSuggestion::KIND_CONTENT,
            'channel_type_code' => $channelCode,
            'model' => $usage->model,
            'input_hash' => ProductBrief::hash($product),
            'status' => $violations === [] ? AiSuggestion::STATUS_PENDING : AiSuggestion::STATUS_INVALID,
            'payload' => ['title' => $title, 'description' => $description],
            'warnings' => $violations,
            'created_by' => Auth::id(),
        ]);

        if ($violations !== []) {
            $usage->update(['status' => 'invalid']);
        }

        $usage->update(['suggestion_id' => $suggestion->id]);

        return $suggestion;
    }

    /** Açıklamada yalnız izinli etiketler kalır; geri kalan HTML atılır. */
    public static function cleanDescription(string $html): string
    {
        return trim(strip_tags($html, '<p><ul><ol><li><strong><br>'));
    }

    private function system(string $channelCode): string
    {
        return <<<TXT
            Sen bir e-ticaret katalog editörüsün. Görevin, verilen ürün bilgisinden "{$channelCode}" kanalı için satışa uygun bir ürün başlığı ve açıklaması yazmak.

            Kesin kurallar:
            {$this->rules($channelCode)}

            İlkeler:
            - Yalnız verilen ürün bilgisine dayan. Bilgide olmayan özellik, malzeme, ölçü, sertifika, garanti, kargo ya da indirim iddiası UYDURMA.
            - Başlık: marka (varsa) + ürün türü + ayırt edici özellik. Büyük harfle bağırma, ünlem, emoji yok.
            - Açıklama: kısa bir giriş paragrafı ve ürün bilgisindeki özelliklerin madde listesi.
            - "En ucuz", "en iyi", "orijinal" gibi doğrulanamayan üstünlük iddiaları yazma.
            TXT;
    }

    private function rules(string $channelCode): string
    {
        return ChannelContentRules::describe($channelCode);
    }
}
