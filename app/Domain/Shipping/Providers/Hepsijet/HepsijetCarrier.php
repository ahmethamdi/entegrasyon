<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Providers\Hepsijet;

use App\Domain\Shipping\Contracts\Carrier;
use App\Domain\Shipping\Exceptions\CarrierException;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Support\CreatedShipment;
use App\Domain\Shipping\Support\JsonTransport;
use App\Domain\Shipping\Support\ShipmentRequest;
use App\Domain\Shipping\Support\TrackingResult;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * HepsiJet — entegrasyon servisleri (REST/JSON, token).
 *
 * Kaynak: HepsiJet'in partnerlerine verdiği "Module Design – Integration
 * (Customer Services)" belgesi (v1.9.2 DOCX + v2.1 PDF; herkese açık
 * portal YOK — bkz. docs/HEPSIJET-API-NOTLARI.md). GERÇEK İSTEKLE
 * DOĞRULANMADI: ne test ne canlı sunucuda denendi; firma varsayılan gizli
 * (`CarrierAccount::UNVERIFIED`, pilot için `CARRIERS_UNVERIFIED_ENABLED`).
 *
 * Belgeden alınanlar:
 *   GET  /auth/getToken                       Basic kullanıcı:şifre → data.token (60 dk)
 *                                             yanlış kimlik: {"status":"FAIL","message":"Kullanıcı Adı veya Şifre Hatalı"}
 *   POST /rest/delivery/sendDeliveryOrderEnhanced  gönderi + ZPL etiket (zplBarcodeDTOList)
 *                                             aynı numara: FAIL + detailStatus BARCODE_EXIST
 *   GET  /delivery/generateZplBarcode/{no}/{paket}  sonradan etiket (belgede /rest öneki YOK)
 *   POST /rest/delivery/integration/track     integrationStatus: NEO, ACCEPTED, OUT_FOR_DELIVERY,
 *                                             DELIVERED, FAILED_ATTEMPT, RETURNED, CANCELLED, DELETED
 *   POST /rest/delivery/deleteDeliveryOrder/{no}   {"deleteReason":"IPTAL"} — yalnız ilk statüde
 *
 * Sonraki her istek `X-Auth-Token` başlığı taşır. v2.1 PDF bir de `apikey`
 * başlığını zorunlu yazar, önceki belge yazmaz → satıcı HepsiJet'ten
 * aldıysa gönderilir, yoksa gönderilmez (zorunlu olup olmadığı DOĞRULANMADI).
 *
 * İDEMPOTENS: barkod (`customerDeliveryNo`) bizim anahtarımızdır ve firmanın
 * kısaltma koduyla başlar (belge: 9–16 karakter). Aynı numarayla ikinci
 * istek BARCODE_EXIST döner → gönderi ilk istekte açılmıştır; etiketi
 * ayrıca isteriz ve BAŞARI döneriz (Aras'taki "önce sor" yerine).
 */
final class HepsijetCarrier implements Carrier
{
    public const CANCEL_REASON = 'IPTAL';

    /** Belge: "Dış müşteri entegrasyonunda gönderi tipi RETAIL gelmelidir." */
    private const DELIVERY_TYPE = 'RETAIL';

    /** Belge: HX_STD = standart teslimat. */
    private const PRODUCT_CODE = 'HX_STD';

    public function __construct(private readonly JsonTransport $http) {}

    public function supportsTracking(): bool
    {
        return true;
    }

    /** Token alınabiliyorsa kimlik doğrudur — firmada iz bırakmaz. */
    public function verify(CarrierAccount $account): void
    {
        Cache::forget($this->tokenKey($account));
        $this->token($account);
    }

    public function create(CarrierAccount $account, ShipmentRequest $request): CreatedShipment
    {
        $r = $request->recipient;
        $phone = ltrim($r->nationalPhone(), '0');

        if ($phone === '') {
            throw new CarrierException(ErrorClass::VALIDATION, 'Alıcı telefonu yok — HepsiJet alıcı telefonunu zorunlu tutuyor.');
        }

        foreach (['company_code', 'sender_city', 'sender_district', 'sender_address'] as $field) {
            if ($account->credential($field) === null) {
                throw new CarrierException(ErrorClass::VALIDATION, 'HepsiJet hesabında firma kodu ya da gönderici adresi eksik; kargo ayarlarından tamamlayın.');
            }
        }

        $reference = substr(hash('sha256', $r->name.'|'.$r->address.'|'.$r->city.'|'.$r->district), 0, 24);
        [$first, $last] = self::splitName($r->name);

        $body = [
            'company' => ['abbreviationCode' => (string) $account->credential('company_code')],
            'delivery' => [
                'customerDeliveryNo' => $request->cargoKey,
                'customerOrderId' => mb_substr($request->orderNumber, 0, 64),
                'deliveryType' => self::DELIVERY_TYPE,
                'totalParcels' => (string) $request->packageCount,
                'product' => ['productCode' => self::PRODUCT_CODE],
                'receiver' => array_filter([
                    'companyCustomerId' => 'C'.$reference,
                    'firstName' => $first,
                    'lastName' => $last,
                    'phone1' => $phone,
                    'email' => $r->email,
                ], static fn ($v): bool => $v !== null && $v !== ''),
                'recipientPerson' => mb_substr($r->name, 0, 200),
                'recipientPersonPhone1' => $phone,
                'recipientAddress' => [
                    'companyAddressId' => 'A'.$reference,
                    'country' => ['name' => 'Türkiye'],
                    'city' => ['name' => $r->city],
                    'town' => ['name' => $r->district],
                    'addressLine1' => mb_substr($r->address, 0, 500),
                ],
                'senderAddress' => array_filter([
                    'companyAddressId' => $account->credential('sender_address_id'),
                    'country' => ['name' => 'Türkiye'],
                    'city' => ['name' => (string) $account->credential('sender_city')],
                    'town' => ['name' => (string) $account->credential('sender_district')],
                    'addressLine1' => (string) $account->credential('sender_address'),
                ], static fn ($v): bool => $v !== null),
            ],
        ];

        if (($xdock = $account->credential('xdock_code')) !== null) {
            $body['currentXDock'] = ['abbreviationCode' => $xdock];
        }

        $reply = $this->call($account, 'POST', '/rest/delivery/sendDeliveryOrderEnhanced', $body);
        $data = JsonTransport::decode($reply) ?? [];

        if (($data['status'] ?? null) === 'OK') {
            $number = (string) ($data['data']['customerDeliveryNo'] ?? $request->cargoKey);
            $zpl = self::zplOf((array) ($data['data']['zplBarcodeDTOList'] ?? []));

            return new CreatedShipment(
                trackingNumber: $number,
                labelZpl: $zpl ?? $this->label($account, $number, $request->packageCount),
            );
        }

        if (($data['detailStatus'] ?? null) === 'BARCODE_EXIST') {
            // Önceki deneme firmada açmış: ikinci gönderi yok, etiketi iste.
            return new CreatedShipment(
                trackingNumber: $request->cargoKey,
                labelZpl: $this->label($account, $request->cargoKey, $request->packageCount),
            );
        }

        throw $this->failure($reply, $data, 'HepsiJet gönderiyi açmadı.');
    }

    public function track(CarrierAccount $account, string $cargoKey): TrackingResult
    {
        $reply = $this->call($account, 'POST', '/rest/delivery/integration/track', ['barcodes' => [$cargoKey]]);
        $data = JsonTransport::decode($reply) ?? [];

        if (($data['status'] ?? null) !== 'OK') {
            $message = is_string($data['message'] ?? null) ? $data['message'] : null;

            if ($message !== null && preg_match('/bulunamad/iu', $message) === 1) {
                throw new CarrierException(ErrorClass::NOT_FOUND, $message);
            }

            throw $this->failure($reply, $data, 'HepsiJet durumu vermedi.', ErrorClass::SERVER_ERROR);
        }

        $row = null;

        foreach ((array) ($data['data'] ?? []) as $candidate) {
            if (is_array($candidate) && (string) ($candidate['barcode'] ?? '') === $cargoKey) {
                $row = $candidate;
            }
        }

        $details = array_values(array_filter((array) ($row['details'] ?? []), 'is_array'));

        if ($row === null || $details === []) {
            // Kayıt/hareket yok: tahmin edilmez, hiçbir şey değişmez.
            return new TrackingResult(TrackingResult::UNKNOWN);
        }

        $last = $details[count($details) - 1];
        $status = is_string($last['integrationStatus'] ?? null) ? $last['integrationStatus'] : null;
        $url = is_string($row['trackingUrl'] ?? null) ? $row['trackingUrl'] : null;

        return new TrackingResult(
            state: self::stateOf($status),
            carrierStatus: $status,
            carrierText: is_string($last['transactionLocation'] ?? null) ? $last['transactionLocation'] : null,
            trackingNumber: $cargoKey,
            trackingUrl: $url !== null && str_starts_with($url, 'http') ? $url : null,
        );
    }

    public function cancel(CarrierAccount $account, string $cargoKey): void
    {
        $reply = $this->call($account, 'POST', '/rest/delivery/deleteDeliveryOrder/'.rawurlencode($cargoKey), ['deleteReason' => self::CANCEL_REASON]);
        $data = JsonTransport::decode($reply) ?? [];

        if (($data['status'] ?? null) === 'OK') {
            return;
        }

        // Belge: silme yalnız ilk statüde; sonrası firmanın metniyle reddedilir.
        throw $this->failure($reply, $data, 'HepsiJet gönderiyi iptal etmedi.');
    }

    /**
     * Firmanın entegrasyon durumu → çekirdeğin durumu (belgedeki Türkçe karşılıklarla).
     *
     * NEO "Gönderi oluştu" · ACCEPTED "Gönderi teslim alındı" · OUT_FOR_DELIVERY
     * "Gönderi yolda" · FAILED_ATTEMPT "Teslimat denemesi başarısız" · RETURNED
     * "Gönderi satıcıya iade edildi" (firma teslim almıştı → kargoda sayılır) ·
     * DELIVERED · CANCELLED · DELETED. Gerçek gönderide DOĞRULANMADI.
     */
    public static function stateOf(?string $status): string
    {
        return match ($status) {
            'NEO' => TrackingResult::WAITING,
            'ACCEPTED', 'OUT_FOR_DELIVERY', 'FAILED_ATTEMPT', 'RETURNED' => TrackingResult::SHIPPED,
            'DELIVERED' => TrackingResult::DELIVERED,
            'CANCELLED', 'DELETED' => TrackingResult::CANCELLED,
            default => TrackingResult::UNKNOWN,
        };
    }

    /** @return array{0: string, 1: string} */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];

        if (count($parts) < 2) {
            $single = $parts[0] ?? '';

            return [$single, $single];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), (string) $last];
    }

    private function label(CarrierAccount $account, string $number, int $packages): string
    {
        $reply = $this->call($account, 'GET', '/delivery/generateZplBarcode/'.rawurlencode($number).'/'.max($packages, 1));
        $data = JsonTransport::decode($reply) ?? [];

        if (($data['status'] ?? null) !== 'OK') {
            throw $this->failure($reply, $data, 'HepsiJet etiketi vermedi.', ErrorClass::SERVER_ERROR);
        }

        $zpl = self::zplOf((array) ($data['data'] ?? []));

        if ($zpl === null) {
            throw new CarrierException(ErrorClass::SERVER_ERROR, 'HepsiJet etiket cevabında ZPL yok.');
        }

        return $zpl;
    }

    /** @param array<mixed> $rows */
    private static function zplOf(array $rows): ?string
    {
        $labels = [];

        foreach ($rows as $row) {
            $zpl = is_array($row) ? ($row['zplBarcode'] ?? null) : null;

            if (is_string($zpl) && str_contains($zpl, '^XA')) {
                $labels[] = $zpl;
            }
        }

        return $labels === [] ? null : implode("\n", $labels);
    }

    /**
     * Token'lı istek. 401/403 gelirse token bir kez tazelenir (süresi
     * dolmuş olabilir); yine reddedilirse AUTHENTICATION.
     *
     * @param  array<string, mixed>|null  $json
     */
    private function call(CarrierAccount $account, string $method, string $path, ?array $json = null): Response
    {
        $url = $this->baseUrl($account).$path;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $reply = $this->http->send($method, $url, $this->headers($account, ['X-Auth-Token' => $this->token($account)]), $json);

            if (! in_array($reply->status(), [401, 403], true)) {
                if ($reply->serverError() && JsonTransport::decode($reply) === null) {
                    throw new CarrierException(ErrorClass::SERVER_ERROR, 'HepsiJet hata verdi (HTTP '.$reply->status().').');
                }

                return $reply;
            }

            Cache::forget($this->tokenKey($account));
        }

        throw new CarrierException(ErrorClass::AUTHENTICATION, 'HepsiJet oturumu reddetti; kullanıcı adı/şifreyi yeniden girin.');
    }

    private function token(CarrierAccount $account): string
    {
        $key = $this->tokenKey($account);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $reply = $this->http->send('GET', $this->baseUrl($account).'/auth/getToken', $this->headers($account, [
            'Authorization' => 'Basic '.base64_encode($account->username().':'.$account->password()),
        ]));
        $data = JsonTransport::decode($reply) ?? [];
        $token = $data['data']['token'] ?? null;

        if (($data['status'] ?? null) === 'OK' && is_string($token) && $token !== '') {
            Cache::put($key, $token, now()->addMinutes((int) config('shipping.hepsijet.token_ttl_minutes', 50)));

            return $token;
        }

        if (($data['status'] ?? null) === 'FAIL' || in_array($reply->status(), [401, 403], true)) {
            throw new CarrierException(ErrorClass::AUTHENTICATION, is_string($data['message'] ?? null) ? $data['message'] : 'HepsiJet kullanıcı adı/şifre reddedildi.');
        }

        throw new CarrierException(ErrorClass::SERVER_ERROR, 'HepsiJet token vermedi (HTTP '.$reply->status().').');
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function headers(CarrierAccount $account, array $extra): array
    {
        $headers = $extra;

        if (($apiKey = $account->credential('api_key')) !== null) {
            $headers['apikey'] = $apiKey;
        }

        return $headers;
    }

    private function baseUrl(CarrierAccount $account): string
    {
        return rtrim((string) config('shipping.hepsijet.'.$account->environment, config('shipping.hepsijet.live')), '/');
    }

    /** Önbellek anahtarı kimliğin özetidir — şifre anahtara düz yazılmaz. */
    private function tokenKey(CarrierAccount $account): string
    {
        return 'shipping:hepsijet:token:'.hash('sha256', $account->environment.'|'.$account->username().'|'.$account->password());
    }

    /** @param array<mixed> $data */
    private function failure(Response $reply, array $data, string $fallback, ErrorClass $otherwise = ErrorClass::VALIDATION): CarrierException
    {
        $message = is_string($data['message'] ?? null) && $data['message'] !== '' ? $data['message'] : $fallback;
        $code = is_string($data['detailStatus'] ?? null) ? $data['detailStatus'] : null;

        // Okunabilir FAIL gövdesi kalıcıdır (firmanın kuralı); gövdesiz 4xx/5xx geçicidir.
        $class = ($data['status'] ?? null) === 'FAIL' ? $otherwise : ErrorClass::SERVER_ERROR;

        if ($reply->serverError()) {
            $class = ErrorClass::SERVER_ERROR;
        }

        return new CarrierException($class, mb_substr($message, 0, 500), $code);
    }
}
