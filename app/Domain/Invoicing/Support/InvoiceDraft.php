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

    /**
     * Müşterinin ödediği toplam — kalemlerin KDV DAHİL tutarı, "1234.56".
     *
     * KURUŞ TAMSAYISIYLA toplanır: kayan noktada 0,1 + 0,2 gibi toplamlar
     * 0,30000000000000004 olur ve çok kalemli siparişte tahsilat faturadan
     * bir kuruş saparak faturayı "kısmen ödenmiş" bırakırdı.
     */
    public function grossTotal(): string
    {
        $cents = 0;

        foreach ($this->lines as $line) {
            $cents += (int) round((float) $line->grossUnitPrice * 100) * $line->quantity;
        }

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
