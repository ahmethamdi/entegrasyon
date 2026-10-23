<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Support;

/**
 * Gönderinin alıcısı — kanaldan ANLIK okunur, SAKLANMAZ.
 *
 * `InvoiceBuyer` ile aynı kural: yalnız bellekte yaşar. Kanal adapter'ı
 * üretir, kargo firmasının istemcisi tüketir; kuyruk işine, veritabanına,
 * log'a ya da Inertia prop'una YAZILMAZ. `__debugInfo` kişisel alanları
 * gizler: bir `dump()` ya da istisna bağlamı adresi log'a taşımasın.
 */
final readonly class ShipmentRecipient
{
    public function __construct(
        public string $name,
        public string $address,
        public string $city,
        public string $district,
        public string $phone,
        public ?string $email = null,
    ) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['recipient' => '[gizli]'];
    }

    /**
     * Firmanın istediği telefon: yalnız rakam, ülke kodu (90) atılır, başta 0.
     * Boş/kısa numara boş döner — çağıran karar verir.
     */
    public function nationalPhone(): string
    {
        $digits = preg_replace('/\D+/', '', $this->phone) ?? '';

        if (str_starts_with($digits, '90') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        return strlen($digits) === 11 ? $digits : '';
    }
}
