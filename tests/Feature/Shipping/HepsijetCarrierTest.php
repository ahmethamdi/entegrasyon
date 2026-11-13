<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Domain\Shipping\Exceptions\CarrierException;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Providers\Hepsijet\HepsijetCarrier;
use App\Domain\Shipping\Support\Carriers;
use App\Domain\Shipping\Support\ShipmentRecipient;
use App\Domain\Shipping\Support\ShipmentRequest;
use App\Domain\Shipping\Support\TrackingResult;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HepsiJet sağlayıcısı — partner belgesindeki örnek yanıtlarla (Http::fake).
 *
 * ⚠️ MOCK_TESTED: cevaplar belgeden kopyalandı, gerçek sunucudan değil.
 * Sınanan sözleşme Yurtiçi/Aras ile aynı: başarı yalnız firmanın açık
 * "OK" cevabıdır; kimlik / alan / geçici hata SINIFIYLA istisnadır; aynı
 * barkodla tekrar (BARCODE_EXIST) ikinci gönderi açmadan başarıdır.
 */
final class HepsijetCarrierTest extends TestCase
{
    use FakesRestCarriers;

    private const ZPL = '^XA^CI28^FO32,133^A0N,45,45^FH\\^FDİstanbul^FS^XZ';

    #[Test]
    public function creates_the_delivery_with_our_barcode_and_returns_the_zpl_label(): void
    {
        $this->tokenOk();
        $this->restReply('sendDeliveryOrderEnhanced', fn ($r) => Http::response($this->createOk($r->data()['delivery']['customerDeliveryNo'])));

        $created = $this->carrier()->create($this->account(), $this->request('HJTABCDEFGHJKLMN'));

        [$token, $create] = $this->restCalls;
        $this->assertStringStartsWith(config('shipping.hepsijet.test'), $token['url'], 'Test hesabı test sunucusuna gider.');
        $this->assertSame('GET', $token['method']);
        $this->assertSame('Basic '.base64_encode('hj-kullanici:hj-sifre'), $token['headers']['Authorization'][0]);
        $this->assertArrayNotHasKey('apikey', $token['headers'], 'API anahtarı girilmediyse başlık yok.');

        $this->assertSame('/rest/delivery/sendDeliveryOrderEnhanced', $create['path']);
        $this->assertSame('tok-1', $create['headers']['X-Auth-Token'][0]);
        $d = $create['json']['delivery'];
        $this->assertSame(['abbreviationCode' => 'HJT'], $create['json']['company']);
        $this->assertSame('HJTABCDEFGHJKLMN', $d['customerDeliveryNo']);
        $this->assertSame('#1001', $d['customerOrderId']);
        $this->assertSame('RETAIL', $d['deliveryType']);
        $this->assertSame('1', $d['totalParcels'], 'Belge: totalParcels String.');
        $this->assertSame(['productCode' => 'HX_STD'], $d['product']);
        $this->assertSame('Ayşe', $d['receiver']['firstName']);
        $this->assertSame('Yılmaz', $d['receiver']['lastName']);
        $this->assertSame('5321112233', $d['receiver']['phone1'], 'Belge örneği: 10 hane, baştaki 0 yok.');
        $this->assertSame('5321112233', $d['recipientPersonPhone1']);
        $this->assertSame('İstanbul', $d['recipientAddress']['city']['name']);
        $this->assertSame('Kadıköy', $d['recipientAddress']['town']['name']);
        $this->assertSame('Türkiye', $d['recipientAddress']['country']['name']);
        $this->assertSame('Kocaeli', $d['senderAddress']['city']['name']);
        $this->assertSame('Gebze', $d['senderAddress']['town']['name']);
        $this->assertSame('A'.substr($d['receiver']['companyCustomerId'], 1), $d['recipientAddress']['companyAddressId']);
        $this->assertStringNotContainsString('Ayşe', $d['receiver']['companyCustomerId'], 'Alıcı kimliği adın özetidir, kendisi değil.');
        $this->assertArrayNotHasKey('currentXDock', $create['json'], 'Depo kodu girilmediyse gönderilmez.');

        $this->assertSame('HJTABCDEFGHJKLMN', $created->trackingNumber);
        $this->assertSame(self::ZPL, $created->labelZpl);
        $this->assertNull($created->labelPdf, 'HepsiJet belgesinde PDF yok.');
    }

