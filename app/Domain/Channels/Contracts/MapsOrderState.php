<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Orders\Enums\OrderState;
use App\Domain\Sync\Support\NormalizedOrderEvent;

/**
 * Kanalın ham sipariş durumunu kanonik duruma çevirme yeteneği (ADR-001).
 *
 * ÇEVİRİ YALNIZ ADAPTER'DADIR: kanal kelimesi kanalın şeklidir. Çekirdek
 * `OrderState` dışında durum kelimesi tanımaz.
 *
 * `null` = "bu olay durumu DEĞİŞTİRMEZ" (tanınmayan kelime, iade talebi gibi
 * ara durumlar, para hareketi). Uydurma bir karşılık seçmek yanlış bir durumu
 * kalıcılaştırırdı; iptal/iade sonucu zaten kalem sayaçlarından türetilir.
 */
interface MapsOrderState
{
    public function orderStateFor(NormalizedOrderEvent $event): ?OrderState;
}
