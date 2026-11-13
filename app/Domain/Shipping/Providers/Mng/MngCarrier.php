<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Providers\Mng;

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
 * MNG Kargo (DHL eCommerce) — API Zone (REST/JSON, IBM API Connect).
 *
 * Kaynak: apizone.mngkargo.com.tr ürün sayfalarına gömülü RESMÎ Swagger 2.0
 * tanımları (Identity, Standard Command, Barcode Command, Standard Query,
 * CBS Info — 13 Kas 2026 okundu; docs/MNG-API-NOTLARI.md). GERÇEK İSTEKLE
 * DOĞRULANMADI; firma varsayılan gizli (`CarrierAccount::UNVERIFIED`).
 *
 * İKİ KİMLİK:
 *   · `X-IBM-Client-Id` / `X-IBM-Client-Secret` — 34Pazar'ın API Zone
 *     uygulamasınındır (config `shipping.mng.*`), HER istekte. Yoksa firma
 *     hiç sunulmaz (`MngApp::configured`).
 *   · satıcının MNG müşteri numarası + şifresi → `POST /token` → `jwt`,
 *     sonraki komut/sorgularda `Authorization: Bearer`.
 *
 * AKIŞ (spec): `createOrder` siparişi açar → `createbarcode` faturalaştırıp
 * gönderiye çevirir ve etiketi üretir (`shipmentId` = müşterinin takip
 * numarası; durum yanıtındaki `trackingUrl` bu numarayla kurulur).
 * Alıcının il/ilçesi KOD ister (`cityCode`/`districtCode`) — CBS Info
 * listesinden adla eşlenir, eşleşmezse firmaya hiç gidilmez (VALIDATION;
 * hata metnine adres parçası YAZILMAZ — `error_detail` diske düşer).
 *
 * İDEMPOTENS: `referenceId` bizim anahtarımızdır (spec: benzersiz, BÜYÜK
 * harf). Aynı referansla ikinci `createOrder` resmî spec'te belgelenmemiş;
 * açık kaynak istemciler HTTP 500 + kod 3002 "KAYIT ZATEN VAR" gördüğünü
 * yazıyor → kayıt var sayılır, önce durum sorulur: gönderi numarası varsa
 * o döner, yoksa yalnız `createbarcode` tekrarlanır.
 */
final class MngCarrier implements Carrier
{
    /** Spec: identityType "for default set 1". */
    private const IDENTITY_TYPE = 1;

    /** Spec kodları: 1 = STANDART_TESLİMAT · 3 = PAKET · 1 = GONDERICI_ODER · 1 = ADRESE_TESLIM. */
    private const SERVICE_STANDARD = 1;

    private const PACKAGING_PARCEL = 3;

    private const PAYMENT_SENDER = 1;

    private const DELIVERY_TO_ADDRESS = 1;

    /** Açık kaynak istemcilerin gördüğü "kayıt zaten var" kodu — resmî spec'te YOK. */
    private const DUPLICATE_CODE = '3002';

    /** Açık kaynak istemcilerin gördüğü "varış şubesi bulunamadı" — geçici; resmî spec'te YOK. */
    private const BRANCH_PENDING_CODE = '20001';

    public function __construct(private readonly JsonTransport $http) {}

    public function supportsTracking(): bool
    {
        return true;
    }

