<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Finance\Support\RemoteFinancialPage;
use Carbon\CarbonInterface;

/**
 * Kanalın finansal işlem defterini (hakediş, kesinti, ödeme) okuyabilme.
 *
 * S1 · Trendyol cari hesap ekstresi. Adapter HAM işlemi döndürür; tür
 * yorumu ve sipariş eşleşmesi çekirdeğin işidir (`TrendyolFinancialTypes`,
 * `MatchFinancialTransactions`) — yorum değişince ham olgu yeniden
 * çekilmeden güncellenebilsin.
 */
interface SupportsFinancialLedger
{
    /**
     * Defterin kaynakları (ör. `settlements`, `otherfinancials`).
     *
     * @return list<string>
     */
    public function financialSources(): array;

    /** Tek istekte sorulabilecek en uzun aralık (gün). */
    public function financialWindowDays(): int;

    /**
     * Bir kaynağın, verilen aralıktaki bir sayfası. Sayfa 0'dan başlar.
     */
    public function fetchFinancialPage(string $source, CarbonInterface $from, CarbonInterface $to, int $page): RemoteFinancialPage;
}
