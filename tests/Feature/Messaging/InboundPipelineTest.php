<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Messaging\Actions\IngestInboxMessage;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Enums\StockStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLine;
use App\Http\Controllers\WebhookController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\Support\Channels\FakeOrderAdapter;
use Tests\TestCase;

/**
 * Gelen hat: webhook → inbox → router → sipariş alımı.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Inbox, §1 · Kararlar 23, 24, §11.
 */
final class InboundPipelineTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeOrderAdapter::reset();
    }

    // ─────────────────────────────────────────────── webhook güvenliği

    /** Geçersiz imza REDDEDİLİR ve hiçbir kayıt yazılmaz. */
    #[Test]
    public function invalid_signature_is_rejected_without_recording(): void
    {
        [, $connection] = $this->makeContext();

        FakeOrderAdapter::$signatureValid = false;

        $response = $this->postJson("/webhooks/{$connection->id}", ['type' => 'created']);

        $response->assertStatus(401);

        $this->assertSame(0, $this->asSystem(fn () => InboxMessage::query()->count()));
    }

    /** Geçerli imza 202 döner ve mesajı kaydeder. */
    #[Test]
    public function valid_webhook_is_recorded_and_returns_202(): void
    {
        Queue::fake();

        [$tenant, $connection] = $this->makeContext();

        $response = $this->postJson(
            "/webhooks/{$connection->id}",
            ['type' => 'created', 'external_order_id' => 'ORD-1'],
            ['X-Fake-Delivery-Id' => 'DLV-1', 'X-Fake-Topic' => 'order.created'],
        );

        $response->assertStatus(202);

        $message = $this->asSystem(fn () => InboxMessage::query()->firstOrFail());

        $this->assertSame('DLV-1', $message->external_event_id);
        $this->assertSame('order.created', $message->event_type);
        $this->assertSame('pending', $message->status);
        $this->assertTrue($message->signature_valid);
        $this->assertSame($tenant->id, $message->tenant_id);

        Queue::assertPushed(ProcessInboxMessage::class, 1);
    }

    /** Var olmayan bağlantı 404 döner. */
    #[Test]
    public function unknown_connection_returns_404(): void
    {
        $response = $this->postJson('/webhooks/'.(string) new UuidV7, ['type' => 'created']);

        $response->assertStatus(404);
    }

    /**
     * BEKLENMEYEN İÇERİK TİPİ 415 ALIR VE AYRIŞTIRILMAZ.
     *
     * Mimari Karar Dokümanı v2.2 · §11 · Webhook güvenliği tablosu:
     * "İçerik tipi — beklenmeyen tip → 415, ayrıştırma yapılmaz".
     *
     * Kapı İMZADAN ÖNCE gelir ve bu bilinçlidir: imza doğrulaması gövdeyi
     * okur ve adapter'a verir; JSON beklerken `multipart/form-data` veya
     * `application/xml` almak, ayrıştırıcıyı hiç tasarlanmadığı bir girdiyle
     * karşılaştırmaktır. Ucuz kapı önce çalışır.
     *
     * 415 DÖNMEK 202 KURALINI İHLAL ETMEZ: "her durumda 202" kuralı
     * TANIDIĞIMIZ bir mesajın işlenmesiyle ilgilidir ve kanalın gereksiz
     * yeniden göndermesini önler. Yanlış içerik tipi kanalın YAPILANDIRMA
     * hatasıdır; 2xx dönmek onu sessizce gizler ve mesaj sonsuza kadar
     * kaybolur.
     */
    #[Test]
    public function unexpected_content_type_is_rejected_with_415(): void
    {
        [, $connection] = $this->makeContext();

        $response = $this->call(
            method: 'POST',
            uri: "/webhooks/{$connection->id}",
            server: ['CONTENT_TYPE' => 'application/xml'],
            content: '<order><id>1</id></order>',
        );

        $response->assertStatus(415);

        // Ayrıştırma YAPILMADI: hiçbir kayıt yazılmamalı.
        $this->assertSame(0, $this->asSystem(fn () => InboxMessage::query()->count()));
    }

    /**
     * HIZ SINIRI BAĞLANTI BAŞINADIR — §11: "dakikada 600 istek".
     *
     * SINIR BAĞLANTI BAŞINA, IP BAŞINA DEĞİL: kanal webhook'ları kendi
     * altyapısından gelir ve aynı IP yüzlerce satıcıya hizmet eder. IP
     * başına sınır konsaydı yoğun bir satıcı, aynı kanaldaki DİĞER
     * satıcıların siparişlerini düşürürdü.
     *
     * SINIRA TAKILAN İSTEK 429 ALIR ve bu, "her durumda 202" kuralının
     * bilinçli istisnasıdır: 429 kanala "yavaşla ve TEKRAR GÖNDER" der ve
     * her ciddi kanal onu böyle yorumlar. 202 dönseydi mesaj kabul edilmiş
     * sayılır ama işlenmezdi — sipariş sessizce kaybolurdu.
     */
    #[Test]
    public function the_webhook_endpoint_is_rate_limited_per_connection(): void
    {
        Queue::fake();

        [, $connection] = $this->makeContext();
        [, $other] = $this->makeContext();

        $limit = WebhookController::MAX_REQUESTS_PER_MINUTE;

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson(
                "/webhooks/{$connection->id}",
                ['type' => 'created', 'external_order_id' => "ORD-{$i}"],
                ['X-Fake-Delivery-Id' => "DLV-{$i}"],
            )->assertStatus(202);
        }

        // Bütçe doldu.
        $this->postJson(
            "/webhooks/{$connection->id}",
            ['type' => 'created', 'external_order_id' => 'ORD-TASMA'],
            ['X-Fake-Delivery-Id' => 'DLV-TASMA'],
        )->assertStatus(429);

        // BAŞKA BAĞLANTI ETKİLENMEZ — kova bağlantı başınadır.
        $this->postJson(
            "/webhooks/{$other->id}",
            ['type' => 'created', 'external_order_id' => 'ORD-DIGER'],
            ['X-Fake-Delivery-Id' => 'DLV-DIGER'],
        )->assertStatus(202);
    }

    /**
     * AYNI webhook iki kez gelirse İKİNCİ KEZ İŞLENMEZ.
     *
     * Birincil tekilleştirme: (channel_connection_id, external_event_id).
     */
    #[Test]
    public function duplicate_delivery_id_is_deduplicated_and_not_requeued(): void
    {
        Queue::fake();

        [, $connection] = $this->makeContext();

        $body = ['type' => 'created', 'external_order_id' => 'ORD-1'];
        $headers = ['X-Fake-Delivery-Id' => 'DLV-SAME'];

        $this->postJson("/webhooks/{$connection->id}", $body, $headers)->assertStatus(202);
        $this->postJson("/webhooks/{$connection->id}", $body, $headers)->assertStatus(202);

        $this->assertSame(1, $this->asSystem(fn () => InboxMessage::query()->count()));

        // Tekrar gelen webhook kuyruğa İKİNCİ kez girmez.
        Queue::assertPushed(ProcessInboxMessage::class, 1);
    }

    /**
     * Olay kimliği YOKSA son çare devreye girer: hash + saatlik pencere.
     */
    #[Test]
    public function messages_without_event_id_deduplicate_by_hash(): void
    {
        Queue::fake();

        [, $connection] = $this->makeContext();

        $body = ['type' => 'created', 'external_order_id' => 'ORD-2'];

        // Delivery-Id başlığı YOK.
        $this->postJson("/webhooks/{$connection->id}", $body)->assertStatus(202);
        $this->postJson("/webhooks/{$connection->id}", $body)->assertStatus(202);

        $this->assertSame(1, $this->asSystem(fn () => InboxMessage::query()->count()));

        $message = $this->asSystem(fn () => InboxMessage::query()->firstOrFail());
        $this->assertNull($message->external_event_id);
    }

    /** Farklı yükler ayrı kayıt üretir — hash farklıdır. */
    #[Test]
    public function different_payloads_are_recorded_separately(): void
    {
        Queue::fake();

        [, $connection] = $this->makeContext();

        $this->postJson("/webhooks/{$connection->id}", ['type' => 'created', 'external_order_id' => 'A']);
        $this->postJson("/webhooks/{$connection->id}", ['type' => 'created', 'external_order_id' => 'B']);

        $this->assertSame(2, $this->asSystem(fn () => InboxMessage::query()->count()));
    }

    /** Aynı olay kimliği FARKLI bağlantılarda ayrışır. */
    #[Test]
    public function same_event_id_across_connections_is_not_deduplicated(): void
    {
        Queue::fake();

        [, $connectionA] = $this->makeContext();
        [, $connectionB] = $this->makeContext();

        $headers = ['X-Fake-Delivery-Id' => 'DLV-SHARED'];

        $this->postJson("/webhooks/{$connectionA->id}", ['type' => 'created'], $headers);
        $this->postJson("/webhooks/{$connectionB->id}", ['type' => 'created'], $headers);

        $this->assertSame(2, $this->asSystem(fn () => InboxMessage::query()->count()));
    }

    // ─────────────────────────────────────────────── koşullu geçiş

    /**
     * Aynı mesaj iki kez kuyruğa girerse TEK işleyici seçilir.
     *
     * UPDATE ... WHERE status = 'pending' koşullu geçişi kazananı belirler;
     * kaybeden kopya erken çıkar ve sipariş iki kez işlenmez.
     */
    #[Test]
    public function conditional_transition_selects_a_single_processor(): void
    {
        [$tenant, $connection, $variant, $warehouseId] = $this->makeContextWithStock(10);

        $message = $this->recordMessage($connection, [
            'type' => 'created',
            'external_order_id' => 'ORD-DUP',
            'lines' => [
                ['external_line_id' => 'l1', 'sku' => $variant->sku, 'quantity' => 3],
            ],
        ]);

        // İki kopya art arda çalışır.
        (new ProcessInboxMessage($tenant->id, $message->id))->handle();
        (new ProcessInboxMessage($tenant->id, $message->id))->handle();

        // Stok YALNIZCA bir kez düştü.
        $this->assertSame(7, $this->onHand($tenant, $warehouseId, $variant->id));
        $this->assertSame(1, $this->asSystem(fn () => Order::query()->count()));

        $this->assertSame('processed', $message->fresh()->status);
        $this->assertSame(1, $message->fresh()->attempt_count);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * KOŞULLU GEÇİŞ BAŞKA KİRACININ SATIRINA DOKUNAMAZ.
     *
     * Mimari Karar Dokümanı v2.2 · §11 · kiracı izolasyonu.
     *
     * `DB::table()` Eloquent global scope'una TABİ DEĞİLDİR: kiracı filtresi
     * AÇIKÇA yazılmazsa UPDATE tüm kiracıları görür. Bu boşluk projede beş
     * ayrı turda çıktı ve kuralı şudur — `DB::table()` her kullanıldığında
     * filtre DE testi DE yazılır.
     *
     * BURADAKİ ZARAR SESSİZ VE KALICIDIR: yanlış eşleşmiş bir çift
     * (A kiracısının işi, B kiracısının mesaj kimliği) B'nin satırını
     * `processing` yapar, ardından gelen `find()` KAPSAMLI olduğu için satırı
     * bulamaz ve iş sessizce çıkar. B'nin mesajı artık `pending` DEĞİLDİR —
     * `inbox:recover` taraması yalnızca `pending` satırları toplar, yani o
     * sipariş bir daha HİÇ işlenmez. Sipariş kaybetmek bu projede en pahalı
     * hata biçimidir.
     */
    #[Test]
    public function conditional_transition_never_claims_another_tenants_message(): void
    {
        [$tenantA] = $this->makeContext();
        [, $connectionB] = $this->makeContext();

        // Mesaj B kiracısına ait.
        $messageB = $this->recordMessage($connectionB, [
            'type' => 'created',
            'external_order_id' => 'ORD-B',
            'lines' => [['external_line_id' => 'l1', 'sku' => 'SKU-B', 'quantity' => 1]],
        ]);

        // İş A kiracısı bağlamında koşar ama B'nin mesaj kimliğini taşır.
        (new ProcessInboxMessage($tenantA->id, $messageB->id))->handle();

        // B'nin satırı DOKUNULMAMIŞ olmalı: hâlâ pending ve deneme sayacı 0.
        $raw = $this->asSystem(fn () => DB::table('inbox_messages')
            ->where('id', $messageB->id)
            ->first());

        $this->assertNotNull($raw);

        $this->assertSame(
            'pending',
            $raw->status,
            'Başka kiracının inbox satırı processing yapıldı — o sipariş '.
            'bir daha hiç işlenmez (inbox:recover yalnızca pending toplar).',
        );

        $this->assertSame(0, (int) $raw->attempt_count);
    }

    /** İşlenmiş mesaj yeniden çalıştırılırsa erken çıkar. */
    #[Test]
    public function processed_message_is_not_reprocessed(): void
    {
        [$tenant, $connection, $variant, $warehouseId] = $this->makeContextWithStock(10);

        $message = $this->recordMessage($connection, [
            'type' => 'created',
            'external_order_id' => 'ORD-ONCE',
            'lines' => [['external_line_id' => 'l1', 'sku' => $variant->sku, 'quantity' => 2]],
        ]);

        (new ProcessInboxMessage($tenant->id, $message->id))->handle();
        $this->assertSame(8, $this->onHand($tenant, $warehouseId, $variant->id));

        (new ProcessInboxMessage($tenant->id, $message->id))->handle();
        $this->assertSame(8, $this->onHand($tenant, $warehouseId, $variant->id));
    }

    // ─────────────────────────────────────────────── yönlendirme

    /** created olayı sipariş yaratır ve stok düşer. */
    #[Test]
    public function created_event_ingests_the_order(): void
    {
        [$tenant, $connection, $variant, $warehouseId] = $this->makeContextWithStock(10);

        $message = $this->recordMessage($connection, [
            'type' => 'created',
            'external_order_id' => 'ORD-100',
            'lines' => [['external_line_id' => 'l1', 'sku' => $variant->sku, 'quantity' => 4]],
        ]);

        (new ProcessInboxMessage($tenant->id, $message->id))->handle();

        $order = $this->asTenant($tenant, fn () => Order::query()->firstOrFail());

        $this->assertSame('ORD-100', $order->external_id);
        $this->assertSame(6, $this->onHand($tenant, $warehouseId, $variant->id));

        $line = $this->asTenant($tenant, fn () => OrderLine::query()->firstOrFail());
        $this->assertSame(StockStatus::APPLIED, $line->stock_status);
        $this->assertSame($variant->id, $line->variant_id);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * İPTAL OLAYI SİPARİŞ YARATMA YOLUNA GİRMEZ.
     *
     * Karar 24: hepsi tek yola girseydi iptal ON CONFLICT DO NOTHING dalına
     * düşer ve SESSİZCE YUTULURDU; stok hiç geri gelmezdi.
     */
    #[Test]
    public function cancellation_event_restores_stock_instead_of_creating_an_order(): void
    {
        [$tenant, $connection, $variant, $warehouseId] = $this->makeContextWithStock(10);

        // Önce sipariş.
        $created = $this->recordMessage($connection, [
            'type' => 'created',
            'external_order_id' => 'ORD-200',
            'lines' => [['external_line_id' => 'l1', 'sku' => $variant->sku, 'quantity' => 3]],
        ]);

        (new ProcessInboxMessage($tenant->id, $created->id))->handle();
        $this->assertSame(7, $this->onHand($tenant, $warehouseId, $variant->id));

        // Sonra iptal — AYNI external_order_id.
        $cancelled = $this->recordMessage($connection, [
            'type' => 'cancelled',
            'external_order_id' => 'ORD-200',
            'external_ref' => 'CAN-1',
            'lines' => [['external_line_id' => 'l1', 'quantity' => 3]],
        ], eventId: 'DLV-CANCEL');

        (new ProcessInboxMessage($tenant->id, $cancelled->id))->handle();

        // Stok geri geldi ve İKİNCİ sipariş yaratılmadı.
        $this->assertSame(10, $this->onHand($tenant, $warehouseId, $variant->id));
        $this->assertSame(1, $this->asTenant($tenant, fn () => Order::query()->count()));

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /** İade olayı da stoğu geri getirir. */
    #[Test]
    public function return_event_restores_stock(): void
    {
        [$tenant, $connection, $variant, $warehouseId] = $this->makeContextWithStock(10);

        $created = $this->recordMessage($connection, [
            'type' => 'created',
            'external_order_id' => 'ORD-300',
            'lines' => [['external_line_id' => 'l1', 'sku' => $variant->sku, 'quantity' => 5]],
        ]);

        (new ProcessInboxMessage($tenant->id, $created->id))->handle();

        $returned = $this->recordMessage($connection, [
            'type' => 'returned',
            'external_order_id' => 'ORD-300',
            'external_ref' => 'RET-1',
            'lines' => [['external_line_id' => 'l1', 'quantity' => 2]],
        ], eventId: 'DLV-RETURN');

        (new ProcessInboxMessage($tenant->id, $returned->id))->handle();

        $this->assertSame(7, $this->onHand($tenant, $warehouseId, $variant->id));

        $line = $this->asTenant($tenant, fn () => OrderLine::query()->firstOrFail());
        $this->assertSame(2, $line->quantity_returned);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * Sipariş bulunamayan olay YUTULMAZ ama patlamaz da.
     *
     * Mesaj işlenmiş sayılır; aksi halde kurtarma taraması onu sonsuza kadar
     * yeniden dener. Eksik, uyarı günlüğüyle görünür kalır.
     */
    #[Test]
    public function event_for_unknown_order_is_marked_processed(): void
    {
        [$tenant, $connection] = $this->makeContext();

        $message = $this->recordMessage($connection, [
            'type' => 'cancelled',
            'external_order_id' => 'ORD-YOK',
            'external_ref' => 'CAN-X',
            'lines' => [['external_line_id' => 'l1', 'quantity' => 1]],
        ]);

        (new ProcessInboxMessage($tenant->id, $message->id))->handle();

        $this->assertSame('processed', $message->fresh()->status);
    }

    /** Sipariş olayı olmayan mesaj işlenmiş sayılır. */
    #[Test]
    public function non_order_event_is_marked_processed(): void
    {
        [$tenant, $connection] = $this->makeContext();

        FakeOrderAdapter::$parsesEvents = false;

        $message = $this->recordMessage($connection, ['type' => 'product.updated']);

        (new ProcessInboxMessage($tenant->id, $message->id))->handle();

        $this->assertSame('processed', $message->fresh()->status);
    }

    // ─────────────────────────────────────────────── kurtarma

    /** Takılı bekleyen mesaj yeniden kuyruğa alınır. */
    #[Test]
    public function recovery_requeues_stuck_pending_messages(): void
    {
        Queue::fake();

        [, $connection] = $this->makeContext();

        $message = $this->recordMessage($connection, ['type' => 'created']);

        // Kayıt ile kuyruğa atma arasında süreç ölmüş gibi geriye alınır.
        $this->asSystem(fn () => DB::table('inbox_messages')
            ->where('id', $message->id)
            ->update(['received_at' => now()->subMinutes(5)]));

        $this->artisan('inbox:recover')->assertSuccessful();

        Queue::assertPushed(ProcessInboxMessage::class, 1);
    }

    /** Taze mesaj kurtarma taramasına takılmaz. */
    #[Test]
    public function recovery_ignores_fresh_messages(): void
    {
        Queue::fake();

        [, $connection] = $this->makeContext();

        $this->recordMessage($connection, ['type' => 'created']);

        $this->artisan('inbox:recover')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** İşlenmiş mesaj kurtarılmaz. */
    #[Test]
    public function recovery_ignores_processed_messages(): void
    {
        Queue::fake();

        [, $connection] = $this->makeContext();

        $message = $this->recordMessage($connection, ['type' => 'created']);

        $this->asSystem(fn () => DB::table('inbox_messages')
            ->where('id', $message->id)
            ->update(['status' => 'processed', 'received_at' => now()->subMinutes(5)]));

        $this->artisan('inbox:recover')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Gelen '.uniqid(),
            owner: User::factory()->create(),
        );

        $code = 'fake-orders';

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => 'Sahte Sipariş Kanalı',
                'kind' => 'marketplace',
                'adapter_class' => FakeOrderAdapter::class,
                'is_active' => true,
            ],
        ));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()
            ->create(['channel_type_code' => $code]));

        return [$tenant, $connection];
    }

    /** @return array{0: Tenant, 1: ChannelConnection, 2: Variant, 3: string} */
    private function makeContextWithStock(int $stock): array
    {
        [$tenant, $connection] = $this->makeContext();

        $warehouseId = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);
        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        $this->asTenant($tenant, fn () => DB::transaction(function () use ($warehouseId, $variant, $stock): void {
            (new LockInventoryRows)->run($warehouseId, [$variant->id]);

            (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variant->id,
                type: MovementType::IMPORT,
                quantity: $stock,
                idempotencyKey: MovementKey::import((string) new UuidV7),
                sourceType: 'import_row',
            );
        }));

        return [$tenant, $connection, $variant, $warehouseId];
    }

    /** @param array<string, mixed> $payload */
    private function recordMessage(
        ChannelConnection $connection,
        array $payload,
        ?string $eventId = null,
    ): InboxMessage {
        return (new IngestInboxMessage)->run(
            connection: $connection,
            source: 'webhook',
            externalEventId: $eventId ?? 'DLV-'.uniqid(),
            eventType: (string) ($payload['type'] ?? 'unknown'),
            payload: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function onHand(Tenant $tenant, string $warehouseId, string $variantId): int
    {
        return (int) $this->asSystem(fn () => DB::table('inventory_levels')
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $variantId)
            ->value('on_hand'));
    }
}
