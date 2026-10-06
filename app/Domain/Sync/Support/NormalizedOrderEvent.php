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
    /**
     * Olay anı — DAİMA UTC.
     *
     * Kanal saati kendi diliminde gönderir (Shopify: mağaza dilimi,
     * `07:19:18-04:00`). Eloquent `datetime` kolonu nesneyi KENDİ dilimindeki
     * duvar saatiyle yazar ve ofseti atar: sipariş 11:19 UTC'de verildiği
     * hâlde 07:19 kaydediliyordu. Kayma yalnız ekranı bozmaz —
     * `placed_at`'e bakan "katalogdan önce satılmış mı" kuralı (A12) ve
     * bağlantı anı karşılaştırmaları yanlış tarafa düşer. Çevrim burada,
     * tek kapıda yapılır: yedi ayrı yazma yeri bu nesneden okur.
     */
    public ?\DateTimeImmutable $occurredAt;

    /**
     * Siparişin VERİLDİĞİ an — DAİMA UTC; verilmezse `occurredAt`.
     *
     * Olay anından AYRIDIR: iptal saatler sonra olur ama sipariş yine aynı
     * anda verilmiştir. `placed_at` ve "bağlantıdan önce mi verildi"
     * kararı (kaçırılmış sipariş) BUNA bakar; olay anına baksaydı
     * bağlantıdan önce verilmiş ama sonra güncellenmiş sipariş yeniden
     * yaratılır ve stok ikinci kez düşerdi.
     */
    public ?\DateTimeImmutable $placedAt;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $type,              // created | updated | cancelled | returned
        public string $externalOrderId,
        public ?string $externalRef,      // kanalın olay kimliği
        public array $payload,
        ?\DateTimeImmutable $occurredAt = null,
        ?\DateTimeImmutable $placedAt = null,
    ) {
        $utc = new \DateTimeZone('UTC');
        $this->occurredAt = $occurredAt?->setTimezone($utc);
        $this->placedAt = ($placedAt ?? $occurredAt)?->setTimezone($utc);
    }
}
