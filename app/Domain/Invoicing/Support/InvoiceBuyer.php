<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

/**
 * Faturadaki alıcı — kanaldan ANLIK okunur, SAKLANMAZ.
 *
 * Bu nesne yalnız bellekte yaşar: kanal adapter'ı üretir, entegratör
 * tüketir. Kuyruk işine, veritabanına, log'a ya da Inertia prop'una
 * YAZILMAZ (kullanıcı kararı, 10 Eki 2026 — "anlık çek, saklama").
 * `__debugInfo` bu yüzden kişisel alanları gizler: bir `dump()` ya da
 * istisna bağlamı adresi log'a taşımasın.
 */
final readonly class InvoiceBuyer
{
    /**
     * TCKN'si bilinmeyen bireysel alıcı için GİB'in kabul ettiği kimlik.
     * Pazar yerleri çoğu siparişte TCKN paylaşmaz.
     */
    public const ANONYMOUS_TCKN = '11111111111';

    public function __construct(
        public string $name,
        public bool $isCompany,
        public string $taxNumber,
        public ?string $taxOffice,
        public string $address,
        public ?string $city,
        public ?string $district,
        public ?string $email = null,
        public ?string $phone = null,
        public string $countryCode = 'TR',
    ) {}

    /**
     * Mükellef sorgusuna değer mi: 11111111111 hiçbir zaman e-fatura
     * mükellefi değildir ve sorgu boşa bir istek olurdu.
     */
    public function hasRealTaxNumber(): bool
    {
        return $this->taxNumber !== self::ANONYMOUS_TCKN && preg_match('/^\d{10,11}$/', $this->taxNumber) === 1;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['isCompany' => $this->isCompany, 'countryCode' => $this->countryCode, 'personal' => '[redacted]'];
    }
}
