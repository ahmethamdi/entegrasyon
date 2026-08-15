<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Stok hareketi türleri.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · Hareket türleri ve §1 · Karar 07.
 */
enum MovementType: string
{
    case SALE = 'SALE';
    case CANCELLATION = 'CANCELLATION';
    case RETURN = 'RETURN';
    case RESERVATION = 'RESERVATION';
    case RELEASE = 'RELEASE';
    case MANUAL_ADJUSTMENT = 'MANUAL_ADJUSTMENT';
    case IMPORT = 'IMPORT';
    case TRANSFER_IN = 'TRANSFER_IN';
    case TRANSFER_OUT = 'TRANSFER_OUT';
    case RECONCILIATION_ADJUSTMENT = 'RECONCILIATION_ADJUSTMENT';

    /**
     * Bu hareket yetersiz stokta reddedilmeli mi?
     *
     * SALE dış dünyada olmuş bitmiş bir olaydır ve reddedilemez; bakiye
     * negatife düşer. RESERVATION ve TRANSFER_OUT bizim kararımızdır ve
     * kabul edilmeden önce doğrulanabilir.
     */
    public function requiresSufficientStock(): bool
    {
        return match ($this) {
            // Bizim kararımız — reddedilebilir
            self::RESERVATION, self::TRANSFER_OUT => true,

            // Dış gerçeklik veya düzeltme — her zaman kabul
            self::SALE,
            self::CANCELLATION,
            self::RETURN,
            self::RELEASE,
            self::MANUAL_ADJUSTMENT,
            self::IMPORT,
            self::TRANSFER_IN,
            self::RECONCILIATION_ADJUSTMENT => false,
        };
    }

    /** Hareket yalnızca rezerve sütununu mu taşıyor? */
    public function affectsReservedOnly(): bool
    {
        return match ($this) {
            self::RESERVATION, self::RELEASE => true,
            default => false,
        };
    }
}
