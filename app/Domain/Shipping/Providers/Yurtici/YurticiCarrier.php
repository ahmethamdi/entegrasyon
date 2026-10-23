<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Providers\Yurtici;

use App\Domain\Shipping\Contracts\Carrier;
use App\Domain\Shipping\Exceptions\CarrierException;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Support\CreatedShipment;
use App\Domain\Shipping\Support\ShipmentRequest;
use App\Domain\Shipping\Support\SoapReply;
use App\Domain\Shipping\Support\SoapTransport;
use App\Domain\Shipping\Support\TrackingResult;
use App\Domain\Sync\Enums\ErrorClass;

/**
 * Yurtiçi Kargo — ShippingOrderDispatcherServices (SOAP).
 *
 * Kaynak: firmanın WSDL'i ve test ortamında 23 Eki 2026'da yapılan gerçek
 * çağrılar (docs/YURTICI-API-NOTLARI.md). Ölçülen kodlar:
 *   outFlag 0                      başarılı
 *   errCode 1626 (üst düzey)       kullanıcı/şifre örtüşmüyor  → AUTHENTICATION
 *   errCode 60020 (create)         aynı cargoKey zaten var      → BAŞARI (idempotens)
 *   errCode 82502 (create)         alan kuralı (ör. adres ≥10)   → VALIDATION
 *   errCode 82519 (query/cancel)   anahtar yok (iptal sonrası)   → NOT_FOUND / iptal edilmiş
 *   operationStatus NOP            "Kargo İşlem Görmemiş."       → waiting
 *   operationStatus CNL            iptal (cancel cevabı)         → cancelled
 *
 * Gönderi açılırken TAKİP NUMARASI YOKTUR: müşterinin sorgulayacağı numara
 * (`docId`) kargo şubede işlem görünce oluşur ve durum sorgusunda gelir.
 * Firma ETİKET VERMEZ: paketin üstündeki barkod bizim cargoKey'imizdir;
 * etiketi `YurticiLabel` üretir.
 */
final class YurticiCarrier implements Carrier
{
    private const NS = 'http://yurticikargo.com.tr/ShippingOrderDispatcherServices';

    /** Doğrulama sorgusunun anahtarı — firmada hiçbir zaman var olmaz. */
    public const PROBE_KEY = '34PAZARDOGRULAMA';

    public function __construct(private readonly SoapTransport $soap) {}

    public function supportsTracking(): bool
    {
        return true;
    }

    public function verify(CarrierAccount $account): void
    {
        // Anahtar yok cevabı (82519) kimliğin DOĞRU olduğunu gösterir;
        // yanlış kimlik üst düzeyde 1626 döner. Başka bir üst düzey ret
        // (outFlag ≠ 0) "bağlandı" sayılmaz — sessiz başarı yok.
        $reply = $this->query($account, self::PROBE_KEY);

        if ($reply->text('outFlag') !== '0') {
            throw new CarrierException(ErrorClass::SERVER_ERROR, $reply->text('outResult') ?? 'Yurtiçi hesabı doğrulamadı.');
        }
    }

    public function create(CarrierAccount $account, ShipmentRequest $request): CreatedShipment
    {
        $r = $request->recipient;

        if (mb_strlen(trim($r->address)) < 10) {
            throw new CarrierException(ErrorClass::VALIDATION, 'Alıcı adresi çok kısa (Yurtiçi en az 10 karakter istiyor).', '82502');
        }

        $order = SoapTransport::fields([
            'cargoKey' => $request->cargoKey,
            'invoiceKey' => $request->cargoKey,
            'receiverCustName' => mb_substr($r->name, 0, 200),
            'receiverAddress' => mb_substr($r->address, 0, 500),
            'cityName' => $r->city,
            'townName' => $r->district,
            'receiverPhone1' => $r->nationalPhone() !== '' ? $r->nationalPhone() : null,
            'emailAddress' => $r->email,
            'cargoCount' => $request->packageCount,
            'description' => 'Sipariş '.$request->orderNumber,
        ]);

        $reply = $this->send($account, 'createShipment', $this->credentials($account, 'userLanguage')
            .'<ShippingOrderVO>'.$order.'</ShippingOrderVO>');

        $this->guardAuthentication($reply);

        $detail = $reply->first('shippingOrderDetailVO');
        $code = $detail === null ? null : $reply->text('errCode', $detail);

        if ($reply->text('outFlag') === '0' && ($code === null || $code === '' || $code === '0')) {
            return new CreatedShipment(labelZpl: YurticiLabel::zpl($request));
        }

        if ($code === '60020') {
            // Aynı anahtarla ikinci istek: gönderi ilk istekte açılmış.
            return new CreatedShipment(labelZpl: YurticiLabel::zpl($request));
        }

        throw new CarrierException(
            $code !== null && $code !== '' ? ErrorClass::VALIDATION : ErrorClass::SERVER_ERROR,
            ($detail === null ? null : $reply->text('errMessage', $detail)) ?? $reply->text('outResult') ?? 'Yurtiçi gönderiyi açmadı.',
            $code,
        );
    }

