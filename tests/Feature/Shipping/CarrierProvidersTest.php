<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Domain\Shipping\Exceptions\CarrierException;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Providers\Aras\ArasCarrier;
use App\Domain\Shipping\Providers\Yurtici\YurticiCarrier;
use App\Domain\Shipping\Support\Carriers;
use App\Domain\Shipping\Support\ShipmentRecipient;
use App\Domain\Shipping\Support\TrackingResult;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S6 · Kargo II — Yurtiçi ve Aras sağlayıcıları, firmaların test
 * sunucusundan alınmış gerçek cevaplarla.
 *
 * Sınanan sözleşme: başarı yalnız firmanın açık "başarılı" cevabıdır;
 * kimlik / alan / geçici hata SINIFIYLA istisna olur; aynı anahtarla
 * tekrar (idempotens) başarıdır.
 */
final class CarrierProvidersTest extends TestCase
{
    use FakesCarriers;

    // ─────────────────────────────────────────────────── Yurtiçi

    #[Test]
    public function yurtici_creates_a_shipment_with_our_key_and_builds_the_label(): void
    {
        $this->replyWith('createShipment', 'yurtici-create-ok');

        $created = $this->yurtici()->create($this->carrierAccount('yurtici'), $this->shipmentRequest());

        $call = $this->carrierCalls[0];
        $this->assertSame(config('shipping.yurtici.test'), $call['url'], 'Test hesabı test sunucusuna gider.');
        $this->assertSame('""', $call['action']);
        $this->assertStringContainsString('<cargoKey>34PTESTANAHTAR01</cargoKey>', $call['body']);
        $this->assertStringContainsString('<invoiceKey>34PTESTANAHTAR01</invoiceKey>', $call['body']);
        $this->assertStringContainsString('<wsUserName>satici-ws</wsUserName><wsPassword>gizli-ws-sifre</wsPassword><userLanguage>TR</userLanguage>', $call['body']);
        $this->assertStringContainsString('<cityName>İstanbul</cityName><townName>Kadıköy</townName>', $call['body']);
        $this->assertStringContainsString('<receiverPhone1>05321112233</receiverPhone1>', $call['body'], '+90 atılır, başa 0.');

        $this->assertNull($created->trackingNumber, 'Yurtiçi takip numarasını açılışta vermez.');
        $this->assertNull($created->labelPdf);
        $this->assertStringContainsString('^FD34PTESTANAHTAR01^FS', (string) $created->labelZpl, 'Barkod bizim anahtarımızdır.');
        $this->assertStringNotContainsString('05321112233', (string) $created->labelZpl, 'Etikette telefonun yalnız son 4 hanesi.');
        $this->assertStringContainsString('*******2233', (string) $created->labelZpl);
    }

    #[Test]
    public function yurtici_treats_a_repeated_key_as_success(): void
    {
        $this->replyWith('createShipment', 'yurtici-create-duplicate');

        $created = $this->yurtici()->create($this->carrierAccount('yurtici'), $this->shipmentRequest());

        $this->assertNotNull($created->labelZpl, '60020 = ilk istekte açılmış; ikinci gönderi yok, sonuç başarı.');
    }

    #[Test]
    public function yurtici_field_rule_is_a_validation_error_with_the_carrier_text(): void
    {
        $this->replyWith('createShipment', 'yurtici-create-invalid');

        $e = $this->carrierError(fn () => $this->yurtici()->create($this->carrierAccount('yurtici'), $this->shipmentRequest()));

        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertSame('82502', $e->carrierCode);
        $this->assertStringContainsString('RECEIVER_ADDRESS', $e->getMessage());
    }

    #[Test]
    public function yurtici_short_address_is_refused_before_any_request(): void
    {
        $this->fakeCarriers();

        $e = $this->carrierError(fn () => $this->yurtici()->create($this->carrierAccount('yurtici'), $this->shipmentRequest(recipient: ['address' => 'Moda'])));

        $this->assertSame(ErrorClass::VALIDATION, $e->class);
        $this->assertSame([], $this->carrierCalls);
    }

    #[Test]
    public function yurtici_wrong_password_is_authentication_on_every_operation(): void
    {
        $this->replyWith('createShipment', 'yurtici-auth');
        $this->replyWith('queryShipment', 'yurtici-auth');

        $this->assertSame(ErrorClass::AUTHENTICATION, $this->carrierError(fn () => $this->yurtici()->create($this->carrierAccount('yurtici'), $this->shipmentRequest()))->class);
        $this->assertSame(ErrorClass::AUTHENTICATION, $this->carrierError(fn () => $this->yurtici()->track($this->carrierAccount('yurtici'), 'X'))->class);
        $this->assertSame(ErrorClass::AUTHENTICATION, $this->carrierError(fn () => $this->yurtici()->verify($this->carrierAccount('yurtici')))->class);
    }

