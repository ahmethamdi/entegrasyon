<?php

declare(strict_types=1);

namespace App\Domain\Orders\Enums;

/**
 * Kanaldan bağımsız sipariş yaşam döngüsü (ADR-001).
 *
 * Her kanal kendi kelimesini kullanır (Trendyol `Picking`, Woo `processing`,
 * Shopify `paid`); bu kelimeler 10 adapter'da ve çekirdekte birbirini tutmayan
 * 4 listede yaşıyordu. Kanonik durum YALNIZ adapter'da eşlenir
 * (`MapsOrderState`), çekirdek yalnız bunu okur. Ham kelime `orders.status`'ta
 * tanı için kalır.
 *
 * En küçük model — 10 kanalın gerçek durumlarından türetildi:
 *
 *   awaiting_payment → awaiting_shipment → shipped → delivered → partially_returned
 *   terminal: cancelled · returned · superseded (Trendyol bölünen paket)
 *
 * İade TALEBİ (ReturnRequested, ReturnInTransit…) sipariş durumu DEĞİLDİR —
 * ADR-005 `return_requests` (S4). Teslim edilememe (UnDelivered) kargonun alt
 * durumudur → `shipped`; ham kelime görünür kalır.
 *
 * GERİYE GİTMEZ: sırasız gelen eski bir durum (kargolandıktan sonra gelen
 * `Picking` güncellemesi) yazılmaz. Sıra kanal saatine değil bu sıralamaya
 * dayanır — kanal saatleri tutarsızdır (Shopify'da olay anı = sipariş anı).
 */
enum OrderState: string
{
    case AWAITING_PAYMENT = 'awaiting_payment';
    case AWAITING_SHIPMENT = 'awaiting_shipment';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case PARTIALLY_RETURNED = 'partially_returned';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';
    case SUPERSEDED = 'superseded';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::CANCELLED, self::RETURNED, self::SUPERSEDED => true,
            default => false,
        };
    }

    /** İlerleme sırası; terminal durumlar sıranın dışında. */
    public function rank(): int
    {
        return match ($this) {
            self::AWAITING_PAYMENT => 0,
            self::AWAITING_SHIPMENT => 1,
            self::SHIPPED => 2,
            self::DELIVERED => 3,
            self::PARTIALLY_RETURNED => 4,
            self::CANCELLED, self::RETURNED, self::SUPERSEDED => 100,
        };
    }

    /**
     * Geçiş kuralı — TEK KAYNAK.
     *
     *   · Bilinmeyen başlangıç (null) → her durum.
     *   · Terminalden çıkış yok (aynı duruma "geçiş" etkisizdir).
     *   · Terminale her ilerleme durumundan geçilir (kanal otoritedir).
     *   · İlerleme durumları yalnız İLERİ gider.
     */
    public static function canTransition(?self $from, self $to): bool
    {
        if ($from === null) {
            return true;
        }

        if ($from === $to || $from->isTerminal()) {
            return false;
        }

        if ($to->isTerminal()) {
            return true;
        }

        return $to->rank() > $from->rank();
    }

    /** Satış sayılmayan durumlar — ciro/kâr bunları dışlar. */
    public static function nonRevenue(): array
    {
        return [self::CANCELLED->value, self::SUPERSEDED->value, self::RETURNED->value];
    }
}
