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
use App\Domain\Shipping\Jobs\CancelCarrierShipmentJob;
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
 * HepsiJet (doğrulama bekliyor, varsayılan gizli) — zincir birlikte: ayarlardan hesap bağla (ek alanlarla)
 * → siparişten gönderi aç → iş alıcıyı kanaldan okur, HepsiJet'te açar →
 * firma teslim alınca takip numarası mevcut kargo bildirimi yoluyla kanala.
 *
 * ⚠️ MOCK_TESTED: HepsiJet cevapları partner belgesinden; gerçek sunucu yok.
 */
final class HepsijetShipmentFlowTest extends TestCase
{
    use FakesRestCarriers;
    use InvoicingFixtures;
    use RefreshDatabase;

    private const RECIPIENT = 'Ayşe Yılmaz';

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection()->flushdb();
        // Pilot açılışı: doğrulanmamış firma varsayılan gizli.
        config(['shipping.unverified_enabled' => ['hepsijet']]);
    }

    #[Test]
    public function an_unverified_carrier_is_hidden_unless_enabled_for_a_pilot(): void
    {
        config(['shipping.unverified_enabled' => []]);
        [, $user] = $this->shopifyOrder();

        $this->assertSame(['yurtici', 'aras'], Carriers::offered());
        $this->actingAs($user)->get('/settings/shipping')->assertInertia(fn (AssertableInertia $page) => $page->has('carriers', 2));
        $this->actingAs($user)->post('/settings/shipping', $this->form())->assertSessionHasErrors('provider');
        $this->assertSame([], $this->restCalls, 'Gizli firmaya istek gitmez.');

        config(['shipping.unverified_enabled' => ['hepsijet']]);
        $this->assertSame(['yurtici', 'aras', 'hepsijet'], Carriers::offered());
    }

    #[Test]
    public function connecting_requires_the_company_code_and_sender_address_and_hides_secrets(): void
    {
        [$tenant, $user] = $this->shopifyOrder();
        $this->restReply('/auth/getToken', $this->token());

        $this->actingAs($user)->post('/settings/shipping', [
            'provider' => 'hepsijet', 'environment' => 'test', 'username' => 'hj-kullanici', 'password' => 'hj-sifre',
        ])->assertSessionHasErrors(['company_code', 'sender_city', 'sender_district', 'sender_address']);
        $this->assertSame([], $this->restCalls, 'Eksik formla firmaya istek gitmez.');

        $this->actingAs($user)->post('/settings/shipping', $this->form())->assertRedirect()->assertSessionHasNoErrors();

        $account = $this->asTenant($tenant, fn () => CarrierAccount::query()->sole());
        $this->assertSame('hepsijet', $account->provider);
        $this->assertSame('HJT', $account->credential('company_code'), 'Kod büyük harfe çevrilir.');
        $this->assertSame('hj-api-anahtar', $account->credential('api_key'));
        $this->assertNull($account->credential('xdock_code'), 'Boş isteğe bağlı alan yazılmaz.');
        $this->assertStringNotContainsString('hj-api-anahtar', (string) DB::table('carrier_accounts')->value('credentials'), 'Diskte şifreli.');
        $this->assertCount(1, $this->restCallsTo('/auth/getToken'), 'Kaydetmeden önce firmada denendi.');

        $response = $this->actingAs($user)->get('/settings/shipping')->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Shipping')
            ->where('carriers.2.code', 'hepsijet')
            ->where('carriers.2.unverified', true)
            ->where('carriers.2.autoTracking', true)
            ->where('carriers.2.account.status', 'connected')
            ->where('carriers.2.account.details.0.value', 'HJT')
            ->where('carriers.0.unverified', false));
        foreach (['hj-sifre', 'hj-api-anahtar', 'hj-kullanici', 'Sanayi Bölgesi'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $response->getContent(), $secret);
        }
    }

    #[Test]
    public function rejected_credentials_are_not_saved(): void
    {
        [$tenant, $user] = $this->shopifyOrder();
        $this->restReply('/auth/getToken', Http::response(['status' => 'FAIL', 'message' => 'Kullanıcı Adı veya Şifre Hatalı'], 401));

        $this->actingAs($user)->post('/settings/shipping', $this->form())->assertSessionHasErrors('carrier_hepsijet');

        $this->assertSame(0, $this->asTenant($tenant, fn () => CarrierAccount::query()->count()));
    }

    #[Test]
    public function the_shipment_is_created_tracked_and_reported_to_the_channel_once(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->shopifyOrder();
        $this->account($tenant);

        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment", ['carrier' => 'hepsijet'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $shipment = $this->shipment($tenant);
        $this->assertSame(CarrierShipment::STATUS_PENDING, $shipment->status);
        $this->assertMatchesRegularExpression('/^HJT[A-Z2-9]{13}$/', $shipment->cargo_key, 'Barkod firma koduyla başlar.');
        Queue::assertPushed(CreateCarrierShipment::class, 1);

        $this->channelResponder = fn () => Http::response(['data' => ['order' => $this->shopifyShippingOrder()]]);
        $this->restReply('/auth/getToken', $this->token());
        $this->restReply('sendDeliveryOrderEnhanced', fn (HttpRequest $r) => Http::response(['status' => 'OK', 'data' => [
            'customerDeliveryNo' => $r->data()['delivery']['customerDeliveryNo'],
            'zplBarcodeDTOList' => [['zplBarcode' => '^XA^FD'.$r->data()['delivery']['customerDeliveryNo'].'^FS^XZ']],
        ]]));
        $this->runCreate($tenant, $shipment);

        $shipment = $this->shipment($tenant);
        $this->assertSame(CarrierShipment::STATUS_CREATED, $shipment->status);
        $this->assertSame($shipment->cargo_key, $shipment->tracking_number);
        $this->assertStringContainsString($shipment->cargo_key, (string) $shipment->label_zpl);
        $sent = $this->restCallsTo('sendDeliveryOrderEnhanced')[0]['json']['delivery'];
        $this->assertSame(self::RECIPIENT, $sent['recipientPerson']);
        $this->assertSame('İstanbul', $sent['recipientAddress']['city']['name']);
        $this->assertSame('Kadıköy', $sent['recipientAddress']['town']['name'], 'Shopify TR: province = il, city = ilçe.');
        $this->assertRecipientNotStoredInPlainText();

        // Etiket hazır, kargoya verilmedi: kanala HİÇBİR ŞEY gitmez.
        Queue::assertNotPushed(PushFulfillment::class);
        $this->actingAs($user)->get("/orders/{$order->id}/carrier-shipment/label.zpl")->assertOk();
        $this->actingAs($user)->get("/orders/{$order->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.carrierShipment.carrier', 'HepsiJet'));

        // Yoklama turu HepsiJet gönderisini de kuyruğa alır.
        $this->artisan('shipping:track')->assertSuccessful();
        Queue::assertPushed(TrackCarrierShipment::class, 1);

        // NEO → bekliyor; ACCEPTED → kanala BİR KEZ; DELIVERED → teslim.
        $this->restReply('integration/track', $this->tracked($shipment->cargo_key, ['NEO']), $this->tracked($shipment->cargo_key, ['NEO', 'ACCEPTED']), $this->tracked($shipment->cargo_key, ['NEO', 'ACCEPTED', 'DELIVERED']));
        $this->runTrack($tenant, $shipment);
        Queue::assertNotPushed(PushFulfillment::class);
        $this->runTrack($tenant, $shipment);
        $this->runTrack($tenant, $shipment);

        $shipment = $this->shipment($tenant);
        $this->assertSame(CarrierShipment::STATUS_DELIVERED, $shipment->status);
        $this->assertSame('https://hepsiexpress.com/gonderi-takibi/'.$shipment->cargo_key, $shipment->tracking_url);
        $fulfillment = $this->asTenant($tenant, fn () => Fulfillment::query()->sole());
        $this->assertSame('HepsiJet', $fulfillment->carrier);
        $this->assertSame($shipment->cargo_key, $fulfillment->tracking_number);
        Queue::assertPushed(PushFulfillment::class, 1);
    }

    #[Test]
    public function cancelling_before_hand_over_deletes_the_delivery_at_hepsijet(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->shopifyOrder();
        $this->account($tenant);
        $shipment = $this->asTenant($tenant, fn (): CarrierShipment => CarrierShipment::query()->create([
            'tenant_id' => $tenant->id, 'order_id' => $order->id, 'provider' => 'hepsijet', 'environment' => 'test',
            'cargo_key' => 'HJTIPTALEDILECEK', 'status' => CarrierShipment::STATUS_CREATED, 'tracking_number' => 'HJTIPTALEDILECEK',
            'create_requested_at' => now(), 'confirmed_at' => now(),
        ]));

        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment/cancel")->assertSessionHasNoErrors();
        Queue::assertPushed(CancelCarrierShipmentJob::class, 1);

        $this->restReply('/auth/getToken', $this->token());
        $this->restReply('deleteDeliveryOrder', ['status' => 'OK', 'message' => 'Delivery has been deleted successfully']);
        $this->asTenant($tenant, fn () => (new CancelCarrierShipmentJob($shipment->id, $tenant->id))->withFakeQueueInteractions()->handle(app(Carriers::class)));

        $this->assertSame(CarrierShipment::STATUS_CANCELLED, $this->asTenant($tenant, fn () => $shipment->fresh())->status);
        $this->assertSame('/rest/delivery/deleteDeliveryOrder/HJTIPTALEDILECEK', $this->restCallsTo('deleteDeliveryOrder')[0]['path']);
    }

    #[Test]
    public function a_withheld_carrier_is_not_offered_for_new_connections(): void
    {
        config(['shipping.withheld' => ['hepsijet']]);
        [, $user] = $this->shopifyOrder();

        $this->actingAs($user)->get('/settings/shipping')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('carriers', 2)
            ->where('carriers.1.code', 'aras'));
        $this->actingAs($user)->post('/settings/shipping', $this->form())->assertSessionHasErrors('provider');
        $this->assertSame(['yurtici', 'aras'], Carriers::offered());
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array<string, string> */
    private function form(): array
    {
        return [
            'provider' => 'hepsijet', 'environment' => 'test', 'username' => 'hj-kullanici', 'password' => 'hj-sifre',
            'api_key' => 'hj-api-anahtar', 'company_code' => 'hjt', 'sender_city' => 'Kocaeli', 'sender_district' => 'Gebze',
            'sender_address' => 'Organize Sanayi Bölgesi 5. Cadde No:7', 'xdock_code' => '',
        ];
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
            'provider' => 'hepsijet',
            'environment' => CarrierAccount::ENV_TEST,
            'credentials' => [
                'username' => 'hj-kullanici', 'password' => 'hj-sifre', 'company_code' => 'HJT',
                'sender_city' => 'Kocaeli', 'sender_district' => 'Gebze', 'sender_address' => 'Organize Sanayi Bölgesi 5. Cadde No:7',
            ],
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

    private function token(): array
    {
        return ['status' => 'OK', 'data' => ['id' => 8, 'token' => 'tok-akis', 'tenant' => ['abbreviationCode' => 'HX']]];
    }

    private function tracked(string $barcode, array $statuses): array
    {
        return ['status' => 'OK', 'data' => [[
            'barcode' => $barcode,
            'trackingUrl' => 'https://hepsiexpress.com/gonderi-takibi/'.$barcode,
            'details' => array_map(static fn (string $s): array => ['transactionLocation' => 'HB DEPO Transfer Merkezi', 'integrationStatus' => $s], $statuses),
        ]]];
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
