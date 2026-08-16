<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Messaging\Consumers\InventoryLevelChangedConsumer;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Messaging\Support\OutboxRelay;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushInventory;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\InventoryBatchBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * DİKEY DİLİM — kapalı döngü: Woo'da sipariş → stok düşer → Woo'ya geri yazılır.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.7, §19 · 3 · İlk çalışan dikey dilim.
 *
 * Faz 1'in çıktısı tek yönlü aktarım değil, KAPALI DÖNGÜDÜR. Bu test
 * dokümandaki zinciri baştan sona, gerçek WooCommerce yükleriyle yürütür:
 *
 *   Woo'da müşteri 1 adet satın alır
 *      ↓  webhook → HMAC (HAM gövde) → X-WC-Webhook-Delivery-ID → inbox → 202
 *   ProcessInboxMessage → OrderEventRouter → IngestChannelOrder
 *      ↓  TEK TRANSACTION: orders + order_lines + LockInventoryRows
 *         + ApplyMovement (10 → 9, KIRPMA YOK) + outbox_events
 *   OutboxRelay → InventoryLevelChangedConsumer
 *      ↓  FAN-OUT: listing başına operasyon; origin kanal ATLANIR
 *   PushInventory → InventoryBatchBuilder → adapter → api_call
 *      ↓  SyncResultRecorder: synced_version = n+1, status = 'synced'
 *   Woo'da stok 9
 *
 * Her adım kendi dosyasında ayrıca sınanıyor; buradaki iddia zincirin
 * KOPMADIĞIDIR — bir adımın ürettiği biçim, sonrakinin okuduğu biçimle aynı mı?
 */
final class WooCommerceVerticalSliceTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'wh_secret_vertical_slice_123';

    /**
     * Tam döngü: Woo siparişi geldi, stok düştü, diğer kanala geri yazıldı.
     *
     * İKİ kanal kullanılıyor çünkü kaynak kanal fan-out'ta ATLANIR: sipariş
     * Woo'dan geldiyse Woo'ya geri yazmak gereksizdir. Trendyol bacağı
     * döngünün gerçekten kapandığını gösterir.
     */
    #[Test]
    public function woo_order_reduces_stock_and_pushes_back_to_other_channel(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $wooConnection = $this->connection($tenant, 'woocommerce');
        $otherConnection = $this->connection($tenant, 'trendyol');

        $wooListing = $this->listing($tenant, $variant, $wooConnection, externalId: '101');
        $otherListing = $this->listing($tenant, $variant, $otherConnection, externalId: '555');

        // Açılış stoğu LEDGER üzerinden: 10 adet.
        $this->seedStock($tenant, $variant, $warehouseId, 10);

        $this->assertSame(10, $this->levelFor($tenant, $variant)->available);

        // ── (1) Woo webhook'u: müşteri 1 adet aldı ───────────────────────
        Queue::fake();

        $body = $this->wooOrderPayload(orderId: 1234, sku: $variant->sku, quantity: 1);
        $raw = json_encode($body, JSON_UNESCAPED_SLASHES);

        $response = $this->call(
            'POST',
            "/webhooks/{$wooConnection->id}",
            server: $this->wooHeaders($raw, deliveryId: 'dlv-1', topic: 'order.created'),
            content: $raw,
        );

        // HER DURUMDA 202 — kanal 2xx dışını başarısızlık sayıp tekrar gönderir.
        $response->assertStatus(202);

        $message = $this->asSystem(fn () => InboxMessage::query()->firstOrFail());

        $this->assertTrue($message->signature_valid, 'HMAC ham gövde üzerinden doğrulanmalı.');
        $this->assertSame('dlv-1', $message->external_event_id);

        // ── (2) Inbox işlenir → sipariş + stok hareketi ──────────────────
        $this->asTenant($tenant, fn () => (new ProcessInboxMessage($tenant->id, $message->id))->handle());

        $order = $this->asTenant($tenant, fn () => Order::query()->firstOrFail());

        $this->assertSame('1234', $order->external_id);
        $this->assertSame(1, $this->asTenant($tenant, fn () => $order->lines()->count()));

        // Stok 10 → 9. Kanonik durum kırpılmadı.
        $level = $this->levelFor($tenant, $variant);

        $this->assertSame(9, $level->on_hand);
        $this->assertSame(9, $level->available);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);

        // ── (3) Outbox relay → fan-out ───────────────────────────────────
        $this->asSystem(fn () => app(OutboxRelay::class)->run());

        // SATIŞ olayı hedeflenir. Açılış IMPORT hareketi de outbox'a olay
        // yazar; sırasız okumak açılış olayını seçer, o da origin taşımaz
        // ve kaynak kanal elenmeden fan-out edilir.
        $event = $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'InventoryLevelChanged')
            ->whereRaw("payload->>'movement_type' = 'SALE'")
            ->firstOrFail());

        $this->asTenant($tenant, fn () => app(
            InventoryLevelChangedConsumer::class
        )->handle($event));

        $operations = $this->asTenant($tenant, fn () => SyncOperation::query()->get());

        // KAYNAK KANAL ATLANDI: Woo'dan gelen değişim Woo'ya geri yazılmaz.
        $this->assertCount(1, $operations, 'Yalnızca diğer kanal için operasyon açılmalı.');
        $this->assertSame($otherListing->id, $operations->first()->entity_id);
        $this->assertSame(1, $event->fresh()->operations_planned);

        // ── (4) PushInventory → gerçek adapter → Woo formatında çağrı ────
        Http::fake(['*' => Http::response(['update' => [['id' => 555]]], 200)]);

        $this->runPushJob($tenant, $operations->first()->id);

        // Kanala MUTLAK değer gitti: 9.
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            $this->assertStringContainsString('products/batch', $request->url());
            $this->assertSame(555, $payload['update'][0]['id']);
            $this->assertSame(9, $payload['update'][0]['stock_quantity']);
            $this->assertTrue($payload['update'][0]['manage_stock']);

            return true;
        });

        // ── (5) Sonuç yazıldı: döngü kapandı ─────────────────────────────
        $operation = $this->asTenant($tenant, fn () => $operations->first()->fresh());

        $this->assertSame(SyncOperationStatus::COMPLETED, $operation->status);

        $state = $this->stateFor($tenant, $otherListing->id);

        $this->assertSame($level->version, $state->synced_version);
        $this->assertSame('synced', $state->status);
        $this->assertFalse($state->is_dirty, 'Döngü kapandıysa satır temiz olmalı.');
        $this->assertNotNull($state->last_synced_at);

        // api_calls kaydı yazıldı — "o istek gitti mi" sorusunun cevabı.
        $call = $this->asSystem(fn () => DB::table('api_calls')->latest('id')->first());

        $this->assertSame(200, $call->status_code);
        $this->assertSame($tenant->id, $call->tenant_id);
        $this->assertSame($otherConnection->id, $call->channel_connection_id);

        // Ledger ↔ projeksiyon eşitliği zincirin sonunda da korunuyor.
        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);

        // Woo listing'i hiç dokunulmadı — kaynak kanal atlandı.
        $this->assertSame(
            0,
            $this->asTenant($tenant, fn () => ListingSyncState::query()
                ->where('listing_id', $wooListing->id)
                ->count()),
            'Kaynak kanal için sync state satırı bile açılmamalı.',
        );
    }

    /**
     * Fazla satış zinciri kırmaz: kanonik negatif kalır, kanala 0 gider.
     *
     * Kırpmanın tek meşru yeri OutboundQuantity'dir. Kanonik bakiye
     * DEĞİŞMEZ; ledger ↔ projeksiyon eşitliği fazla satışta da korunur.
     */
    #[Test]
    public function oversold_order_keeps_canonical_negative_and_sends_zero(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $wooConnection = $this->connection($tenant, 'woocommerce');
        $otherConnection = $this->connection($tenant, 'trendyol');

        $this->listing($tenant, $variant, $wooConnection, externalId: '101');
        $otherListing = $this->listing($tenant, $variant, $otherConnection, externalId: '555');

        // Yalnızca 1 adet var, müşteri 3 aldı.
        $this->seedStock($tenant, $variant, $warehouseId, 1);

        Queue::fake();

        $body = $this->wooOrderPayload(orderId: 9001, sku: $variant->sku, quantity: 3);
        $raw = json_encode($body, JSON_UNESCAPED_SLASHES);

        $this->call(
            'POST',
            "/webhooks/{$wooConnection->id}",
            server: $this->wooHeaders($raw, deliveryId: 'dlv-oversell', topic: 'order.created'),
            content: $raw,
        )->assertStatus(202);

        $message = $this->asSystem(fn () => InboxMessage::query()->firstOrFail());

        $this->asTenant($tenant, fn () => (new ProcessInboxMessage($tenant->id, $message->id))->handle());

        // SİPARİŞ REDDEDİLMEDİ; bakiye negatife düştü.
        $level = $this->levelFor($tenant, $variant);

        $this->assertSame(-2, $level->available, 'Fazla satış kabul edilir, bakiye negatife düşer.');
        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);

        // Zincir devam eder.
        $this->asSystem(fn () => app(OutboxRelay::class)->run());

        // SATIŞ olayı hedeflenir. Açılış IMPORT hareketi de outbox'a olay
        // yazar; sırasız okumak açılış olayını seçer, o da origin taşımaz
        // ve kaynak kanal elenmeden fan-out edilir.
        $event = $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'InventoryLevelChanged')
            ->whereRaw("payload->>'movement_type' = 'SALE'")
            ->firstOrFail());

        $this->asTenant($tenant, fn () => app(
            InventoryLevelChangedConsumer::class
        )->handle($event));

        Http::fake(['*' => Http::response(['update' => [['id' => 555]]], 200)]);

        $operation = $this->asTenant($tenant, fn () => SyncOperation::query()->firstOrFail());

        $this->runPushJob($tenant, $operation->id);

        // Kanala KIRPILMIŞ değer gitti.
        Http::assertSent(function (Request $request): bool {
            $this->assertSame(0, $request->data()['update'][0]['stock_quantity']);

            return true;
        });

        // Kanonik durum DEĞİŞMEDİ — kırpma yalnızca giden yükte.
        $this->assertSame(-2, $this->levelFor($tenant, $variant)->available);
        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);

        $this->assertSame(
            SyncOperationStatus::COMPLETED,
            $this->asTenant($tenant, fn () => $operation->fresh())->status,
        );

        $this->assertSame(
            $this->levelFor($tenant, $variant)->version,
            $this->stateFor($tenant, $otherListing->id)->synced_version,
        );
    }

    /**
     * Sahte imzalı webhook zincire HİÇ giremez.
     *
     * Doğrulanmamış webhook = sahte sipariş enjeksiyonu. Reddedilen istek
     * inbox'a KAYDEDİLMEZ; kaydedilseydi kurtarma işi onu işlemeye çalışırdı.
     */
    #[Test]
    public function forged_webhook_never_enters_the_chain(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();

        $wooConnection = $this->connection($tenant, 'woocommerce');
        $this->listing($tenant, $variant, $wooConnection, externalId: '101');

        $this->seedStock($tenant, $variant, $warehouseId, 10);

        $body = $this->wooOrderPayload(orderId: 6666, sku: $variant->sku, quantity: 5);
        $raw = json_encode($body, JSON_UNESCAPED_SLASHES);

        $this->call(
            'POST',
            "/webhooks/{$wooConnection->id}",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode('sahte imza'),
                'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'dlv-forged',
                'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created',
            ],
            content: $raw,
        )->assertStatus(401);

        $this->assertSame(0, $this->asSystem(fn () => InboxMessage::query()->count()));
        $this->assertSame(0, $this->asTenant($tenant, fn () => Order::query()->count()));

        // Stok DOKUNULMADI.
        $this->assertSame(10, $this->levelFor($tenant, $variant)->available);
        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: Variant, 2: string} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Slice '.uniqid(),
            owner: User::factory()->create(),
        );

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        $warehouseId = $this->asTenant($tenant, fn () => Warehouse::query()
            ->where('is_default', true)
            ->firstOrFail())->id;

        return [$tenant, $variant, $warehouseId];
    }

    /** Gerçek WooCommerceAdapter'a bağlı bağlantı + webhook sırrı. */
    private function connection(Tenant $tenant, string $code): ChannelConnection
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => ucfirst($code),
                'kind' => $code === 'woocommerce' ? 'store' : 'marketplace',
                // İKİ bağlantı da Woo adapter'ını kullanıyor: bu test
                // zincirin kopmadığını sınıyor, kanal çeşitliliğini değil.
                'adapter_class' => WooCommerceAdapter::class,
                'is_active' => true,
                'rate_limit_profile' => ['requests_per_second' => 5, 'burst_capacity' => 10],
            ],
        ));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'channel_type_code' => $code,
            'external_account_id' => $code.'-'.uniqid().'.example.com',
            'settings' => ['base_url' => "https://{$code}.example.com/wp-json/wc/v3/"],
        ]));

        $this->asTenant($tenant, fn () => app(CredentialVault::class)->store($connection, [
            'consumer_key' => 'ck_vertical_slice_1234567890',
            'consumer_secret' => 'cs_vertical_slice_1234567890',
            'webhook_secret' => self::WEBHOOK_SECRET,
        ]));

        return $connection;
    }

    private function listing(
        Tenant $tenant,
        Variant $variant,
        ChannelConnection $connection,
        string $externalId,
    ): Listing {
        return $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'external_id' => $externalId,
        ]));
    }

    /** Açılış stoğu LEDGER üzerinden — projeksiyona doğrudan yazılmaz. */
    private function seedStock(Tenant $tenant, Variant $variant, string $warehouseId, int $qty): void
    {
        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: $qty,
            idempotencyKey: 'import:'.$variant->id,
            sourceType: 'test',
        ));
    }

    /**
     * Gerçek WooCommerce sipariş gövdesi.
     *
     * @return array<string, mixed>
     */
    private function wooOrderPayload(int $orderId, string $sku, int $quantity): array
    {
        return [
            'id' => $orderId,
            'number' => (string) $orderId,
            'status' => 'processing',
            'currency' => 'TRY',
            'date_created_gmt' => '2026-08-16T09:15:00',
            'date_paid' => '2026-08-16T09:15:10',
            'total' => (string) (100 * $quantity),
            'total_tax' => '0.00',
            'shipping_total' => '0.00',
            'customer_id' => 42,
            'billing' => [
                'email' => 'musteri@example.com',
                'first_name' => 'Test',
            ],
            'line_items' => [
                [
                    'id' => 77,
                    'name' => 'Test Ürün',
                    'sku' => $sku,
                    'quantity' => $quantity,
                    'price' => '100.00',
                    'subtotal' => (string) (100 * $quantity),
                    'total' => (string) (100 * $quantity),
                ],
            ],
        ];
    }

    /**
     * Woo webhook başlıkları — imza HAM gövde üzerinden hesaplanır.
     *
     * @return array<string, string>
     */
    private function wooHeaders(string $raw, string $deliveryId, string $topic): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(
                hash_hmac('sha256', $raw, self::WEBHOOK_SECRET, true)
            ),
            'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => $deliveryId,
            'HTTP_X_WC_WEBHOOK_TOPIC' => $topic,
        ];
    }

    private function runPushJob(Tenant $tenant, string $operationId): void
    {
        $this->asTenant($tenant, function () use ($operationId): void {
            (new PushInventory($operationId))->handle(
                app(InventoryBatchBuilder::class),
                app(SyncResultRecorder::class),
                app(AdapterRegistry::class),
            );
        });
    }

    private function levelFor(Tenant $tenant, Variant $variant): InventoryLevel
    {
        return $this->asTenant($tenant, fn () => InventoryLevel::query()
            ->where('variant_id', $variant->id)
            ->firstOrFail());
    }

    private function stateFor(Tenant $tenant, string $listingId): ListingSyncState
    {
        return $this->asTenant($tenant, fn () => ListingSyncState::query()
            ->where('listing_id', $listingId)
            ->where('domain', SyncDomain::INVENTORY->value)
            ->firstOrFail());
    }
}