    /** Token alınabiliyorsa müşteri numarası/şifre doğrudur — firmada iz bırakmaz. */
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
            throw new CarrierException(ErrorClass::VALIDATION, 'Alıcı telefonu yok — MNG en az bir telefon istiyor.');
        }

        [$cityCode, $districtCode] = $this->codesFor($account, $r->city, $r->district);
        $key = strtoupper($request->cargoKey);

        $pieces = [];

        for ($i = 1; $i <= max($request->packageCount, 1); $i++) {
            // Desi/kg firmaya zorunlu (int); ölçü bilinmediği için 1 (Aras ile aynı varsayım).
            $pieces[] = ['barcode' => $key.'_'.$i, 'desi' => 1, 'kg' => 1, 'content' => 'Sipariş '.$request->orderNumber];
        }

        $reply = $this->call($account, 'POST', '/standardcmdapi/createOrder', [
            'order' => [
                'referenceId' => $key,
                'barcode' => $key,
                'isCOD' => 0,
                'codAmount' => 0,
                'shipmentServiceType' => self::SERVICE_STANDARD,
                'packagingType' => self::PACKAGING_PARCEL,
                'content' => 'Sipariş '.$request->orderNumber,
                'smsPreference1' => 0,
                'smsPreference2' => 0,
                'smsPreference3' => 0,
                'paymentType' => self::PAYMENT_SENDER,
                'deliveryType' => self::DELIVERY_TO_ADDRESS,
                'description' => 'Sipariş '.$request->orderNumber,
            ],
            'orderPieceList' => $pieces,
            'recipient' => array_filter([
                'fullName' => mb_substr($r->name, 0, 200),
                'address' => mb_substr($r->address, 0, 500),
                'cityCode' => $cityCode,
                'districtCode' => $districtCode,
                'mobilePhoneNumber' => $phone,
                'email' => $r->email,
            ], static fn ($v): bool => $v !== null && $v !== ''),
        ]);

        if (! $reply->successful()) {
            $data = self::body($reply);

            if (self::errorCode($data) !== self::DUPLICATE_CODE && preg_match('/zaten var/iu', self::message($data) ?? '') !== 1) {
                throw $this->failure($reply, 'MNG siparişi açmadı.');
            }

            // Önceki deneme açmış: gönderiye çevrildiyse numarası döner.
            $status = $this->status($account, $key);

            if ($status !== null && ($shipmentId = self::string($status['shipmentId'] ?? null)) !== null) {
                return new CreatedShipment(trackingNumber: $shipmentId);
            }
        }

        return $this->barcode($account, $key);
    }

    public function track(CarrierAccount $account, string $cargoKey): TrackingResult
    {
        $status = $this->status($account, strtoupper($cargoKey));

        if ($status === null) {
            throw new CarrierException(ErrorClass::NOT_FOUND, 'Gönderi MNG\'de bulunamadı.');
        }

        $code = self::string($status['shipmentStatusCode'] ?? null);
        $delivered = (int) ($status['isDelivered'] ?? 0) === 1;
        $url = self::string($status['trackingUrl'] ?? null);

        if ($url !== null && str_starts_with($url, 'www.')) {
            $url = 'https://'.$url;
        }

        return new TrackingResult(
            state: $delivered ? TrackingResult::DELIVERED : self::stateOf($code),
            carrierStatus: $code,
            carrierText: ($text = self::string($status['shipmentStatus'] ?? null)) === null ? null : str_replace('_', ' ', $text),
            trackingNumber: self::string($status['shipmentId'] ?? null),
            trackingUrl: $url !== null && str_starts_with($url, 'http') ? $url : null,
        );
    }

    /**
     * Barkod basılmışsa önce gönderi (`cancelshipment`), sonra sipariş
     * (`cancelorder`) iptal edilir. Spec iptal edilmiş kaydın cevabını
     * belgelemiyor → yalnız 2xx başarıdır; gerisi firmanın metniyle VALIDATION.
     */
    public function cancel(CarrierAccount $account, string $cargoKey): void
    {
        $key = strtoupper($cargoKey);
        $status = $this->status($account, $key);
        $shipmentId = $status === null ? null : self::string($status['shipmentId'] ?? null);

        if ($shipmentId !== null) {
            $reply = $this->call($account, 'PUT', '/barcodecmdapi/cancelshipment', ['referenceId' => $key, 'shipmentId' => $shipmentId]);

            if (! $reply->successful()) {
                throw $this->failure($reply, 'MNG gönderiyi iptal etmedi.');
            }
        }

        $reply = $this->call($account, 'PUT', '/standardcmdapi/cancelorder/'.rawurlencode($key));

        if (! $reply->successful()) {
            throw $this->failure($reply, 'MNG siparişi iptal etmedi.');
        }
    }

    /**
     * Spec durum kodları: 1 Gönderi_Hazırlandı · 2 Transfer_Aşamasında ·
     * 3 Teslimat_Birimine_Ulaştı · 4 Alıcı_Adresine_Yönlendirildi ·
     * 5 Teslim_Edildi · 6 Teslim_Edilemedi · 7 Geri_Geliyor · 8 Destek_Gerekiyor.
     * Spec'te İPTAL kodu YOK. 1 = barkod basıldı, şube henüz almadı → bekliyor.
     * Gerçek gönderide DOĞRULANMADI.
     */
    public static function stateOf(?string $code): string
    {
        return match ($code) {
            '1' => TrackingResult::WAITING,
            '2', '3', '4', '6', '7', '8' => TrackingResult::SHIPPED,
            '5' => TrackingResult::DELIVERED,
            default => TrackingResult::UNKNOWN,
        };
    }

    /** İl/ilçe adını karşılaştırılabilir biçime getirir ("İSTANBUL", "Istanbul 34" → "ISTANBUL"). */
    public static function normalise(string $name): string
    {
        $upper = mb_strtoupper(strtr($name, ['i' => 'İ', 'ı' => 'I']), 'UTF-8');
        $ascii = strtr($upper, ['İ' => 'I', 'Ş' => 'S', 'Ğ' => 'G', 'Ü' => 'U', 'Ö' => 'O', 'Ç' => 'C', 'Â' => 'A', 'Î' => 'I', 'Û' => 'U']);

        return preg_replace('/[^A-Z]/', '', $ascii) ?? '';
    }

    private function barcode(CarrierAccount $account, string $key): CreatedShipment
    {
        $reply = $this->call($account, 'POST', '/barcodecmdapi/createbarcode', ['referenceId' => $key]);

        if (! $reply->successful()) {
            $failure = $this->failure($reply, 'MNG barkodu üretmedi.');

            // 20001 "VARIŞ ŞUBESİ BULUNAMADI" — açık kaynak notu: geçici, yeniden denenir.
            if ($failure->carrierCode === self::BRANCH_PENDING_CODE) {
                throw new CarrierException(ErrorClass::SERVER_ERROR, $failure->getMessage(), $failure->carrierCode);
            }

            throw $failure;
        }

        $data = self::first(self::body($reply));
        $shipmentId = self::string($data['shipmentId'] ?? null);

        if ($shipmentId === null) {
            throw new CarrierException(ErrorClass::SERVER_ERROR, 'MNG barkod cevabında gönderi numarası yok.');
        }

        // Spec `barcodes[].value` biçimini yazmıyor; ZPL ise saklanır, değilse etiket yok.
        $labels = [];

        foreach ((array) ($data['barcodes'] ?? []) as $barcode) {
            $value = is_array($barcode) ? ($barcode['value'] ?? null) : null;

            if (is_string($value) && str_contains($value, '^XA')) {
                $labels[] = $value;
            }
        }

        return new CreatedShipment(trackingNumber: $shipmentId, labelZpl: $labels === [] ? null : implode("\n", $labels));
    }

    /**
     * Gönderi durumu; kayıt yoksa (404 — spec "Shipment not found", sipariş
     * henüz gönderiye çevrilmediyse de) null.
     *
     * @return array<mixed>|null
     */
    private function status(CarrierAccount $account, string $key): ?array
    {
        $reply = $this->call($account, 'GET', '/standardqueryapi/getshipmentstatus/'.rawurlencode($key));

        if ($reply->status() === 404) {
            return null;
        }

        if (! $reply->successful()) {
            throw $this->failure($reply, 'MNG durumu vermedi.', ErrorClass::SERVER_ERROR);
        }

        $data = self::first(self::body($reply));

        return $data === [] ? null : $data;
    }

    /**
     * İl/ilçe adı → CBS kodu. Liste 7 gün önbellekte (il/ilçe listesi
     * değişmez); bulunamayan ad VALIDATION — tahminle kod gönderilmez.
     *
     * @return array{0: int, 1: int}
     */
    private function codesFor(CarrierAccount $account, string $city, string $district): array
    {
        $cityCode = $this->match($this->lookup($account, '/cbsinfoapi/getcities'), $city);

        if ($cityCode === null) {
            throw new CarrierException(ErrorClass::VALIDATION, 'Alıcının ili MNG il listesinde bulunamadı; siparişteki il adını kontrol edin.');
        }

        $districtCode = $this->match($this->lookup($account, '/cbsinfoapi/getdistricts/'.$cityCode), $district);

        if ($districtCode === null) {
            throw new CarrierException(ErrorClass::VALIDATION, 'Alıcının ilçesi MNG ilçe listesinde bulunamadı; siparişteki ilçe adını kontrol edin.');
        }

        return [(int) $cityCode, (int) $districtCode];
    }

    /** @param list<array<mixed>> $rows */
    private function match(array $rows, string $name): ?string
    {
        $wanted = self::normalise($name);

        foreach ($rows as $row) {
            if ($wanted !== '' && self::normalise((string) ($row['name'] ?? '')) === $wanted && ($code = self::string($row['code'] ?? null)) !== null) {
                return $code;
            }
        }

        return null;
    }

    /** @return list<array<mixed>> */
    private function lookup(CarrierAccount $account, string $path): array
    {
        $cacheKey = 'shipping:mng:cbs:'.$account->environment.':'.$path;
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        // CBS Info Bearer istemez (spec), yalnız uygulama başlıkları.
        $reply = $this->http->send('GET', $this->baseUrl($account).$path, $this->appHeaders($account));

        if (! $reply->successful()) {
            throw $this->failure($reply, 'MNG il/ilçe listesi alınamadı.', ErrorClass::SERVER_ERROR);
        }

        $rows = array_values(array_filter(self::body($reply), 'is_array'));

        if ($rows !== []) {
            Cache::put($cacheKey, $rows, now()->addDays(7));
        }

        return $rows;
    }

    /**
     * Bearer'lı istek. 401 gelirse token bir kez tazelenir; yine reddedilirse AUTHENTICATION.
     *
     * @param  array<string, mixed>|null  $json
     */
    private function call(CarrierAccount $account, string $method, string $path, ?array $json = null): Response
    {
        $url = $this->baseUrl($account).$path;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $reply = $this->http->send($method, $url, [
                ...$this->appHeaders($account),
                'Authorization' => 'Bearer '.$this->token($account),
            ], $json);

            if ($reply->status() !== 401) {
                return $reply;
            }

            Cache::forget($this->tokenKey($account));
        }

        throw new CarrierException(ErrorClass::AUTHENTICATION, self::message(self::body($reply)) ?? 'MNG oturumu reddetti; müşteri numarası/şifreyi yeniden girin.');
    }

    private function token(CarrierAccount $account): string
    {
        $key = $this->tokenKey($account);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $reply = $this->http->send('POST', $this->baseUrl($account).'/token', $this->appHeaders($account), [
            'customerNumber' => $account->username(),
            'password' => $account->password(),
            'identityType' => self::IDENTITY_TYPE,
        ]);
        $data = self::first(self::body($reply));
        $jwt = self::string($data['jwt'] ?? null);

        if ($reply->successful() && $jwt !== null) {
            Cache::put($key, $jwt, now()->addMinutes((int) config('shipping.mng.token_ttl_minutes', 60)));

            return $jwt;
        }

        if (in_array($reply->status(), [400, 401, 403], true)) {
            throw new CarrierException(ErrorClass::AUTHENTICATION, self::message(self::body($reply)) ?? 'MNG müşteri numarası/şifre reddedildi.');
        }

        throw new CarrierException(ErrorClass::SERVER_ERROR, 'MNG token vermedi (HTTP '.$reply->status().').');
    }

    /** @return array<string, string> */
    private function appHeaders(CarrierAccount $account): array
    {
        $app = MngApp::credentials($account->environment);

        if ($app === null) {
            throw new CarrierException(ErrorClass::VALIDATION, 'MNG uygulama anahtarı (API Zone) tanımlı değil.');
        }

        return ['X-IBM-Client-Id' => $app['id'], 'X-IBM-Client-Secret' => $app['secret']];
    }

    private function baseUrl(CarrierAccount $account): string
    {
        return rtrim((string) config('shipping.mng.'.$account->environment, config('shipping.mng.live')), '/');
    }

    /** Önbellek anahtarı kimliğin özetidir — şifre anahtara düz yazılmaz. */
    private function tokenKey(CarrierAccount $account): string
    {
        return 'shipping:mng:token:'.hash('sha256', $account->environment.'|'.$account->username().'|'.$account->password());
    }

    private function failure(Response $reply, string $fallback, ErrorClass $client = ErrorClass::VALIDATION): CarrierException
    {
        $data = self::body($reply);
        $message = self::message($data) ?? $fallback;
        $code = self::errorCode($data);

        // 4xx = firmanın kuralı (kalıcı); 5xx / gövdesiz = geçici.
        $class = $reply->clientError() && $data !== [] ? $client : ErrorClass::SERVER_ERROR;

        return new CarrierException($class, mb_substr($message, 0, 500), $code);
    }

    /** @return array<mixed> */
    private static function body(Response $reply): array
    {
        return JsonTransport::decode($reply) ?? [];
    }

    /**
     * Tek nesne beklenen cevap bazen tek elemanlı dizi gelir (açık kaynak notu).
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function first(array $data): array
    {
        return array_is_list($data) && isset($data[0]) && is_array($data[0]) ? $data[0] : $data;
    }

    /**
     * İki hata biçimi: spec'teki ProblemDetails (`title`/`detail`) ve açık
     * kaynak istemcilerin gördüğü `{"error":{"code","message","description"}}`.
     *
     * @param  array<mixed>  $data
     */
    private static function message(array $data): ?string
    {
        $data = self::first($data);
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];

        foreach ([$error['message'] ?? null, $error['description'] ?? null, $data['detail'] ?? null, $data['title'] ?? null, $data['message'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /** @param array<mixed> $data */
    private static function errorCode(array $data): ?string
    {
        $data = self::first($data);
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];

        return self::string($error['code'] ?? null);
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
