<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

/**
 * Kanalın hız sınırı profili.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · Sorumluluk dağılımı.
 *
 * PROFİLİ ADAPTER BİLDİRİR, UYGULAMAYI ÇEKİRDEK YAPAR: sınır kanala ve hatta
 * hesaba özgüdür (Trendyol'da satıcı seviyesine göre değişir ve yanıt
 * başlığından öğrenilir), ama kova mantığı ortaktır.
 */
final readonly class RateLimitProfile
{
    public function __construct(
        public int $requestsPerSecond,
        public int $burstCapacity,
        /** Eşzamanlı istek tavanı; 0 = sınırsız. */
        public int $maxConcurrent = 0,
    ) {}

    /** @param array<string, mixed> $profile */
    public static function fromArray(array $profile): self
    {
        return new self(
            requestsPerSecond: (int) ($profile['requests_per_second'] ?? 5),
            burstCapacity: (int) ($profile['burst_capacity'] ?? 10),
            maxConcurrent: (int) ($profile['max_concurrent'] ?? 0),
        );
    }

    /** Profil tanımlı değilken güvenli varsayılan — yavaş ama çalışır. */
    public static function conservative(): self
    {
        return new self(requestsPerSecond: 2, burstCapacity: 4);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'requests_per_second' => $this->requestsPerSecond,
            'burst_capacity' => $this->burstCapacity,
            'max_concurrent' => $this->maxConcurrent,
        ];
    }
}
