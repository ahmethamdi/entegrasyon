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
    /**
     * Kanalın ham alanlarından alıcı — HB, N11 ve Shopify ortak kuralı
     * (Trendyol'un `invoiceBuyer`'ıyla aynı):
     *   · 10 haneli VKN varsa FİRMA adına (firma adı yoksa kişi adı)
     *   · yoksa bireysel; 11 haneli TCKN yoksa GİB'in anonim kimliği
     *   · adres yoksa "-" (e-arşiv boş adres kabul etmez)
     */
    public static function fromChannel(
        ?string $person,
        ?string $company,
        ?string $vkn,
        ?string $tckn,
        ?string $taxOffice,
        ?string $address,
        ?string $city,
        ?string $district,
        ?string $email,
        ?string $countryCode,
    ): self {
        $text = static fn (?string $v): ?string => $v !== null && trim($v) !== '' ? trim($v) : null;

        $vkn = preg_replace('/\D/', '', (string) $vkn);
        $tckn = preg_replace('/\D/', '', (string) $tckn);
        $isCompany = strlen((string) $vkn) === 10;
        $person = $text($person) ?? 'Nihai Tüketici';

        return new self(
            name: $isCompany ? ($text($company) ?? $person) : $person,
            isCompany: $isCompany,
            taxNumber: $isCompany ? (string) $vkn : (strlen((string) $tckn) === 11 ? (string) $tckn : self::ANONYMOUS_TCKN),
            taxOffice: $isCompany ? $text($taxOffice) : null,
            address: $text($address) ?? '-',
            city: $text($city),
            district: $text($district),
            email: $text($email),
            countryCode: strtoupper($text($countryCode) ?? 'TR'),
        );
    }

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
