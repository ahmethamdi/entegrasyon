<?php

declare(strict_types=1);

namespace App\Domain\Orders\Support;

use DateTimeImmutable;

/**
 * Kargo bildirimi — STOK HAREKETİ ÜRETMEZ.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · fulfillments.
 *
 * Mal SATIŞTA zaten düşülmüştür; bu tablo yalnızca teslim durumunu izler.
 * Hareket üretseydi aynı satış iki kez düşülür ve bakiye kalıcı bozulurdu.
 *
 * `externalId` PAKET kimliğidir, sipariş kimliği değil: bir sipariş birden
 * çok kargo paketine bölünebilir ve her paket kendi durumunu taşır.
 * Tekillik `(order_id, external_id)` üzerinedir.
 */
final readonly class FulfillmentEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $orderId,
        /** Paket kimliği; kanal vermezse NULL — bildirim yine kaydedilir. */
        public ?string $externalId = null,
        public ?string $carrier = null,
        public ?string $trackingNumber = null,
        public ?string $status = null,
        public ?DateTimeImmutable $shippedAt = null,
        public ?DateTimeImmutable $deliveredAt = null,
        public array $payload = [],
        public ?DateTimeImmutable $occurredAt = null,
        public ?string $inboxMessageId = null,
    ) {}
}
