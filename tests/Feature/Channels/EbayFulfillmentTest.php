<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Ebay\EbayAdapter;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * eBay kargo bildirimi — `createShippingFulfillment`.
 *
 * Her başarılı çağrı yeni paket açtığı için tekrar zararsız DEĞİLDİR;
 * adapter önce siparişin kargo kayıtlarını okur. Kalem kimlikleri
 * kanaldaki siparişten alınır.
 */
final class EbayFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_URL = 'https://api.ebay.com/sell/fulfillment/v1/order/12-34567-89012';

    private const FULFILLMENT_URL = self::ORDER_URL.'/shipping_fulfillment';

    /** Açık kalemler, eşlenen firma kodu ve sadeleşmiş numara doğru uca gider; kimlik `Location`'dan okunur. */
    #[Test]
    public function the_tracking_number_is_posted_with_the_open_line_items(): void
    {
        Http::fake([
            self::FULFILLMENT_URL => Http::sequence()
                ->push(['fulfillments' => [], 'total' => 0], 200)
                ->push('', 201, ['Location' => self::FULFILLMENT_URL.'/YK1234']),
            self::ORDER_URL => Http::response($this->order(), 200),
        ]);

        $result = $this->push(carrier: 'MNG Kargo', tracking: 'yk 12-34');

        $this->assertTrue($result->successful);
        $this->assertSame('YK1234', $result->data['external_id'] ?? null);

        Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'POST'
            && $r->url() === self::FULFILLMENT_URL
            && $r['shippingCarrierCode'] === 'MNGTurkey'
            && $r['trackingNumber'] === 'YK1234'
            && $r['shippedDate'] === '2026-10-09T08:15:00.000Z'
            // Kargolanmış kalem gönderilmez.
            && $r['lineItems'] === [['lineItemId' => '10001', 'quantity' => 2]]
            && $r->hasHeader('Authorization', 'Bearer ebay-access'));
    }

    /**
     * ⚠️ NUMARA SİPARİŞTE ZATEN VARSA İSTEK ATILMAZ.
     *
     * Yanıtı kaybolan istek yeniden denendiğinde eBay aynı numarayla ikinci
     * paketi açardı. Karşılaştırma boşluk, tire ve harf farkını yok sayar.
     */
    #[Test]
    public function an_already_recorded_tracking_number_is_not_posted_again(): void
    {
        Http::fake([
            self::FULFILLMENT_URL => Http::response(['fulfillments' => [
                ['fulfillmentId' => 'YK1234', 'shipmentTrackingNumber' => 'YK1234', 'shippingCarrierCode' => 'Other'],
            ], 'total' => 1], 200),
            self::ORDER_URL => Http::response($this->order(), 200),
        ]);

        $result = $this->push(tracking: ' yk-12 34 ');

        $this->assertTrue($result->successful);
        $this->assertTrue($result->data['already_shipped'] ?? false);
        $this->assertSame('YK1234', $result->data['external_id'] ?? null);
        Http::assertNotSent(fn (HttpRequest $r): bool => $r->method() === 'POST');
    }

    /** Kargo kayıtları okunamazsa gönderilmez — geçici hata ikinci paket demek olurdu. */
    #[Test]
    public function unreadable_fulfillments_stop_the_push(): void
    {
        Http::fake([
            self::FULFILLMENT_URL => Http::response(['errors' => [['errorId' => 30500]]], 500),
            self::ORDER_URL => Http::response($this->order(), 200),
        ]);

        try {
            $this->push();
            $this->fail('Okunamayan kargo listesi yutuldu.');
        } catch (RequestException $e) {
            $this->assertSame(500, $e->response->status());
        }

        Http::assertNotSent(fn (HttpRequest $r): bool => $r->method() === 'POST');
    }

    /** Bütün kalemler kargolanmışsa istek atılmaz ve başarı döner. */
    #[Test]
    public function a_fully_fulfilled_order_sends_nothing(): void
    {
        Http::fake([
            self::FULFILLMENT_URL => Http::response(['fulfillments' => [], 'total' => 0], 200),
            self::ORDER_URL => Http::response(['orderId' => '12-34567-89012', 'lineItems' => [
                ['lineItemId' => '10001', 'quantity' => 1, 'lineItemFulfillmentStatus' => 'FULFILLED'],
            ]], 200),
        ]);

        $result = $this->push();

        $this->assertTrue($result->successful);
        $this->assertTrue($result->data['already_fulfilled'] ?? false);
        Http::assertNotSent(fn (HttpRequest $r): bool => $r->method() === 'POST');
    }

    /** Siparişin kanal kimliği yoksa istek atılmaz. */
    #[Test]
    public function an_order_without_an_order_id_sends_nothing(): void
    {
        Http::fake();

        $result = $this->push(orderId: null);

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        Http::assertNothingSent();
    }

    /** Harf/rakam taşımayan numara eBay'e gitmez. */
    #[Test]
    public function a_tracking_number_without_alphanumerics_sends_nothing(): void
    {
        Http::fake();

        $result = $this->push(tracking: ' - ');

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        Http::assertNothingSent();
    }

    /**
     * ⚠️ 403 KİMLİK HATASI DEĞİL, `VALIDATION`DIR.
     *
     * `AUTHENTICATION` sayılsaydı devre kesici SÜRESİZ açılır ve tek kargo
     * bildirimi bağlantının stok/fiyat akışını durdururdu.
     */
    #[Test]
    public function a_forbidden_call_is_validation_not_authentication(): void
    {
        Http::fake([
            self::FULFILLMENT_URL => Http::sequence()
                ->push(['fulfillments' => [], 'total' => 0], 200)
                ->push(['errors' => [['errorId' => 1100, 'message' => 'Access denied']]], 403),
            self::ORDER_URL => Http::response($this->order(), 200),
        ]);

        $result = $this->push();

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        $this->assertStringContainsString('eBay panelinden', (string) $result->errorMessage);
    }

    /** 401 (süresi dolmuş token) dokunulmadan yükselir; onu yenileme düzeltir. */
    #[Test]
    public function an_unauthorized_call_is_left_to_classification(): void
    {
        Http::fake([
            self::FULFILLMENT_URL => Http::response(['errors' => [['errorId' => 1001]]], 401),
        ]);

        try {
            $this->push();
            $this->fail('401 yutuldu.');
        } catch (RequestException $e) {
            $this->assertSame(401, $e->response->status());
        }
    }

    /**
     * ⚠️ LİSTEDE OLMAYAN FİRMA `Other` İLE GİDER.
     *
     * "Yurtiçi Kargo" eBay kod listesinde yok; serbest metin gönderilseydi
     * Türkiye'den gönderen her satıcının bildirimi reddedilirdi.
     */
    #[Test]
    public function an_unknown_carrier_is_sent_as_other(): void
    {
        Http::fake([
            self::FULFILLMENT_URL => Http::sequence()
                ->push(['fulfillments' => [], 'total' => 0], 200)
                ->push('', 201, ['Location' => self::FULFILLMENT_URL.'/YK123']),
            self::ORDER_URL => Http::response($this->order(), 200),
        ]);

        $this->assertTrue($this->push(carrier: 'Yurtiçi Kargo')->successful);

        Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'POST' && $r['shippingCarrierCode'] === 'Other');
    }

    /** Eşlenen kod `32300` ile reddedilirse bir kez `Other` ile yeniden gider. */
    #[Test]
    public function a_rejected_carrier_code_is_retried_as_other(): void
    {
        Http::fake([
            self::FULFILLMENT_URL => Http::sequence()
                ->push(['fulfillments' => [], 'total' => 0], 200)
                ->push(['errors' => [['errorId' => 32300, 'message' => 'Invalid shipment tracking number or carrier']]], 400)
                ->push('', 201, ['Location' => self::FULFILLMENT_URL.'/YK123']),
            self::ORDER_URL => Http::response($this->order(), 200),
        ]);

        $result = $this->push(carrier: 'DHL');

        $this->assertTrue($result->successful);

        $posts = collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'POST')->values();
        $this->assertCount(2, $posts);
        $this->assertSame('DHL', $posts[0][0]['shippingCarrierCode']);
        $this->assertSame('Other', $posts[1][0]['shippingCarrierCode']);
    }

    /** Firmayla ilgisiz 400 yeniden denenmez — istisna sınıflandırmaya gider. */
    #[Test]
    public function an_unrelated_bad_request_is_not_retried(): void
    {
        Http::fake([
            self::FULFILLMENT_URL => Http::sequence()
                ->push(['fulfillments' => [], 'total' => 0], 200)
                ->push(['errors' => [['errorId' => 32500, 'message' => 'Invalid shipped date']]], 400),
            self::ORDER_URL => Http::response($this->order(), 200),
        ]);

        try {
            $this->push(carrier: 'DHL');
            $this->fail('İlgisiz 400 yutuldu.');
        } catch (RequestException $e) {
            $this->assertSame(400, $e->response->status());
        }

        Http::assertSentCount(3);
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    /** @return array<string, mixed> */
    private function order(): array
    {
        return [
            'orderId' => '12-34567-89012',
            'lineItems' => [
                ['lineItemId' => '10001', 'quantity' => 2, 'lineItemFulfillmentStatus' => 'NOT_STARTED'],
                ['lineItemId' => '10002', 'quantity' => 1, 'lineItemFulfillmentStatus' => 'FULFILLED'],
            ],
        ];
    }

    private function push(?string $carrier = 'DHL', string $tracking = 'YK123', ?string $orderId = '12-34567-89012'): AdapterResult
    {
        $adapter = $this->adapter();

        $order = new Order;
        $order->external_id = $orderId;

        $fulfillment = new Fulfillment;
        $fulfillment->carrier = $carrier;
        $fulfillment->tracking_number = $tracking;
        $fulfillment->shipped_at = Carbon::parse('2026-10-09 08:15:00');
        $fulfillment->setRelation('order', $order);

        return $adapter->pushFulfillment($fulfillment);
    }

    private function adapter(): EbayAdapter
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'ebay'],
            ['name' => 'eBay', 'kind' => 'marketplace', 'adapter_class' => EbayAdapter::class, 'supports_webhooks' => false, 'is_active' => false],
        ));

        $tenant = (new CreateTenant)->run(name: 'eBay Kargo '.uniqid(), owner: User::factory()->create());

        return $this->asTenant($tenant, function (): EbayAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ebay',
                'external_account_id' => 'ebay-seller-'.uniqid(),
                'status' => 'active',
                'settings' => ['marketplace_id' => 'EBAY_DE'],
            ]);

            app(CredentialVault::class)->store($connection, [
                'client_id' => 'app-id',
                'client_secret' => 'cert-id',
                'access_token' => 'ebay-access',
                'refresh_token' => 'ebay-refresh',
            ]);

            return new EbayAdapter(
                $connection,
                new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)),
            );
        });
    }
}
