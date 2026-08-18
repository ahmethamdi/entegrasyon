<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Support\PollChannelOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sipariş yoklama turu — webhook'suz kanaldan sipariş alma.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 ("Sipariş yoklaması"),
 * §6 · Inbox, §14 · Trendyol, §1 · Karar 24.
 *
 * DEĞİŞMEZ KURAL — YOKLAMA WEBHOOK'LA AYNI İNBOX'A YAZAR:
 *   `IngestInboxMessage` tek gelen hattır ve `source` alanı ayrımı taşır
 *   (`webhook` | `polling`). İkinci bir yol açılsaydı tekilleştirme iki
 *   kez yazılır, biri unutulurdu; `inbox:recover` taraması da iki yeri
 *   bilmek zorunda kalırdı.
 *
 * DEĞİŞMEZ KURAL — YALNIZCA YENİ SATIR KUYRUĞA GİRER:
 *   Webhook yolundaki `wasRecentlyCreated` kontrolünün aynısı. Her turda
 *   yeniden kuyruğa atılsaydı aynı sipariş defalarca işlenir ve
 *   `ProcessInboxMessage`'ın koşullu geçişi olmasa stok tekrar tekrar
 *   düşerdi.
 */
final class PollChannelOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tur iş atar; gerçek worker asenkrondur ve `sync` sürücü onu
        // taklit etmez (bağlam temizliği çağıranı da vururdu).
        Queue::fake();
    }

    /**
     * ÇEKİLEN SİPARİŞ İNBOX'A `polling` KAYNAĞIYLA YAZILIR.
     *
     * Kaynak ayrımı iz sürmek için gerekli: bir sipariş kaybolduğunda
     * "webhook mu gelmedi, tur mu görmedi" sorusu ancak bu alanla
     * cevaplanır.
     */
    #[Test]
    public function polled_orders_land_in_the_inbox_as_polling_source(): void
    {
        [$tenant, $connection] = $this->setUpConnection();

        Http::fake(['*' => Http::response([
            'content' => [
                ['orderNumber' => 'TY-1', 'status' => 'Created', 'lines' => []],
                ['orderNumber' => 'TY-2', 'status' => 'Created', 'lines' => []],
            ],
            'totalPages' => 1,
        ], 200)]);

        $count = app(PollChannelOrders::class)->run();

        $this->assertSame(2, $count);

        $messages = $this->asTenant($tenant, fn () => InboxMessage::query()
            ->orderBy('external_event_id')
            ->get());

        $this->assertCount(2, $messages);
        $this->assertSame('polling', $messages[0]->source);
        $this->assertSame('TY-1:Created', $messages[0]->external_event_id);
    }

    /**
     * YENİ SATIR KUYRUĞA GİRER.
     *
     * Yazmak yetmez: kuyruğa atılmazsa sipariş inbox'ta `pending` kalır ve
     * yalnızca dakikalık `inbox:recover` taraması onu kurtarır — yani stok
     * bir dakikaya kadar geç düşer.
     */
    #[Test]
    public function newly_ingested_messages_are_queued_for_processing(): void
    {
        $this->setUpConnection();

        Http::fake(['*' => Http::response([
            'content' => [['orderNumber' => 'TY-1', 'status' => 'Created', 'lines' => []]],
            'totalPages' => 1,
        ], 200)]);

        app(PollChannelOrders::class)->run();

        Queue::assertPushed(ProcessInboxMessage::class, 1);
    }

    /**
     * AYNI SİPARİŞ İKİNCİ TURDA YENİDEN KUYRUĞA GİRMEZ.
     *
     * Yoklama doğası gereği aynı siparişi tekrar tekrar görür (pencere
     * geriye bakar). Tekilleştirme olmasaydı her tur aynı siparişi yeniden
     * işler ve `ProcessInboxMessage`'ın koşullu geçişi son savunma
     * olarak kalırdı — savunmayı tek katmana indirmek yanlıştır.
     */
    #[Test]
    public function the_same_order_is_not_queued_twice_across_rounds(): void
    {
        [$tenant] = $this->setUpConnection();

        Http::fake(['*' => Http::response([
            'content' => [['orderNumber' => 'TY-1', 'status' => 'Created', 'lines' => []]],
            'totalPages' => 1,
        ], 200)]);

        app(PollChannelOrders::class)->run();
        app(PollChannelOrders::class)->run();

        // Tek satır, tek iş.
        $rows = $this->asTenant($tenant, fn (): int => InboxMessage::query()->count());

        $this->assertSame(1, $rows, 'Aynı sipariş ikinci satır AÇMAZ.');

        Queue::assertPushed(ProcessInboxMessage::class, 1);
    }

    /**
     * DURUM DEĞİŞİMİ AYRI SATIR AÇAR — İPTAL YUTULMAZ.
     *
     * Bu testin koruduğu şey Karar 24'ün özüdür: kimlik yalnızca sipariş
     * numarasına bağlansaydı, iptal birincil tekillik indeksine takılır ve
     * `insertOrIgnore` tarafından SESSİZCE YUTULURDU. Stok geri eklenmez
     * ve bakiye kalıcı olarak eksik kalırdı.
     */
    #[Test]
    public function a_status_change_creates_a_separate_inbox_row(): void
    {
        [$tenant] = $this->setUpConnection();

        Http::fake(['*' => Http::sequence()
            ->push(['content' => [
                ['orderNumber' => 'TY-1', 'status' => 'Created', 'lines' => []],
            ], 'totalPages' => 1], 200)
            ->push(['content' => [
                ['orderNumber' => 'TY-1', 'status' => 'Cancelled', 'lines' => []],
            ], 'totalPages' => 1], 200),
        ]);

        app(PollChannelOrders::class)->run();
        app(PollChannelOrders::class)->run();

        $ids = $this->asTenant($tenant, fn (): array => InboxMessage::query()
            ->orderBy('external_event_id')
            ->pluck('external_event_id')
            ->all());

        $this->assertSame(['TY-1:Cancelled', 'TY-1:Created'], $ids);

        // İKİ iş: iptal de işlenmeli.
        Queue::assertPushed(ProcessInboxMessage::class, 2);
    }

    /**
     * PENCERE İMLECİ BAĞLANTIYA YAZILIR VE SÜREÇLE ÖLMEZ.
     *
     * İmleç saklanmasaydı her tur baştan tarar; sabit bir başlangıç ise
     * katalog büyüdükçe her turda binlerce siparişi yeniden çeker ve
     * kotayı tüketirdi.
     */
    #[Test]
    public function the_polling_cursor_is_persisted_on_the_connection(): void
    {
        [$tenant, $connection] = $this->setUpConnection();

        Http::fake(['*' => Http::response([
            'content' => [['orderNumber' => 'TY-1', 'status' => 'Created', 'lines' => []]],
            'totalPages' => 1,
        ], 200)]);

        app(PollChannelOrders::class)->run();

        // HAM SATIR okunur: Eloquent kimlik haritası bellekteki nesneyi
        // geri verir ve `save()` silinse bile test geçerdi.
        $settings = $this->asSystem(fn () => json_decode(
            (string) DB::table('channel_connections')->where('id', $connection->id)->value('settings'),
            true,
        ));

        $this->assertArrayHasKey('orders_polled_through', $settings);
        $this->assertNotEmpty($settings['orders_polled_through']);
    }

    /**
     * PENCERE GERİYE BAKAR — SINIRDA YAZILAN SİPARİŞ KAYBOLMAZ.
     *
     * İmleç son turun bitiş anına eşit yazılsaydı, istek sürerken kanalda
     * oluşan sipariş iki pencerenin ARASINA düşer ve HİÇ görülmezdi.
     * Örtüşme kasıtlıdır; tekilleştirme zaten kopyayı eler.
     */
    #[Test]
    public function the_window_overlaps_so_boundary_orders_are_not_lost(): void
    {
        [, $connection] = $this->setUpConnection();

        $through = now()->subMinutes(10);

        $this->asSystem(fn () => DB::table('channel_connections')
            ->where('id', $connection->id)
            ->update(['settings' => json_encode([
                ...$connection->settings,
                'orders_polled_through' => $through->toIso8601String(),
            ])]));

        Http::fake(['*' => Http::response(['content' => [], 'totalPages' => 1], 200)]);

        app(PollChannelOrders::class)->run();

        Http::assertSent(function ($request) use ($through): bool {
            $startedAt = (int) $request->data()['startDate'];

            // İmleçten GERİDE olmak yetmez — imlecin KENDİSİ zaten
            // geçmişte, yani örtüşme silinse de "geride" kalırdı. Asıl
            // iddia: aradaki fark gerçek bir örtüşme kadar OLMALI.
            $gapMinutes = ($through->getTimestampMs() - $startedAt) / 60000;

            return $gapMinutes >= 1;
        });
    }

    /**
     * TEK BOZUK BAĞLANTI TURU DURDURMAZ.
     *
     * Taksonomide birebir aynı hata yaşandı: ilk bağlantıda pes edilseydi
     * o kanaldaki tüm satıcılar siparişsiz kalır ve sorun kendi
     * bağlantılarında olmadığı için hiçbiri düzeltemezdi.
     */
    #[Test]
    public function one_broken_connection_does_not_stop_the_round(): void
    {
        [$tenantA, $connectionA] = $this->setUpConnection(supplierId: '111');
        [$tenantB] = $this->setUpConnection(supplierId: '222');

        Http::fake([
            '*/suppliers/111/*' => Http::response(['errors' => []], 500),
            '*/suppliers/222/*' => Http::response([
                'content' => [['orderNumber' => 'TY-9', 'status' => 'Created', 'lines' => []]],
                'totalPages' => 1,
            ], 200),
        ]);

        $count = app(PollChannelOrders::class)->run();

        // Sağlam bağlantının siparişi ALINDI.
        $this->assertSame(1, $count);

        $rows = $this->asTenant($tenantB, fn (): int => InboxMessage::query()->count());
        $this->assertSame(1, $rows);
    }

    /**
     * BOZUK BAĞLANTININ İMLECİ İLERLEMEZ.
     *
     * İlerleseydi hata anındaki pencere sonsuza kadar atlanır ve o
     * penceredeki siparişler bir daha HİÇ sorulmazdı — sessiz sipariş
     * kaybı.
     */
    #[Test]
    public function a_failed_connection_does_not_advance_its_cursor(): void
    {
        [, $connection] = $this->setUpConnection(supplierId: '111');

        Http::fake(['*' => Http::response(['errors' => []], 500)]);

        app(PollChannelOrders::class)->run();

        $settings = $this->asSystem(fn () => json_decode(
            (string) DB::table('channel_connections')->where('id', $connection->id)->value('settings'),
            true,
        ));

        $this->assertArrayNotHasKey(
            'orders_polled_through',
            $settings,
            'Başarısız turda imleç yazılmamalı.',
        );
    }

    /**
     * SAĞLIKSIZ BAĞLANTI YOKLANMAZ.
     *
     * `active` olmayan bağlantıya iş atılmaz; kimlik bilgisi geçersizken
     * her turda 401 yemek kotayı tüketir ve devre kesiciyi açar.
     */
    #[Test]
    public function inactive_connections_are_skipped(): void
    {
        [, $connection] = $this->setUpConnection();

        $this->asSystem(fn () => DB::table('channel_connections')
            ->where('id', $connection->id)
            ->update(['status' => 'pending']));

        Http::fake();

        $count = app(PollChannelOrders::class)->run();

        $this->assertSame(0, $count);

        Http::assertNothingSent();
    }

    /**
     * WEBHOOK GÖNDEREN KANAL YOKLANMAZ.
     *
     * Woo webhook gönderir; aynı sipariş iki yoldan gelirdi. Tekilleştirme
     * kopyayı elese bile İSTEK zaten yapılmış olur ve kota boşuna harcanır
     * — üstelik her turda, her Woo mağazası için.
     *
     * Gerçek çalıştırmada doğrulandı (`orders:poll` Woo bağlantısına hiç
     * dokunmadı); bu test o davranışı kalıcı kılar.
     */
    #[Test]
    public function connections_that_deliver_webhooks_are_not_polled(): void
    {
        [$tenant] = $this->setUpConnection();

        // Kanal türü webhook gönderiyor olsun.
        $this->asSystem(fn () => DB::table('channel_types')
            ->where('code', 'trendyol')
            ->update(['supports_webhooks' => true]));

        Http::fake();

        $count = app(PollChannelOrders::class)->run();

        $this->assertSame(0, $count);

        Http::assertNothingSent();
    }

    /**
     * SAYFALAR SONUNA KADAR ÇEKİLİR.
     *
     * İlk sayfayla yetinilseydi yoğun satıcının siparişlerinin çoğu her
     * turda görülmez ve hiç işlenmezdi.
     */
    #[Test]
    public function all_pages_are_drained(): void
    {
        [$tenant] = $this->setUpConnection();

        Http::fake(['*' => Http::sequence()
            ->push(['content' => [
                ['orderNumber' => 'TY-1', 'status' => 'Created', 'lines' => []],
            ], 'page' => 0, 'totalPages' => 2], 200)
            ->push(['content' => [
                ['orderNumber' => 'TY-2', 'status' => 'Created', 'lines' => []],
            ], 'page' => 1, 'totalPages' => 2], 200),
        ]);

        $count = app(PollChannelOrders::class)->run();

        $this->assertSame(2, $count);

        $rows = $this->asTenant($tenant, fn (): int => InboxMessage::query()->count());
        $this->assertSame(2, $rows);
    }

    // ──────────────────────────────────────────────────────── yardımcı

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function setUpConnection(string $supplierId = '123456'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Yoklama '.uniqid(), owner: $user);

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'trendyol'],
            [
                'name' => 'Trendyol',
                'kind' => 'marketplace',
                'adapter_class' => TrendyolAdapter::class,
                'capabilities' => [
                    'catalog' => true, 'inventory' => true, 'pricing' => true,
                    'orders' => true, 'taxonomy' => true, 'approval' => true,
                    'fulfillment' => false,
                ],
                'rate_limit_profile' => ['requests_per_second' => 5, 'burst_capacity' => 10],
                'supports_webhooks' => false,
                'is_active' => true,
            ],
        ));

        $connection = $this->asTenant($tenant, function () use ($supplierId): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'trendyol',
                'external_account_id' => $supplierId,
                'status' => 'active',
                'settings' => [
                    'base_url' => 'https://api.trendyol.com/sapigw',
                    'supplier_id' => $supplierId,
                ],
            ]);

            app(CredentialVault::class)->store($connection, [
                'api_key' => 'anahtar',
                'api_secret' => 'sifre',
            ]);

            return $connection;
        });

        return [$tenant, $connection];
    }
}
