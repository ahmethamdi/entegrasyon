<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Hepsiburada\HepsiburadaAdapter;
use App\Domain\Channels\Adapters\N11\N11Adapter;
use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Exceptions\OutboundWriteDisabled;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\OutboundWriteGate;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Actions\PublishListing;
use App\Domain\Sync\Enums\PublishReason;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushInventory;
use App\Domain\Sync\Jobs\PushListing;
use App\Domain\Sync\Jobs\PushPrices;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncAttempt;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\InventoryBatchBuilder;
use App\Domain\Sync\Support\PriceBatchBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Tests\TestCase;

/**
 * ADR-010 · KİMLİK BİLGİSİ VAR ≠ KANALA YAZMA İZNİ VAR.
 *
 * Gerçek Hepsiburada/N11 adapter'ları + sahte HTTP: kanala giden HER istek
 * (okuma dahil) kaydedilir. "Engellendi" iddiası istek listesinin boş
 * olmasıyla kanıtlanır, operasyon durumuyla değil.
 */
final class OutboundWriteGateTest extends TestCase
{
    use BuildsPublishingScenario;
    use RefreshDatabase;

    /** @var list<string> "METOT url" */
    private array $sent = [];

    /** Sıralı import cevapları; boşsa başarılı trackingId. */
    private array $importAnswers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublishing();