    #[Test]
    public function yurtici_verify_accepts_the_unknown_key_answer(): void
    {
        $this->replyWith('queryShipment', 'yurtici-query-not-found');

        $this->yurtici()->verify($this->carrierAccount('yurtici'));

        $this->assertStringContainsString('<keys>'.YurticiCarrier::PROBE_KEY.'</keys>', $this->callsTo('queryShipment')[0]);
        $this->assertStringContainsString('<wsLanguage>TR</wsLanguage>', $this->callsTo('queryShipment')[0]);
    }

    #[Test]
    public function yurtici_tracking_maps_waiting_and_not_found(): void
    {
        $this->replyWith('queryShipment', 'yurtici-query-waiting', 'yurtici-query-not-found');

        $waiting = $this->yurtici()->track($this->carrierAccount('yurtici'), '34PTESTANAHTAR01');
        $this->assertSame(TrackingResult::WAITING, $waiting->state);
        $this->assertSame('NOP', $waiting->carrierStatus);
        $this->assertSame('Kargo İşlem Görmemiş.', $waiting->carrierText);
        $this->assertNull($waiting->trackingNumber);

        $this->assertSame(ErrorClass::NOT_FOUND, $this->carrierError(fn () => $this->yurtici()->track($this->carrierAccount('yurtici'), '34PTESTANAHTAR01'))->class);
    }

    #[Test]
    public function yurtici_tracking_reads_the_document_number_once_the_branch_processes_it(): void
    {
        // Belgedeki biçim (shippingDeliveryItemDetailVO) — gerçek gönderide DOĞRULANMADI.
        $this->replyWith('queryShipment', fn () => Http::response(str_replace(
            '<operationCode>0</operationCode><operationMessage>Kargo İşlem Görmemiş.</operationMessage><operationStatus>NOP</operationStatus>',
            '<operationCode>1</operationCode><operationMessage>Kargo teslim alındı.</operationMessage><operationStatus>IND</operationStatus>'
            .'<shippingDeliveryItemDetailVO><docId>112233445566</docId><trackingUrl>https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=112233445566</trackingUrl></shippingDeliveryItemDetailVO>',
            $this->fixture('yurtici-query-waiting', '34PTESTANAHTAR01'),
        )));

        $shipped = $this->yurtici()->track($this->carrierAccount('yurtici'), '34PTESTANAHTAR01');

        $this->assertSame(TrackingResult::SHIPPED, $shipped->state);
        $this->assertSame('112233445566', $shipped->trackingNumber);
        $this->assertStringStartsWith('https://www.yurticikargo.com/', (string) $shipped->trackingUrl);
    }

    #[Test]
    public function yurtici_unknown_status_changes_nothing(): void
    {
        $this->assertSame(TrackingResult::UNKNOWN, YurticiCarrier::stateOf('XYZ'));
        $this->assertSame(TrackingResult::UNKNOWN, YurticiCarrier::stateOf(null));
        $this->assertSame(TrackingResult::DELIVERED, YurticiCarrier::stateOf('DLV'));
        $this->assertSame(TrackingResult::CANCELLED, YurticiCarrier::stateOf('CNL'));
    }

    #[Test]
    public function yurtici_cancel_accepts_cancelled_and_already_gone(): void
    {
        $this->replyWith('cancelShipment', 'yurtici-cancel-ok', 'yurtici-cancel-already');

        $this->yurtici()->cancel($this->carrierAccount('yurtici'), '34PTESTANAHTAR01');
        // 82519: anahtar artık yok = önceki iptal tutmuş.
        $this->yurtici()->cancel($this->carrierAccount('yurtici'), '34PTESTANAHTAR01');

        $this->assertCount(2, $this->callsTo('cancelShipment'));
        $this->assertStringContainsString('<cargoKeys>34PTESTANAHTAR01</cargoKeys>', $this->callsTo('cancelShipment')[0]);
    }

    // ─────────────────────────────────────────────────── Aras

