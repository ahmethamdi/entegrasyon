<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Fulfillment\Exceptions\LabelRejected;
use App\Domain\Fulfillment\Support\CommonLabel;
use App\Domain\Fulfillment\Support\ShipmentInfo;
use App\Domain\Orders\Models\Order;

/**
 * Pazaryeri ORTAK KARGO ETİKETİ yeteneği (Trendyol createCommonLabel /
 * getCommonLabel).
 *
 * Adapter yalnız kanalın OTORİTER etiketini getirir; depo görünümü
 * (ZPL dosyası, yazdırma sayfası) `LabelDocuments` katmanında üretilir.
 * Adapter yan etkisizdir: durum satırına `PrepareShippingLabel` yazar.
 *
 * Kesin ret `LabelRejected` (sebep koduyla); geçici hata HTTP istisnası.
 */
interface SupportsCommonLabel
{
    /**
     * Paketi kanaldan anlık okur.
     *
     * @throws LabelRejected paket bulunamadı
     */
    public function fetchShipment(Order $order): ShipmentInfo;

    /**
     * Etiket ister. Dönüş = kanal isteği kabul etti.
     *
     * @throws LabelRejected
     */
    public function requestCommonLabel(ShipmentInfo $shipment, int $boxQuantity): void;

    /**
     * Etiketi sorar; henüz üretilmediyse null.
     *
     * @throws LabelRejected
     */
    public function fetchCommonLabel(ShipmentInfo $shipment): ?CommonLabel;
}
