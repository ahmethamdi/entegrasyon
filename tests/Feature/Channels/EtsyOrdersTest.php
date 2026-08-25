<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Support\PollChannelOrders;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Etsy sipariş yoklaması — slice 3.7.
 *
 * V3.0 · §11.4 (receipt / transaction, YOKLAMA).
 *
 * ═════════════════════════════════════════════════════════════════════
 * ⚠️ ETSY WEBHOOK SUNMAZ — SİPARİŞ YOKLAMAYLA GELİR
 * ═════════════════════════════════════════════════════════════════════
 * Trendyol kalıbı birebir geçerlidir. `supports_webhooks = false`
 * olmasaydı yoklama turu bu kanalı ATLAR ve siparişler HİÇ GELMEZDİ.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ OLAY KİMLİĞİ `{receipt_id}:{status}` — P0 (§11.4)
 * ─────────────────────────────────────────────────────────────────────
 * Yalnızca `receipt_id`'ye bağlansaydı aynı siparişin sonraki İPTALİ
 * birincil tekillik indeksine takılır ve `insertOrIgnore` tarafından
 * SESSİZCE YUTULURDU — stok geri eklenmez, bakiye kalıcı eksik kalırdı.
 * Karar 24'ün açıkça uyardığı hata biçimi budur.
 */