    #[Test]
    public function the_token_is_reused_and_the_api_key_and_xdock_are_sent_when_given(): void
    {
        $this->tokenOk();
        $this->restReply('sendDeliveryOrderEnhanced', fn ($r) => Http::response($this->createOk($r->data()['delivery']['customerDeliveryNo'])));
        $account = $this->account(['api_key' => 'hj-api-anahtar', 'xdock_code' => 'KD_KMRBGZ1']);

        $this->carrier()->create($account, $this->request());
        $this->carrier()->create($account, $this->request('HJTZZZZZZZZZZZZZ'));

        $this->assertCount(1, $this->restCallsTo('/auth/getToken'), 'Token 60 dk geçerli: ikinci istek yeniden almaz.');
        $this->assertSame('hj-api-anahtar', $this->restCallsTo('/auth/getToken')[0]['headers']['apikey'][0]);
        $create = $this->restCallsTo('sendDeliveryOrderEnhanced')[1];
        $this->assertSame('hj-api-anahtar', $create['headers']['apikey'][0]);
        $this->assertSame(['abbreviationCode' => 'KD_KMRBGZ1'], $create['json']['currentXDock']);
    }

    #[Test]
    public function a_repeated_barcode_is_success_and_fetches_the_label_without_a_second_delivery(): void
    {
        $this->tokenOk();
        $this->restReply('sendDeliveryOrderEnhanced', Http::response(['status' => 'FAIL', 'detailStatus' => 'BARCODE_EXIST', 'message' => 'Gönderi numarası sistemde kayıtlı.'], 400));
        $this->restReply('generateZplBarcode', ['status' => 'OK', 'data' => [['customerDeliveryNo' => 'HJTABCDEFGHJKLMN', 'zplBarcode' => self::ZPL]]]);

        $created = $this->carrier()->create($this->account(), $this->request('HJTABCDEFGHJKLMN'));

        $this->assertSame('HJTABCDEFGHJKLMN', $created->trackingNumber);
        $this->assertSame(self::ZPL, $created->labelZpl);
        $label = $this->restCallsTo('generateZplBarcode')[0];
        $this->assertSame('GET', $label['method']);
        $this->assertSame('/delivery/generateZplBarcode/HJTABCDEFGHJKLMN/1', $label['path'], 'Belgedeki yol (/rest öneki yok).');
        $this->assertCount(1, $this->restCallsTo('sendDeliveryOrderEnhanced'));
    }

    #[Test]
    public function wrong_credentials_are_authentication_on_verify_and_create(): void
    {
        $this->restReply('/auth/getToken', Http::response(['status' => 'FAIL', 'message' => 'Kullanıcı Adı veya Şifre Hatalı'], 401));

        $e = $this->carrierError(fn () => $this->carrier()->verify($this->account()));
        $this->assertSame(ErrorClass::AUTHENTICATION, $e->class);
        $this->assertSame('Kullanıcı Adı veya Şifre Hatalı', $e->getMessage());

        $e = $this->carrierError(fn () => $this->carrier()->create($this->account(), $this->request()));
        $this->assertSame(ErrorClass::AUTHENTICATION, $e->class);
        $this->assertCount(0, $this->restCallsTo('sendDeliveryOrderEnhanced'));
    }

    #[Test]
    public function an_expired_token_is_renewed_once_then_a_second_refusal_is_authentication(): void
    {
        $this->restReply('/auth/getToken', $this->token('tok-eski'), $this->token('tok-yeni'));
        $this->restReply('sendDeliveryOrderEnhanced', Http::response('', 401), fn ($r) => Http::response($this->createOk($r->data()['delivery']['customerDeliveryNo'])));

        $this->carrier()->create($this->account(), $this->request());

        $creates = $this->restCallsTo('sendDeliveryOrderEnhanced');
        $this->assertSame('tok-eski', $creates[0]['headers']['X-Auth-Token'][0]);
        $this->assertSame('tok-yeni', $creates[1]['headers']['X-Auth-Token'][0]);

        $this->restReply('integration/track', Http::response('', 403));
        $e = $this->carrierError(fn () => $this->carrier()->track($this->account(), 'HJTX'));
        $this->assertSame(ErrorClass::AUTHENTICATION, $e->class);
    }

    #[Test]
    public function a_field_rule_is_a_validation_error_with_the_carrier_text(): void
    {
        $this->tokenOk();
        $this->restReply('sendDeliveryOrderEnhanced', Http::response(['status' => 'FAIL', 'message' => 'recipientAddress.town zorunludur'], 400));

        $e = $this->carrierError(fn () => $this->carrier()->create($this->account(), $this->request()));

        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertSame('recipientAddress.town zorunludur', $e->getMessage());
    }

