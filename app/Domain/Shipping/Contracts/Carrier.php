<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Contracts;

use App\Domain\Shipping\Exceptions\CarrierException;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Support\CreatedShipment;
use App\Domain\Shipping\Support\ShipmentRequest;
use App\Domain\Shipping\Support\TrackingResult;

/**
 * Kargo firması sözleşmesi — kanal adapter'ıyla aynı ayrım: firmanın
 * cevabını YALNIZ sağlayıcı anlar (hata sınıfı dahil), ne yapılacağına
 * çekirdek karar verir.
 *
 * Her yöntem hata durumunda `CarrierException` fırlatır; sessiz başarı
 * YOKTUR — tanınmayan cevap da istisnadır.
 *
 * İDEMPOTENS SÖZLEŞMESİ: `create` aynı `cargoKey` ile ikinci kez
 * çağrılırsa firmada ikinci gönderi açılmaz ve yöntem BAŞARI döner
 * (Yurtiçi 60020 "mevcuttur", Aras aynı IntegrationCode'u günceller —
 * ikisi de 23 Eki test ortamında ölçüldü). `cancel` zaten iptal edilmiş
 * gönderide başarı döner.
 */
interface Carrier
{
    /** Kimlik bilgisini zararsız bir çağrıyla dener; yanlışsa AUTHENTICATION. */
    public function verify(CarrierAccount $account): void;

    /** @throws CarrierException */
    public function create(CarrierAccount $account, ShipmentRequest $request): CreatedShipment;

    /** @throws CarrierException */
    public function track(CarrierAccount $account, string $cargoKey): TrackingResult;

    /** @throws CarrierException */
    public function cancel(CarrierAccount $account, string $cargoKey): void;

    /** Firma durum sorgusunu destekliyor mu (desteklemiyorsa satıcı "kargoya verdim" der). */
    public function supportsTracking(): bool;
}
