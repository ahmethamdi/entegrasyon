<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use Carbon\CarbonImmutable;

/**
 * Kanalın tek finansal işlemi — OLDUĞU GİBİ.
 *
 * Tutarlar decimal string ya da NULL. NULL = kanal bildirmedi; 0 değildir
 * (Trendyol satış satırında `commissionAmount` olmayan bir tür NULL döner).
 *
 * @property-read array<string, mixed> $raw
 */
final readonly class RemoteFinancialTransaction
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $source,
        public string $externalId,
        public string $typeRaw,
        public ?string $debt,
        public ?string $credit,
        public ?string $commissionAmount,
        public ?string $commissionRate,
        public ?string $sellerRevenue,
        public ?string $currency,
        public ?string $orderNumber,
        public ?string $shipmentPackageId,
        public ?string $barcode,
        public ?CarbonImmutable $transactionDate,
        public ?CarbonImmutable $paymentDate,
        public ?string $paymentOrderId,
        public ?string $receiptId,
        public ?string $commissionInvoiceSerial,
        public ?string $description,
        public array $raw,
    ) {}

    /**
     * Ham gövdenin özeti — aynı kimlikle FARKLI içerik gelirse fark edilsin.
     */
    public function rawHash(): string
    {
        return hash('sha256', (string) json_encode(self::sortKeys($this->raw), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @param array<array-key, mixed> $value */
    private static function sortKeys(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }
}
