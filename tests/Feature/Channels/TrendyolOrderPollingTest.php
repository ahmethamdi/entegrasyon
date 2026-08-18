<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\InboxMessage;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Trendyol sipariş yoklaması — §13 · Faz 2'nin SON maddesi.
 *
 * Mimari Karar Dokümanı v2.2 · §14 · Trendyol, §7 · SupportsOrders,
 * §6 · Inbox, §1 · Karar 24.
 *
 * DEĞİŞMEZ KURAL — WEBHOOK YOK, YOKLAMA VAR:
 *   Trendyol webhook göndermez. Sipariş turla çekilir ve **webhook'la aynı
 *   inbox'a** yazılır (`source = 'polling'`). İki ayrı yol olsaydı
 *   tekilleştirme iki kez yazılır, biri unutulurdu.
 *
 * DEĞİŞMEZ KURAL — OLAY KİMLİĞİ SİPARİŞ NUMARASI + DURUMDUR:
 *   Kanal bir teslim kimliği vermez. Kimlik yalnızca sipariş numarasına
 *   bağlansaydı, aynı siparişin sonraki İPTALİ birincil tekillik indeksine
 *   (`channel_connection_id, external_event_id`) takılır ve
 *   `insertOrIgnore` tarafından SESSİZCE YUTULURDU — stok geri eklenmez ve
 *   bakiye kalıcı olarak eksik kalırdı. Karar 24'ün açıkça uyardığı hata
 *   biçimi budur.
 *
 * DEĞİŞMEZ KURAL — HAM GÖVDE ÖNCE YAZILIR:
 *   Ayrıştırma sonra yapılır. Ayrıştırma hatası siparişin kaybolmasına
 *   değil, inbox satırının hata durumuna düşmesine yol açar.
 */
