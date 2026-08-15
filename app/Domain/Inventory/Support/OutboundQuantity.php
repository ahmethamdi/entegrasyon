<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Models\InventoryLevel;

/**
 * Kanala gönderilecek stok miktarı.
 *
 * Mimari Karar Dokümanı v2.2 · §1 · Karar 05 ve §5 · Giden dönüşüm.
 *
 * KIRPMANIN TEK MEŞRU YERİ BURASIDIR.
 *
 * Kanonik bakiye fazla satış nedeniyle negatif olabilir; kanallar negatif
 * stok kabul etmez. Bu kırpma yalnızca adapter yükünde bulunur ve HİÇBİR
 * YERDE SAKLANMAZ. Kanonik durum asla kırpılmaz — ledger toplamı ile
 * projeksiyon arasındaki eşitlik korunur.
 *
 * Mutabakat karşılaştırması da bu değeri kullanır: kanonik available = −1
 * iken kanaldaki 0 değeri DOĞRUDUR ve sürüklenme değildir (§10).
 */
final class OutboundQuantity
{
    public static function forChannel(InventoryLevel $level): int
    {
        return max($level->available, 0);
    }

    /** Ham bakiyeden hesaplama — seviye nesnesi elde yokken. */
    public static function fromAvailable(int $available): int
    {
        return max($available, 0);
    }
}