        Http::fake(function (Request $request) {
            $this->sent[] = $request->method().' '.$request->url();
            $url = $request->url();

            if (str_contains($url, '/api/products/check-product-status')) {
                return Http::response(['success' => true, 'code' => 0, 'data' => [['merchant' => self::MERCHANT, 'skuStatusList' => []]]]);
            }

            if (str_contains($url, '/api/products/import')) {
                $answer = array_shift($this->importAnswers);

                return $answer ?? Http::response(['success' => true, 'code' => 0, 'data' => ['trackingId' => 'T-1']]);
            }

            return Http::response(['id' => 'U-1', 'success' => true, 'content' => []]);
        });
    }

    // ─────────────────────────────────────────── yayın kapısı

    #[Test]
    public function credentials_present_and_publishing_disabled_blocks_the_job_without_any_request(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        $operations = $this->publish($tenant, $product, $connection);

        // Kimlik bilgisi kasada, adapter yayın yeteneğine sahip — ama izin kapalı.
        $this->setWrite($connection, OutboundWrite::PRODUCT_PUBLISH, false);

        $this->runPush($tenant, $operations[0])->assertNotReleased();

        $this->assertSame([], $this->sent, 'İzin kapalıyken kanala tek istek (arama dahil) gitmemeli.');
        $this->assertBlocked($tenant, $operations[0], PublishReason::PRODUCT_PUBLISHING_DISABLED);
    }

    #[Test]
    public function credentials_present_and_publishing_enabled_reaches_the_adapter(): void
    {
        [$tenant, $connection, $product] = $this->readyHb();
        $operations = $this->publish($tenant, $product, $connection);

        $this->runPush($tenant, $operations[0]);

        $this->assertTrue(collect($this->sent)->contains(fn (string $s) => str_contains($s, '/api/products/import')), 'İzin açıkken yaratma isteği gitmeli.');
        $this->assertSame(1, $this->attempts($tenant, $operations[0]));
    }

    #[Test]
    public function missing_credentials_with_publishing_enabled_still_fail_normal_credential_validation(): void
    {
        [$tenant, $connection, $product] = $this->readyHb();
        $operations = $this->publish($tenant, $product, $connection);

        TenantContext::runAsSystem(fn () => DB::table('channel_credentials')->where('channel_connection_id', $connection->id)->delete());

        $this->runPush($tenant, $operations[0]);

        $this->assertSame([], $this->sent, 'Kimliksiz istek atılmamalı.');
        $this->assertSame(1, $this->attempts($tenant, $operations[0]), 'Kapı izin verdi; adapter denendi ve kimlik doğrulamasına takıldı.');
        $operation = $this->operation($tenant, $operations[0]);
        $state = TenantContext::runFor($tenant->id, fn () => ListingSyncState::query()->where('listing_id', $operation->entity_id)->where('domain', SyncDomain::CONTENT->value)->first());
        $this->assertNotSame(PublishReason::PRODUCT_PUBLISHING_DISABLED->value, $state?->reason_code);
    }

    #[Test]
    public function a_connection_created_directly_in_the_database_cannot_publish_by_default(): void
    {
        $tenant = $this->tenant();
        $this->hbConnection($tenant);
        TenantContext::runAsSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'n11'], [
            'name' => 'N11', 'kind' => 'marketplace', 'adapter_class' => N11Adapter::class,
            'supports_webhooks' => false, 'is_active' => true,
        ]));

        // Panel ve fabrika YOK — betik/iç araç gibi doğrudan satır.
        $id = (string) Uuid::v7();
        TenantContext::runAsSystem(fn () => DB::table('channel_connections')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'channel_type_code' => 'hepsiburada', 'label' => 'Betik',
            'external_account_id' => 'betik-'.uniqid(), 'status' => 'active', 'health_status' => 'healthy',
            'settings' => json_encode([HepsiburadaAdapter::INTEGRATOR_KEY => 'pazar_dev', HepsiburadaAdapter::ENVIRONMENT_KEY => HepsiburadaAdapter::ENVIRONMENT_TEST, 'vat_rate' => 20]),
            'connected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));

        $row = TenantContext::runAsSystem(fn () => DB::table('channel_connections')->where('id', $id)->first());
        $this->assertFalse($row->product_publish_enabled);
        $this->assertFalse($row->price_sync_enabled);
        $this->assertFalse($row->stock_sync_enabled);

        // Eloquent ile, izin alanı verilmeden — `$fillable` dışında olduğu için verilse de yazılmaz.
        $viaModel = TenantContext::runFor($tenant->id, function () use ($tenant): ChannelConnection {
            $c = ChannelConnection::query()->create([
                'tenant_id' => $tenant->id, 'channel_type_code' => 'n11', 'label' => 'İç araç',
                'external_account_id' => 'ic-'.uniqid(), 'status' => 'active', 'settings' => [],
                'product_publish_enabled' => true, 'stock_sync_enabled' => true, 'price_sync_enabled' => true,
            ]);

            return $c->fresh();
        });
        $this->assertFalse($viaModel->product_publish_enabled, 'Toplu atama izni açamamalı.');
        $this->assertFalse($viaModel->stock_sync_enabled);
        $this->assertFalse($viaModel->price_sync_enabled);
    }

    #[Test]
    public function a_db_created_hb_connection_with_valid_credentials_publishes_nothing_even_via_a_directly_dispatched_job(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();

        // İzin kapalı ve kimlik geçerli: panel akışı tamamen atlanır.
        $this->setWrite($connection, OutboundWrite::PRODUCT_PUBLISH, false);

        $this->assertThrows(
            fn () => $this->publish($tenant, $product, $connection),
            OutboundWriteDisabled::class,
        );
        $this->assertSame(0, TenantContext::runFor($tenant->id, fn () => Listing::query()->count()), 'Kapalı izinde satır yaratılmamalı.');

        // Doğrudan listing + operasyon + iş — `PublishListing`'i de atlar.
        $variant = reset($variants);
        $listing = TenantContext::runFor($tenant->id, fn () => Listing::query()->create([
            'tenant_id' => $tenant->id, 'channel_connection_id' => $connection->id, 'variant_id' => $variant->id, 'lifecycle_status' => 'draft',
        ]));
        $operation = $this->openOperation($tenant, $listing, SyncDomain::CONTENT, 1);

        PushListing::dispatch($operation->id, $tenant->id);
        Queue::assertPushed(PushListing::class);
        $this->runPush($tenant, $operation->id);

        $this->assertSame([], $this->sent);
        $this->assertBlocked($tenant, $operation->id, PublishReason::PRODUCT_PUBLISHING_DISABLED);
    }

    #[Test]
    public function a_forged_publication_request_is_rejected_server_side(): void
    {
        [$tenant, $connection, $product] = $this->readyHb();
        $this->setWrite($connection, OutboundWrite::PRODUCT_PUBLISH, false);

        $this->actingAs($this->owner)
            ->post("/products/{$product->id}/channels", ['connection_id' => $connection->id])
            ->assertRedirect("/products/{$product->id}/channels")
            ->assertSessionHas('warning', PublishReason::PRODUCT_PUBLISHING_DISABLED->label());

        $this->assertSame(0, TenantContext::runFor($tenant->id, fn () => Listing::query()->count()));
        $this->assertSame(0, TenantContext::runFor($tenant->id, fn () => SyncOperation::query()->count()));
        Queue::assertNothingPushed();
        $this->assertSame([], $this->sent);
    }

    #[Test]
    public function a_job_queued_while_enabled_writes_nothing_if_publishing_is_disabled_before_it_runs(): void
    {
        [$tenant, $connection, $product] = $this->readyHb();

        // T0 açık → T1 iş kuyrukta → T2 yönetici kapatır → T3 işçi çalışır.
        $operations = $this->publish($tenant, $product, $connection);
        Queue::assertPushed(PushListing::class, 3);

        $this->setWrite($connection, OutboundWrite::PRODUCT_PUBLISH, false);

        foreach ($operations as $op) {
            $this->runPush($tenant, $op);
            $this->assertBlocked($tenant, $op, PublishReason::PRODUCT_PUBLISHING_DISABLED);
        }

        $this->assertSame([], $this->sent);
    }

    #[Test]
    public function a_retrying_publication_stops_writing_once_publishing_is_disabled_and_keeps_listing_history(): void
    {
        [$tenant, $connection, $product, $variants] = $this->readyHb();
        $operations = $this->publish($tenant, $product, $connection);

        // İlk deneme sunucu hatası alır → yeniden deneme.
        $this->importAnswers[] = Http::response(['message' => 'down'], 503);
        $this->runPush($tenant, $operations[0])->assertReleased();
        $this->assertSame(SyncOperationStatus::RETRYING, $this->operation($tenant, $operations[0])->status);

        $sentBefore = count($this->sent);
        $listing = $this->listing($tenant, reset($variants), $connection);
        $claim = $listing->create_requested_at;

        $this->setWrite($connection, OutboundWrite::PRODUCT_PUBLISH, false);

        $this->runPush($tenant, $operations[0])->assertNotReleased();

        $this->assertCount($sentBefore, $this->sent, 'Yeniden deneme kanala yazmamalı.');
        $this->assertBlocked($tenant, $operations[0], PublishReason::PRODUCT_PUBLISHING_DISABLED);
        $this->assertSame(1, $this->attempts($tenant, $operations[0]), 'Kapı deneme AÇMAZ.');

        $kept = $listing->fresh();
        $this->assertNotNull($kept, 'Geçmiş listing satırı silinmemeli.');
        $this->assertEquals($claim, $kept->create_requested_at, 'Belirsiz yaratma talebi korunmalı.');
    }

    #[Test]
    public function the_gate_fails_closed_for_another_tenant_null_ids_and_missing_rows(): void
    {
        [$tenant, $connection] = $this->readyHb();
        $other = $this->tenant();
        $gate = app(OutboundWriteGate::class);

        $this->assertTrue($gate->allows($connection->id, $tenant->id, OutboundWrite::PRODUCT_PUBLISH));
        $this->assertFalse($gate->allows($connection->id, $other->id, OutboundWrite::PRODUCT_PUBLISH), 'Başka kiracının bağlantısı.');
        $this->assertFalse($gate->allows(null, $tenant->id, OutboundWrite::PRODUCT_PUBLISH));
        $this->assertFalse($gate->allows($connection->id, null, OutboundWrite::PRODUCT_PUBLISH));
        $this->assertFalse($gate->allows((string) Uuid::v7(), $tenant->id, OutboundWrite::PRODUCT_PUBLISH));
    }

    #[Test]
    public function another_tenants_product_cannot_be_published_through_a_connection_it_does_not_own(): void
    {
        [, $connection] = $this->readyHb();
        $other = $this->tenant();
        [$foreignProduct] = $this->tshirt($other, 'YABANCI-1');

        $this->assertThrows(
            fn () => TenantContext::runFor($other->id, fn () => app(PublishListing::class)->run($foreignProduct->fresh(), $connection)),
            OutboundWriteDisabled::class,
        );
        $this->assertSame(0, TenantContext::runAsSystem(fn () => Listing::query()->count()));
    }

    #[Test]
    public function read_only_lookups_keep_working_while_every_write_is_disabled(): void
    {
        [$tenant, $connection, , $variants] = $this->readyHb();

        foreach (OutboundWrite::cases() as $write) {
            $this->setWrite($connection, $write, false);
        }

        $remote = TenantContext::runFor($tenant->id, fn () => app(AdapterRegistry::class)->for($connection->fresh())->findExistingListing(reset($variants)));

        $this->assertNull($remote);
        $this->assertTrue(collect($this->sent)->contains(fn (string $s) => str_contains($s, 'check-product-status')), 'Okuma isteği gitmeli — bağlantı "yok" sayılmaz.');
    }

    // ─────────────────────────────────────────── stok / fiyat kapısı

    #[Test]
    public function hb_stock_push_is_blocked_while_stock_sync_is_disabled_and_reaches_the_channel_when_enabled(): void
    {
        [$tenant, $connection, , $variants] = $this->readyHb();
        $listing = $this->liveListing($tenant, $connection, reset($variants));

        $this->setWrite($connection, OutboundWrite::STOCK_SYNC, false);
        $blocked = $this->openOperation($tenant, $listing, SyncDomain::INVENTORY, 1);
        $this->runInventory($tenant, $blocked->id);

        $this->assertSame([], $this->sent);
        $this->assertBlocked($tenant, $blocked->id, PublishReason::STOCK_SYNC_DISABLED, SyncDomain::INVENTORY);

        $this->setWrite($connection, OutboundWrite::STOCK_SYNC, true);
        $allowed = $this->openOperation($tenant, $listing, SyncDomain::INVENTORY, 2);
        $this->runInventory($tenant, $allowed->id);

        $this->assertNotSame([], $this->sent, 'İzin açıkken stok isteği gitmeli.');
        $this->assertSame(1, $this->attempts($tenant, $allowed->id));
    }

    #[Test]
    public function hb_price_push_is_blocked_while_price_sync_is_disabled_and_reaches_the_channel_when_enabled(): void
    {
        [$tenant, $connection, , $variants] = $this->readyHb();
        $listing = $this->liveListing($tenant, $connection, reset($variants));

        $this->setWrite($connection, OutboundWrite::PRICE_SYNC, false);
        $blocked = $this->openOperation($tenant, $listing, SyncDomain::PRICE, 1);
        $this->runPrices($tenant, $blocked->id);

        $this->assertSame([], $this->sent);
        $this->assertBlocked($tenant, $blocked->id, PublishReason::PRICE_SYNC_DISABLED, SyncDomain::PRICE);

        $this->setWrite($connection, OutboundWrite::PRICE_SYNC, true);
        $allowed = $this->openOperation($tenant, $listing, SyncDomain::PRICE, 2);
        $this->runPrices($tenant, $allowed->id);

        $this->assertNotSame([], $this->sent, 'İzin açıkken fiyat isteği gitmeli.');
        $this->assertSame(1, $this->attempts($tenant, $allowed->id));
    }

    #[Test]
    public function n11_stock_and_price_writes_are_blocked_independently_of_publishing(): void
    {
        [$tenant, $connection, , $variants] = $this->readyN11();
        $listing = $this->liveListing($tenant, $connection, reset($variants));

        // Yayın açık, stok/fiyat kapalı: izinler birbirinden bağımsız.
        $this->setWrite($connection, OutboundWrite::STOCK_SYNC, false);
        $this->setWrite($connection, OutboundWrite::PRICE_SYNC, false);

        $stock = $this->openOperation($tenant, $listing, SyncDomain::INVENTORY, 1);
        $price = $this->openOperation($tenant, $listing, SyncDomain::PRICE, 1);
        $this->runInventory($tenant, $stock->id);
        $this->runPrices($tenant, $price->id);

        $this->assertSame([], $this->sent);
        $this->assertBlocked($tenant, $stock->id, PublishReason::STOCK_SYNC_DISABLED, SyncDomain::INVENTORY);
        $this->assertBlocked($tenant, $price->id, PublishReason::PRICE_SYNC_DISABLED, SyncDomain::PRICE);
    }

    // ─────────────────────────────────────────── yardımcılar

    private function setWrite(ChannelConnection $connection, OutboundWrite $write, bool $enabled): void
    {
        TenantContext::runAsSystem(fn () => DB::table('channel_connections')->where('id', $connection->id)->update([$write->column() => $enabled]));
    }

    private function liveListing(Tenant $tenant, ChannelConnection $connection, Variant $variant): Listing
    {
        return TenantContext::runFor($tenant->id, fn () => Listing::query()->create([
            'tenant_id' => $tenant->id, 'channel_connection_id' => $connection->id, 'variant_id' => $variant->id,
            'lifecycle_status' => 'live', 'external_id' => (string) $variant->sku, 'listed_at' => now(),
            'channel_metadata' => ['hb_sku' => 'HBV'.$variant->sku],
        ]));
    }

    private function openOperation(Tenant $tenant, Listing $listing, SyncDomain $domain, int $version): SyncOperation
    {
        $operation = TenantContext::runFor($tenant->id, fn () => app(OpenSyncOperation::class)->run(
            listing: $listing, domain: $domain, eventVersion: $version, intent: SyncIntent::NORMAL_SYNC,
        ));
        $this->assertNotNull($operation);

        return $operation;
    }

    private function runInventory(Tenant $tenant, string $operationId): void
    {
        Redis::connection()->flushdb();
        (new PushInventory($operationId, $tenant->id))->withFakeQueueInteractions()
            ->handle(app(InventoryBatchBuilder::class), app(SyncResultRecorder::class), app(AdapterRegistry::class));
    }

    private function runPrices(Tenant $tenant, string $operationId): void
    {
        Redis::connection()->flushdb();
        (new PushPrices($operationId, $tenant->id))->withFakeQueueInteractions()
            ->handle(app(PriceBatchBuilder::class), app(SyncResultRecorder::class), app(AdapterRegistry::class));
    }

    private function operation(Tenant $tenant, string $id): SyncOperation
    {
        return TenantContext::runFor($tenant->id, fn () => SyncOperation::query()->findOrFail($id));
    }

    private function attempts(Tenant $tenant, string $operationId): int
    {
        return TenantContext::runFor($tenant->id, fn () => SyncAttempt::query()->where('sync_operation_id', $operationId)->count());
    }

    private function assertBlocked(Tenant $tenant, string $operationId, PublishReason $reason, SyncDomain $domain = SyncDomain::CONTENT): void
    {
        $operation = $this->operation($tenant, $operationId);
        $this->assertSame(SyncOperationStatus::DEAD, $operation->status);

        $state = TenantContext::runFor($tenant->id, fn () => ListingSyncState::query()
            ->where('listing_id', $operation->entity_id)->where('domain', $domain->value)->first());
        $this->assertSame($reason->value, $state?->reason_code);
        $this->assertNull($state?->last_error, 'Durumda düzyazı tutulmaz; metin panelde çevrilir.');
    }
}
