<?php

declare(strict_types=1);

namespace App\Domain\Orders\Enums;

/**
 * Sipariş olayı türü.
 *
 * Mimari Karar Dokümanı v2.2 · §1 · Karar 24, §4 · order_events.
 *
 * DEĞİŞMEZ KURAL — TİPE GÖRE AYRI YOLLAR:
 *   created tekillik kısıtıyla yeni sipariş yaratır; güncelleme, iptal, iade
 *   ve kargo olayları MEVCUT siparişi bulur ve order_events üzerinden işlenir.
 *
 *   Tüm olaylar tek yola girerse güncellemeler ON CONFLICT DO NOTHING dalına
 *   düşer ve SESSİZCE YUTULUR. Bedeli tüketicide açık tip dağıtımıdır.
 */
enum OrderEventType: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';
    case FULFILLED = 'fulfilled';

    /** Bizim ürettiğimiz denetim olayı — kanaldan gelmez, external_ref taşımaz. */
    case OVERSELL_DETECTED = 'OVERSELL_DETECTED';

    /** Bu olay stok hareketi üretir mi? */
    public function affectsStock(): bool
    {
        return match ($this) {
            self::CREATED, self::CANCELLED, self::RETURNED => true,
            default => false,
        };
    }

    /** Yeni sipariş yaratan tek tür. */
    public function createsOrder(): bool
    {
        return $this === self::CREATED;
    }
}
