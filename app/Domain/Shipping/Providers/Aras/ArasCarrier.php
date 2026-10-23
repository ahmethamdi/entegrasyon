<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Providers\Aras;

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
 * Aras Kargo — arascargoservice.asmx (SOAP, .NET).
 *
 * Kaynak: firmanın WSDL'i ve test ortamında 23 Eki 2026'da yapılan gerçek
 * çağrılar (docs/ARAS-API-NOTLARI.md). Ölçülen kodlar:
 *   SetOrder      ResultCode 0 başarılı · 1000 kullanıcı/şifre yanlış
 *                 aynı IntegrationCode ile tekrar → yine 0 (ikinci gönderi yok)
 *   GetArasBarcode ResultCode 0 → TrackingNumber + ZebraZpl + ZebraPdf (base64 PDF)
 *                 1001 kod bulunamadı · 999 kullanıcı/şifre yanlış
 *   CancelDispatch 0 başarılı · 405 daha önce iptal · -1 kayıt yok · -2 kimlik
 *
 * ⚠️ SetOrder aynı kodla TEKRARLANINCA gönderi çoğalmaz ama Aras YENİ
 * barkod/takip numarası üretir (23 Eki, test ortamında ölçüldü; aynı
 * kayda tekrarlanan GetArasBarcode ise hep aynı numarayı verir). Bu
 * yüzden `create` önce barkodu SORAR: kayıt varsa onu döndürür, SetOrder
 * yalnız kayıt yoksa (1001) gider — yeniden deneme takip numarasını
 * değiştirmez.
 *
 * DURUM SORGUSU YOK: Aras'ın gönderi hareket servisi (GetCargoInfo,
 * GetQueryJSON) ayrı müşteri kodu/kullanıcı istiyor; test kullanıcısı
 * reddedildi, cevap biçimi DOĞRULANAMADI. Tahminle ayrıştırıcı yazılmadı —
 * satıcı "kargoya verdim" der; takip numarası etiketle birlikte gelir.
 *
 * ⚠️ GetOrderWithIntegrationCode yanlış şifrede de BOŞ ve hatasız döner
 * (ölçüldü) — sessiz başarı üreteceği için hiçbir yerde kullanılmaz.
 */
final class ArasCarrier implements Carrier
{
    private const NS = 'http://tempuri.org/';

    public const PROBE_KEY = '34PAZARDOGRULAMA';

    public function __construct(private readonly SoapTransport $soap) {}

    public function supportsTracking(): bool
    {
        return false;
    }

    public function verify(CarrierAccount $account): void
    {
        // Olmayan kod için barkod: 1001 = kimlik doğru, 999 = yanlış (ölçüldü).
        $reply = $this->barcode($account, self::PROBE_KEY);
        $code = $reply->text('ResultCode');

        if ($code === '1001' || $code === '0') {
            return;
        }

        throw $this->error($reply->text('Message'), $code, ErrorClass::SERVER_ERROR);
    }

