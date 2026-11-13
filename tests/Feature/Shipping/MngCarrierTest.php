<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Domain\Shipping\Exceptions\CarrierException;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Providers\Mng\MngApp;
use App\Domain\Shipping\Providers\Mng\MngCarrier;
use App\Domain\Shipping\Support\ShipmentRecipient;
use App\Domain\Shipping\Support\ShipmentRequest;
use App\Domain\Shipping\Support\TrackingResult;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MNG Kargo sağlayıcısı — API Zone Swagger örnek yanıtlarıyla (Http::fake).
 *
 * ⚠️ MOCK_TESTED: cevaplar resmî Swagger tanımındaki örneklerden; gerçek
 * sunucuya istek atılmadı. Kod 3002 "kayıt zaten var" yalnız açık kaynak
 * istemcilerde görüldü — resmî değil (testte o yüzden ayrıca işaretli).
 */
final class MngCarrierTest extends TestCase
{
    use FakesRestCarriers;

    private const ZPL = '^XA^FO50,50^BCN,100,Y,N,N^FD4536457657^FS^XZ';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'shipping.mng.test_client_id' => 'platform-test-id',
            'shipping.mng.test_client_secret' => 'platform-test-secret',
            'shipping.mng.client_id' => 'platform-canli-id',
            'shipping.mng.client_secret' => 'platform-canli-secret',
        ]);
    }

    #[Test]
    public function creates_the_order_with_cbs_codes_then_the_barcode(): void
    {
        $this->standardReplies();

        $created = $this->carrier()->create($this->account(), $this->request('34PTESTANAHTAR01'));

        $token = $this->restCallsTo('/mngapi/api/token')[0];
        $this->assertStringStartsWith(config('shipping.mng.test'), $token['url'], 'Test hesabı sandbox adresine gider.');
        $this->assertSame(['customerNumber' => '312947702', 'password' => 'mng-sifre', 'identityType' => 1], $token['json']);
        $this->assertSame('platform-test-id', $token['headers']['X-IBM-Client-Id'][0]);
        $this->assertSame('platform-test-secret', $token['headers']['X-IBM-Client-Secret'][0]);

        $cities = $this->restCallsTo('cbsinfoapi/getcities')[0];
        $this->assertArrayNotHasKey('Authorization', $cities['headers'], 'CBS Info Bearer istemez (spec).');
        $this->assertSame('/mngapi/api/cbsinfoapi/getdistricts/34', $this->restCallsTo('getdistricts')[0]['path']);

        $order = $this->restCallsTo('createOrder')[0];
        $this->assertSame('Bearer eyJ.test.jwt', $order['headers']['Authorization'][0]);
        $this->assertSame('platform-test-id', $order['headers']['X-IBM-Client-Id'][0]);
        $o = $order['json']['order'];
        $this->assertSame('34PTESTANAHTAR01', $o['referenceId']);
        $this->assertSame('34PTESTANAHTAR01', $o['barcode'], 'Spec: barcode = referenceId, büyük harf.');
        $this->assertSame([1, 3, 1, 1], [$o['shipmentServiceType'], $o['packagingType'], $o['paymentType'], $o['deliveryType']]);
        $this->assertSame([0, 0, 0], [$o['smsPreference1'], $o['smsPreference2'], $o['smsPreference3']]);
        $this->assertSame('Sipariş #1001', $o['description']);
        $this->assertSame([['barcode' => '34PTESTANAHTAR01_1', 'desi' => 1, 'kg' => 1, 'content' => 'Sipariş #1001']], $order['json']['orderPieceList']);
        $rc = $order['json']['recipient'];
        $this->assertSame(34, $rc['cityCode']);
        $this->assertSame(445, $rc['districtCode']);
        $this->assertSame('Ayşe Yılmaz', $rc['fullName']);
        $this->assertSame('5321112233', $rc['mobilePhoneNumber'], 'Spec örneği: 10 hane.');
        $this->assertArrayNotHasKey('cityName', $rc, 'Ad alanları şemada yok — gönderilmez.');

        $this->assertSame(['referenceId' => '34PTESTANAHTAR01'], $this->restCallsTo('createbarcode')[0]['json']);
        $this->assertSame('4536457657', $created->trackingNumber, 'shipmentId = takip numarası.');
        $this->assertSame(self::ZPL, $created->labelZpl);
        $this->assertNull($created->labelPdf);
    }

    #[Test]
    public function the_token_and_city_lists_are_reused(): void
    {
        $this->standardReplies();

        $this->carrier()->create($this->account(), $this->request('34PBIRINCI000001'));
        $this->carrier()->create($this->account(), $this->request('34PIKINCI0000002'));

        $this->assertCount(1, $this->restCallsTo('/mngapi/api/token'));
        $this->assertCount(1, $this->restCallsTo('getcities'));
        $this->assertCount(1, $this->restCallsTo('getdistricts'));
        $this->assertCount(2, $this->restCallsTo('createOrder'));
    }

    #[Test]
    public function an_unknown_city_or_district_never_reaches_create_order(): void
    {
        $this->standardReplies();

        $e = $this->carrierError(fn () => $this->carrier()->create($this->account(), $this->request(recipient: ['district' => 'Atlantis'])));
        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertStringContainsString('ilçesi', $e->getMessage());
        $this->assertStringNotContainsString('Atlantis', $e->getMessage(), 'Adres parçası hata metnine (diske) yazılmaz.');

        $e = $this->carrierError(fn () => $this->carrier()->create($this->account(), $this->request(recipient: ['city' => 'Gotham'])));
        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertSame([], $this->restCallsTo('createOrder'));
    }

    #[Test]
    public function city_names_match_regardless_of_case_accents_and_suffixes(): void
    {
        $this->assertSame(MngCarrier::normalise('İstanbul'), MngCarrier::normalise('ISTANBUL'));
        $this->assertSame(MngCarrier::normalise('istanbul'), MngCarrier::normalise('İSTANBUL 34'));
        $this->assertSame(MngCarrier::normalise('Kadıköy'), MngCarrier::normalise('KADIKÖY'));
        $this->assertSame(MngCarrier::normalise('Çukurova'), MngCarrier::normalise('CUKUROVA'));
        $this->assertNotSame(MngCarrier::normalise('Kadıköy'), MngCarrier::normalise('Kartal'));
    }

    #[Test]
    public function a_repeated_reference_is_read_back_without_a_second_shipment(): void
    {
        // ⚠️ 3002 resmî spec'te yok; açık kaynak istemcilerin gördüğü cevap.
        $this->standardReplies();
        $this->restReply('createOrder', Http::response(['error' => ['code' => '3002', 'message' => 'BU SİPARİS NUMARASINA AİT KAYIT ZATEN VAR!']], 500));
        $this->restReply('getshipmentstatus', ['shipmentStatusCode' => 1, 'referenceId' => '34PTEKRAR0000001', 'shipmentId' => '987654321', 'shipmentStatus' => 'Gönderi_Hazırlandı']);

        $created = $this->carrier()->create($this->account(), $this->request('34PTEKRAR0000001'));

        $this->assertSame('987654321', $created->trackingNumber, 'Gönderiye çevrilmiş kayıt: numarası döner.');
        $this->assertSame([], $this->restCallsTo('createbarcode'), 'İkinci barkod/gönderi YOK.');

        // Sipariş var ama henüz gönderiye çevrilmemiş → yalnız barkod.
        $this->restReply('getshipmentstatus', Http::response(['title' => 'Shipment not found', 'status' => 404], 404));
        $created = $this->carrier()->create($this->account(), $this->request('34PTEKRAR0000001'));
        $this->assertSame('4536457657', $created->trackingNumber);
        $this->assertCount(1, $this->restCallsTo('createbarcode'));
    }

    #[Test]
    public function wrong_credentials_are_authentication(): void
    {
        $this->restReply('/mngapi/api/token', Http::response(['type' => 'about:blank', 'title' => 'Müşteri numarası veya şifre hatalı', 'status' => 400], 400));

        $e = $this->carrierError(fn () => $this->carrier()->verify($this->account()));
        $this->assertSame(ErrorClass::AUTHENTICATION, $e->class);
        $this->assertSame('Müşteri numarası veya şifre hatalı', $e->getMessage());
    }

    #[Test]
    public function an_expired_jwt_is_renewed_once_then_a_second_refusal_is_authentication(): void
    {
        $this->restReply('/mngapi/api/token', $this->token('eyJ.eski'), $this->token('eyJ.yeni'));
        $this->restReply('getshipmentstatus', Http::response('', 401), $this->shipmentStatus(3));

        $result = $this->carrier()->track($this->account(), '34PTAKIP00000001');

        $this->assertSame(TrackingResult::SHIPPED, $result->state);
        $calls = $this->restCallsTo('getshipmentstatus');
        $this->assertSame('Bearer eyJ.eski', $calls[0]['headers']['Authorization'][0]);
        $this->assertSame('Bearer eyJ.yeni', $calls[1]['headers']['Authorization'][0]);

        $this->restReply('getshipmentstatus', Http::response('', 401));
        $this->assertSame(ErrorClass::AUTHENTICATION, $this->carrierError(fn () => $this->carrier()->track($this->account(), '34PTAKIP00000001'))->class);
    }

    #[Test]
    public function a_bad_request_is_validation_with_the_problem_details_text(): void
    {
        $this->standardReplies();
        $this->restReply('createOrder', Http::response(['type' => 'about:blank', 'title' => 'One or more validation errors occurred.', 'status' => 400, 'detail' => 'recipient.address zorunludur'], 400));

        $e = $this->carrierError(fn () => $this->carrier()->create($this->account(), $this->request()));

        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertSame('recipient.address zorunludur', $e->getMessage());
        $this->assertSame([], $this->restCallsTo('createbarcode'));
    }

    #[Test]
    public function tracking_maps_the_documented_status_codes(): void
    {
        $this->tokenOk();
        $this->restReply('getshipmentstatus', $this->shipmentStatus(1), $this->shipmentStatus(2), [$this->shipmentStatus(6)], $this->shipmentStatus(5, delivered: true), $this->shipmentStatus(99), Http::response(['title' => 'Shipment not found', 'status' => 404], 404));

        $waiting = $this->carrier()->track($this->account(), '34pkucukharf0001');
        $this->assertSame(TrackingResult::WAITING, $waiting->state);
        $this->assertSame('/mngapi/api/standardqueryapi/getshipmentstatus/34PKUCUKHARF0001', $this->restCalls[1]['path'], 'Referans büyük harfle.');

        $shipped = $this->carrier()->track($this->account(), 'X');
        $this->assertSame(TrackingResult::SHIPPED, $shipped->state);
        $this->assertSame('2', $shipped->carrierStatus);
        $this->assertSame('14556546', $shipped->trackingNumber);
        $this->assertSame('https://www.mngkargo.com.tr/track/14556546', $shipped->trackingUrl, 'Spec örneğinde şema yok → https eklenir.');

        $this->assertSame(TrackingResult::SHIPPED, $this->carrier()->track($this->account(), 'X')->state, 'Tek elemanlı dizi cevabı da okunur; 6 Teslim_Edilemedi = firmada.');
        $delivered = $this->carrier()->track($this->account(), 'X');
        $this->assertSame(TrackingResult::DELIVERED, $delivered->state);
        $this->assertSame('Teslim Edildi', $delivered->carrierText);
        $this->assertSame(TrackingResult::UNKNOWN, $this->carrier()->track($this->account(), 'X')->state, 'Tanınmayan kod hiçbir şeyi değiştirmez.');
        $this->assertSame(ErrorClass::NOT_FOUND, $this->carrierError(fn () => $this->carrier()->track($this->account(), 'X'))->class);
    }

    #[Test]
    public function cancel_cancels_the_shipment_then_the_order_and_a_refusal_keeps_the_text(): void
    {
        $this->tokenOk();
        $this->restReply('getshipmentstatus', $this->shipmentStatus(1), Http::response(['title' => 'Shipment not found', 'status' => 404], 404));
        $this->restReply('cancelshipment', Http::response('"Shipment canceled"', 200));
        $this->restReply('cancelorder', Http::response('"Order canceled"', 200), Http::response(['detail' => 'Şube kabulü yapılmış sipariş iptal edilemez'], 400));

        $this->carrier()->cancel($this->account(), '34PIPTAL00000001');

        $shipment = $this->restCallsTo('cancelshipment')[0];
        $this->assertSame('PUT', $shipment['method']);
        $this->assertSame(['referenceId' => '34PIPTAL00000001', 'shipmentId' => '14556546'], $shipment['json']);
        $order = $this->restCallsTo('cancelorder')[0];
        $this->assertSame('PUT', $order['method']);
        $this->assertSame('/mngapi/api/standardcmdapi/cancelorder/34PIPTAL00000001', $order['path']);

        $e = $this->carrierError(fn () => $this->carrier()->cancel($this->account(), '34PIPTAL00000001'));
        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertSame('Şube kabulü yapılmış sipariş iptal edilemez', $e->getMessage());
        $this->assertCount(1, $this->restCallsTo('cancelshipment'), 'Gönderiye çevrilmemiş kayıtta yalnız sipariş iptali.');
    }

    #[Test]
    public function server_errors_are_temporary_and_missing_platform_keys_never_call_out(): void
    {
        $this->tokenOk();
        $this->restReply('getshipmentstatus', Http::response('<html>Bakım</html>', 503), fn () => Http::failedConnection());

        $this->assertSame(ErrorClass::SERVER_ERROR, $this->carrierError(fn () => $this->carrier()->track($this->account(), 'X'))->class);
        $this->assertSame(ErrorClass::NETWORK, $this->carrierError(fn () => $this->carrier()->track($this->account(), 'X'))->class);

        config(['shipping.mng.test_client_id' => '', 'shipping.mng.client_id' => '']);
        $before = count($this->restCalls);
        $this->assertFalse(MngApp::configured());
        $this->assertSame(ErrorClass::VALIDATION, $this->carrierError(fn () => $this->carrier()->verify($this->account()))->class);
        $this->assertCount($before, $this->restCalls, 'Uygulama anahtarı yokken MNG\'ye istek gitmez.');
    }

    #[Test]
    public function a_live_account_uses_the_live_host_and_live_platform_keys(): void
    {
        $this->tokenOk();

        $this->carrier()->verify($this->account(CarrierAccount::ENV_LIVE));

        $call = $this->restCalls[0];
        $this->assertSame('https://api.mngkargo.com.tr/mngapi/api/token', $call['url']);
        $this->assertSame('platform-canli-id', $call['headers']['X-IBM-Client-Id'][0]);
        $this->assertTrue($this->carrier()->supportsTracking());
    }

    #[Test]
    public function a_pending_destination_branch_is_retried_not_rejected(): void
    {
        // ⚠️ 20001 resmî spec'te yok; açık kaynak istemcilerin gördüğü cevap.
        $this->standardReplies();
        $this->restReply('createbarcode', Http::response(['error' => ['code' => '20001', 'message' => 'VARIŞ ŞUBESİ BULUNAMADI']], 400));

        $e = $this->carrierError(fn () => $this->carrier()->create($this->account(), $this->request()));

        $this->assertSame(ErrorClass::SERVER_ERROR, $e->class);
        $this->assertFalse($e->class->isPermanent());
        $this->assertSame('20001', $e->carrierCode);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function carrier(): MngCarrier
    {
        return app(MngCarrier::class);
    }

    private function account(string $environment = CarrierAccount::ENV_TEST): CarrierAccount
    {
        return new CarrierAccount([
            'provider' => 'mng',
            'environment' => $environment,
            'credentials' => ['username' => '312947702', 'password' => 'mng-sifre'],
            'status' => CarrierAccount::STATUS_CONNECTED,
        ]);
    }

    private function request(string $key = '34PTESTANAHTAR01', array $recipient = []): ShipmentRequest
    {
        return new ShipmentRequest(
            cargoKey: $key,
            recipient: new ShipmentRecipient(...[
                'name' => 'Ayşe Yılmaz',
                'address' => 'Caferağa Mah. Moda Cad. No:12 D:3',
                'city' => 'İstanbul',
                'district' => 'Kadıköy',
                'phone' => '+90 532 111 22 33',
                'email' => 'ayse@example.com',
                ...$recipient,
            ]),
            orderNumber: '#1001',
        );
    }

    private function tokenOk(): void
    {
        $this->restReply('/mngapi/api/token', $this->token('eyJ.test.jwt'));
    }

    /** Spec'teki GenerateTokenResponse örneği. */
    private function token(string $jwt): array
    {
        return ['jwt' => $jwt, 'refreshToken' => 'f41c92bf-cc20-491b-9e4c-efc1b253e43a', 'jwtExpireDate' => '10.03.2020 16:05:00', 'refreshTokenExpireDate' => '09.04.2020 15:04:31'];
    }

    private function standardReplies(): void
    {
        $this->tokenOk();
        // Spec örneği biçiminde (code "01", name "Adana"); İstanbul/Kadıköy kodları örnek değer.
        $this->restReply('getcities', [['code' => '01', 'name' => 'Adana'], ['code' => '34', 'name' => 'İSTANBUL']]);
        $this->restReply('getdistricts', [['cityCode' => '34', 'cityName' => 'İSTANBUL', 'code' => '440', 'name' => 'KARTAL'], ['cityCode' => '34', 'cityName' => 'İSTANBUL', 'code' => '445', 'name' => 'KADIKÖY']]);
        $this->restReply('createOrder', fn (HttpRequest $r) => Http::response(['orderInvoiceId' => '456764543', 'orderInvoiceDetailId' => '25423565', 'shipperBranchCode' => '1345', 'referenceId' => $r->data()['order']['referenceId']]));
        $this->restReply('createbarcode', fn (HttpRequest $r) => Http::response(['referenceId' => $r->data()['referenceId'], 'invoiceId' => '564645774', 'shipmentId' => '4536457657', 'barcodes' => [['pieceNumber' => 1, 'value' => self::ZPL]]]));
    }

    /** Spec'teki GetShipmentStatusResponse örneği. */
    private function shipmentStatus(int $code, bool $delivered = false): array
    {
        $names = [1 => 'Gönderi_Hazırlandı', 2 => 'Transfer_Aşamasında', 3 => 'Teslimat_Birimine_Ulaştı', 5 => 'Teslim_Edildi', 6 => 'Teslim_Edilemedi'];

        return [
            'shipmentStatusCode' => $code, 'orderId' => '5464767', 'referenceId' => 'SIPARIS34567', 'shipmentId' => '14556546',
            'shipmentSerialandNumber' => 'F56576', 'shipmentDateTime' => '12-02-2019 10:30', 'shipmentStatus' => $names[$code] ?? 'Bilinmeyen',
            'statusDateTime' => '13-02-2019 12:03', 'trackingUrl' => 'www.mngkargo.com.tr/track/14556546', 'isDelivered' => $delivered ? 1 : 0,
        ];
    }

    private function carrierError(\Closure $call): CarrierException
    {
        try {
            $call();
        } catch (CarrierException $e) {
            return $e;
        }

        $this->fail('CarrierException bekleniyordu.');
    }
}
