<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Jobs\PushFulfillment;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Shipping\Actions\HandOverCarrierShipment;
use App\Domain\Shipping\Jobs\CreateCarrierShipment;
use App\Domain\Shipping\Jobs\TrackCarrierShipment;
use App\Domain\Shipping\Models\CarrierAccount;
use App\Domain\Shipping\Models\CarrierShipment;
use App\Domain\Shipping\Support\Carriers;
use App\Domain\Sync\Support\ChannelErrorText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Invoicing\InvoicingFixtures;
use Tests\TestCase;

/**
 * MNG Kargo (doğrulama bekliyor, varsayılan gizli) — zincir birlikte:
 * iki kapı (platform anahtarı + pilot açılışı) → hesap bağla → siparişten
 * gönderi aç → CBS kodu + createOrder + createbarcode → durum yoklaması →
 * takip numarası kanala bir kez.
 *
 * ⚠️ MOCK_TESTED: MNG cevapları resmî Swagger örneklerinden; gerçek sunucu yok.
 */
final class MngShipmentFlowTest extends TestCase
{
    use FakesRestCarriers;
    use InvoicingFixtures;
    use RefreshDatabase;

    private const RECIPIENT = 'Ayşe Yılmaz';

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection()->flushdb();
        config([
            'shipping.mng.test_client_id' => 'platform-test-id',
            'shipping.mng.test_client_secret' => 'platform-test-secret',
            'shipping.unverified_enabled' => ['mng'],
        ]);
    }

    #[Test]
    public function mng_needs_the_platform_key_and_the_pilot_switch(): void
    {
        [, $user] = $this->shopifyOrder();
        $this->assertContains('mng', Carriers::offered());

        config(['shipping.unverified_enabled' => []]);
        $this->assertNotContains('mng', Carriers::offered(), 'Doğrulanmamış: varsayılan gizli.');

        config(['shipping.unverified_enabled' => ['mng'], 'shipping.mng.test_client_id' => '']);
        $this->assertNotContains('mng', Carriers::offered(), 'API Zone anahtarı yok: sunulmaz.');
        $this->actingAs($user)->post('/settings/shipping', $this->form())->assertSessionHasErrors('provider');
        $this->assertSame([], $this->restCalls);
    }

    #[Test]
    public function connecting_tries_the_token_first_and_hides_the_password(): void
    {
        [$tenant, $user] = $this->shopifyOrder();
        $this->restReply('/mngapi/api/token', ['jwt' => 'eyJ.akis', 'refreshToken' => 'r']);

        $this->actingAs($user)->post('/settings/shipping', $this->form())->assertRedirect()->assertSessionHasNoErrors();

        $account = $this->asTenant($tenant, fn () => CarrierAccount::query()->sole());
        $this->assertSame('mng', $account->provider);
        $this->assertSame('312947702', $account->username());
        $this->assertSame(['customerNumber' => '312947702', 'password' => 'mng-sifre', 'identityType' => 1], $this->restCalls[0]['json']);

        $response = $this->actingAs($user)->get('/settings/shipping')->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('carriers.2.code', 'mng')
            ->where('carriers.2.unverified', true)
            ->where('carriers.2.fields.0.label', 'MNG müşteri numarası')
            ->where('carriers.2.account.status', 'connected'));
        $this->assertStringNotContainsString('mng-sifre', (string) $response->getContent());
        $this->assertStringNotContainsString('platform-test-secret', (string) $response->getContent());
    }

    #[Test]
    public function wrong_credentials_are_not_saved(): void
    {
        [$tenant, $user] = $this->shopifyOrder();
        $this->restReply('/mngapi/api/token', Http::response(['title' => 'Kullanıcı bilgileri hatalı', 'status' => 400], 400));

        $this->actingAs($user)->post('/settings/shipping', $this->form())->assertSessionHasErrors('carrier_mng');

        $this->assertSame(0, $this->asTenant($tenant, fn () => CarrierAccount::query()->count()));
    }

    #[Test]
    public function the_shipment_is_created_tracked_and_reported_to_the_channel_once(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->shopifyOrder();
        $this->account($tenant);

        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment", ['carrier' => 'mng'])->assertSessionHasNoErrors();
        $shipment = $this->shipment($tenant);
        $this->assertMatchesRegularExpression('/^34P[A-Z2-9]{12}$/', $shipment->cargo_key);

        $this->channelResponder = fn () => Http::response(['data' => ['order' => $this->shopifyShippingOrder()]]);
        $this->restReply('/mngapi/api/token', ['jwt' => 'eyJ.akis']);
        $this->restReply('getcities', [['code' => '34', 'name' => 'İSTANBUL']]);
        $this->restReply('getdistricts', [['cityCode' => '34', 'code' => '445', 'name' => 'KADIKÖY']]);
        $this->restReply('createOrder', fn (HttpRequest $r) => Http::response(['orderInvoiceId' => '1', 'referenceId' => $r->data()['order']['referenceId']]));
        $this->restReply('createbarcode', ['referenceId' => $shipment->cargo_key, 'invoiceId' => '564645774', 'shipmentId' => '4536457657', 'barcodes' => [['pieceNumber' => 1, 'value' => '^XA^FD4536457657^FS^XZ']]]);
        $this->runCreate($tenant, $shipment);

        $shipment = $this->shipment($tenant);
        $this->assertSame(CarrierShipment::STATUS_CREATED, $shipment->status);
        $this->assertSame('4536457657', $shipment->tracking_number);
        $this->assertSame('^XA^FD4536457657^FS^XZ', $shipment->label_zpl);
        $recipient = $this->restCallsTo('createOrder')[0]['json']['recipient'];
        $this->assertSame([34, 445], [$recipient['cityCode'], $recipient['districtCode']], 'Shopify TR: province = il, city = ilçe → CBS kodu.');
        $this->assertSame(self::RECIPIENT, $recipient['fullName']);
        $this->assertRecipientNotStoredInPlainText();
        Queue::assertNotPushed(PushFulfillment::class);

        $this->artisan('shipping:track')->assertSuccessful();
        Queue::assertPushed(TrackCarrierShipment::class, 1);

        $this->restReply('getshipmentstatus', $this->shipmentStatus(1), $this->shipmentStatus(2), $this->shipmentStatus(5, true));
        $this->runTrack($tenant, $shipment);
        Queue::assertNotPushed(PushFulfillment::class);
        $this->assertSame('Gönderi Hazırlandı', $this->shipment($tenant)->carrier_status_text);
        $this->runTrack($tenant, $shipment);
        $this->runTrack($tenant, $shipment);

        $shipment = $this->shipment($tenant);
        $this->assertSame(CarrierShipment::STATUS_DELIVERED, $shipment->status);
        $this->assertSame('https://www.mngkargo.com.tr/track/4536457657', $shipment->tracking_url);
        $fulfillment = $this->asTenant($tenant, fn () => Fulfillment::query()->sole());
        $this->assertSame('MNG Kargo', $fulfillment->carrier);
        $this->assertSame('4536457657', $fulfillment->tracking_number);
        Queue::assertPushed(PushFulfillment::class, 1);
    }

    #[Test]
    public function an_unmatched_district_fails_visibly_without_reaching_mng(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->shopifyOrder();
        $this->account($tenant);
        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment", ['carrier' => 'mng']);

        $this->channelResponder = fn () => Http::response(['data' => ['order' => $this->shopifyShippingOrder()]]);
        $this->restReply('/mngapi/api/token', ['jwt' => 'eyJ.akis']);
        $this->restReply('getcities', [['code' => '34', 'name' => 'İSTANBUL']]);
        $this->restReply('getdistricts', [['cityCode' => '34', 'code' => '440', 'name' => 'KARTAL']]);
        $this->runCreate($tenant, $this->shipment($tenant));

        $shipment = $this->shipment($tenant);
        $this->assertSame(CarrierShipment::STATUS_FAILED, $shipment->status);
        $this->assertStringContainsString('ilçe listesinde bulunamadı', (string) $shipment->error_detail);
        $this->assertSame([], $this->restCallsTo('createOrder'));
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array<string, string> */
    private function form(): array
    {
        return ['provider' => 'mng', 'environment' => 'test', 'username' => '312947702', 'password' => 'mng-sifre'];
    }

    /** @return array{0: Tenant, 1: User, 2: Order} */
    private function shopifyOrder(): array
    {
        [$tenant, $user, $order] = $this->trendyolOrder('shopify');
        $this->asTenant($tenant, fn () => Order::query()->whereKey($order->id)->update(['state' => 'awaiting_shipment']));

        return [$tenant, $user, $order->fresh()];
    }

    private function account(Tenant $tenant): CarrierAccount
    {
        return $this->asTenant($tenant, fn (): CarrierAccount => CarrierAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'mng',
            'environment' => CarrierAccount::ENV_TEST,
            'credentials' => ['username' => '312947702', 'password' => 'mng-sifre'],
            'status' => CarrierAccount::STATUS_CONNECTED,
            'verified_at' => now(),
        ]));
    }

    private function shipment(Tenant $tenant): CarrierShipment
    {
        return $this->asTenant($tenant, fn (): CarrierShipment => CarrierShipment::query()->sole());
    }

    private function runCreate(Tenant $tenant, CarrierShipment $shipment): void
    {
        Redis::connection()->flushdb();
        $job = (new CreateCarrierShipment($shipment->id, $tenant->id))->withFakeQueueInteractions();
        $job->handle(app(AdapterRegistry::class), app(Carriers::class), app(ChannelErrorText::class), app(CircuitBreaker::class), app(ChannelRateLimiter::class));
    }

    private function runTrack(Tenant $tenant, CarrierShipment $shipment): void
    {
        $this->travel(30)->minutes();
        (new TrackCarrierShipment($shipment->id, $tenant->id))->handle(app(Carriers::class), app(HandOverCarrierShipment::class));
    }

    private function shipmentStatus(int $code, bool $delivered = false): array
    {
        $names = [1 => 'Gönderi_Hazırlandı', 2 => 'Transfer_Aşamasında', 5 => 'Teslim_Edildi'];

        return ['shipmentStatusCode' => $code, 'shipmentId' => '4536457657', 'shipmentStatus' => $names[$code], 'trackingUrl' => 'www.mngkargo.com.tr/track/4536457657', 'isDelivered' => $delivered ? 1 : 0];
    }

    private function shopifyShippingOrder(): array
    {
        return [
            'cancelledAt' => null,
            'email' => 'ayse@example.com',
            'phone' => null,
            'shippingAddress' => [
                'name' => self::RECIPIENT, 'company' => null, 'address1' => 'Caferağa Mah. Moda Cad. No:12', 'address2' => 'D:3',
                'city' => 'Kadıköy', 'province' => 'İstanbul', 'phone' => '+90 532 111 22 33', 'countryCodeV2' => 'TR',
            ],
        ];
    }

    private function assertRecipientNotStoredInPlainText(): void
    {
        foreach (['carrier_shipments', 'orders', 'order_events', 'fulfillments', 'sync_operations'] as $table) {
            $dump = json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString(self::RECIPIENT, (string) $dump, "{$table} alıcının adını düz metin saklıyor.");
            $this->assertStringNotContainsString('Moda Cad', (string) $dump, "{$table} alıcının adresini düz metin saklıyor.");
        }
    }
}