    #[Test]
    public function a_missing_phone_or_sender_address_is_refused_before_any_request(): void
    {
        $this->tokenOk();

        $e = $this->carrierError(fn () => $this->carrier()->create($this->account(), $this->request(recipient: ['phone' => ''])));
        $this->assertSame(ErrorClass::VALIDATION, $e->class);

        $e = $this->carrierError(fn () => $this->carrier()->create($this->account(['sender_city' => '']), $this->request()));
        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertStringContainsString('gönderici adresi', $e->getMessage());

        $this->assertSame([], $this->restCalls, 'Eksik bilgiyle firmaya istek gitmez.');
    }

    #[Test]
    public function tracking_maps_the_documented_integration_statuses(): void
    {
        $this->tokenOk();
        $this->restReply(
            'integration/track',
            $this->tracked('HJTK', ['NEO']),
            $this->tracked('HJTK', ['NEO', 'ACCEPTED']),
            $this->tracked('HJTK', ['ACCEPTED', 'OUT_FOR_DELIVERY', 'DELIVERED']),
            $this->tracked('HJTK', ['NEO', 'DELETED']),
            $this->tracked('HJTK', ['YENI_BIR_KOD']),
            ['status' => 'OK', 'data' => []],
        );

        $waiting = $this->carrier()->track($this->account(), 'HJTK');
        $this->assertSame(TrackingResult::WAITING, $waiting->state);
        $this->assertSame(['barcodes' => ['HJTK']], $this->restCallsTo('integration/track')[0]['json']);

        $shipped = $this->carrier()->track($this->account(), 'HJTK');
        $this->assertSame(TrackingResult::SHIPPED, $shipped->state, 'ACCEPTED = "Gönderi teslim alındı".');
        $this->assertSame('ACCEPTED', $shipped->carrierStatus);
        $this->assertSame('HB DEPO Transfer Merkezi', $shipped->carrierText);
        $this->assertSame('HJTK', $shipped->trackingNumber);
        $this->assertSame('https://hepsiexpress.com/gonderi-takibi/HJTK', $shipped->trackingUrl);

        $this->assertSame(TrackingResult::DELIVERED, $this->carrier()->track($this->account(), 'HJTK')->state);
        $this->assertSame(TrackingResult::CANCELLED, $this->carrier()->track($this->account(), 'HJTK')->state);
        $this->assertSame(TrackingResult::UNKNOWN, $this->carrier()->track($this->account(), 'HJTK')->state, 'Tanınmayan kod hiçbir şeyi değiştirmez.');
        $this->assertSame(TrackingResult::UNKNOWN, $this->carrier()->track($this->account(), 'HJTK')->state, 'Hareket yok: tahmin yok.');
    }

    #[Test]
    public function tracking_an_unknown_barcode_is_not_found(): void
    {
        $this->tokenOk();
        $this->restReply('integration/track', Http::response(['status' => 'FAIL', 'message' => 'Gönderi numarası bulunamadı'], 400));

        $e = $this->carrierError(fn () => $this->carrier()->track($this->account(), 'HJTYOK'));

        $this->assertSame(ErrorClass::NOT_FOUND, $e->class);
    }

