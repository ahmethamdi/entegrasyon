<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsTaxonomy;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\InventoryPushItem;
use App\Support\Logging\PayloadRedactor;
use App\Support\Tenancy\TenantContext;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WooCommerceAdapter — gerçek kanal adapter'ı.
 *
 * Mimari Karar Dokümanı v2.2 · §7, §13 · faz 1.4/1.7, §1 · Karar 25–26.
 *
 * DEĞİŞMEZ KURAL — STOK MUTLAK DEĞER GÖNDERİLİR:
 *   Delta ASLA gönderilmez. Kaybolan veya iki kez işlenen bir istek, delta
 *   modelinde kanaldaki bakiyeyi kalıcı olarak kaydırır ve fark geri
 *   kazanılamaz. Mutlak değerde tekrar zararsızdır — yeniden denemenin
 *   güvenli olmasının ve mutabakatın çalışabilmesinin dayanağı budur.
 *
 * DEĞİŞMEZ KURAL — ADAPTER YAN ETKİSİZDİR:
 *   Veritabanına yazmaz, kuyruğa iş atmaz, durum güncellemez. Girdi alır,
 *   kanalla konuşur, AdapterResult döner. Durumu SyncResultRecorder yazar.
 *
 * DEĞİŞMEZ KURAL — SINIFLANDIRMAYI ADAPTER YAPAR, KARARI ÇEKİRDEK VERİR:
 *   classifyError() Woo'nun hata gövdesini okur; ne yapılacağına RetryPolicy
 *   karar verir.
 *
 * YETENEKLER TİP SİSTEMİNDE: Woo'da taksonomi ve onay süreci YOKTUR;
 * `instanceof SupportsTaxonomy` false döner ve panelde o sekme çıkmaz.
 */
