<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use InvalidArgumentException;

/**
 * Tek bir listing için gönderilecek stok kalemi.
 *
 * Mimari Karar Dokümanı v2.2 · §1 · Karar 25.
 *
 * quantity MUTLAK ve NEGATİF OLAMAZ. Negatif bir değer buraya ulaşıyorsa
 * çağıran OutboundQuantity::forChannel() uygulamayı unutmuş demektir; sessizce
 * kırpmak yerine istisna fırlatılır — kırpmanın ikinci bir yerde yapılması,
 * kanonik durumun yanlışlıkla kırpıldığı gerçek hatayı gizlerdi.
 */
final readonly class InventoryPushItem
{
    public function __construct(
        public string $listingId,
        public string $externalId,
        public string $sku,
        public int $quantity,
        /** Operasyonun taşıdığı iş sürümü — sonuç yazımında kullanılır. */
        public int $version,
    ) {
        if ($quantity < 0) {
            throw new InvalidArgumentException(
                "Giden stok miktarı negatif olamaz ({$quantity}, listing {$listingId}). ".
                'Kırpma OutboundQuantity::forChannel() içinde yapılmalıdır.'
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'listing_id' => $this->listingId,
            'external_id' => $this->externalId,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'version' => $this->version,
        ];
    }
}
