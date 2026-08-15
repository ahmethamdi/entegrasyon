<?php

declare(strict_types=1);

namespace App\Domain\Orders\Enums;

/**
 * Sipariş satırının stok uygulama durumu.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · order_lines, §5 · Fazla satış davranışı.
 *
 * OVERSOLD satırlar panelde uyarıyla listelenir. Bu alan bir SAYAÇ DEĞİLDİR;
 * fazla satılan miktar negatif available'ın kendisidir. Burada yalnızca
 * "bu satır uygulandığında stok yetiyor muydu" bilgisi durur.
 */
enum StockStatus: string
{
    /** Henüz stok düşülmedi — eşleşmemiş SKU veya bekleyen işlem. */
    case PENDING = 'PENDING';

    /** Stok düşüldü, bakiye yetiyordu. */
    case APPLIED = 'APPLIED';

    /** Stok düşüldü ama bakiye yetmiyordu — fazla satış. */
    case OVERSOLD = 'OVERSOLD';

    /** Bakiyeye göre uygun durumu seçer. */
    public static function forAvailability(int $availableBefore, int $quantity): self
    {
        return $availableBefore < $quantity ? self::OVERSOLD : self::APPLIED;
    }

    public function isOversold(): bool
    {
        return $this === self::OVERSOLD;
    }
}
