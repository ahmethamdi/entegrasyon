<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Ticimax\TicimaxAdapter;
use App\Domain\Channels\Adapters\Ticimax\TicimaxSoapFault;
use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Enums\ErrorClass;
use App\Support\Logging\PayloadRedactor;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ticimax kargo bildirimi — `SaveKargoTakipNo` + `SetSiparisKargoyaVerildi`.
 *
 * Adapter önce numarayı ve sipariş durumunu okur, yalnız eksik adımı atar.
 * Zarf `TicimaxSoap` ile elle kurulur; mesaj parçaları WSDL sırasındadır.
 */
final class TicimaxFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private const SIPARIS_URL = 'https://www.magazam.com/Servis/SiparisServis.svc';

    /** Numara WSDL sırasıyla yazılır, firma kimliğiyle atanır, sipariş "Kargoya verildi" olur. */
    #[Test]
    public function the_tracking_number_is_saved_and_the_order_marked_shipped(): void
    {
        $this->fakeTicimax();

        $result = $this->push(carrier: 'YURTİÇİ KARGO', tracking: 'YK123');

        $this->assertTrue($result->successful);
        $this->assertTrue($result->data['tracking_written'] ?? false);

        Http::assertSent(function (Request $r): bool {
            $xml = $r->body();

            return $r->url() === self::SIPARIS_URL
                && $r->hasHeader('SOAPAction', '"http://tempuri.org/ISiparisServis/SaveKargoTakipNo"')
                // ⚠️ Mesaj parçaları WSDL SIRASINDA — alfabetik değil.
                && preg_match(
                    '#<SaveKargoTakipNo[^>]*><UyeKodu>YETKI-KODU-123</UyeKodu><siparisId>5501</siparisId>'
                    .'<kargoKodu></kargoKodu><kargoTakipNo>YK123</kargoTakipNo><kargoTakipLink></kargoTakipLink>'
                    .'<BarkodBilgisi></BarkodBilgisi><KargoTakipLinkGoster>false</KargoTakipLinkGoster></SaveKargoTakipNo>#',
                    $xml,
                ) === 1;
        });

        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), '<SetSiparisKargoFirmaId')
            && str_contains($r->body(), '<siparisId>5501</siparisId><kargoFirmaId>3</kargoFirmaId>'));
        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), '<SetSiparisKargoyaVerildi')
            && str_contains($r->body(), '<siparisId>5501</siparisId>'));
    }

    /**
     * ⚠️ NUMARA AYNI VE SİPARİŞ KARGODAYSA HİÇBİR ŞEY YAZILMAZ.
     *
     * Yanıtı kaybolan iş yeniden denendiğinde alıcı ikinci bildirimi
     * almamalı. Karşılaştırma boşluk ve harf farkını yok sayar.
     */
    #[Test]
    public function an_already_shipped_order_with_the_same_number_is_not_written(): void
    {
        $this->fakeTicimax(status: 6, currentTracking: 'YK123');

        $result = $this->push(tracking: ' yk 123 ');

        $this->assertTrue($result->successful);
        $this->assertTrue($result->data['already_shipped'] ?? false);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->body(), '<SaveKargoTakipNo')
            || str_contains($r->body(), '<SetSiparisKargoyaVerildi')
            || str_contains($r->body(), '<SetSiparisKargoFirmaId'));
    }

    /** Numara yazılmış ama durum kalmışsa (önceki deneme yarıda kesildi) yalnız durum yazılır. */
    #[Test]
    public function only_the_missing_status_step_is_sent(): void
    {
        $this->fakeTicimax(status: 2, currentTracking: 'YK123');

        $this->assertTrue($this->push()->successful);

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->body(), '<SaveKargoTakipNo'));
        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), '<SetSiparisKargoyaVerildi'));
    }

    /** `SaveKargoTakipNo` durumu kendisi 6 yaptıysa ikinci geçiş yapılmaz. */
    #[Test]
    public function the_status_is_not_set_twice_when_saving_already_shipped_it(): void
    {
        $this->fakeTicimax(statusAfterSave: 6);

        $this->assertTrue($this->push()->successful);

        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), '<SaveKargoTakipNo'));
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->body(), '<SetSiparisKargoyaVerildi'));
    }

    /** İptal edilmiş siparişe kargo yazılmaz. */
    #[Test]
    public function a_cancelled_order_is_not_shipped(): void
    {
        $this->fakeTicimax(status: 8);

        $result = $this->push();

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->body(), '<SaveKargoTakipNo'));
    }

    /** Siparişin kanal kimliği yoksa istek atılmaz. */
    #[Test]
    public function an_order_without_a_ticimax_id_sends_nothing(): void
    {
        Http::fake();

        $result = $this->push(orderId: null);

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        Http::assertNothingSent();
    }

    /**
     * ⚠️ YETKİ REDDİ KİMLİK HATASI DEĞİL, `VALIDATION`DIR.
     *
     * `AUTHENTICATION` sayılsaydı devre kesici SÜRESİZ açılır ve tek kargo
     * bildirimi bağlantının stok akışını durdururdu.
     */
    #[Test]
    public function an_authorization_fault_is_validation_not_authentication(): void
    {
        $this->fakeTicimax(save: Http::response(
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault>'
            .'<faultcode>s:Client</faultcode><faultstring>Bu işlem için yetkiniz yok.</faultstring>'
            .'</s:Fault></s:Body></s:Envelope>',
            500,
        ));

        $result = $this->push();

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        $this->assertStringContainsString('Ticimax panelinden', (string) $result->errorMessage);
    }

    /** Yetkiyle ilgisiz Fault dokunulmadan yükselir (sınıflandırma geçici sayar). */
    #[Test]
    public function an_unrelated_fault_is_left_to_classification(): void
    {
        $this->fakeTicimax(save: Http::response(
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault>'
            .'<faultcode>s:Server</faultcode><faultstring>Object reference not set</faultstring>'
            .'</s:Fault></s:Body></s:Envelope>',
            500,
        ));

        $this->expectException(TicimaxSoapFault::class);

        $this->push();
    }

    /**
     * ⚠️ MAĞAZANIN LİSTESİNDE OLMAYAN FİRMA ATANMAZ.
     *
     * `kargoKodu` boş gider (PDF: "Boş gönderilebilir"); tahmini bir kod ya
     * da kimlik yanlış firmayı yazardı. Numara yine gider.
     */
    #[Test]
    public function an_unknown_carrier_is_not_assigned(): void
    {
        $this->fakeTicimax();

        $this->assertTrue($this->push(carrier: 'Bizim Kurye')->successful);

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->body(), '<SetSiparisKargoFirmaId'));
        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), '<kargoKodu></kargoKodu><kargoTakipNo>YK123</kargoTakipNo>'));
    }

    /** Firma listesi okunamazsa numara yine gider — liste yardımcı bilgidir. */
    #[Test]
    public function an_unreadable_carrier_list_does_not_stop_the_push(): void
    {
        $this->fakeTicimax(carriers: Http::response(
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault>'
            .'<faultcode>s:Server</faultcode><faultstring>Servis kullanılamıyor</faultstring>'
            .'</s:Fault></s:Body></s:Envelope>',
            500,
        ));

        $this->assertTrue($this->push(carrier: 'Yurtiçi Kargo')->successful);

        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), '<SaveKargoTakipNo'));
    }

    /**
     * Numara okuması `IsError` dönerse (numarası olmayan sipariş) bildirim
     * DURMAZ: yeniden yazmak üzerine yazmadır, ikinci paket açmaz.
     */
    #[Test]
    public function an_is_error_on_the_tracking_check_does_not_stop_the_push(): void
    {
        $this->fakeTicimax(kontrol: Http::response($this->soap('SiparisKargoTakipNoKontrol',
            '<a:ErrorCode>1</a:ErrorCode><a:ErrorMessage>Kargo takip numarası bulunamadı</a:ErrorMessage><a:IsError>true</a:IsError>'
            .'<a:KargoTakipLink i:nil="true"/><a:KargoTakipNo i:nil="true"/>')));

        $result = $this->push();

        $this->assertTrue($result->successful);
        $this->assertSame('OK', $result->data['save_result'] ?? null);
        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), '<SaveKargoTakipNo'));
    }

    /** Numara okumasındaki YETKİ reddi yine `VALIDATION` olur, yazma denenmez. */
    #[Test]
    public function an_authorization_is_error_on_the_tracking_check_is_validation(): void
    {
        $this->fakeTicimax(kontrol: Http::response($this->soap('SiparisKargoTakipNoKontrol',
            '<a:ErrorCode>401</a:ErrorCode><a:ErrorMessage>Yetkisiz erişim</a:ErrorMessage><a:IsError>true</a:IsError>')));

        $result = $this->push();

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->body(), '<SaveKargoTakipNo'));
    }

    /**
     * ⚠️ BAŞKA SİPARİŞ DÖNERSE YAZILMAZ.
     *
     * Süzgeç sessizce yok sayılırsa ilk sipariş döner; onun durumuyla
     * karar verilip yazılsaydı satır yanlış yere "gönderildi" olurdu.
     */
    #[Test]
    public function a_missing_or_mismatched_order_is_not_found_and_nothing_is_written(): void
    {
        $this->fakeTicimax(listedOrderId: 42);

        $result = $this->push();

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::NOT_FOUND, $result->errorClass);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->body(), '<SaveKargoTakipNo')
            || str_contains($r->body(), '<SetSiparisKargoyaVerildi'));
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    private function fakeTicimax(
        int $status = 2,
        ?int $statusAfterSave = null,
        string $currentTracking = '',
        ?PromiseInterface $carriers = null,
        ?PromiseInterface $save = null,
        ?PromiseInterface $kontrol = null,
        int $listedOrderId = 5501,
    ): void {
        $saved = false;

        Http::fake(function (Request $r) use ($status, $statusAfterSave, $currentTracking, $carriers, $save, $kontrol, $listedOrderId, &$saved): PromiseInterface {
            $action = $r->header('SOAPAction')[0] ?? '';

            return match (true) {
                str_contains($action, '/SelectSiparis"') => Http::response($this->soap('SelectSiparis',
                    '<a:WebSiparis><a:Durum>'.($saved ? ($statusAfterSave ?? $status) : $status).'</a:Durum><a:ID>'.$listedOrderId.'</a:ID></a:WebSiparis>')),
                str_contains($action, '/SiparisKargoTakipNoKontrol"') => $kontrol ?? Http::response($this->soap('SiparisKargoTakipNoKontrol',
                    '<a:ErrorCode>0</a:ErrorCode><a:ErrorMessage i:nil="true"/><a:IsError>false</a:IsError>'
                    .'<a:KargoTakipLink i:nil="true"/><a:KargoTakipNo>'.$currentTracking.'</a:KargoTakipNo>')),
                str_contains($action, '/SelectKargoFirmalari"') => $carriers ?? Http::response($this->soap('SelectKargoFirmalari',
                    '<a:KargoFirma><a:ID>3</a:ID><a:Tanim>Yurtiçi Kargo</a:Tanim></a:KargoFirma>'
                    .'<a:KargoFirma><a:ID>5</a:ID><a:Tanim>Aras Kargo</a:Tanim></a:KargoFirma>')),
                str_contains($action, '/SetSiparisKargoFirmaId"') => Http::response($this->soap('SetSiparisKargoFirmaId', '1')),
                str_contains($action, '/SaveKargoTakipNo"') => (function () use ($save, &$saved): PromiseInterface {
                    $saved = true;

                    return $save ?? Http::response($this->soap('SaveKargoTakipNo', 'OK'));
                })(),
                str_contains($action, '/SetSiparisKargoyaVerildi"') => Http::response($this->soap('SetSiparisKargoyaVerildi', '')),
                default => Http::response('beklenmeyen işlem: '.$action, 500),
            };
        });
    }

    private function soap(string $operation, string $inner): string
    {
        return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            .'<'.$operation.'Response xmlns="http://tempuri.org/"><'.$operation.'Result xmlns:a="http://schemas.datacontract.org/2004/07/" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">'
            .$inner
            .'</'.$operation.'Result></'.$operation.'Response></s:Body></s:Envelope>';
    }

    private function push(?string $carrier = 'Yurtiçi Kargo', string $tracking = 'YK123', ?string $orderId = '5501'): AdapterResult
    {
        $order = new Order;
        $order->external_id = $orderId;

        $fulfillment = new Fulfillment;
        $fulfillment->carrier = $carrier;
        $fulfillment->tracking_number = $tracking;
        $fulfillment->setRelation('order', $order);

        return $this->adapter()->pushFulfillment($fulfillment);
    }

    private function adapter(): TicimaxAdapter
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'ticimax'],
            [
                'name' => 'Ticimax', 'kind' => 'storefront', 'adapter_class' => TicimaxAdapter::class,
                'capabilities' => [], 'rate_limit_profile' => [], 'supports_webhooks' => false, 'is_active' => false,
            ],
        ));

        $tenant = (new CreateTenant)->run(name: 'Ticimax Kargo '.uniqid(), owner: User::factory()->create());

        return $this->asTenant($tenant, function (): TicimaxAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ticimax',
                'external_account_id' => 'www.magazam.com',
                'settings' => [],
            ]);

            app(CredentialVault::class)->store($connection, ['uye_kodu' => 'YETKI-KODU-123']);

            return new TicimaxAdapter(
                $connection,
                new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)),
            );
        });
    }
}