    #[Test]
    public function aras_creates_the_order_then_reads_tracking_number_and_labels(): void
    {
        $this->replyWith('SetOrder', 'aras-setorder-ok');
        $this->replyWith('GetArasBarcode', 'aras-barcode-not-found', 'aras-barcode-ok');

        $created = $this->aras()->create($this->carrierAccount('aras'), $this->shipmentRequest());

        $this->assertSame(['GetArasBarcode', 'SetOrder', 'GetArasBarcode'], array_column($this->carrierCalls, 'operation'), 'Önce sorulur, kayıt yoksa açılır.');
        $set = $this->carrierCalls[1];
        $this->assertSame(config('shipping.aras.test'), $set['url']);
        $this->assertSame('"http://tempuri.org/SetOrder"', $set['action']);
        foreach (['IntegrationCode', 'TradingWaybillNumber', 'InvoiceNumber', 'BarcodeNumber'] as $field) {
            $this->assertStringContainsString("<{$field}>34PTESTANAHTAR01</{$field}>", $set['body'], $field);
        }
        $this->assertStringContainsString('<ReceiverCityName>İstanbul</ReceiverCityName><ReceiverTownName>Kadıköy</ReceiverTownName>', $set['body']);
        $this->assertStringContainsString('<userName>satici-ws</userName><password>gizli-ws-sifre</password>', $set['body']);
        $this->assertStringContainsString('<Username>satici-ws</Username>', $this->callsTo('GetArasBarcode')[0], 'Barkod işleminde alan adı büyük harfle.');

        $this->assertSame('6843860752720', $created->trackingNumber);
        $this->assertStringStartsWith('^XA', (string) $created->labelZpl);
        $this->assertStringStartsWith('%PDF', (string) base64_decode((string) $created->labelPdf, true));
    }

    #[Test]
    public function aras_retry_returns_the_existing_record_without_set_order(): void
    {
        // SetOrder tekrarı Aras'ta YENİ takip numarası üretir (ölçüldü) →
        // kayıt varsa yeniden gönderilmez, aynı numara döner.
        $this->replyWith('SetOrder', 'aras-setorder-ok');
        $this->replyWith('GetArasBarcode', 'aras-barcode-ok');

        $created = $this->aras()->create($this->carrierAccount('aras'), $this->shipmentRequest());

        $this->assertSame('6843860752720', $created->trackingNumber);
        $this->assertSame([], $this->callsTo('SetOrder'));
    }

    #[Test]
    public function aras_authentication_codes_are_authentication_everywhere(): void
    {
        $this->replyWith('SetOrder', 'aras-setorder-auth');
        $this->replyWith('GetArasBarcode', 'aras-barcode-auth');
        $this->replyWith('CancelDispatch', 'aras-cancel-auth');

        $this->assertSame('999', $this->carrierError(fn () => $this->aras()->create($this->carrierAccount('aras'), $this->shipmentRequest()))->carrierCode, 'İlk soru kimliği yakalar.');
        $this->assertSame(ErrorClass::AUTHENTICATION, $this->carrierError(fn () => $this->aras()->verify($this->carrierAccount('aras')))->class);
        $this->assertSame(ErrorClass::AUTHENTICATION, $this->carrierError(fn () => $this->aras()->cancel($this->carrierAccount('aras'), 'X'))->class);
    }

    #[Test]
    public function aras_verify_accepts_the_unknown_code_answer(): void
    {
        $this->replyWith('GetArasBarcode', fn () => Http::response(str_replace(
            ['<ResultCode>999</ResultCode>', 'Kullanıcı adı yada şifreniz yanlıştır. Bu servisi kullanma izniniz yoktur. Müşteri Temsilcinizle görüşünüz.'],
            ['<ResultCode>1001</ResultCode>', 'Entegrasyon kodu bulunamadı.'],
            $this->fixture('aras-barcode-auth'),
        )));

        $this->aras()->verify($this->carrierAccount('aras'));

        $this->assertStringContainsString('<integrationCode>'.ArasCarrier::PROBE_KEY.'</integrationCode>', $this->callsTo('GetArasBarcode')[0]);
    }

    #[Test]
    public function aras_barcode_not_ready_right_after_set_order_is_temporary(): void
    {
        $this->replyWith('SetOrder', 'aras-setorder-ok');
        $this->replyWith('GetArasBarcode', 'aras-barcode-not-found');

        $e = $this->carrierError(fn () => $this->aras()->create($this->carrierAccount('aras'), $this->shipmentRequest()));

        $this->assertFalse($e->class->isPermanent(), 'İş yeniden dener; SetOrder tekrarı zararsız.');
    }

    #[Test]
    public function aras_cancel_of_a_missing_record_is_done_and_tracking_is_not_offered(): void
    {
        $this->replyWith('CancelDispatch', 'aras-cancel-not-found');

        $this->aras()->cancel($this->carrierAccount('aras'), '34PTESTANAHTAR01');

        $this->assertFalse($this->aras()->supportsTracking());
        $this->assertSame(TrackingResult::UNKNOWN, $this->aras()->track($this->carrierAccount('aras'), 'X')->state);
    }