    public function track(CarrierAccount $account, string $cargoKey): TrackingResult
    {
        $reply = $this->query($account, $cargoKey);
        $detail = $reply->first('shippingDeliveryDetailVO');

        if ($detail === null) {
            throw new CarrierException(ErrorClass::SERVER_ERROR, 'Yurtiçi durum cevabında gönderi satırı yok.');
        }

        $code = $reply->text('errCode', $detail);

        if ($code === '82519') {
            throw new CarrierException(ErrorClass::NOT_FOUND, $reply->text('errMessage', $detail) ?? 'Gönderi Yurtiçi\'de bulunamadı.', $code);
        }

        if ($code !== null && $code !== '' && $code !== '0') {
            throw new CarrierException(ErrorClass::SERVER_ERROR, $reply->text('errMessage', $detail) ?? 'Yurtiçi durumu vermedi.', $code);
        }

        $status = $reply->text('operationStatus', $detail);
        $item = $reply->first('shippingDeliveryItemDetailVO', $detail);
        $docId = $item === null ? null : $reply->text('docId', $item);
        $url = $item === null ? null : $reply->text('trackingUrl', $item);

        return new TrackingResult(
            state: self::stateOf($status),
            carrierStatus: $status,
            carrierText: $reply->text('operationMessage', $detail),
            trackingNumber: $docId !== null && $docId !== '' && $docId !== '0' ? $docId : null,
            trackingUrl: $url !== null && str_starts_with($url, 'http') ? $url : null,
        );
    }

    public function cancel(CarrierAccount $account, string $cargoKey): void
    {
        $reply = $this->send($account, 'cancelShipment', $this->credentials($account, 'userLanguage')
            .'<cargoKeys>'.SoapTransport::text($cargoKey).'</cargoKeys>');

        $this->guardAuthentication($reply);

        $detail = $reply->first('shippingCancelDetailVO');
        $code = $detail === null ? null : $reply->text('errCode', $detail);

        // 82519 = anahtar artık yok: gönderi daha önce iptal edilmiş (ölçüldü).
        if ($code === '82519' || ($detail !== null && $reply->text('operationStatus', $detail) === 'CNL')) {
            return;
        }

        throw new CarrierException(
            ErrorClass::VALIDATION,
            ($detail === null ? null : ($reply->text('errMessage', $detail) ?? $reply->text('operationMessage', $detail))) ?? 'Yurtiçi gönderiyi iptal etmedi.',
            $code,
        );
    }

    /**
     * Firmanın işlem durumu → çekirdeğin durumu.
     *
     * NOP ve CNL test ortamında ölçüldü. IND, ISR, DLV, ISC, BI Yurtiçi
     * entegrasyon belgesindeki adlardır — gerçek gönderide DOĞRULANMADI.
     * Tanınmayan kod `unknown`: hiçbir şey değiştirilmez.
     */
    public static function stateOf(?string $status): string
    {
        return match ($status) {
            'NOP' => TrackingResult::WAITING,
            'IND', 'ISR' => TrackingResult::SHIPPED,
            'DLV' => TrackingResult::DELIVERED,
            'CNL', 'ISC', 'BI' => TrackingResult::CANCELLED,
            default => TrackingResult::UNKNOWN,
        };
    }

    private function query(CarrierAccount $account, string $cargoKey): SoapReply
    {
        $reply = $this->send($account, 'queryShipment', $this->credentials($account, 'wsLanguage')
            .SoapTransport::fields([
                'keys' => $cargoKey,
                'keyType' => 0,
                'addHistoricalData' => 'false',
                'onlyTracking' => 'false',
            ]));

        $this->guardAuthentication($reply);

        return $reply;
    }

    private function credentials(CarrierAccount $account, string $languageField): string
    {
        return SoapTransport::fields([
            'wsUserName' => $account->username(),
            'wsPassword' => $account->password(),
            $languageField => 'TR',
        ]);
    }

    private function send(CarrierAccount $account, string $operation, string $inner): SoapReply
    {
        $endpoint = (string) config('shipping.yurtici.'.$account->environment, config('shipping.yurtici.live'));

        // Yurtiçi işlemleri boş SOAPAction kabul eder (WSDL'de soapAction='').
        return $this->soap->call($endpoint, '', '<ship:'.$operation.' xmlns:ship="'.self::NS.'">'.$inner.'</ship:'.$operation.'>');
    }

    private function guardAuthentication(SoapReply $reply): void
    {
        // Üst düzey errCode (detay satırının DIŞINDA): 1626 = kimlik.
        $top = $reply->first('outFlag')?->parentNode;
        $code = null;

        foreach ($top?->childNodes ?? [] as $child) {
            if ($child->localName === 'errCode') {
                $code = trim($child->textContent);
            }
        }

        if ($code === '1626') {
            throw new CarrierException(ErrorClass::AUTHENTICATION, $reply->text('outResult') ?? 'Yurtiçi kullanıcı adı/şifre reddedildi.', $code);
        }
    }
}
