<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

/**
 * Fatura kalemi — fiyat KDV DAHİL ve indirim düşülmüş birim fiyattır.
 *
 * Pazar yerleri müşterinin ödediği fiyatı (KDV dahil) verir; entegratörler
 * çoğunlukla KDV HARİÇ birim fiyat ister. Çeviri burada tek yerde yapılır
 * (`netUnitPrice`), her entegratör ayrı ayrı bölmeye kalkmasın.
 */
final readonly class InvoiceLine
{
    public function __construct(
        public string $description,
        public int $quantity,
        public string $grossUnitPrice,
        public int $vatRate,
        public ?string $sku = null,
    ) {}

    /**
     * KDV hariç birim fiyat, 4 basamak.
     *
     * 2 basamağa yuvarlansaydı 99,99 TL'lik (%20) kalem 83,33 olur ve
     * KDV eklenince 99,996 → faturada müşterinin ödediğinden farklı tutar
     * çıkabilirdi; çok adetli kalemde fark büyür. 4 basamak toplamı
     * kuruşuna kadar korur.
     */
    public function netUnitPrice(): string
    {
        $net = (float) $this->grossUnitPrice / (1 + $this->vatRate / 100);

        return number_format($net, 4, '.', '');
    }
}