    #[Test]
    public function cancel_deletes_the_order_and_a_refusal_keeps_the_carrier_text(): void
    {
        $this->tokenOk();
        $this->restReply(
            'deleteDeliveryOrder',
            ['status' => 'OK', 'message' => 'Delivery has been deleted successfully'],
            Http::response(['status' => 'FAIL', 'message' => 'Gönderi ilk statüde değil'], 400),
        );

        $this->carrier()->cancel($this->account(), 'HJTIPTAL');

        $call = $this->restCallsTo('deleteDeliveryOrder')[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('/rest/delivery/deleteDeliveryOrder/HJTIPTAL', $call['path']);
        $this->assertSame(['deleteReason' => 'IPTAL'], $call['json']);

        $e = $this->carrierError(fn () => $this->carrier()->cancel($this->account(), 'HJTIPTAL'));
        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertSame('Gönderi ilk statüde değil', $e->getMessage());
    }

    #[Test]
    public function network_html_and_server_errors_are_temporary(): void
    {
        $this->tokenOk();
        $this->restReply(
            'sendDeliveryOrderEnhanced',
            fn () => Http::failedConnection(),
            Http::response('<html>502 Bad Gateway</html>', 502),
            Http::response('', 429),
        );

        $this->assertSame(ErrorClass::NETWORK, $this->carrierError(fn () => $this->carrier()->create($this->account(), $this->request()))->class);
        $this->assertSame(ErrorClass::SERVER_ERROR, $this->carrierError(fn () => $this->carrier()->create($this->account(), $this->request()))->class);
        $this->assertSame(ErrorClass::RATE_LIMITED, $this->carrierError(fn () => $this->carrier()->create($this->account(), $this->request()))->class);
    }

    #[Test]
    public function a_live_account_uses_the_live_endpoint(): void
    {
        $this->tokenOk();

        $this->carrier()->verify($this->account(environment: CarrierAccount::ENV_LIVE));

        $this->assertStringStartsWith('https://integration.hepsijet.com/auth/getToken', $this->restCalls[0]['url']);
    }

    #[Test]
    public function barcodes_start_with_the_company_code_and_fit_the_documented_length(): void
    {
        $key = Carriers::newCargoKeyFor($this->account(['company_code' => 'ETF']));
        $this->assertMatchesRegularExpression('/^ETF[A-Z2-9]{13}$/', $key, 'Belge: firma kısaltmasıyla başlar, 9–16 karakter.');
        $this->assertNotSame($key, Carriers::newCargoKeyFor($this->account(['company_code' => 'ETF'])));
        $this->assertMatchesRegularExpression('/^34P[A-Z2-9]{12}$/', Carriers::newCargoKeyFor(new CarrierAccount(['provider' => 'yurtici'])));

        $this->assertSame(['Ayşe Nur', 'Yılmaz'], HepsijetCarrier::splitName(' Ayşe  Nur Yılmaz '));
        $this->assertSame(['Cem', 'Cem'], HepsijetCarrier::splitName('Cem'));
        $this->assertTrue($this->carrier()->supportsTracking());
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function carrier(): HepsijetCarrier
    {
        return app(HepsijetCarrier::class);
    }

    private function account(array $extra = [], string $environment = CarrierAccount::ENV_TEST): CarrierAccount
    {
        return new CarrierAccount([
            'provider' => CarrierAccount::PROVIDER_HEPSIJET,
            'environment' => $environment,
            'credentials' => [
                'username' => 'hj-kullanici',
                'password' => 'hj-sifre',
                'company_code' => 'HJT',
                'sender_city' => 'Kocaeli',
                'sender_district' => 'Gebze',
                'sender_address' => 'Organize Sanayi Bölgesi 5. Cadde No:7',
                ...$extra,
            ],
            'status' => CarrierAccount::STATUS_CONNECTED,
        ]);
    }

    private function request(string $key = 'HJTTESTANAHTAR01', array $recipient = []): ShipmentRequest
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
        $this->restReply('/auth/getToken', $this->token('tok-1'));
    }

    /** Belgedeki getToken örneği. */
    private function token(string $token): array
    {
        return ['status' => 'OK', 'data' => [
            'id' => 8, 'username' => 'hj-kullanici', 'language' => 'tr', 'token' => $token,
            'tenant' => ['id' => 1, 'rowVersion' => 0, 'name' => 'HepsiExpress', 'abbreviationCode' => 'HX'],
        ]];
    }

    /** Belgedeki sendDeliveryOrderEnhanced örneği (totalParcel=1). */
    private function createOk(string $number): array
    {
        return ['status' => 'OK', 'data' => [
            'customerDeliveryNo' => $number,
            'zplBarcodeDTOList' => [[
                'sender' => 'Morhipo', 'receiverCity' => 'İstanbul', 'receiverTown' => 'BAYRAMPAŞA',
                'productName' => 'Standart Teslimat', 'zplBarcode' => self::ZPL,
            ]],
        ]];
    }

    /** Belgedeki integration/track örneği; durumlar sırayla. */
    private function tracked(string $barcode, array $statuses): array
    {
        $details = array_map(static fn (string $s): array => [
            'transactionLocation' => $s === 'NEO' || $s === 'ACCEPTED' ? 'HB DEPO Transfer Merkezi' : 'Dağıtım Aracında',
            'transactionDate' => '2018-11-09T05:58:18+03:00',
            'integrationStatus' => $s,
        ], $statuses);

        return ['status' => 'OK', 'data' => [[
            'barcode' => $barcode,
            'trackingUrl' => 'https://hepsiexpress.com/gonderi-takibi/'.$barcode,
            'details' => $details,
        ]]];
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
