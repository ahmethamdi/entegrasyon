<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Sms\Actions\RunSmsNotifications;
use App\Domain\Sms\Jobs\NotifyTenantSms;
use App\Domain\Sms\Models\SmsAccount;
use App\Domain\Sms\Models\SmsMessage;
use App\Domain\Sync\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Invoicing\InvoicingFixtures;
use Tests\TestCase;

/**
 * SMS bildirim turu — satıcıya yeni sipariş / stok / kanal sorunu, alıcıya kargo.
 *
 * Sınanan sözleşme: aynı olay için İKİNCİ SMS YOK; geçmiş olay (bağlanmadan
 * önceki, içe aktarılan eski sipariş) SMS'e dönüşmez; sessiz saatte hiçbir
 * şey gitmez ve sabah özetlenir; pazaryeri alıcısına SMS gitmez; alıcının
 * telefonu hiçbir tabloda düz durmaz.
 */
final class SmsNotificationsTest extends TestCase
{
    use FakesNetgsm;
    use InvoicingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Sipariş fikstürü 08.10.2026 10:00 (UTC) verilmiş; tur 14:30 TR.
        $this->travelTo(Carbon::parse('2026-10-08 11:30:00', 'UTC'));
        $this->fakeNetgsm();
    }

    #[Test]
    public function a_new_order_is_sent_to_the_seller_once(): void
    {
        [$tenant] = $this->sellerWithAccount('trendyol');

        $this->runFor($tenant);
        $this->runFor($tenant);

        $this->assertCount(1, $this->netgsmSends(), 'Aynı sipariş için ikinci SMS yok.');
        $send = $this->netgsmSends()[0]['body'];
        $this->assertSame('905559998877', $send['messages'][0]['recipient']);
        $this->assertSame('34Pazar: Yeni sipariş - Trendyol #10500001, 240,00 TL.', $send['messages'][0]['text']);

        $message = $this->asTenant($tenant, fn () => SmsMessage::query()->sole());
        $this->assertSame(SmsMessage::STATUS_SENT, $message->status);
        $this->assertSame('90555•••8877', $message->recipient_masked);
        $this->assertSame('17949071472739000017964541', $message->provider_job_id);
    }

    #[Test]
    public function several_new_orders_become_one_summary(): void
    {
        [$tenant, , $order] = $this->sellerWithAccount('trendyol');
        $this->asTenant($tenant, fn () => Order::query()->create([
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $order->channel_connection_id,
            'external_id' => '9002',
            'external_number' => '10500002',
            'status' => 'Created',
            'currency' => 'TRY',
            'grand_total' => '1010.50',
            'placed_at' => now(),
        ]));

        $this->runFor($tenant);

        $this->assertCount(1, $this->netgsmSends());
        $this->assertSame('34Pazar: 2 yeni sipariş (Trendyol 2), toplam 1.250,50 TL.', $this->netgsmSends()[0]['body']['messages'][0]['text']);
        $this->assertSame(2, $this->asTenant($tenant, fn () => SmsMessage::query()->sole()->event_count));
    }

    #[Test]
    public function orders_from_before_the_account_was_set_up_are_not_sent(): void
    {
        [$tenant] = $this->sellerWithAccount('trendyol', ['notify_since' => now()->addMinute()]);

        $this->travel(2)->minutes();
        $this->runFor($tenant);

        $this->assertSame([], $this->netgsmSends());
    }

    #[Test]
    public function an_old_order_imported_now_is_not_a_new_order(): void
    {
        [$tenant, , $order] = $this->sellerWithAccount('trendyol');
        // Yeni bağlanan kanalın 30 gün önceki siparişi bugün içe aktarıldı.
        $this->asTenant($tenant, fn () => Order::query()->whereKey($order->id)->update(['placed_at' => now()->subDays(30)]));

        $this->runFor($tenant);

        $this->assertSame([], $this->netgsmSends());
    }

    #[Test]
    public function quiet_hours_hold_messages_until_the_morning_summary(): void
    {
        // 23:30 TR
        $this->travelTo(Carbon::parse('2026-10-08 20:30:00', 'UTC'));
        [$tenant] = $this->sellerWithAccount('trendyol', [], placedAt: now());

        $this->runFor($tenant);
        $this->assertSame([], $this->netgsmSends(), 'Gece SMS gitmez.');

        // 08:05 TR
        $this->travelTo(Carbon::parse('2026-10-09 05:05:00', 'UTC'));
        $this->runFor($tenant);
        $this->assertCount(1, $this->netgsmSends(), 'Gece gelen sipariş sabah özetlenir.');

        // Sessiz saat kapalıysa gece de gider.
        $this->assertFalse(app(RunSmsNotifications::class)->isQuiet(new SmsAccount(['quiet_hours' => false]), Carbon::parse('2026-10-08 20:30:00', 'UTC')));
    }

    #[Test]
    public function a_live_product_running_out_of_stock_is_sent_once_a_day(): void
    {
        [$tenant, , $order] = $this->sellerWithAccount('trendyol', ['events' => [SmsAccount::EVENT_STOCK_OUT]]);

        $this->asTenant($tenant, function () use ($order): void {
            $variantId = DB::table('order_lines')->where('order_id', $order->id)->value('variant_id');
            Listing::factory()->create(['channel_connection_id' => $order->channel_connection_id, 'variant_id' => $variantId]);
            DB::table('inventory_levels')->where('variant_id', $variantId)->update(['on_hand' => DB::raw('reserved'), 'updated_at' => now()]);
        });

        $this->runFor($tenant);
        $this->travel(3)->hours();
        $this->asTenant($tenant, fn () => DB::table('inventory_levels')->update(['updated_at' => now()]));
        $this->runFor($tenant);

        $this->assertCount(1, $this->netgsmSends(), 'Aynı ürün aynı gün bir kez.');
        $this->assertSame('34Pazar: KHV-1 stokta tükendi; kanallarda stok 0 oldu.', $this->netgsmSends()[0]['body']['messages'][0]['text']);
    }

    #[Test]
    public function a_product_without_a_live_listing_is_not_reported(): void
    {
        [$tenant, , $order] = $this->sellerWithAccount('trendyol', ['events' => [SmsAccount::EVENT_STOCK_OUT]]);
        $this->asTenant($tenant, function () use ($order): void {
            $variantId = DB::table('order_lines')->where('order_id', $order->id)->value('variant_id');
            DB::table('inventory_levels')->where('variant_id', $variantId)->update(['on_hand' => DB::raw('reserved'), 'updated_at' => now()]);
        });

        $this->runFor($tenant);

        $this->assertSame([], $this->netgsmSends());
    }

    #[Test]
    public function a_broken_channel_is_reported_once_per_outage(): void
    {
        [$tenant, , $order] = $this->sellerWithAccount('trendyol', ['events' => [SmsAccount::EVENT_CHANNEL_PROBLEM]]);
        $break = fn (Carbon $lastHealthy) => $this->asTenant($tenant, fn () => ChannelConnection::query()->whereKey($order->channel_connection_id)->update([
            'health_status' => 'unhealthy', 'status' => 'pending', 'connected_at' => now()->subDay(), 'last_healthy_at' => $lastHealthy,
        ]));

        $break(now()->subMinutes(5));
        $this->runFor($tenant);
        $this->assertSame([], $this->netgsmSends(), '15 dakikadan kısa kesinti için SMS yok.');

        $break(now()->subMinutes(20));
        $this->runFor($tenant);
        $this->runFor($tenant);
        $this->assertCount(1, $this->netgsmSends());
        $this->assertSame('34Pazar: Trendyol bağlantısında sorun var; stok ve sipariş akışı durdu. Panelden kontrol edin.', $this->netgsmSends()[0]['body']['messages'][0]['text']);

        // Düzeldi, sonra yeniden koptu: yeni kesinti → yeni SMS.
        $this->travel(2)->hours();
        $break(now()->subMinutes(30));
        $this->runFor($tenant);
        $this->assertCount(2, $this->netgsmSends());
    }

    #[Test]
    public function the_buyer_of_a_shopify_order_gets_the_tracking_number_and_the_phone_is_not_stored(): void
    {
        [$tenant, , $order] = $this->sellerWithAccount('shopify', ['events' => [SmsAccount::EVENT_BUYER_SHIPPED]]);
        $this->channelResponder = fn () => Http::response(['data' => ['order' => [
            'cancelledAt' => null,
            'email' => 'ayse@example.com',
            'phone' => null,
            'shippingAddress' => [
                'name' => 'Ayşe Yılmaz', 'company' => null, 'address1' => 'Caferağa Mah. Moda Cad. No:12', 'address2' => 'D:3',
                'city' => 'Kadıköy', 'province' => 'İstanbul', 'phone' => '+90 532 111 22 33', 'countryCodeV2' => 'TR',
            ],
        ]]]);
        $this->shipped($tenant, $order);

        $this->runFor($tenant);
        $this->runFor($tenant);

        $this->assertCount(1, $this->netgsmSends(), 'Aynı kargo için ikinci SMS yok.');
        $sent = $this->netgsmSends()[0]['body']['messages'][0];
        $this->assertSame('905321112233', $sent['recipient']);
        $this->assertSame('Siparişiniz #10500001 kargoya verildi. Yurtiçi Kargo takip no: YK123456789', $sent['text']);

        foreach (['sms_messages', 'sms_event_keys', 'sms_accounts'] as $table) {
            $dump = (string) json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('5321112233', $dump, "{$table} alıcının telefonunu düz saklıyor.");
            $this->assertStringNotContainsString('Ayşe', $dump);
        }
    }

    #[Test]
    public function a_marketplace_buyer_never_gets_an_sms(): void
    {
        [$tenant, , $order] = $this->sellerWithAccount('trendyol', ['events' => [SmsAccount::EVENT_BUYER_SHIPPED]]);
        $this->shipped($tenant, $order);

        $this->runFor($tenant);

        $this->assertSame([], $this->netgsmSends(), 'Pazaryeri alıcı telefonu vermez ve platform dışı mesajı yasaklar.');
    }

    #[Test]
    public function a_shipment_entered_in_the_channel_itself_is_not_messaged(): void
    {
        [$tenant, , $order] = $this->sellerWithAccount('shopify', ['events' => [SmsAccount::EVENT_BUYER_SHIPPED]]);
        $this->shipped($tenant, $order, ['source' => Fulfillment::SOURCE_CHANNEL, 'push_status' => null]);

        $this->runFor($tenant);

        $this->assertSame([], $this->netgsmSends(), 'Kanal kendi bildirimini zaten gönderir.');
    }

    #[Test]
    public function rejected_credentials_stop_the_account_and_are_logged(): void
    {
        [$tenant] = $this->sellerWithAccount('trendyol');
        $this->fakeNetgsm(['/auth/v3/token' => ['token-invalid', 401]]);

        $this->runFor($tenant);

        $account = $this->asTenant($tenant, fn () => SmsAccount::query()->sole());
        $this->assertSame(SmsAccount::STATUS_REVOKED, $account->status);
        $this->assertSame(SmsMessage::STATUS_FAILED, $this->asTenant($tenant, fn () => SmsMessage::query()->sole()->status));

        $this->fakeNetgsm();
        $this->runFor($tenant);
        $this->assertSame([], $this->netgsmSends(), 'Kopmuş hesapla deneme yapılmaz; satıcı yeniden bağlar.');
    }

    #[Test]
    public function the_scheduler_queues_only_tenants_with_notifications(): void
    {
        Queue::fake();
        [$tenant] = $this->sellerWithAccount('trendyol');
        [$silent] = $this->sellerWithAccount('trendyol', ['events' => []]);

        $this->artisan('sms:notify')->assertSuccessful();

        Queue::assertPushed(NotifyTenantSms::class, 1);
        Queue::assertPushed(NotifyTenantSms::class, fn (NotifyTenantSms $job): bool => $job->tenantId === $tenant->id);
        $this->assertNotSame($tenant->id, $silent->id);
    }

    #[Test]
    public function the_kill_switch_stops_everything(): void
    {
        Queue::fake();
        config(['sms.enabled' => false]);
        $this->sellerWithAccount('trendyol');

        $this->artisan('sms:notify')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: mixed, 2: Order} */
    private function sellerWithAccount(string $channel, array $overrides = [], ?Carbon $placedAt = null): array
    {
        [$tenant, $user, $order] = $this->trendyolOrder($channel);

        if ($placedAt !== null) {
            $this->asTenant($tenant, fn () => Order::query()->whereKey($order->id)->update(['placed_at' => $placedAt]));
        }

        $this->asTenant($tenant, fn () => SmsAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => SmsAccount::PROVIDER_NETGSM,
            'environment' => SmsAccount::ENV_TEST,
            'credentials' => ['username' => '8500000000', 'password' => 'password001'],
            'sender' => 'TESTHEADER',
            'senders' => ['TESTHEADER'],
            'status' => SmsAccount::STATUS_CONNECTED,
            'verified_at' => now(),
            'notify_phone' => '0555 999 88 77',
            'events' => [SmsAccount::EVENT_NEW_ORDER],
            'quiet_hours' => true,
            'notify_since' => now()->subHour(),
            ...$overrides,
        ]));

        return [$tenant, $user, $order->fresh()];
    }

    private function shipped(Tenant $tenant, Order $order, array $overrides = []): void
    {
        $this->asTenant($tenant, fn () => Fulfillment::query()->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'carrier' => 'Yurtiçi Kargo',
            'tracking_number' => 'YK123456789',
            'status' => 'shipped',
            'shipped_at' => now(),
            'source' => Fulfillment::SOURCE_PANEL,
            'push_status' => Fulfillment::PUSH_SENT,
            'pushed_at' => now(),
            ...$overrides,
        ]));
    }

    private function runFor(Tenant $tenant): void
    {
        (new NotifyTenantSms($tenant->id))->handle(app(RunSmsNotifications::class));
    }
}