final class TrendyolOrderPollingTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────── sipariş çekme

    /**
     * YOKLAMA TARİH PENCERESİYLE VE SATICI YOLUYLA GİDER.
     *
     * Trendyol tarihi milisaniye epoch bekler; saniye gönderilseydi pencere
     * 1970'e düşer ve kanal TÜM sipariş geçmişini döndürürdü.
     */
    #[Test]
    public function orders_are_fetched_with_a_millisecond_date_window(): void
    {
        Http::fake(['*' => Http::response(['content' => [], 'totalPages' => 1], 200)]);

        $since = Carbon::parse('2026-08-18 10:00:00');

        $this->adapter()->fetchOrders($since);

        Http::assertSent(function (Request $request) use ($since): bool {
            $query = $request->data();

            return str_contains($request->url(), '/suppliers/123456/orders')
                && (int) $query['startDate'] === $since->getTimestampMs();
        });
    }

    /**
     * HAM SİPARİŞ GÖVDESİ OLDUĞU GİBİ DÖNER.
     *
     * `OrderPage` ham gövde taşır ve doğrudan inbox'a yazılır; ayrıştırma
     * `parseOrderEvent` ile SONRA yapılır.
     */
    #[Test]
    public function fetched_orders_carry_the_raw_channel_body(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [
                ['orderNumber' => 'TY-1', 'status' => 'Created'],
                ['orderNumber' => 'TY-2', 'status' => 'Shipped'],
            ],
            'totalPages' => 1,
        ], 200)]);

        $page = $this->adapter()->fetchOrders(Carbon::parse('2026-08-18 10:00:00'));

        $this->assertSame(2, $page->count());
        $this->assertSame('TY-1', $page->orders[0]['orderNumber']);
        $this->assertFalse($page->hasMore, 'Tek sayfada devam yok.');
    }

    /**
     * SAYFA VARSA DEVAM İMLECİ TAŞINIR.
     *
     * Tek sayfayla yetinilseydi yoğun bir satıcının siparişlerinin çoğu her
     * turda GÖRÜLMEZ ve hiç işlenmezdi.
     */
    #[Test]
    public function pagination_is_reported_through_the_cursor(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['orderNumber' => 'TY-1', 'status' => 'Created']],
            'page' => 0,
            'totalPages' => 3,
        ], 200)]);

        $page = $this->adapter()->fetchOrders(Carbon::parse('2026-08-18 10:00:00'));

        $this->assertTrue($page->hasMore);
        $this->assertSame('1', $page->nextCursor, 'Sonraki sayfa numarası taşınmalı.');
    }

    /** İmleç verildiğinde o sayfa istenir. */
    #[Test]
    public function cursor_is_sent_as_the_page_number(): void
    {
        Http::fake(['*' => Http::response(['content' => [], 'totalPages' => 3], 200)]);

        $this->adapter()->fetchOrders(Carbon::parse('2026-08-18 10:00:00'), cursor: '2');

        Http::assertSent(fn (Request $request): bool => (int) $request->data()['page'] === 2);
    }

    /**
     * BAŞARISIZ YANIT SESSİZCE BOŞ SAYFAYA DÖNÜŞMEZ.
     *
     * `json()` bir 500 gövdesinde de dizi döndürür. Boş sayfa "yeni sipariş
     * yok" diye okunur, imleç ilerletilir ve o penceredeki siparişler bir
     * daha HİÇ sorulmazdı — sipariş kaybı en pahalı hata biçimidir.
     */
    #[Test]
    public function failed_fetch_raises_instead_of_returning_an_empty_page(): void
    {
        Http::fake(['*' => Http::response(['errors' => []], 500)]);

        $this->expectException(RequestException::class);

        $this->adapter()->fetchOrders(Carbon::parse('2026-08-18 10:00:00'));
    }

    // ───────────────────────────────────────────────────── normalizasyon

    /** Yeni sipariş `created` yoluna gider ve kalemler kanonikleşir. */
    #[Test]
    public function a_created_order_is_normalized_with_its_lines(): void
    {
        $event = $this->adapter()->parseOrderEvent($this->inboxMessage([
            'orderNumber' => 'TY-1',
            'status' => 'Created',
            'grossAmount' => 250.0,
            'totalPrice' => 240.0,
            'currencyCode' => 'TRY',
            'lines' => [
                [
                    'id' => 9001,
                    'barcode' => 'BARKOD-A',
                    'productName' => 'Tişört',
                    'quantity' => 2,
                    'amount' => 120.0,
                ],
            ],
        ]));

        $this->assertNotNull($event);
        $this->assertSame('created', $event->type);
        $this->assertSame('TY-1', $event->externalOrderId);

        $line = $event->payload['lines'][0];

        // SKU BARKODDUR: eşleştirme barkod üzerinden yapılır.
        $this->assertSame('BARKOD-A', $line['sku']);
        $this->assertSame(2, $line['quantity']);
    }

    /**
     * İPTAL AYRI YOLA GİDER — `created` SANILMAZ.
     *
     * Tek yola sokulsaydı iptal, siparişin yeniden yaratılması gibi
     * işlenir ve stok İKİ KEZ düşerdi (Karar 24).
     */
    #[Test]
    public function a_cancelled_order_is_normalized_as_a_cancellation(): void
    {
        $event = $this->adapter()->parseOrderEvent($this->inboxMessage([
            'orderNumber' => 'TY-1',
            'status' => 'Cancelled',
            'lines' => [['id' => 9001, 'barcode' => 'BARKOD-A', 'quantity' => 1]],
        ]));

        $this->assertNotNull($event);
        $this->assertSame('cancelled', $event->type);
    }

    /** İade durumu `returned` yoluna gider — stok GERİ EKLENİR. */
    #[Test]
    public function a_returned_order_is_normalized_as_a_return(): void
    {
        $event = $this->adapter()->parseOrderEvent($this->inboxMessage([
            'orderNumber' => 'TY-1',
            'status' => 'Returned',
            'lines' => [['id' => 9001, 'barcode' => 'BARKOD-A', 'quantity' => 1]],
        ]));

        $this->assertNotNull($event);
        $this->assertSame('returned', $event->type);
    }

    /**
     * OLAY ÇIPASI DURUMU TAŞIR.
     *
     * `externalRef` stok hareketi idempotency anahtarının çıpasıdır.
     * Yalnızca sipariş numarasına bağlansaydı aynı siparişin iptali ve
     * iadesi `order_events` üzerinde ÇAKIŞIR ve ikincisi sessizce
     * yutulurdu.
     */
    #[Test]
    public function the_event_ref_distinguishes_status_changes_of_one_order(): void
    {
        // TEK adapter: `(channel_type_code, external_account_id)` GLOBAL
        // tekildir ve ikinci bağlantı kısıtı ihlal ederdi.
        $adapter = $this->adapter();

        $cancelled = $adapter->parseOrderEvent($this->inboxMessage([
            'orderNumber' => 'TY-1', 'status' => 'Cancelled', 'lines' => [],
        ]));

        $returned = $adapter->parseOrderEvent($this->inboxMessage([
            'orderNumber' => 'TY-1', 'status' => 'Returned', 'lines' => [],
        ], externalEventId: 'TY-1:Returned'));

        $this->assertNotSame(
            $cancelled->externalRef,
            $returned->externalRef,
            'Aynı siparişin iki farklı olayı AYNI çıpayı taşıyamaz.',
        );
    }

    /**
     * SİPARİŞ NUMARASI YOKSA NULL DÖNER — UYDURULMAZ.
     *
     * Kimliksiz gövdeden sipariş yaratılamaz; satır hata durumuna düşer ve
     * elle incelenir. Sessizce yutulsaydı kayıp fark edilmezdi.
     */
    #[Test]
    public function a_body_without_an_order_number_is_not_an_order_event(): void
    {
        $event = $this->adapter()->parseOrderEvent($this->inboxMessage([
            'status' => 'Created',
        ]));

        $this->assertNull($event);
    }

    /**
     * BİLİNMEYEN DURUM `updated` SAYILIR — stok hareketi ÜRETMEZ.
     *
     * Trendyol durum listesini genişletebilir. Bilinmeyen bir durumu
     * `created` saymak var olan siparişi yeniden yaratmayı denerdi;
     * `cancelled` saymak ise satılmış stoğu geri eklerdi. İkisi de
     * bakiyeyi bozar — `updated` güvenli olanıdır.
     */
    #[Test]
    public function an_unknown_status_falls_back_to_update(): void
    {
        $event = $this->adapter()->parseOrderEvent($this->inboxMessage([
            'orderNumber' => 'TY-1',
            'status' => 'AwaitingSomethingNew',
            'lines' => [],
        ]));

        $this->assertNotNull($event);
        $this->assertSame('updated', $event->type);
    }

    // ──────────────────────────────────────────────────────── yardımcı

    /** @param array<string, mixed> $payload */
    private function inboxMessage(array $payload, ?string $externalEventId = null): InboxMessage
    {
        $message = new InboxMessage;
        $message->payload = $payload;
        $message->event_type = 'order.polled';
        $message->external_event_id = $externalEventId
            ?? (isset($payload['orderNumber'])
                ? $payload['orderNumber'].':'.($payload['status'] ?? '')
                : null);

        return $message;
    }

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Yoklama '.uniqid(), owner: $user);

        return [$tenant, $user];
    }

    private function channelType(): ChannelType
    {
        return $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
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
    }

    private function connection(string $supplierId = '123456'): ChannelConnection
    {
        $this->channelType();

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
    }

    private function adapter(): TrendyolAdapter
    {
        [$tenant] = $this->makeTenant();

        return $this->asTenant($tenant, function (): TrendyolAdapter {
            $connection = $this->connection();

            return new TrendyolAdapter(
                $connection,
                new ChannelHttpClient(
                    $connection,
                    app(CredentialVault::class),
                    app(PayloadRedactor::class),
                ),
            );
        });
    }
}
