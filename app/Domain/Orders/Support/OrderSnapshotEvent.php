<?php

declare(strict_types=1);

namespace App\Domain\Orders\Support;

use DateTimeImmutable;

/**
 * Sipariş anlık görüntüsü güncellemesi — STOK HAREKETİ ÜRETMEZ.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · orders, §6 · Yönlendirme.
 *
 * NULL ALAN "DEĞİŞMEDİ" DEMEKTİR, "BOŞALT" DEĞİL: kanal her olayda tüm
 * alanları göndermez. Boş değerin mevcut veriyi ezmesi bilgi kaybıdır ve
 * geri alınamaz — eski durum kaybolduğu için satıcı neyin değiştiğini
 * anlayamaz.
 *
 * KALEM TAŞIMAZ: kalem değişikliği stok demektir ve stok yalnızca
 * iptal/iade yollarından geçer (§1 · Karar 24).
 */
final readonly class OrderSnapshotEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $orderId,
        /** Kanalın olay kimliği — denetim kaydının idempotency çıpası. */
        public ?string $externalRef,
        public ?string $status = null,
        public ?string $financialStatus = null,
        public array $payload = [],
        public ?DateTimeImmutable $occurredAt = null,
        public ?string $inboxMessageId = null,
    ) {}
}