final class EtsyOrdersTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tur iş atar; gerçek worker asenkrondur ve `sync` sürücü onu
        // taklit etmez.
        Queue::fake();
    }

    // ═══════════════════════════════ P0 · olay kimliği {receipt_id}:{status}

    /**
     * ⚠️ EN ÖNEMLİ TEST: AYNI SİPARİŞİN İPTALİ YUTULMAZ.
     *
     * Aynı `receipt_id` önce `paid`, sonra `canceled` durumuyla gelir.
     * Kimlik yalnızca `receipt_id`'ye bağlansaydı ikinci mesaj birincil
     * tekillik indeksine takılır ve `insertOrIgnore` onu SESSİZCE
     * YUTARDI: iptal hiç işlenmez, satılmış stok geri EKLENMEZ ve
     * bakiye kalıcı olarak eksik kalırdı.
     */
    #[Test]
    public function a_cancellation_of_the_same_receipt_is_not_swallowed(): void
    {
        [$tenant, $connection] = $this->connected();

        Http::fake(['*' => Http::sequence()
            ->push(['results' => [$this->receipt('9001', 'paid')], 'count' => 1], 200)
            ->push(['results' => [$this->receipt('9001', 'canceled')], 'count' => 1], 200)]);

        app(PollChannelOrders::class)->run();
        app(PollChannelOrders::class)->run();

        $ids = $this->asTenant($tenant, fn (): array => InboxMessage::query()
            ->orderBy('external_event_id')
            ->pluck('external_event_id')
            ->all());

        $this->assertSame(
            ['9001:canceled', '9001:paid'],
            $ids,
            'İptal YUTULDU — kimlik duruma bağlanmamış demektir; stok geri '
            .'eklenmez ve bakiye kalıcı eksik kalır (§11.4 · Karar 24).',
        );
    }

    /** Olay kimliği `{receipt_id}:{status}` biçimindedir — §11.4 birebir. */
    #[Test]
    public function the_event_id_is_receipt_id_and_status(): void
    {
        [$tenant, $connection] = $this->connected();

        $this->fakeReceipts([$this->receipt('9001', 'paid')]);

        app(PollChannelOrders::class)->run();

        $message = $this->asTenant($tenant, fn (): InboxMessage => InboxMessage::query()->firstOrFail());

        $this->assertSame('9001:paid', $message->external_event_id);
    }

    /**
     * ⚠️ AYNI DURUMDAKİ TEKRAR İKİNCİ SATIR AÇMAZ.
     *
     * Pencere GERİYE bakar (5 dk örtüşme), yani yoklama aynı siparişi
     * tur tur yeniden görür. Tekilleştirme olmasaydı her tur yeni bir
     * inbox satırı yazar ve stok tekrar tekrar düşerdi.
     */
    #[Test]
    public function the_same_receipt_in_the_same_status_is_deduplicated(): void
    {
        [$tenant, $connection] = $this->connected();

        Http::fake(['*' => Http::sequence()
            ->push(['results' => [$this->receipt('9001', 'paid')], 'count' => 1], 200)
            ->push(['results' => [$this->receipt('9001', 'paid')], 'count' => 1], 200)]);

        app(PollChannelOrders::class)->run();
        app(PollChannelOrders::class)->run();

        $this->assertSame(
            1,
            $this->asTenant($tenant, fn (): int => InboxMessage::query()->count()),
        );
    }

    // ═══════════════════════════════════════════════ yoklama turu

    /**
     * Çekilen sipariş inbox'a `polling` kaynağıyla ve `signature_valid`
     * ile yazılır.
     *
     * ⚠️ `signature_valid = true` BİR EKSİKLİK DEĞİLDİR: gövdeyi
     * kanaldan BİZ istedik ve kimlikli bir çağrıyla aldık (§11.4).
     * İmza, bize GÖNDERİLEN bir gövdenin sahiciliğini kanıtlar.
     */
    #[Test]
    public function polled_receipts_land_in_the_inbox(): void
    {
        [$tenant, $connection] = $this->connected();

        $this->fakeReceipts([$this->receipt('9001', 'paid')]);

        $ingested = app(PollChannelOrders::class)->run();

        $this->assertSame(1, $ingested);

        $message = $this->asTenant($tenant, fn (): InboxMessage => InboxMessage::query()->firstOrFail());

        $this->assertSame('polling', $message->source);
        $this->assertTrue((bool) $message->signature_valid);
    }

    /**
     * ⚠️ İSTEK MAĞAZANIN RECEIPTS UÇ NOKTASINA GİDER ve PENCERE TAŞIR.
     *
     * `min_created` gönderilmeseydi her tur TÜM sipariş geçmişini çeker
     * ve Etsy'nin GÜNLÜK kotasını (§21) tek turda yakardı.
     */
    #[Test]
    public function the_poll_asks_the_shop_receipts_endpoint_with_a_window(): void
    {
        [$tenant, $connection] = $this->connected();

        $this->fakeReceipts([]);

        app(PollChannelOrders::class)->run();

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/shops/777/receipts')
                && str_contains($request->url(), 'min_created=');
        });
    }

    /**
     * ⚠️ WEBHOOK GÖNDEREN KANAL YOKLANMAZ — kapı `supports_webhooks`.
     *
     * Etsy'de o alan `false`'tur; `true` olsaydı tur bu kanalı ATLAR ve
     * siparişler HİÇ GELMEZDİ (§11.4).
     */
    #[Test]
    public function etsy_is_polled_because_it_declares_no_webhooks(): void
    {
        [$tenant, $connection] = $this->connected();

        $this->assertFalse(
            (bool) $this->asSystem(
                fn () => ChannelType::query()->where('code', 'etsy')->value('supports_webhooks')
            ),
            'Etsy `supports_webhooks = true` ise yoklama turu onu ATLAR ve '
            .'siparişler HİÇ GELMEZ — Etsy webhook SUNMAZ (§11.4).',
        );

        $this->fakeReceipts([$this->receipt('9001', 'paid')]);

        $this->assertSame(1, app(PollChannelOrders::class)->run());
    }

    // ═══════════════════════════════════════════ normalleştirme

    /** `paid` yeni sipariş yaratır. */
    #[Test]
    public function a_paid_receipt_becomes_a_created_event(): void
    {
        $event = $this->parse($this->receipt('9001', 'paid'));

        $this->assertSame('created', $event->type);
        $this->assertSame('9001', $event->externalOrderId);
    }

    /** `canceled` iptal yoluna gider — stok GERİ EKLENİR. */
    #[Test]
    public function a_canceled_receipt_becomes_a_cancelled_event(): void
    {
        $this->assertSame('cancelled', $this->parse($this->receipt('9001', 'canceled'))->type);
    }

    /**
     * ⚠️ İADE İÇİN AYRI UÇ NOKTA YOKTUR ve bu DÜRÜST bir sınırdır.
     *
     * Satıcı iadeyi Etsy panelinden işler ve `receipt` durumu değişir;
     * yoklama bunu `updated` görür ve stok hareketi ÜRETMEZ (§11.4).
     * `returned` sayılsaydı SATILMIŞ stok geri eklenir ve bakiye
     * bozulurdu — iade panelden elle girilir.
     */
    #[Test]
    public function a_refunded_receipt_is_an_update_not_a_return(): void
    {
        $this->assertSame(
            'updated',
            $this->parse($this->receipt('9001', 'refunded'))->type,
            '`returned` sayılsaydı satılmış stok geri eklenir ve bakiye '
            .'bozulurdu; Etsy iade için ayrı uç nokta VERMİYOR (§11.4).',
        );
    }

    /**
     * ⚠️ BİLİNMEYEN DURUM `updated` SAYILIR.
     *
     * Etsy durum listesini genişletebilir. `created` saymak var olan
     * siparişi yeniden yaratmayı denerdi; `cancelled` saymak satılmış
     * stoğu geri eklerdi. İkisi de bakiyeyi bozar; `updated` stok
     * hareketi ÜRETMEZ ve güvenli olanıdır.
     */
    #[Test]
    public function an_unknown_status_is_treated_as_an_update(): void
    {
        $this->assertSame('updated', $this->parse($this->receipt('9001', 'hicboyle_yok'))->type);
    }

    /**
     * ⚠️ KİMLİKSİZ GÖVDEDEN SİPARİŞ YARATILMAZ.
     *
     * `null` dönmek satırı hata durumuna düşürür ve elle incelenir;
     * uydurma bir kimlikle sipariş yaratmak kanalda karşılığı olmayan
     * bir kayıt bırakırdı.
     */
    #[Test]
    public function a_receipt_without_an_id_is_rejected(): void
    {
        $this->assertNull($this->parse(['status' => 'paid', 'transactions' => []]));
    }

    /**
     * ⚠️ KALEMLER `transactions`'TAN GELİR ve KİMLİK `transaction_id`'DİR
     * (§11.4). SKU `transactions[].sku`'dur.
     *
     * SKU eşleşmezse `order_lines.variant_id` NULL kalır, satır PENDING
     * olur ve SİPARİŞ KAYBEDİLMEZ (Karar 24).
     */
    #[Test]
    public function order_lines_come_from_transactions(): void
    {
        $event = $this->parse($this->receipt('9001', 'paid'));

        $lines = $event->payload['lines'];

        $this->assertCount(2, $lines);
        $this->assertSame('77001', $lines[0]['external_line_id']);
        $this->assertSame('TSH-M', $lines[0]['sku']);
        $this->assertSame(2, $lines[0]['quantity']);
        $this->assertSame('TSH-L', $lines[1]['sku']);
    }

    /**
     * ⚠️ PARA OKUMADA NESNEDİR — burada da (§11.3'ün fiyat kuralı).
     *
     * Ham `amount` okunsaydı 19.90 TL kanonik siparişte **1990 TL**
     * görünür ve sipariş toplamları tamamen yanlış olurdu.
     */
    #[Test]
    public function money_objects_are_converted_not_read_raw(): void
    {
        $event = $this->parse($this->receipt('9001', 'paid'));

        $this->assertSame('19.90', $event->payload['lines'][0]['unit_price']);
        $this->assertSame('59.80', $event->payload['grand_total']);
    }

    /** Çıpa durumu taşır — normalizer tarafında da. */
    #[Test]
    public function the_normalized_external_ref_carries_the_status(): void
    {
        $this->assertSame('9001:canceled', $this->parse($this->receipt('9001', 'canceled'))->externalRef);
    }

    // ══════════════════════════ DİKEY DİLİM · "yazıldı" mı "çalışıyor" mu

    /**
     * ⚠️ YOKLANAN SİPARİŞ GERÇEKTEN STOK DÜŞÜRÜR — uçtan uca.
     *
     * Yukarıdaki testler ayrıştırmanın DOĞRU olduğunu kanıtlar, zincirin
     * ÇALIŞTIĞINI kanıtlamaz. Bu test tam yolu sürer:
     *
     *   yoklama → inbox → `ProcessInboxMessage` → `OrderEventRouter`
     *   → `IngestChannelOrder` → `ApplyMovement`
     *
     * Bir halka kopuk olsaydı (normalizer tanınmayan tip üretse, SKU
     * eşleşmese, router dalı olmasa) ayrıştırma testleri YİNE YEŞİL
     * kalır ve kanal "çalışıyor" görünürken tek bir sipariş bile
     * işlenmezdi.
     *
     * ⚠️ AÇILIŞ STOĞU LEDGER ÜZERİNDEN GİRER — `inventory_levels`
     * satırına doğrudan yazmak `on_hand = Σ on_hand_delta` eşitliğini
     * bozar.
     */
    #[Test]
    public function a_polled_receipt_actually_reduces_stock(): void
    {
        [$tenant, $connection] = $this->connected();

        $variant = $this->asTenant(
            $tenant,
            fn (): Variant => Variant::factory()->create(['sku' => 'TSH-M'])
        );

        $warehouseId = (string) $this->asTenant(
            $tenant,
            fn () => Warehouse::query()->where('is_default', true)->value('id')
        );

        // Açılış stoğu LEDGER üzerinden: 10 adet.
        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: 10,
            idempotencyKey: 'import:'.$variant->id,
            sourceType: 'test',
        ));

        // Receipt TSH-M'den 2 adet taşıyor (`receipt()` yardımcısı).
        $this->fakeReceipts([$this->receipt('9001', 'paid')]);

        $this->assertSame(1, app(PollChannelOrders::class)->run());

        $message = $this->asTenant($tenant, fn (): InboxMessage => InboxMessage::query()->firstOrFail());

        // Inbox → router → sipariş → stok.
        $this->asTenant($tenant, fn () => (new ProcessInboxMessage($tenant->id, $message->id))->handle());

        $order = $this->asTenant($tenant, fn (): Order => Order::query()->firstOrFail());

        $this->assertSame('9001', $order->external_id);
        $this->assertSame(2, $this->asTenant($tenant, fn (): int => $order->lines()->count()));

        // ⚠️ ASIL KANIT: stok 10 → 8. Zincirin herhangi bir halkası kopuk
        // olsaydı bakiye 10'da KALIRDI.
        $level = $this->asTenant($tenant, fn () => InventoryLevel::query()
            ->where('variant_id', $variant->id)
            ->firstOrFail());

        $this->assertSame(8, (int) $level->on_hand);
        $this->assertSame(8, (int) $level->available);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * ⚠️ ZAMANLANMIŞ KOMUT `orders:poll` ETSY'Yİ GERÇEKTEN YOKLAR.
     *
     * Yukarıdaki testler `PollChannelOrders` sınıfını DOĞRUDAN çağırır;
     * bu test ZAMANLAMANIN sürdüğü komutu sürer. Komut kayıtlı ama
     * yanlış scope'u sürüyor olsaydı (ya da hiç kayıtlı olmasaydı)
     * destek sınıfının testleri YİNE YEŞİL kalır ve üretimde tek bir
     * sipariş bile çekilmezdi.
     *
     * İddia "komut sıfırla çıktı" DEĞİL — kanala GERÇEKTEN istek gitti
     * ve inbox satırı YAZILDI.
     */
    #[Test]
    public function the_scheduled_command_actually_polls_etsy(): void
    {
        [$tenant, $connection] = $this->connected();

        $this->fakeReceipts([$this->receipt('9001', 'paid')]);

        $this->artisan('orders:poll')->assertSuccessful();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/shops/777/receipts'));

        $this->assertSame(
            '9001:paid',
            $this->asTenant($tenant, fn () => InboxMessage::query()->value('external_event_id')),
        );
    }

    /**
     * ⚠️ EŞLEŞMEYEN SKU SİPARİŞİ KAYBETTİRMEZ (Karar 24).
     *
     * `TSH-L` bizde YOK: o satırın `variant_id`'si NULL kalır, stok
     * DÜŞÜLMEZ ve satır PENDING olur — ama sipariş YAZILIR. Sipariş
     * kaybetmek stok tutarsızlığından KÖTÜDÜR.
     */
    #[Test]
    public function an_unmatched_sku_still_records_the_order(): void
    {
        [$tenant, $connection] = $this->connected();

        // Yalnızca TSH-M var; receipt TSH-L'yi de taşıyor.
        $variant = $this->asTenant(
            $tenant,
            fn (): Variant => Variant::factory()->create(['sku' => 'TSH-M'])
        );

        $warehouseId = (string) $this->asTenant(
            $tenant,
            fn () => Warehouse::query()->where('is_default', true)->value('id')
        );

        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: 10,
            idempotencyKey: 'import:'.$variant->id,
            sourceType: 'test',
        ));

        $this->fakeReceipts([$this->receipt('9001', 'paid')]);

        app(PollChannelOrders::class)->run();

        $message = $this->asTenant($tenant, fn (): InboxMessage => InboxMessage::query()->firstOrFail());

        $this->asTenant($tenant, fn () => (new ProcessInboxMessage($tenant->id, $message->id))->handle());

        // Sipariş İKİ kalemiyle YAZILDI — eşleşmeyen satır atılmadı.
        $order = $this->asTenant($tenant, fn (): Order => Order::query()->firstOrFail());

        $this->assertSame(2, $this->asTenant($tenant, fn (): int => $order->lines()->count()));

        // Eşleşen kalemin stoğu düştü (10 − 2), eşleşmeyen HİÇ dokunmadı.
        $level = $this->asTenant($tenant, fn () => InventoryLevel::query()
            ->where('variant_id', $variant->id)
            ->firstOrFail());

        $this->assertSame(8, (int) $level->on_hand);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    // ═══════════════════════════════════════════════════ yetenek

    /** Yetenek `instanceof` ile okunur. */
    #[Test]
    public function the_adapter_declares_the_orders_capability(): void
    {
        $this->assertInstanceOf(SupportsOrders::class, $this->adapter());
    }

    /**
     * ⚠️ WEBHOOK DOĞRULAMASI DAİMA `false` (§11.4).
     *
     * `true` dönmek Etsy adına İMZASIZ SİPARİŞ ENJEKTE etmenin kapısını
     * açardı — Trendyol'daki kararın aynısı.
     */
    #[Test]
    public function webhook_verification_stays_false_even_now_that_orders_exist(): void
    {
        $this->assertFalse($this->adapter()->verifyWebhookSignature('{}', []));
    }

    /**
     * ⚠️ ONAY ADIMI YOKTUR ama SESSİZCE BAŞARILI DÖNMEZ.
     *
     * Etsy'de satıcının siparişi "üstlenmesi" diye bir kavram yok;
     * yazılmamış bir yetenek gibi istisna fırlatmak da YANLIŞ olurdu —
     * çağıran sonsuza kadar hata alırdı. Sözleşme gereği başarı döner
     * ve bunun bir NO-OP olduğu sonuçta GÖRÜNÜR.
     */
    #[Test]
    public function acknowledging_an_order_is_an_explicit_no_op(): void
    {
        $result = $this->adapter()->acknowledgeOrder(new Order);

        $this->assertTrue($result->successful);
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    /**
     * Ham receipt'i normalize eder — inbox satırı ÜZERİNDEN.
     *
     * @param  array<string, mixed>  $receipt
     */
    private function parse(array $receipt): ?NormalizedOrderEvent
    {
        [$tenant, $connection] = $this->connected();

        $this->fakeReceipts([$receipt]);

        app(PollChannelOrders::class)->run();

        return $this->asTenant($tenant, function () use ($connection) {
            $message = InboxMessage::query()->latest('id')->firstOrFail();

            return $this->adapterFor($connection)->parseOrderEvent($message);
        });
    }

    /** @param list<array<string, mixed>> $receipts */
    private function fakeReceipts(array $receipts): void
    {
        Http::fake(['*' => Http::response([
            'results' => $receipts,
            'count' => count($receipts),
        ], 200)]);
    }

    /**
     * Gerçekçi Etsy receipt gövdesi — İKİ transaction taşır.
     *
     * @return array<string, mixed>
     */
    private function receipt(string $receiptId, string $status): array
    {
        return [
            'receipt_id' => (int) $receiptId,
            'status' => $status,
            'create_timestamp' => 1756000000,
            'currency_code' => 'TRY',
            'grandtotal' => ['amount' => 5980, 'divisor' => 100, 'currency_code' => 'TRY'],
            'total_price' => ['amount' => 5980, 'divisor' => 100, 'currency_code' => 'TRY'],
            'total_shipping_cost' => ['amount' => 0, 'divisor' => 100, 'currency_code' => 'TRY'],
            'transactions' => [
                [
                    'transaction_id' => 77001,
                    'sku' => 'TSH-M',
                    'title' => 'Tişört M',
                    'quantity' => 2,
                    'price' => ['amount' => 1990, 'divisor' => 100, 'currency_code' => 'TRY'],
                ],
                [
                    'transaction_id' => 77002,
                    'sku' => 'TSH-L',
                    'title' => 'Tişört L',
                    'quantity' => 1,
                    'price' => ['amount' => 2000, 'divisor' => 100, 'currency_code' => 'TRY'],
                ],
            ],
        ];
    }

    private function adapter(): EtsyAdapter
    {
        [$tenant, $connection] = $this->connected();

        return $this->asTenant($tenant, fn (): EtsyAdapter => $this->adapterFor($connection));
    }

    private function adapterFor(ChannelConnection $connection): EtsyAdapter
    {
        return new EtsyAdapter(
            $connection,
            new ChannelHttpClient(
                $connection,
                app(CredentialVault::class),
                app(PayloadRedactor::class),
            ),
        );
    }

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function connected(): array
    {
        if (isset($this->cached)) {
            return $this->cached;
        }

        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'etsy'],
            [
                'name' => 'Etsy',
                'kind' => 'marketplace',
                'adapter_class' => EtsyAdapter::class,
                // ⚠️ ETSY WEBHOOK SUNMAZ — bu alan yoklama kapısıdır.
                'supports_webhooks' => false,
                'is_active' => false,
            ],
        ));

        $tenant = (new CreateTenant)->run(
            name: 'Etsy Sipariş '.uniqid(),
            owner: User::factory()->create(),
        );

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'etsy',
                'external_account_id' => 'etsy-'.uniqid(),
                'status' => 'active',
                'settings' => [
                    EtsyAdapter::KEYSTRING_KEY => 'key-abc',
                    EtsyAdapter::SHOP_ID_KEY => '777',
                ],
            ]);

            app(CredentialVault::class)->store($connection, [
                'access_token' => '12345.token',
                'refresh_token' => '12345.refresh',
            ]);

            return $connection;
        });

        return $this->cached = [$tenant, $connection];
    }

    /** @var array{0: Tenant, 1: ChannelConnection}|null */
    private ?array $cached = null;
}