final class WooCommerceAdapterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Stok MUTLAK değer olarak, wc/v3 batch uç noktasına gider.
     */
    #[Test]
    public function push_inventory_sends_absolute_quantities_to_batch_endpoint(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        Http::fake([
            '*' => Http::response(['update' => [['id' => 101], ['id' => 202]]], 200),
        ]);

        $batch = new InventoryPushBatch(
            channelConnectionId: $connection->id,
            items: [
                new InventoryPushItem('l1', '101', 'SKU-A', quantity: 9, version: 182),
                new InventoryPushItem('l2', '202', 'SKU-B', quantity: 0, version: 182),
            ],
        );

        $result = $this->asTenant($tenant, fn () => $this->adapterFor($connection)->pushInventory($batch));

        $this->assertTrue($result->successful);

        Http::assertSent(function (Request $request): bool {
            $this->assertStringContainsString('products/batch', $request->url());
            $this->assertSame('POST', $request->method());

            $payload = $request->data();

            // MUTLAK değer: stock_quantity alanı, delta değil.
            $this->assertSame(101, $payload['update'][0]['id']);
            $this->assertSame(9, $payload['update'][0]['stock_quantity']);
            $this->assertSame(202, $payload['update'][1]['id']);
            $this->assertSame(0, $payload['update'][1]['stock_quantity']);

            // Woo stok yönetimi açık olmadan miktarı yok sayar.
            $this->assertTrue($payload['update'][0]['manage_stock']);

            // Delta anlamına gelebilecek hiçbir alan YOK.
            $this->assertArrayNotHasKey('stock_delta', $payload['update'][0]);
            $this->assertArrayNotHasKey('adjust', $payload['update'][0]);

            return true;
        });
    }

    /**
     * Adapter KIRPMA YAPMAZ — miktarı olduğu gibi geçirir.
     *
     * Kırpmanın tek meşru yeri OutboundQuantity::forChannel()'dır ve yükü
     * kuran InventoryBatchBuilder onu uygulamıştır. Adapter ikinci kez
     * kırparsa, kanonik durumun yanlışlıkla kırpılmadığı GERÇEK bir hatayı
     * gizler: negatif bir değer buraya ulaşıyorsa yukarıda bir yerde
     * dönüşüm atlanmış demektir ve sessizce düzeltmek onu görünmez kılar.
     *
     * MUTASYON NOTU — bu kural DAVRANIŞLA sınanamaz:
     *   Adapter'a `max($q, 0)` eklendiğinde hiçbir test kırmızıya dönmez ve
     *   dönemez de: InventoryPushItem negatifi kurucuda zaten reddettiği
     *   için adapter'a hiç negatif ulaşmaz, dolayısıyla ikinci kırpma her
     *   zaman işlemsizdir. Kuralı koruyan şey testler değil, bir alt
     *   testteki YAPISAL sınırdır (negative_quantity_is_rejected...).
     *   Bu test o sınırın geçirdiği değerlerin AYNEN gittiğini kaydeder.
     */
    #[Test]
    public function adapter_does_not_clamp_quantities_itself(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        Http::fake(['*' => Http::response(['update' => []], 200)]);

        // InventoryPushItem negatif miktarı zaten reddeder; bu yüzden
        // kırpmanın yokluğu sınırdaki değerlerin AYNEN geçtiğiyle kanıtlanır.
        $batch = new InventoryPushBatch($connection->id, [
            new InventoryPushItem('l1', '101', 'SKU-A', quantity: 0, version: 1),
            new InventoryPushItem('l2', '202', 'SKU-B', quantity: 7, version: 1),
        ]);

        $this->asTenant($tenant, fn () => $this->adapterFor($connection)->pushInventory($batch));

        Http::assertSent(function (Request $request): bool {
            $updates = $request->data()['update'];

            $this->assertSame(0, $updates[0]['stock_quantity']);
            $this->assertSame(7, $updates[1]['stock_quantity']);

            // Sıfır stok "stokta yok" olarak işaretlenir; Woo aksi halde
            // ürünü satın alınabilir gösterir ve fazla satış üretir.
            $this->assertSame('outofstock', $updates[0]['stock_status']);
            $this->assertSame('instock', $updates[1]['stock_status']);

            return true;
        });
    }

    /**
     * Negatif miktar sessizce KIRPILMAZ, istisna fırlatır.
     *
     * Negatif bir değer yüke ulaşıyorsa OutboundQuantity atlanmış demektir.
     * Kırpmak hatayı gizler; fırlatmak onu görünür kılar — kanonik durumun
     * kırpıldığı gün fark edilmeyen bir bozulma yaşanmaz.
     */
    #[Test]
    public function negative_quantity_is_rejected_before_it_reaches_the_channel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new InventoryPushItem('l1', '101', 'SKU-A', quantity: -2, version: 1);
    }

    /**
     * Adapter YAN ETKİSİZDİR — veritabanına yazmaz.
     *
     * Tek istisna ChannelHttpClient'ın api_calls günlüğüdür; o da adapter'ın
     * değil istemcinin işidir ve durum yazımı değil teknik kayıttır.
     */
    #[Test]
    public function adapter_writes_no_domain_state(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        Http::fake(['*' => Http::response(['update' => []], 200)]);

        $before = $this->asSystem(fn (): array => [
            'operations' => DB::table('sync_operations')->count(),
            'states' => DB::table('listing_sync_states')->count(),
            'attempts' => DB::table('sync_attempts')->count(),
            'levels' => DB::table('inventory_levels')->count(),
        ]);

        $this->asTenant($tenant, fn () => $this->adapterFor($connection)->pushInventory(
            new InventoryPushBatch($connection->id, [
                new InventoryPushItem('l1', '101', 'SKU-A', quantity: 5, version: 1),
            ])
        ));

        $after = $this->asSystem(fn (): array => [
            'operations' => DB::table('sync_operations')->count(),
            'states' => DB::table('listing_sync_states')->count(),
            'attempts' => DB::table('sync_attempts')->count(),
            'levels' => DB::table('inventory_levels')->count(),
        ]);

        $this->assertSame($before, $after, 'Adapter domain durumuna YAZMAMALI.');
    }

    /** Boş yük çağrı YAPMAZ — boşuna kota harcanmaz. */
    #[Test]
    public function empty_batch_makes_no_http_call(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        Http::fake();

        $result = $this->asTenant($tenant, fn () => $this->adapterFor($connection)->pushInventory(
            new InventoryPushBatch($connection->id, [])
        ));

        $this->assertTrue($result->successful);

        Http::assertNothingSent();
    }

    /**
     * Hata sınıflandırma — Woo'nun cevabı çekirdeğin anladığı sınıfa çevrilir.
     *
     * Sınıflandırmayı adapter yapar çünkü gövdeyi yalnızca o anlar; ne
     * yapılacağına (yeniden dene / kalıcı hata / mutabakata devret) çekirdek
     * karar verir.
     */
    #[Test]
    public function error_classification_maps_woo_responses_to_core_classes(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        $adapter = $this->asTenant($tenant, fn () => $this->adapterFor($connection));

        $cases = [
            [429, ErrorClass::RATE_LIMITED],
            [500, ErrorClass::SERVER_ERROR],
            [503, ErrorClass::SERVER_ERROR],
            [401, ErrorClass::AUTHENTICATION],
            [403, ErrorClass::AUTHENTICATION],
            [404, ErrorClass::NOT_FOUND],
            [409, ErrorClass::CONFLICT],
            [400, ErrorClass::VALIDATION],
            [422, ErrorClass::VALIDATION],
        ];

        foreach ($cases as [$status, $expected]) {
            $this->assertSame(
                $expected,
                $adapter->classifyError($this->requestExceptionWith($status)),
                "HTTP {$status} → {$expected->value} bekleniyordu.",
            );
        }

        // Ağ hatası ayrı sınıf: sonuç belirsizdir, idempotency kritiktir.
        $this->assertSame(
            ErrorClass::NETWORK,
            $adapter->classifyError(new ConnectionException('cURL error 28')),
        );
    }

    /**
     * 429 kalıcı DEĞİL, 401 kalıcı — ayrım çekirdeğin kararını belirler.
     */
    #[Test]
    public function rate_limit_is_transient_but_auth_is_permanent(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        $adapter = $this->asTenant($tenant, fn () => $this->adapterFor($connection));

        $this->assertFalse(
            $adapter->classifyError($this->requestExceptionWith(429))->isPermanent(),
            '429 geçicidir; kanal toparlar.',
        );

        $this->assertTrue(
            $adapter->classifyError($this->requestExceptionWith(401))->isPermanent(),
            '401 kullanıcı müdahalesi gerektirir.',
        );
    }

    /**
     * Başarısız çağrı İSTİSNA fırlatır — PushInventory onu yakalar.
     *
     * AdapterResult::failure() dönmek yerine fırlatmak bilinçlidir: iş
     * tarafındaki try/catch sınıflandırma ve yeniden deneme kararını tek
     * yerde topluyor (§12 · iş tarafı).
     */
    #[Test]
    public function failed_push_throws_so_the_job_can_classify_it(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        Http::fake(['*' => Http::response(['message' => 'Too many requests'], 429)]);

        $batch = new InventoryPushBatch($connection->id, [
            new InventoryPushItem('l1', '101', 'SKU-A', quantity: 3, version: 5),
        ]);

        $caught = null;

        try {
            $this->asTenant($tenant, fn () => $this->adapterFor($connection)->pushInventory($batch));
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Başarısız çağrı istisna fırlatmalı.');

        $adapter = $this->asTenant($tenant, fn () => $this->adapterFor($connection));

        $this->assertSame(ErrorClass::RATE_LIMITED, $adapter->classifyError($caught));
    }

    /**
     * Uzak stok okuma — mutabakatın girdisi.
     */
    #[Test]
    public function fetch_inventory_reads_remote_quantities(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        Http::fake([
            '*' => Http::response([
                ['id' => 101, 'stock_quantity' => 4],
                ['id' => 202, 'stock_quantity' => 0],
            ], 200),
        ]);

        $listings = $this->asTenant($tenant, fn () => [
            $this->listingWithExternalId($connection, '101'),
            $this->listingWithExternalId($connection, '202'),
        ]);

        $snapshot = $this->asTenant($tenant, fn () => $this->adapterFor($connection)->fetchInventory($listings));

        $this->assertSame(4, $snapshot->quantityFor('101'));
        $this->assertSame(0, $snapshot->quantityFor('202'));
        $this->assertNull($snapshot->quantityFor('999'));
        $this->assertNotNull($snapshot->observedAt, 'Okuma anı taşınmalı — gecikme sürüklenme sanılmasın.');
    }

    /**
     * KİMLİK BİLGİSİ KİRACI BAĞLAMI OLMADAN DA GÖNDERİLİR.
     *
     * `channel_credentials` kiracıya göre kapsanır ve `ChannelHttpClient`
     * bağlam OLMADAN çağrılabilir: kiracı bağlamını kurmayan bir kuyruk işi,
     * `runAsSystem` ile koşan bir tarama (seviye 2 kurtarma, mutabakat) veya
     * panelden tetiklenen sağlık kontrolü. Kapsanmış sorgu o durumda istisna
     * fırlatır ve istemci onu yutup isteği SESSİZCE KİMLİKSİZ gönderirdi.
     *
     * Bedeli en pahalı hata biçimidir: Woo 401 döner, adapter bunu
     * AUTHENTICATION diye sınıflandırır, `RetryPolicy` KALICI hata sayar ve
     * listing "anahtarın yanlış" diyerek ölür — oysa anahtar doğrudur ve
     * yalnızca hiç gönderilmemiştir. Kullanıcı anahtarı defalarca yeniden
     * girer, hiçbiri işe yaramaz.
     *
     * Bu test bağlamı BİLEREK bırakır. `verifyWebhookSignature` aynı
     * gerekçeyle aynı biçimi zaten kullanıyordu (§13 · faz 1.4'te bulundu);
     * bu, aynı boşluğun istek yolundaki hâliydi.
     */
    #[Test]
    public function credentials_are_sent_even_without_tenant_context(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        $this->asTenant($tenant, fn () => app(CredentialVault::class)->store($connection, [
            'consumer_key' => 'ck_1234567890',
            'consumer_secret' => 'cs_1234567890',
        ]));

        Http::fake(['*' => Http::response(['environment' => []], 200)]);

        // Çağrı bağlam DIŞINDA yapılır: gerçek kuyruk işinin hâli bu.
        $this->assertFalse(
            TenantContext::hasTenant(),
            'Test kurgusu gereği bağlam bırakılmış olmalı.',
        );

        $this->adapterFor($connection)->healthCheck();

        Http::assertSent(function (Request $request): bool {
            $sent = $request->header('Authorization')[0] ?? '';

            return $sent === 'Basic '.base64_encode('ck_1234567890:cs_1234567890');
        });
    }

    /**
     * HMAC HAM gövde üzerinden doğrulanır.
     *
     * Ayrıştırıp yeniden serileştirmek baytları değiştirir ve imza tutmaz.
     * Doğrulanmamış webhook = sahte sipariş enjeksiyonu.
     */
    #[Test]
    public function webhook_signature_is_verified_over_the_raw_body(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        $secret = 'wh_secret_1234567890';

        $this->asTenant($tenant, fn () => app(CredentialVault::class)->store($connection, [
            'consumer_key' => 'ck_1234567890',
            'consumer_secret' => 'cs_1234567890',
            'webhook_secret' => $secret,
        ]));

        $adapter = $this->asTenant($tenant, fn () => $this->adapterFor($connection));

        // Woo imzayı ham gövdenin base64(HMAC-SHA256) hali olarak gönderir.
        $raw = '{"id":1234,"status":"processing","line_items":[{"sku":"SKU-A","quantity":1}]}';
        $valid = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        $this->assertTrue(
            $adapter->verifyWebhookSignature($raw, ['x-wc-webhook-signature' => [$valid]]),
        );

        // Tek bayt değişince imza tutmaz.
        $this->assertFalse(
            $adapter->verifyWebhookSignature($raw.' ', ['x-wc-webhook-signature' => [$valid]]),
            'Yeniden serileştirme baytları değiştirir; imza TUTMAMALI.',
        );

        $this->assertFalse(
            $adapter->verifyWebhookSignature($raw, ['x-wc-webhook-signature' => ['sahte']]),
        );

        // İmza başlığı hiç yoksa reddedilir — muafiyet yoktur.
        $this->assertFalse($adapter->verifyWebhookSignature($raw, []));
    }

    /** Olay kimliği X-WC-Webhook-Delivery-ID — inbox tekilleştirmesinin çıpası. */
    #[Test]
    public function event_id_comes_from_the_delivery_header(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        $adapter = $this->asTenant($tenant, fn () => $this->adapterFor($connection));

        $this->assertSame(
            'dlv-99',
            $adapter->extractEventId(['x-wc-webhook-delivery-id' => ['dlv-99']]),
        );

        // Kimlik yoksa null döner ve inbox hash yoluna düşer (son çare).
        $this->assertNull($adapter->extractEventId([]));

        $this->assertSame(
            'order.updated',
            $adapter->extractEventType([
                'x-wc-webhook-topic' => ['order.updated'],
            ]),
        );
    }

    /**
     * Yetenekler TİP SİSTEMİNDE okunur — panelde kanal adı kontrol edilmez.
     *
     * Woo'da taksonomi ve onay süreci yoktur; kategori serbesttir.
     */
    #[Test]
    public function capabilities_are_declared_through_interfaces(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        $adapter = $this->asTenant($tenant, fn () => $this->adapterFor($connection));

        $this->assertInstanceOf(ChannelAdapter::class, $adapter);
        $this->assertInstanceOf(SupportsInventory::class, $adapter);
        $this->assertInstanceOf(SupportsCatalog::class, $adapter);
        $this->assertInstanceOf(SupportsOrders::class, $adapter);

        // Woo'da YOK.
        $this->assertNotInstanceOf(SupportsTaxonomy::class, $adapter);

        $capabilities = $this->asTenant($tenant, fn () => app(AdapterRegistry::class)
            ->capabilitiesOf($adapter));

        $this->assertTrue($capabilities['inventory']);
        $this->assertTrue($capabilities['orders']);
        $this->assertFalse($capabilities['taxonomy']);
        $this->assertFalse($capabilities['approval']);
    }

    /** wc/v3 batch sınırı 100 — gruplama bu sayıya uyar. */
    #[Test]
    public function batch_size_matches_the_wc_v3_endpoint_limit(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        $this->assertSame(
            100,
            $this->asTenant($tenant, fn () => $this->adapterFor($connection))->maxInventoryBatchSize(),
        );
    }

    /**
     * Registry gerçek adapter'ı üretir ve HER ÇAĞRIDA YENİ örnek verir.
     *
     * Paylaşılan örnek, aynı worker sürecinde kiracı A'nın kimlik bilgisini
     * kiracı B'nin işinde kullanır (P0 güvenlik).
     */
    #[Test]
    public function registry_builds_a_fresh_adapter_with_a_real_http_client(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        [$first, $second] = $this->asTenant($tenant, fn (): array => [
            app(AdapterRegistry::class)->for($connection),
            app(AdapterRegistry::class)->for($connection),
        ]);

        $this->assertInstanceOf(WooCommerceAdapter::class, $first);
        $this->assertNotSame($first, $second, 'Aynı bağlantı için bile paylaşılmamalı.');
        $this->assertSame($connection->id, $first->connection()->id);
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function makeConnection(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Woo '.uniqid(),
            owner: User::factory()->create(),
        );

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'store',
                'adapter_class' => WooCommerceAdapter::class,
                'is_active' => true,
                'rate_limit_profile' => ['requests_per_second' => 5, 'burst_capacity' => 10],
            ],
        ));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'channel_type_code' => 'woocommerce',
            'external_account_id' => 'shop.example.com',
            'settings' => ['base_url' => 'https://shop.example.com/wp-json/wc/v3/'],
        ]));

        return [$tenant, $connection];
    }

    private function adapterFor(ChannelConnection $connection): WooCommerceAdapter
    {
        return new WooCommerceAdapter(
            connection: $connection,
            client: new ChannelHttpClient(
                connection: $connection,
                vault: app(CredentialVault::class),
                redactor: app(PayloadRedactor::class),
            ),
        );
    }

    private function listingWithExternalId(
        ChannelConnection $connection,
        string $externalId,
    ): Listing {
        return Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'external_id' => $externalId,
        ]);
    }

    /** Woo'nun verdiği HTTP durum koduyla bir istek istisnası üretir. */
    private function requestExceptionWith(int $status): RequestException
    {
        $response = new \Illuminate\Http\Client\Response(
            new Response($status, [], json_encode(['message' => "status {$status}"]))
        );

        return new RequestException($response);
    }
}
