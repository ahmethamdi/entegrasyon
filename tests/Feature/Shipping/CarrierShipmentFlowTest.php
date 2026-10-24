<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Fulfillment\Enums\FulfillmentReason;
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
 * S6 · Kargo II (Beta) — zincir birlikte: ayarlardan hesap bağla →
 * siparişten gönderi aç → iş alıcıyı kanaldan okur, firmada açar →
 * kargoya verilince takip numarası MEVCUT kargo bildirimi yoluyla kanala.
 *
 * Korunan kararlar: alıcının adı/adresi hiçbir tabloya düz metin düşmez;
 * sipariş başına tek gönderi; etiket hazır ≠ kargoya verildi; kimlik
 * hatası hesabı "koptu"ya düşürür; anahtar kapalıyken özellik yok.
 */
final class CarrierShipmentFlowTest extends TestCase
{
    use FakesCarriers;
    use InvoicingFixtures;
    use RefreshDatabase;

    private const RECIPIENT = 'Ayşe Yılmaz';

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection()->flushdb();
    }

    // ─────────────────────────────────────────────────── ayarlar

    #[Test]
    public function connecting_tests_the_credentials_first_and_never_shows_the_password(): void
    {
        [$tenant, $user] = $this->shopifyOrder();
        $this->replyWith('queryShipment', 'yurtici-query-not-found');

        $this->actingAs($user)->post('/settings/shipping', [
            'provider' => 'yurtici', 'environment' => 'test', 'username' => 'satici-ws', 'password' => 'gizli-ws-sifre',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $account = $this->asTenant($tenant, fn () => CarrierAccount::query()->sole());
        $this->assertSame(CarrierAccount::STATUS_CONNECTED, $account->status);
        $this->assertSame('test', $account->environment);
        $this->assertSame('gizli-ws-sifre', $account->password());
        $this->assertStringNotContainsString('gizli-ws-sifre', (string) DB::table('carrier_accounts')->value('credentials'), 'Kimlik diskte şifreli.');

        $response = $this->actingAs($user)->get('/settings/shipping')->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Shipping')
            ->where('carriers.0.code', 'yurtici')
            ->where('carriers.0.beta', false)
            ->where('carriers.0.account.status', 'connected')
            ->where('carriers.0.account.username', '•••-ws')
            ->where('carriers.1.account', null));
        $this->assertStringNotContainsString('gizli-ws-sifre', (string) $response->getContent());
        $this->assertStringNotContainsString('satici-ws', (string) $response->getContent());
    }

    #[Test]
    public function rejected_credentials_are_not_saved(): void
    {
        [$tenant, $user] = $this->shopifyOrder();
        $this->replyWith('GetArasBarcode', 'aras-barcode-auth');

        $this->actingAs($user)->post('/settings/shipping', [
            'provider' => 'aras', 'environment' => 'live', 'username' => 'satici', 'password' => 'yanlis',
        ])->assertSessionHasErrors('carrier_aras');

        $this->assertSame(0, $this->asTenant($tenant, fn () => CarrierAccount::query()->count()));
    }

    // ─────────────────────────────────────────────────── Yurtiçi uçtan uca

    #[Test]
    public function yurtici_shipment_is_created_tracked_and_reported_to_the_channel_once(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->shopifyOrder();
        $this->account($tenant, 'yurtici');

        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment", ['carrier' => 'yurtici'])
            ->assertRedirect()->assertSessionHasNoErrors();
        // Çift tıklama: ikinci satır ve ikinci iş YOK.
        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment", ['carrier' => 'yurtici'])->assertSessionHasNoErrors();

        $shipment = $this->shipment($tenant);
        $this->assertSame(CarrierShipment::STATUS_PENDING, $shipment->status);
        $this->assertSame($user->id, $shipment->requested_by);
        Queue::assertPushedOn('orders:high', CreateCarrierShipment::class);
        Queue::assertPushed(CreateCarrierShipment::class, 1);

        $this->channelResponder = fn () => Http::response(['data' => ['order' => $this->shopifyShippingOrder()]]);
        $this->replyWith('createShipment', 'yurtici-create-ok');
        $this->runCreate($tenant, $shipment);

        $shipment = $this->shipment($tenant);
        $this->assertSame(CarrierShipment::STATUS_CREATED, $shipment->status);
        $this->assertNotNull($shipment->create_requested_at);
        $this->assertNull($shipment->tracking_number, 'Takip no şube işleyince gelir.');
        $this->assertStringContainsString($shipment->cargo_key, (string) $shipment->label_zpl);
        $this->assertStringContainsString('<receiverCustName>'.self::RECIPIENT.'</receiverCustName>', $this->callsTo('createShipment')[0]);
        $this->assertStringContainsString('<cityName>İstanbul</cityName><townName>Kadıköy</townName>', $this->callsTo('createShipment')[0], 'Shopify TR: province = il, city = ilçe.');
        $this->assertRecipientNotStoredInPlainText();

        // Etiket hazır, kargoya verilmedi: kanala HİÇBİR ŞEY gitmez.
        Queue::assertNotPushed(PushFulfillment::class);
        $this->actingAs($user)->get("/orders/{$order->id}/carrier-shipment/label.zpl")->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="kargo-'.$shipment->cargo_key.'.zpl"');
        $this->actingAs($user)->get("/orders/{$order->id}/carrier-shipment/label.pdf")->assertNotFound();
        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment/hand-over")->assertSessionHasErrors('shipment');

        // Durum turu 1: işlem görmemiş → yalnız durum metni.
        $this->replyWith('queryShipment', 'yurtici-query-waiting');
        $this->runTrack($tenant, $shipment);
        $this->assertSame('Kargo İşlem Görmemiş.', $this->shipment($tenant)->carrier_status_text);
        Queue::assertNotPushed(PushFulfillment::class);

        // Durum turu 2: şube teslim aldı → takip no kanala, BİR KEZ.
        $this->replyWith('queryShipment', fn (HttpRequest $r) => Http::response($this->processed('IND', $r)), fn (HttpRequest $r) => Http::response($this->processed('DLV', $r)));
        $this->runTrack($tenant, $shipment);
        $this->runTrack($tenant, $shipment);

        $shipment = $this->shipment($tenant);
        $this->assertSame(CarrierShipment::STATUS_DELIVERED, $shipment->status);
        $this->assertSame('112233445566', $shipment->tracking_number);
        $fulfillment = $this->asTenant($tenant, fn () => Fulfillment::query()->sole());
        $this->assertSame($fulfillment->id, $shipment->fulfillment_id);
        $this->assertSame('Yurtiçi Kargo', $fulfillment->carrier);
        $this->assertSame('112233445566', $fulfillment->tracking_number);
        Queue::assertPushed(PushFulfillment::class, 1);
    }

    #[Test]
    public function the_order_screen_shows_the_shipment_without_recipient_data(): void
    {
        [$tenant, $user, $order] = $this->shopifyOrder();
        $this->account($tenant, 'aras');

        $this->actingAs($user)->get("/orders/{$order->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.carrierChannelSupported', true)
            ->where('order.carrierShipment', null)
            ->where('order.carrierAccounts.0.code', 'aras'));

        $this->openShipment($tenant, $order, 'aras', ['status' => CarrierShipment::STATUS_CREATED, 'tracking_number' => '6843860752720', 'label_pdf' => base64_encode('%PDF-1.4 x')]);

        $response = $this->actingAs($user)->get("/orders/{$order->id}");
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.carrierShipment.status', 'created')
            ->where('order.carrierShipment.trackingNumber', '6843860752720')
            ->where('order.carrierShipment.hasPdf', true)
            ->where('order.carrierShipment.cancellable', true)
            ->missing('order.carrierShipment.label_pdf'));
        $this->assertStringNotContainsString('JVBERi', (string) $response->getContent(), 'Etiket gövdesi sayfaya gömülmez.');
    }

    // ─────────────────────────────────────────────────── Aras uçtan uca

    #[Test]
    public function aras_shipment_from_woocommerce_gets_tracking_with_the_label_and_waits_for_hand_over(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->wooOrder();
        $this->account($tenant, 'aras');

        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment", ['carrier' => 'aras'])->assertSessionHasNoErrors();

        $this->channelResponder = fn () => Http::response($this->wooShippingOrder());
        $this->replyWith('SetOrder', 'aras-setorder-ok');
        $this->replyWith('GetArasBarcode', 'aras-barcode-not-found', 'aras-barcode-ok');
        $this->runCreate($tenant, $this->shipment($tenant));

        $shipment = $this->shipment($tenant);
        $this->assertSame(CarrierShipment::STATUS_CREATED, $shipment->status);
        $this->assertSame('6843860752720', $shipment->tracking_number);
        $this->assertStringContainsString('<ReceiverCityName>İstanbul</ReceiverCityName>', $this->callsTo('SetOrder')[0], 'Woo TR34 → İstanbul.');
        $this->assertStringContainsString('<ReceiverPhone1>05321112233</ReceiverPhone1>', $this->callsTo('SetOrder')[0], 'Teslimat telefonu yoksa fatura telefonu.');
        $this->assertRecipientNotStoredInPlainText();

        $this->actingAs($user)->get("/orders/{$order->id}/carrier-shipment/label.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Queue::assertNotPushed(PushFulfillment::class);

        // Aras durum sorgusu sunmaz: yoklama dokunmaz, satıcı "kargoya verdim" der.
        $this->artisan('shipping:track')->assertSuccessful();
        Queue::assertNotPushed(TrackCarrierShipment::class);

        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment/hand-over")->assertSessionHasNoErrors();
        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment/hand-over")->assertSessionHasNoErrors();

        $this->assertSame(CarrierShipment::STATUS_SHIPPED, $this->shipment($tenant)->status);
        $this->assertSame('Aras Kargo', $this->asTenant($tenant, fn () => Fulfillment::query()->sole())->carrier);
        Queue::assertPushed(PushFulfillment::class, 1);
        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment/cancel")->assertSessionHasErrors('shipment');
    }

    // ─────────────────────────────────────────────────── hatalar

    #[Test]
    public function rejected_credentials_during_create_revoke_the_account(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->shopifyOrder();
        $this->account($tenant, 'yurtici');
        $shipment = $this->openShipment($tenant, $order, 'yurtici');

        $this->channelResponder = fn () => Http::response(['data' => ['order' => $this->shopifyShippingOrder()]]);
        $this->replyWith('createShipment', 'yurtici-auth');
        $this->runCreate($tenant, $shipment);

        $after = $this->shipment($tenant);
        $this->assertSame(CarrierShipment::STATUS_FAILED, $after->status);
        $this->assertSame(FulfillmentReason::CARRIER_AUTH_FAILED, $after->reason());
        $this->assertSame(CarrierAccount::STATUS_REVOKED, $this->asTenant($tenant, fn () => CarrierAccount::query()->sole())->status);
    }

    #[Test]
    public function a_field_rule_fails_visibly_and_retry_keeps_the_same_key(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->shopifyOrder();
        $this->account($tenant, 'yurtici');
        $shipment = $this->openShipment($tenant, $order, 'yurtici');

        $this->channelResponder = fn () => Http::response(['data' => ['order' => $this->shopifyShippingOrder()]]);
        $this->replyWith('createShipment', 'yurtici-create-invalid', 'yurtici-create-duplicate');
        $this->runCreate($tenant, $shipment);

        $failed = $this->shipment($tenant);
        $this->assertSame(FulfillmentReason::CARRIER_REJECTED, $failed->reason());
        $this->assertStringContainsString('RECEIVER_ADDRESS', (string) $failed->error_detail);

        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment", ['carrier' => 'yurtici'])->assertSessionHasNoErrors();
        $this->runCreate($tenant, $failed);

        $after = $this->shipment($tenant);
        $this->assertSame(CarrierShipment::STATUS_CREATED, $after->status, '60020 = aynı anahtar zaten var → başarı.');
        $this->assertSame($shipment->cargo_key, $after->cargo_key, 'Yeniden deneme aynı anahtarla: firmada ikinci gönderi açılamaz.');
    }

    #[Test]
    public function a_timeout_is_retried_with_the_same_key(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->shopifyOrder();
        $this->account($tenant, 'yurtici');
        $shipment = $this->openShipment($tenant, $order, 'yurtici');

        $this->channelResponder = fn () => Http::response(['data' => ['order' => $this->shopifyShippingOrder()]]);
        $this->replyWith('createShipment', fn () => Http::failedConnection(), 'yurtici-create-ok');

        $this->runCreate($tenant, $shipment)->assertReleased();
        $this->assertSame(CarrierShipment::STATUS_CREATING, $this->shipment($tenant)->status, 'Ulaşmış olabilir.');

        $this->runCreate($tenant, $shipment)->assertNotReleased();
        $this->assertSame(CarrierShipment::STATUS_CREATED, $this->shipment($tenant)->status);
        $keys = array_map(static fn (string $b): string => (string) preg_replace('#.*<cargoKey>([^<]+)</cargoKey>.*#s', '$1', $b), $this->callsTo('createShipment'));
        $this->assertSame([$shipment->cargo_key, $shipment->cargo_key], $keys);
    }

    #[Test]
    public function a_channel_without_a_shippable_address_never_reaches_the_carrier(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->shopifyOrder();
        $this->account($tenant, 'yurtici');
        $shipment = $this->openShipment($tenant, $order, 'yurtici');

        $this->fakeCarriers();
        $this->channelResponder = fn () => Http::response([
            'data' => ['order' => null],
            'errors' => [['message' => 'Access denied for shippingAddress field.', 'extensions' => ['code' => 'ACCESS_DENIED']]],
        ]);
        $this->runCreate($tenant, $shipment);

        $after = $this->shipment($tenant);
        $this->assertSame(FulfillmentReason::RECIPIENT_UNAVAILABLE, $after->reason());
        $this->assertStringContainsString('korumalı müşteri verisi', (string) $after->error_detail);
        $this->assertSame([], $this->carrierCalls);
    }

    /**
     * ⚠️ TELEFON İZNİ YOKSA GÖNDERİ YİNE AÇILIR.
     *
     * Korumalı veri başvurusu telefonsuz yapılacak; telefon sorgusu Shopify'da
     * TÜM sorguyu reddeder. Telefonsuz ikinci sorgu adresi alır, firmaya
     * telefon alanı boş gider.
     */
    #[Test]
    public function a_denied_phone_field_falls_back_to_an_address_without_phone(): void
    {
        Queue::fake();
        [$tenant, , $order] = $this->shopifyOrder();
        $this->account($tenant, 'yurtici');
        $shipment = $this->openShipment($tenant, $order, 'yurtici');

        $queries = [];
        $this->channelResponder = function (HttpRequest $request) use (&$queries) {
            $query = (string) ($request->data()['query'] ?? '');
            $queries[] = $query;

            if (str_contains($query, 'phone')) {
                return Http::response([
                    'data' => ['order' => null],
                    'errors' => [['message' => 'This app is not approved to access the phone field.', 'extensions' => ['code' => 'ACCESS_DENIED']]],
                ]);
            }

            $order = $this->shopifyShippingOrder();
            unset($order['phone'], $order['shippingAddress']['phone']);

            return Http::response(['data' => ['order' => $order]]);
        };
        $this->replyWith('createShipment', 'yurtici-create-ok');
        $this->runCreate($tenant, $shipment);

        $this->assertSame(CarrierShipment::STATUS_CREATED, $this->shipment($tenant)->status);
        $this->assertCount(2, $queries, 'Telefonlu sorgu reddedilince telefonsuz bir kez daha sorulur.');
        $this->assertStringNotContainsString('phone', $queries[1]);
        $this->assertStringNotContainsString('<receiverPhone1>0', $this->callsTo('createShipment')[0]);
    }

    #[Test]
    public function marketplace_orders_other_tenants_and_missing_accounts_are_refused(): void
    {
        Queue::fake();
        [, $user, $order] = $this->shopifyOrder();
        [$otherTenant, $otherUser, $trendyolOrder] = $this->trendyolOrder();
        $this->setAwaiting($otherTenant, $trendyolOrder);
        $this->account($otherTenant, 'yurtici');

        // Hesap yok.
        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment", ['carrier' => 'yurtici'])
            ->assertSessionHasErrors(['shipment' => FulfillmentReason::CARRIER_ACCOUNT_MISSING->label()]);
        // Pazar yeri: anlaşmalı kargo kullanılır.
        $this->actingAs($otherUser)->post("/orders/{$trendyolOrder->id}/carrier-shipment", ['carrier' => 'yurtici'])
            ->assertSessionHasErrors(['shipment' => FulfillmentReason::CHANNEL_UNSUPPORTED->label()]);
        // Başka kiracının siparişi.
        $this->actingAs($otherUser)->post("/orders/{$order->id}/carrier-shipment", ['carrier' => 'yurtici'])->assertNotFound();

        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('carrier_shipments')->count());
    }

    // ─────────────────────────────────────────────────── iptal

    #[Test]
    public function cancelling_before_hand_over_then_reopening_uses_a_new_key(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->shopifyOrder();
        $this->account($tenant, 'yurtici');
        $shipment = $this->openShipment($tenant, $order, 'yurtici', ['status' => CarrierShipment::STATUS_CREATED, 'label_zpl' => '^XA^XZ']);

        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment/cancel")->assertSessionHasNoErrors();
        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment/cancel")->assertSessionHasErrors('shipment');
        Queue::assertPushed(CancelCarrierShipmentJob::class, 1);

        $this->replyWith('cancelShipment', 'yurtici-cancel-ok');
        $this->asTenant($tenant, fn () => (new CancelCarrierShipmentJob($shipment->id, $tenant->id))->withFakeQueueInteractions()->handle(app(Carriers::class)));
        $this->assertSame(CarrierShipment::STATUS_CANCELLED, $this->shipment($tenant)->status);
        $this->assertStringContainsString('<cargoKeys>'.$shipment->cargo_key.'</cargoKeys>', $this->callsTo('cancelShipment')[0]);

        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment", ['carrier' => 'yurtici'])->assertSessionHasNoErrors();
        $reopened = $this->shipment($tenant);
        $this->assertSame(CarrierShipment::STATUS_PENDING, $reopened->status);
        $this->assertNotSame($shipment->cargo_key, $reopened->cargo_key);
        $this->assertNull($reopened->label_zpl);
    }

    #[Test]
    public function a_refused_cancel_returns_the_shipment_with_the_carrier_text(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->shopifyOrder();
        $this->account($tenant, 'aras');
        $shipment = $this->openShipment($tenant, $order, 'aras', ['status' => CarrierShipment::STATUS_CREATED]);

        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment/cancel");
        $this->replyWith('CancelDispatch', fn () => Http::response(str_replace(['<ResultCode>-1</ResultCode>', 'Kayıt Bulunmadı.'], ['<ResultCode>12</ResultCode>', 'Kargo teslim alınmış, iptal edilemez.'], $this->fixture('aras-cancel-not-found'))));
        $this->asTenant($tenant, fn () => (new CancelCarrierShipmentJob($shipment->id, $tenant->id))->withFakeQueueInteractions()->handle(app(Carriers::class)));

        $after = $this->shipment($tenant);
        $this->assertSame(CarrierShipment::STATUS_CREATED, $after->status, 'Sessizce "iptal edildi" denmez.');
        $this->assertSame(FulfillmentReason::CARRIER_REJECTED, $after->reason());
        $this->assertSame('Kargo teslim alınmış, iptal edilemez.', $after->error_detail);
    }

    // ─────────────────────────────────────────────────── anahtar

    #[Test]
    public function the_kill_switch_hides_everything_and_stops_tracking(): void
    {
        Queue::fake();
        [$tenant, $user, $order] = $this->shopifyOrder();
        $this->account($tenant, 'yurtici');
        $this->openShipment($tenant, $order, 'yurtici', ['status' => CarrierShipment::STATUS_CREATED, 'confirmed_at' => now()]);
        config(['shipping.enabled' => false]);

        $this->actingAs($user)->get('/settings/shipping')->assertNotFound();
        $this->actingAs($user)->post("/orders/{$order->id}/carrier-shipment", ['carrier' => 'yurtici'])->assertNotFound();
        $this->actingAs($user)->get("/orders/{$order->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('features.carrierShipping', false)
            ->where('order.carrierChannelSupported', false)
            ->where('order.carrierShipment', null));
        $this->artisan('shipping:track')->assertSuccessful();
        Queue::assertNotPushed(TrackCarrierShipment::class);

        config(['shipping.enabled' => true]);
        $this->artisan('shipping:track')->assertSuccessful();
        Queue::assertPushedOn('maintenance', TrackCarrierShipment::class);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: User, 2: Order} */
    private function shopifyOrder(): array
    {
        [$tenant, $user, $order] = $this->trendyolOrder('shopify');

        return [$tenant, $user, $this->setAwaiting($tenant, $order)];
    }

    /** @return array{0: Tenant, 1: User, 2: Order} */
    private function wooOrder(): array
    {
        [$tenant, $user, $order] = $this->trendyolOrder('woocommerce');

        return [$tenant, $user, $this->setAwaiting($tenant, $order)];
    }

    private function setAwaiting(Tenant $tenant, Order $order): Order
    {
        $this->asTenant($tenant, fn () => Order::query()->whereKey($order->id)->update(['state' => 'awaiting_shipment']));

        return $order->fresh();
    }

    private function account(Tenant $tenant, string $provider): CarrierAccount
    {
        return $this->asTenant($tenant, fn (): CarrierAccount => CarrierAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => $provider,
            'environment' => CarrierAccount::ENV_TEST,
            'credentials' => ['username' => 'satici-ws', 'password' => 'gizli-ws-sifre'],
            'status' => CarrierAccount::STATUS_CONNECTED,
            'verified_at' => now(),
        ]));
    }

    private function openShipment(Tenant $tenant, Order $order, string $provider, array $overrides = []): CarrierShipment
    {
        return $this->asTenant($tenant, fn (): CarrierShipment => CarrierShipment::query()->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'provider' => $provider,
            'environment' => CarrierAccount::ENV_TEST,
            'cargo_key' => Carriers::newCargoKey(),
            'status' => CarrierShipment::STATUS_PENDING,
            ...$overrides,
        ]));
    }

    private function shipment(Tenant $tenant): CarrierShipment
    {
        return $this->asTenant($tenant, fn (): CarrierShipment => CarrierShipment::query()->sole());
    }

    private function runCreate(Tenant $tenant, CarrierShipment $shipment): CreateCarrierShipment
    {
        Redis::connection()->flushdb();
        $job = (new CreateCarrierShipment($shipment->id, $tenant->id))->withFakeQueueInteractions();
        $job->handle(app(AdapterRegistry::class), app(Carriers::class), app(ChannelErrorText::class), app(CircuitBreaker::class), app(ChannelRateLimiter::class));

        return $job;
    }

    private function runTrack(Tenant $tenant, CarrierShipment $shipment): void
    {
        // Aynı gönderiye 25 dk'dan sık bakılmaz → zamanı ilerlet.
        $this->travel(30)->minutes();
        (new TrackCarrierShipment($shipment->id, $tenant->id))->handle(app(Carriers::class), app(HandOverCarrierShipment::class));
    }

    /** Şubede işlem görmüş gönderi (belgedeki biçim — gerçek gönderide doğrulanmadı). */
    private function processed(string $status, HttpRequest $request): string
    {
        preg_match('#<keys>([^<]+)</keys>#', $request->body(), $m);

        return str_replace(
            '<operationCode>0</operationCode><operationMessage>Kargo İşlem Görmemiş.</operationMessage><operationStatus>NOP</operationStatus>',
            '<operationCode>1</operationCode><operationMessage>Kargo yolda.</operationMessage><operationStatus>'.$status.'</operationStatus>'
            .'<shippingDeliveryItemDetailVO><docId>112233445566</docId></shippingDeliveryItemDetailVO>',
            $this->fixture('yurtici-query-waiting', $m[1] ?? 'X'),
        );
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

    private function wooShippingOrder(): array
    {
        return [
            'id' => 9001,
            'status' => 'processing',
            'billing' => ['first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'phone' => '0532 111 22 33', 'email' => 'ayse@example.com'],
            'shipping' => ['first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'company' => '', 'address_1' => 'Caferağa Mah. Moda Cad. No:12', 'address_2' => '', 'city' => 'Kadıköy', 'state' => 'TR34', 'phone' => ''],
        ];
    }

    /** Alıcının adı hiçbir tabloda düz metin olarak durmaz (etiket dahil — şifreli). */
    private function assertRecipientNotStoredInPlainText(): void
    {
        foreach (['carrier_shipments', 'orders', 'order_events', 'fulfillments', 'sync_operations'] as $table) {
            $dump = json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString(self::RECIPIENT, (string) $dump, "{$table} alıcının adını düz metin saklıyor.");
            $this->assertStringNotContainsString('Moda Cad', (string) $dump, "{$table} alıcının adresini düz metin saklıyor.");
        }
    }
}