    // ─────────────────────────────────────────────────── taşıyıcı

    #[Test]
    public function recipient_text_is_escaped_and_cannot_break_the_envelope(): void
    {
        $this->replyWith('createShipment', 'yurtici-create-ok');

        $this->yurtici()->create($this->carrierAccount('yurtici'), $this->shipmentRequest(recipient: ['name' => 'A&B <Ltd>']));

        $this->assertStringContainsString('<receiverCustName>A&amp;B &lt;Ltd&gt;</receiverCustName>', $this->carrierCalls[0]['body']);
    }

    #[Test]
    public function network_html_and_server_errors_are_temporary_classes(): void
    {
        $this->replyWith('createShipment',
            fn () => Http::failedConnection(),
            fn () => Http::response('<html>The resource cannot be found.</html>', 404),
            fn () => Http::response('<html>bakım</html>', 503),
        );

        foreach ([ErrorClass::NETWORK, ErrorClass::SERVER_ERROR, ErrorClass::SERVER_ERROR] as $expected) {
            $e = $this->carrierError(fn () => $this->yurtici()->create($this->carrierAccount('yurtici'), $this->shipmentRequest()));
            $this->assertSame($expected, $e->class);
            $this->assertFalse($e->class->isPermanent());
        }
    }

    #[Test]
    public function aras_set_order_rejection_is_permanent_with_the_carrier_code(): void
    {
        $this->replyWith('GetArasBarcode', 'aras-barcode-not-found');
        $this->replyWith('SetOrder', 'aras-setorder-auth');

        $e = $this->carrierError(fn () => $this->aras()->create($this->carrierAccount('aras'), $this->shipmentRequest()));

        $this->assertSame(ErrorClass::AUTHENTICATION, $e->class);
        $this->assertSame('1000', $e->carrierCode);
    }

    #[Test]
    public function a_soap_fault_about_credentials_is_authentication(): void
    {
        $this->replyWith('GetArasBarcode', fn () => Http::response('<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Server</faultcode><faultstring>Kullanıcı Adı yada Şifreniz yanlış</faultstring></soap:Fault></soap:Body></soap:Envelope>', 500));

        $this->assertSame(ErrorClass::AUTHENTICATION, $this->carrierError(fn () => $this->aras()->create($this->carrierAccount('aras'), $this->shipmentRequest()))->class);
    }

    #[Test]
    public function a_live_account_uses_the_live_endpoint(): void
    {
        $this->replyWith('queryShipment', 'yurtici-query-not-found');

        $this->yurtici()->verify($this->carrierAccount('yurtici', CarrierAccount::ENV_LIVE));

        $this->assertSame('https://ws.yurticikargo.com/KOPSWebServices/ShippingOrderDispatcherServices', $this->carrierCalls[0]['url']);
    }

    #[Test]
    public function recipient_never_leaks_through_debug_output_and_phones_normalise(): void
    {
        $r = new ShipmentRecipient('Ayşe Yılmaz', 'Moda Cad. No:12', 'İstanbul', 'Kadıköy', '0090 (532) 111 22 33');

        $this->assertStringNotContainsString('Ayşe', print_r($r, true));
        $this->assertSame('05321112233', (new ShipmentRecipient('a', 'b', 'c', 'd', '+905321112233'))->nationalPhone());
        $this->assertSame('05321112233', (new ShipmentRecipient('a', 'b', 'c', 'd', '5321112233'))->nationalPhone());
        $this->assertSame('', (new ShipmentRecipient('a', 'b', 'c', 'd', '123'))->nationalPhone());
    }

    #[Test]
    public function cargo_keys_have_the_accepted_shape_and_do_not_repeat(): void
    {
        $keys = array_map(static fn (): string => Carriers::newCargoKey(), range(1, 200));

        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^34P[A-Z2-9]{12}$/', $key);
        }

        $this->assertCount(200, array_unique($keys));
    }

    private function yurtici(): YurticiCarrier
    {
        return app(YurticiCarrier::class);
    }

    private function aras(): ArasCarrier
    {
        return app(ArasCarrier::class);
    }

    private function carrierError(\Closure $call): CarrierException
    {
        try {
            $call();
        } catch (CarrierException $e) {
            return $e;
        }

        $this->fail('Kargo firması isteği istisnasız döndü (sessiz başarı).');
    }
}
