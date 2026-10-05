<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Actions\ConnectChannel;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Woo sipariş webhook'ları bağlarken OTOMATİK kurulur.
 *
 * 5 Eki 2026'dan önce: Woo "webhook kanalı" olduğu için sipariş yoklamasından
 * çıkarılıyordu, ama formda imza anahtarı alanı yoktu ve alıcı adres panelde
 * hiç gösterilmiyordu → her webhook 401, HİÇBİR sipariş gelmiyordu; stok
 * gönderimi çalıştığı için bağlantı yeşil görünüyordu.
 */
final class WooWebhookRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const SYSTEM_STATUS = '*/wp-json/wc/v3/system_status*';

    private const WEBHOOKS = '*/wp-json/wc/v3/webhooks*';

    #[Test]
    public function connecting_registers_the_three_order_topics_with_a_generated_secret(): void
    {
        $tenant = $this->makeTenant();

        Http::fake([
            self::SYSTEM_STATUS => Http::response(['environment' => []]),
            self::WEBHOOKS => fn (Request $r) => $r->method() === 'GET'
                ? Http::response([])
                : Http::response(['id' => random_int(1, 999)], 201),
        ]);

        $connection = $this->asTenant($tenant, fn () => $this->connect());

        $this->assertSame('active', $connection->status);

        $deliveryUrl = route('webhooks.receive', ['connectionId' => $connection->id]);
        $secret = $this->secretOf($tenant, $connection);

        $this->assertNotNull($secret);
        $this->assertGreaterThanOrEqual(32, strlen($secret));

        $created = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/webhooks'));

        $this->assertEqualsCanonicalizing(
            ['order.created', 'order.updated', 'order.deleted'],
            $created->map(fn (Request $r) => $r['topic'])->all(),
        );

        foreach ($created as $request) {
            $this->assertSame($deliveryUrl, $request['delivery_url']);
            $this->assertSame($secret, $request['secret']);
            $this->assertSame('active', $request['status']);
        }

        $this->assertTrue($connection->fresh()->settings['webhooks']['registered']);
    }

    /**
     * Kurulan anahtar İŞE YARIYOR mu: Woo'nun yapacağı gibi imzalanmış bir
     * teslim alıcıdan 202 almalı. Anahtar kasaya yazılıp adapter başka yerden
     * okusaydı ilk test yeşil, siparişler yine 401 olurdu.
     */
    #[Test]
    public function a_delivery_signed_with_the_registered_secret_is_accepted(): void
    {
        $tenant = $this->makeTenant();

        Http::fake([
            self::SYSTEM_STATUS => Http::response(['environment' => []]),
            self::WEBHOOKS => fn (Request $r) => $r->method() === 'GET'
                ? Http::response([])
                : Http::response(['id' => 1], 201),
        ]);

        $connection = $this->asTenant($tenant, fn () => $this->connect());
        $secret = $this->secretOf($tenant, $connection);

        $body = json_encode(['id' => 501, 'status' => 'processing', 'line_items' => []]);
        $signature = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $this->call('POST', "/webhooks/{$connection->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created',
            'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'd-1',
        ], $body)->assertStatus(202);

        $this->call('POST', "/webhooks/{$connection->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, 'baska', true)),
            'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created',
            'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'd-2',
        ], $body)->assertStatus(401);
    }

    #[Test]
    public function reconnecting_renews_existing_subscriptions_and_keeps_the_secret(): void
    {
        $tenant = $this->makeTenant();

        // Woo'daki abonelikler: ilk turda boş; ikinci turda üçü de var, biri
        // Woo tarafından kapatılmış (5 teslim hatası).
        // ⚠️ Http::fake() İKİNCİ KEZ çağrılmaz: Laravel yeni sahteyi eskinin
        // ARKASINA ekler ve ilk eşleşen kazanır — ikinci tur ilk turun
        // yanıtlarını alırdı. Tek sahte, değişen durum.
        $remote = [];

        Http::fake([
            self::SYSTEM_STATUS => Http::response(['environment' => []]),
            self::WEBHOOKS => function (Request $r) use (&$remote) {
                return $r->method() === 'GET'
                    ? Http::response($remote)
                    : Http::response(['id' => 1], 201);
            },
        ]);

        $connection = $this->asTenant($tenant, fn () => $this->connect());
        $firstSecret = $this->secretOf($tenant, $connection);
        $deliveryUrl = route('webhooks.receive', ['connectionId' => $connection->id]);

        $remote = [
            ['id' => 11, 'topic' => 'order.created', 'delivery_url' => $deliveryUrl, 'status' => 'active'],
            ['id' => 12, 'topic' => 'order.updated', 'delivery_url' => $deliveryUrl, 'status' => 'disabled'],
            ['id' => 13, 'topic' => 'order.deleted', 'delivery_url' => $deliveryUrl, 'status' => 'active'],
        ];
        $firstRound = count(Http::recorded());

        // Yeniden bağlama: aynı mağaza, YENİ Woo anahtarları (rotasyon).
        $this->asTenant($tenant, fn () => $this->connect(consumerKey: 'ck_yeni', consumerSecret: 'cs_yeni'));

        $secondRound = collect(Http::recorded())->slice($firstRound)->map(fn (array $pair) => $pair[0]);

        $this->assertCount(
            0,
            $secondRound->filter(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/webhooks')),
            'Yeniden bağlama kopya abonelik açtı.',
        );

        foreach ([11, 12, 13] as $id) {
            Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
                && str_ends_with(parse_url($r->url(), PHP_URL_PATH), "/webhooks/{$id}")
                && $r['status'] === 'active'
                && $r['secret'] === $firstSecret);
        }

        // Anahtar korunur VE yeni Woo anahtarları kasada.
        $secrets = $this->asTenant($tenant, fn () => app(CredentialVault::class)->read($connection));
        $this->assertSame($firstSecret, $secrets['webhook_secret']);
        $this->assertSame('ck_yeni', $secrets['consumer_key']);
    }

    #[Test]
    public function duplicates_are_disabled_and_foreign_webhooks_are_left_alone(): void
    {
        $tenant = $this->makeTenant();

        $connection = $this->asTenant($tenant, function () {
            Http::fake([self::SYSTEM_STATUS => Http::response([], 500)]);

            return $this->connect();   // sağlıksız: kurulum çalışmaz
        });

        $this->assertSame('pending', $connection->status);
        $deliveryUrl = route('webhooks.receive', ['connectionId' => $connection->id]);

        Http::fake([
            self::WEBHOOKS => fn (Request $r) => match ($r->method()) {
                'GET' => Http::response([
                    ['id' => 21, 'topic' => 'order.created', 'delivery_url' => $deliveryUrl],
                    ['id' => 22, 'topic' => 'order.created', 'delivery_url' => $deliveryUrl],
                    ['id' => 99, 'topic' => 'order.created', 'delivery_url' => 'https://baska-eklenti.example/hook'],
                ]),
                default => Http::response(['id' => 1], 201),
            },
        ]);

        $result = $this->asTenant(
            $tenant,
            fn () => app(AdapterRegistry::class)->for($connection)->registerWebhooks($deliveryUrl, 'sir'),
        );

        $this->assertFalse($result->failed());
        $this->assertSame([22], $result->data['disabled_duplicates']);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/webhooks/22')
            && $r['status'] === 'disabled');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/webhooks/99'));
    }

    /**
     * Kurulum başarısız olsa da bağlantı AKTİF kalır: stok/ürün gönderimi
     * webhook'a bağlı değil. Hata `settings.webhooks`'ta görünür olmalı.
     */
    #[Test]
    public function a_failed_registration_keeps_the_connection_active_and_records_the_error(): void
    {
        $tenant = $this->makeTenant();

        Http::fake([
            self::SYSTEM_STATUS => Http::response(['environment' => []]),
            self::WEBHOOKS => Http::response(['code' => 'woocommerce_rest_cannot_view'], 403),
        ]);

        $connection = $this->asTenant($tenant, fn () => $this->connect());

        $this->assertSame('active', $connection->status);
        $this->assertFalse($connection->fresh()->settings['webhooks']['registered']);
        $this->assertNotEmpty($connection->fresh()->settings['webhooks']['error']);
    }

    #[Test]
    public function the_command_retries_registration_for_active_connections(): void
    {
        $tenant = $this->makeTenant();

        $wooDown = true;

        Http::fake([
            self::SYSTEM_STATUS => Http::response(['environment' => []]),
            self::WEBHOOKS => function (Request $r) use (&$wooDown) {
                if ($wooDown) {
                    return Http::response([], 503);
                }

                return $r->method() === 'GET'
                    ? Http::response([])
                    : Http::response(['id' => 1], 201);
            },
        ]);

        $connection = $this->asTenant($tenant, fn () => $this->connect());
        $this->assertFalse($connection->fresh()->settings['webhooks']['registered']);

        $wooDown = false;

        $this->artisan('channels:register-webhooks')->assertSuccessful();

        $this->assertTrue($connection->fresh()->settings['webhooks']['registered']);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function connect(string $consumerKey = 'ck_test_key', string $consumerSecret = 'cs_test_secret'): ChannelConnection
    {
        return app(ConnectChannel::class)->run(
            channelTypeCode: 'woocommerce',
            label: 'Ana Mağaza',
            storeUrl: 'https://magaza.example.com',
            secrets: ['consumer_key' => $consumerKey, 'consumer_secret' => $consumerSecret],
        );
    }

    private function secretOf(Tenant $tenant, ChannelConnection $connection): ?string
    {
        return $this->asTenant(
            $tenant,
            fn () => app(CredentialVault::class)->read($connection)['webhook_secret'] ?? null,
        );
    }

    private function makeTenant(): Tenant
    {
        $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'storefront',
                'adapter_class' => WooCommerceAdapter::class,
                'supports_webhooks' => true,
                'is_active' => true,
            ],
        ));

        return (new CreateTenant)->run(
            name: 'Woo '.uniqid(),
            owner: User::factory()->create(),
        );
    }
}