    public function create(CarrierAccount $account, ShipmentRequest $request): CreatedShipment
    {
        $r = $request->recipient;
        $key = $request->cargoKey;

        // Önce sor: önceki deneme firmada açtıysa aynı kayıt (aynı takip no).
        $existing = $this->barcode($account, $key);
        $existingCode = $existing->text('ResultCode');

        if ($existingCode === '0') {
            return $this->createdFrom($existing);
        }

        if ($existingCode !== '1001') {
            throw $this->error($existing->text('Message'), $existingCode, ErrorClass::SERVER_ERROR);
        }

        $order = SoapTransport::fields([
            'UserName' => $account->username(),
            'Password' => $account->password(),
            'TradingWaybillNumber' => $key,
            'InvoiceNumber' => $key,
            'ReceiverName' => mb_substr($r->name, 0, 200),
            'ReceiverAddress' => mb_substr($r->address, 0, 500),
            'ReceiverPhone1' => $r->nationalPhone() !== '' ? $r->nationalPhone() : null,
            'ReceiverCityName' => $r->city,
            'ReceiverTownName' => $r->district,
            'PieceCount' => $request->packageCount,
            'IntegrationCode' => $key,
            'Description' => 'Sipariş '.$request->orderNumber,
        ]).'<PieceDetails><PieceDetail>'.SoapTransport::fields([
            'VolumetricWeight' => 1,
            'Weight' => 1,
            'BarcodeNumber' => $key,
        ]).'</PieceDetail></PieceDetails>';

        $reply = $this->send($account, 'SetOrder', '<orderInfo><Order>'.$order.'</Order></orderInfo>'
            .SoapTransport::fields(['userName' => $account->username(), 'password' => $account->password()]));

        $code = $reply->text('ResultCode');

        if ($code !== '0') {
            throw $this->error($reply->text('ResultMessage'), $code, ErrorClass::VALIDATION);
        }

        // Etiket ve takip numarası ayrı çağrıda. Başarısız olursa iş
        // yeniden dener ve bir sonraki tur önce barkodu sorar (yukarıda);
        // SetOrder yalnız kayıt yoksa tekrarlanır.
        $barcode = $this->barcode($account, $key);
        $bcode = $barcode->text('ResultCode');

        if ($bcode !== '0') {
            // 1001 hemen sonra = firma kaydı henüz görmüyor → geçici.
            throw $this->error($barcode->text('Message'), $bcode, ErrorClass::SERVER_ERROR);
        }

        return $this->createdFrom($barcode);
    }

    private function createdFrom(SoapReply $barcode): CreatedShipment
    {
        $bcode = $barcode->text('ResultCode');
        $tracking = $barcode->text('TrackingNumber');
        $zpl = $barcode->first('ZebraZpl');
        $pdf = $barcode->first('ZebraPdf');
        $zplText = $zpl === null ? null : $barcode->text('string', $zpl);
        $pdfText = $pdf === null ? null : $barcode->text('string', $pdf);

        if ($tracking === null || $tracking === '' || ($zplText === null && $pdfText === null)) {
            throw new CarrierException(ErrorClass::SERVER_ERROR, 'Aras barkod cevabında takip numarası ya da etiket yok.', $bcode);
        }

        return new CreatedShipment(
            trackingNumber: $tracking,
            labelZpl: $zplText !== '' ? $zplText : null,
            labelPdf: $pdfText !== null && $pdfText !== '' && str_starts_with((string) base64_decode(substr($pdfText, 0, 12), true), '%PDF') ? $pdfText : null,
        );
    }

    public function track(CarrierAccount $account, string $cargoKey): TrackingResult
    {
        return new TrackingResult(TrackingResult::UNKNOWN);
    }

    public function cancel(CarrierAccount $account, string $cargoKey): void
    {
        $reply = $this->send($account, 'CancelDispatch', SoapTransport::fields([
            'userName' => $account->username(),
            'password' => $account->password(),
            'integrationCode' => $cargoKey,
        ]));

        $code = $reply->text('ResultCode');

        // 405 "daha önce iptal" ve -1 "kayıt yok": iptal isteği zaten yerine gelmiş.
        if (in_array($code, ['0', '405', '-1'], true)) {
            return;
        }

        throw $this->error($reply->text('ResultMessage'), $code, ErrorClass::VALIDATION);
    }

    private function barcode(CarrierAccount $account, string $key): SoapReply
    {
        return $this->send($account, 'GetArasBarcode', SoapTransport::fields([
            'Username' => $account->username(),
            'Password' => $account->password(),
            'integrationCode' => $key,
        ]));
    }

    private function send(CarrierAccount $account, string $operation, string $inner): SoapReply
    {
        $endpoint = (string) config('shipping.aras.'.$account->environment, config('shipping.aras.live'));

        return $this->soap->call($endpoint, self::NS.$operation, '<'.$operation.' xmlns="'.self::NS.'">'.$inner.'</'.$operation.'>');
    }

    /** Kimlik kodları (1000 / 999 / -2) her işlemde AUTHENTICATION'dır. */
    private function error(?string $message, ?string $code, ErrorClass $otherwise): CarrierException
    {
        $class = in_array($code, ['1000', '999', '-2'], true) ? ErrorClass::AUTHENTICATION : $otherwise;

        return new CarrierException($class, $message ?? 'Aras isteği reddetti.', $code);
    }
}
