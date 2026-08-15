<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Sync\Enums\ErrorClass;

/**
 * Adapter çağrısının sonucu.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · Adapter yan etkisizdir.
 *
 * DEĞİŞMEZ KURAL: adapter veritabanına YAZMAZ, kuyruğa iş ATMAZ, durum
 * GÜNCELLEMEZ. Girdi alır, kanalla konuşur, bu nesneyi döner. Durumu
 * SyncResultRecorder yazar.
 *
 * Bu ayrım olmadan her adapter kendi durum yazma mantığını taşır ve
 * "hangi adapter sync state'i nasıl güncelliyor" sorusu cevapsız kalırdı.
 */
final readonly class AdapterResult
{
    /**
     * @param  array<string, mixed>  $data  Kanaldan dönen faydalı veri (external_id vb.)
     * @param  int|null  $retryAfter  Kanalın Retry-After başlığı, saniye
     */
    private function __construct(
        public bool $successful,
        public array $data = [],
        public ?ErrorClass $errorClass = null,
        public ?string $errorMessage = null,
        public ?int $retryAfter = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function success(array $data = []): self
    {
        return new self(successful: true, data: $data);
    }

    public static function failure(
        ErrorClass $errorClass,
        ?string $message = null,
        ?int $retryAfter = null,
    ): self {
        return new self(
            successful: false,
            errorClass: $errorClass,
            errorMessage: $message,
            retryAfter: $retryAfter,
        );
    }

    public function failed(): bool
    {
        return ! $this->successful;
    }

    /** Bu sonuç kullanıcı müdahalesi gerektiriyor mu? */
    public function isPermanentFailure(): bool
    {
        return $this->failed() && $this->errorClass?->isPermanent() === true;
    }
}
