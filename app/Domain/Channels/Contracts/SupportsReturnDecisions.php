<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Returns\Exceptions\ReturnActionRejected;

/**
 * Kanalda iade talebine satıcı KARARI yazabilme — S4.
 *
 * DIŞARI YAZMADIR: çağıran `OutboundWrite::RETURN_DECISION` kapısından
 * geçer (ADR-010). Kesin ret `ReturnActionRejected`; geçici hata HTTP
 * istisnası — sonucu belirsiz istek KÖRLEMESİNE tekrarlanmaz.
 */
interface SupportsReturnDecisions extends SupportsReturns
{
    /**
     * @param  list<string>  $externalItemIds
     *
     * @throws ReturnActionRejected
     */
    public function approveReturnItems(string $externalClaimId, array $externalItemIds): void;

    /**
     * Tek kalemi reddeder (kalem başına bir istek — çoklu kimliğin
     * kodlanışı belgede belirsiz).
     *
     * @throws ReturnActionRejected
     */
    public function rejectReturnItem(string $externalClaimId, string $externalItemId, string $issueReasonId, string $description): void;

    /**
     * Ek dosya gerektirmeden kullanılabilen red sebepleri.
     *
     * @return list<array{id: string, label: string}>
     */
    public function returnRejectionReasons(): array;

    /**
     * Kalemin kanal durum geçmişi (denetim kaydı) — fiziksel varış kanıtı için.
     *
     * @return list<string> ham durumlar
     */
    public function returnItemStatusHistory(string $externalItemId): array;
}
