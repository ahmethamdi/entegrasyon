<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use DateTimeImmutable;

/**
 * Bir siparişin faturası için gereken her şey — kanaldan anlık okunur.
 *
 * `SupportsInvoiceData::fetchInvoiceDraft()` üretir, `InvoiceProvider`
 * tüketir. Alıcıyı taşıdığı için SAKLANMAZ ve kuyruğa serileştirilmez
 * (`InvoiceBuyer`).
 *
 * İnternet satışı alanları (`platformName`, `platformUrl`, `paymentType`)
 * e-arşivde ZORUNLUDUR: GİB internetten satışta satışın yapıldığı adresi
 * ve ödeme biçimini ister.
 */
final readonly class InvoiceDraft
{
    /** Ödeme aracısı — pazar yeri tahsil etti (GİB kodu). */
    public const PAYMENT_INTERMEDIARY = 'ODEMEARACISI';

    /**
     * @param  list<InvoiceLine>  $lines
     */
    public function __construct(
        public InvoiceBuyer $buyer,
        public array $lines,
        public string $orderNumber,
        public DateTimeImmutable $orderedAt,
        public string $platformName,
        public string $platformUrl,
        public string $currency = 'TRY',
        public string $paymentType = self::PAYMENT_INTERMEDIARY,
    ) {}
}
