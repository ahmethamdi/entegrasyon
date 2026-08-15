<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Kanonik sipariş olayı — kanaldan bağımsız biçim.
 *
 * Mimari Karar Dokümanı v2.2 · §1 · Karar 24, §7 · SupportsOrders.
 *
 * TİP KRİTİKTİR: created / updated / cancelled / returned ayrı yollara gider.
 * Tek yola sokulsaydı iptal ve iade, siparişin yeniden yaratılması gibi
 * işlenir ve stok iki kez düşerdi.
 *
 * externalRef stok hareketi idempotency anahtarının çıpasıdır: aynı iptal
 * ikinci kez geldiğinde order_events satırı çakışır ve hareket hiç oluşmaz.
 */
final readonly class NormalizedOrderEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $type,              // created | updated | cancelled | returned
        public string $externalOrderId,
        public ?string $externalRef,      // kanalın olay kimliği
        public array $payload,
        public ?\DateTimeImmutable $occurredAt = null,
    ) {}
}
