<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Trendyol\Catalog;

/**
 * Ürün yükünün katalogda OLMAYAN, bağlantıdan ya da kanaldan gelen kısmı.
 *
 * Marka kimliği kanaldan aranır (`brands/by-name`); KDV, desi ve adresler
 * bağlantı ayarıdır. Mapper ağ çağrısı yapmaz — bu değerler ona hazır verilir.
 */
final readonly class ListingContext
{
    public function __construct(
        public int $brandId,
        public int $vatRate,
        public float $dimensionalWeight,
        public ?int $shipmentAddressId = null,
        public ?int $returningAddressId = null,
    ) {}
}
