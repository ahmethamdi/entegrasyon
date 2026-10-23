<?php

declare(strict_types=1);

namespace App\Domain\Ai\Support;

/**
 * Model yanıtı — ayrıştırılmış JSON ve jeton sayıları.
 *
 * `data` NULL olabilir: model reddettiyse (`refusal`) ya da çıktı sınırına
 * takıldıysa (`max_tokens`) JSON yarımdır ve okunmaz. Çağıran `stopReason`'a
 * bakar; yarım JSON'u "boş öneri" diye kaydetmek satıcıya modelin hiçbir
 * şey bulamadığını söylerdi.
 *
 * @phpstan-type Usage array{input: int, output: int, cache_read: int, cache_write: int}
 */
final readonly class AiReply
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public ?array $data,
        public string $model,
        public ?string $stopReason,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {}

    public function refused(): bool
    {
        return $this->stopReason === 'refusal';
    }
}
