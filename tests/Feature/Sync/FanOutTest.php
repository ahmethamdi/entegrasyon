<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Consumers\InventoryLevelChangedConsumer;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushInventory;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

/**
 * P0 testi T3 — fan-out listing başına operasyon üretir.
 *
 * Mimari Karar Dokümanı v2.2 · §18 · T3 (faz 1.5), §6 · Fan-out tüketicisi,
 * §1 · Karar 11.
 *
 * DEĞİŞMEZ KURAL: fan-out outbox TÜKETİCİSİNDE yapılır; bir olay, varyantın
 * canlı listing sayısı kadar ayrı sync_operation üretir. Gruplama ise
 * InventoryBatchBuilder'ın işidir. Tüketici gruplama yapmaz, batch builder
 * fan-out yapmaz.
 */
final class FanOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bu sınıf PLANLAMAYI sınar, gönderimi değil. Kuyruk sahte olmazsa
        // sync sürücü PushInventory'yi derhal çalıştırır ve kuyruk kancaları
        // kiracı bağlamını temizleyerek tüketicinin kalan turlarını bozar —
        // P0 izolasyon korumasının doğru ama burada istenmeyen davranışı.
        // Gerçek worker asenkrondur; gönderim PushInventoryTest'te sınanır.
        Queue::fake();
    }

    /**
     * T3 — tek olay, üç canlı listing → üç ayrı operasyon.
     */
    #[Test]
    public function single_event_fans_out_to_one_operation_per_listing(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listings = $this->listVariantOn($tenant, $variant, ['woocommerce', 'trendyol', 'shopify']);

        $event = $this->inventoryChangedEvent($tenant, $variant, version: 182);

        $this->asTenant($tenant, fn () => app(InventoryLevelChangedConsumer::class)->handle($event));

        $operations = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('operation_type', 'INVENTORY_PUSH')
            ->get());

        $this->assertCount(3, $operations);

        // Planlama bitti — downstream başarısı BEKLENMEZ.
        $this->assertSame(3, $event->fresh()->operations_planned);
        $this->assertNotNull($event->fresh()->consumed_at);

        // Her operasyon AYRI listing, AYRI anahtar, AYNI olay.
        $this->assertCount(3, $operations->pluck('entity_id')->unique());
        $this->assertCount(3, $operations->pluck('idempotency_key')->unique());
        $this->assertCount(1, $operations->pluck('outbox_event_id')->unique());
        $this->assertSame($event->id, $operations->first()->outbox_event_id);

        // Granülerlik: listing × domain × sürüm.
        foreach ($operations as $operation) {
            $this->assertSame(182, $operation->entity_version);
            $this->assertSame('listing', $operation->entity_type);
            $this->assertSame(SyncIntent::NORMAL_SYNC, $operation->intent);
            $this->assertSame(SyncOperationStatus::PENDING, $operation->status);

            $this->assertSame(
                "inv:{$operation->entity_id}:182",
                $operation->idempotency_key,
            );
        }

        // Operasyonlar üç AYRI bağlantıya dağıldı.
        $this->assertCount(3, $operations->pluck('channel_connection_id')->unique());

        // Her listing'in kendi sync state satırı ilerledi.
        foreach ($operations as $operation) {
            $state = $this->stateFor($tenant, $operation->entity_id);

            $this->assertSame(182, $state->desired_version);
            $this->assertSame(0, $state->synced_version);
            $this->assertTrue($state->is_dirty, 'desired > synced iken is_dirty true olmalı.');
            $this->assertSame('pending', $state->status);
            $this->assertNotNull($state->last_requested_at);
        }

        $this->assertCount(3, $listings);
    }

    /** Canlı olmayan listeler fan-out hedefi değildir. */
    #[Test]
    public function draft_and_delisted_listings_are_not_fanned_out(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $this->asTenant($tenant, function () use ($variant): void {
            Listing::factory()->for($this->connection('woocommerce'), 'connection')
                ->create(['variant_id' => $variant->id]);

            Listing::factory()->draft()->for($this->connection('trendyol'), 'connection')
                ->create(['variant_id' => $variant->id]);

            Listing::factory()->delisted()->for($this->connection('shopify'), 'connection')
                ->create(['variant_id' => $variant->id]);
        });

        $event = $this->inventoryChangedEvent($tenant, $variant, version: 5);

        $this->asTenant($tenant, fn () => app(InventoryLevelChangedConsumer::class)->handle($event));

        // Yalnızca canlı olan için operasyon açıldı.
        $this->assertSame(1, $this->asTenant($tenant, fn () => SyncOperation::query()->count()));
        $this->assertSame(1, $event->fresh()->operations_planned);
    }

    /**
     * Kaynak kanal dışlaması — bir ENİYİLEME, doğruluk kuralı değil.
     *
     * Stok değişimi Woo siparişinden geldiyse Woo'ya geri yazmak gereksizdir.
     * Ama kanal otorite dışına ÇIKARILMAZ: mutabakat onu da kontrol eder ve
     * gerçek bir sürüklenme bulursa onarım operasyonu açar (§10).
     */
    #[Test]
    public function origin_connection_is_skipped_in_fan_out(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listings = $this->listVariantOn($tenant, $variant, ['woocommerce', 'trendyol']);
        $origin = $listings[0];

        $event = $this->inventoryChangedEvent(
            $tenant,
            $variant,
            version: 7,
            originConnectionId: $origin->channel_connection_id,
        );

        $this->asTenant($tenant, fn () => app(InventoryLevelChangedConsumer::class)->handle($event));

        $operations = $this->asTenant($tenant, fn () => SyncOperation::query()->get());

        $this->assertCount(1, $operations);
        $this->assertNotSame($origin->id, $operations->first()->entity_id);
        $this->assertSame(1, $event->fresh()->operations_planned);
    }

    /**
     * Hiç canlı listing yoksa bile olay TÜKETİLMİŞ damgalanır.
     *
     * Erken çıkışta damgalanmazsa seviye 1 bütünlük taraması olayı kayıp
     * sanar ve sonsuza kadar yeniden yayınlar (§6).
     */
    #[Test]
    public function event_is_marked_consumed_even_with_no_listings(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $event = $this->inventoryChangedEvent($tenant, $variant, version: 3);

        $this->asTenant($tenant, fn () => app(InventoryLevelChangedConsumer::class)->handle($event));

        $this->assertNotNull($event->fresh()->consumed_at, 'Erken çıkışta bile damgalanmalı.');
        $this->assertSame(0, $event->fresh()->operations_planned);
        $this->assertSame(0, $this->asTenant($tenant, fn () => SyncOperation::query()->count()));
    }

    /**
     * Tüketici idempotenttir — aynı olay iki kez işlenirse operasyon ikilenmez.
     *
     * Relay çökme senaryosunda olay iki kez yayınlanabilir (§6); ikinci tur
     * sürüm kapısına takılır ve yeni operasyon üretmez.
     */
    #[Test]
    public function replaying_same_event_does_not_duplicate_operations(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $this->listVariantOn($tenant, $variant, ['woocommerce', 'trendyol']);

        $event = $this->inventoryChangedEvent($tenant, $variant, version: 42);

        $this->asTenant($tenant, function () use ($event): void {
            app(InventoryLevelChangedConsumer::class)->handle($event);
            app(InventoryLevelChangedConsumer::class)->handle($event);
        });

        $this->assertSame(2, $this->asTenant($tenant, fn () => SyncOperation::query()->count()));
    }

    /** Bir varyantın olayı BAŞKA varyantın listelerine yayılmaz. */
    #[Test]
    public function fan_out_does_not_leak_across_variants(): void
    {
        [$tenant, $variantA] = $this->makeContext();

        $variantB = $this->asTenant($tenant, fn () => Variant::factory()->create());

        $this->listVariantOn($tenant, $variantA, ['woocommerce']);
        $this->listVariantOn($tenant, $variantB, ['trendyol']);

        $event = $this->inventoryChangedEvent($tenant, $variantA, version: 9);

        $this->asTenant($tenant, fn () => app(InventoryLevelChangedConsumer::class)->handle($event));

        $operations = $this->asTenant($tenant, fn () => SyncOperation::query()->get());

        $this->assertCount(1, $operations);

        $listing = $this->asTenant($tenant, fn () => Listing::query()
            ->where('variant_id', $variantA->id)
            ->firstOrFail());

        $this->assertSame($listing->id, $operations->first()->entity_id);
    }

    /** Fan-out başka kiracının listelerine ASLA dokunmaz. */
    #[Test]
    public function fan_out_does_not_leak_across_tenants(): void
    {
        [$tenantA, $variantA] = $this->makeContext();
        [$tenantB, $variantB] = $this->makeContext();

        $this->listVariantOn($tenantA, $variantA, ['woocommerce']);
        $this->listVariantOn($tenantB, $variantB, ['woocommerce']);

        $event = $this->inventoryChangedEvent($tenantA, $variantA, version: 4);

        $this->asTenant($tenantA, fn () => app(InventoryLevelChangedConsumer::class)->handle($event));

        $operations = $this->asSystem(fn () => SyncOperation::query()->get());

        $this->assertCount(1, $operations);
        $this->assertSame($tenantA->id, $operations->first()->tenant_id);
    }

    /**
     * Her açılan operasyon için AYRI bir iş kuyruğa girer.
     *
     * Fan-out yalnızca satır yazsaydı operasyonlar sonsuza kadar pending
     * beklerdi; zincirin dışa yönü bu dispatch ile kapanır. İş başına bir
     * operasyon: gruplama kuyrukta değil, InventoryBatchBuilder'da yapılır.
     */
    #[Test]
    public function each_opened_operation_dispatches_its_own_push_job(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $this->listVariantOn($tenant, $variant, ['woocommerce', 'trendyol', 'shopify']);

        $event = $this->inventoryChangedEvent($tenant, $variant, version: 21);

        $this->asTenant($tenant, fn () => app(InventoryLevelChangedConsumer::class)->handle($event));

        $operations = $this->asTenant($tenant, fn () => SyncOperation::query()->get());

        $this->assertCount(3, $operations);

        Queue::assertPushed(PushInventory::class, 3);

        foreach ($operations as $operation) {
            Queue::assertPushed(
                PushInventory::class,
                fn (PushInventory $job): bool => $job->operationId === $operation->id,
            );
        }
    }

    /**
     * ESKİ sürümlü olay iş ÜRETMEZ — sürüm kapısı eler.
     *
     * Sıra dışı gelen bayat bir olay kuyruğa iş atarsa worker boş yere
     * uyanır ve daha kötüsü, kapı yalnızca burada olsaydı eski sürüm
     * kanala giderdi.
     *
     * NOT: aynı sürümün tekrarı iş ÜRETİR ve bu bilinçlidir — operasyon
     * hâlâ pending'dir, tekrar dispatch teslim garantisini güçlendirir ve
     * mutlak değer gönderildiği için zararsızdır.
     */
    #[Test]
    public function stale_version_event_dispatches_no_job(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $this->listVariantOn($tenant, $variant, ['woocommerce']);

        // v30 kapıdan geçer.
        $this->asTenant($tenant, fn () => app(InventoryLevelChangedConsumer::class)->handle(
            $this->inventoryChangedEvent($tenant, $variant, version: 30)
        ));

        // İlk turun işleri sayıma karışmasın.
        Queue::fake();

        // v29 sıra dışı geldi: desired_version (30) > 29 → kapı eler.
        $event = $this->inventoryChangedEvent($tenant, $variant, version: 29);

        $this->asTenant($tenant, fn () => app(InventoryLevelChangedConsumer::class)->handle($event));

        Queue::assertNothingPushed();

        // Kapıya takılsa bile olay TÜKETİLMİŞ damgalanır.
        $this->assertNotNull($event->fresh()->consumed_at);
        $this->assertSame(0, $event->fresh()->operations_planned);

        // Bayat sürüm istenen duruma YAZILMADI.
        $listing = $this->asTenant($tenant, fn () => Listing::query()
            ->where('variant_id', $variant->id)->firstOrFail());

        $this->assertSame(30, $this->stateFor($tenant, $listing->id)->desired_version);
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: Variant} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Fan-out '.uniqid(),
            owner: User::factory()->create(),
        );

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        return [$tenant, $variant];
    }

    /**
     * Varyantı verilen kanallarda CANLI olarak listeler.
     *
     * @param  list<string>  $channelTypeCodes
     * @return list<Listing>
     */
    private function listVariantOn(Tenant $tenant, Variant $variant, array $channelTypeCodes): array
    {
        return $this->asTenant($tenant, fn (): array => array_map(
            fn (string $code): Listing => Listing::factory()->create([
                'channel_connection_id' => $this->connection($code)->id,
                'variant_id' => $variant->id,
            ]),
            $channelTypeCodes,
        ));
    }

    /**
     * Kanal bağlantısı — channel_types satırı yoksa yaratır.
     *
     * Seed yalnızca woocommerce ve trendyol taşıyor; test üçüncü bir kanalı
     * da kullanıyor.
     */
    private function connection(string $channelTypeCode): ChannelConnection
    {
        $this->asSystem(function () use ($channelTypeCode): void {
            ChannelType::query()->firstOrCreate(
                ['code' => $channelTypeCode],
                [
                    'name' => ucfirst($channelTypeCode),
                    'kind' => 'marketplace',
                    'adapter_class' => 'App\\Domain\\Channels\\Adapters\\'.ucfirst($channelTypeCode).'Adapter',
                    'is_active' => true,
                ],
            );
        });

        return ChannelConnection::factory()->create(['channel_type_code' => $channelTypeCode]);
    }

    /** InventoryLevelChanged olayı — ApplyMovement'ın yazdığı yükle aynı biçimde. */
    private function inventoryChangedEvent(
        Tenant $tenant,
        Variant $variant,
        int $version,
        ?string $originConnectionId = null,
    ): OutboxEvent {
        return $this->asTenant($tenant, fn () => OutboxEvent::record(
            aggregateType: 'inventory_level',
            aggregateId: (string) new UuidV7,
            eventType: 'InventoryLevelChanged',
            payload: [
                'warehouse_id' => (string) new UuidV7,
                'variant_id' => $variant->id,
                'on_hand' => 5,
                'reserved' => 0,
                'available' => 5,
                'version' => $version,
                'origin_connection_id' => $originConnectionId,
            ],
            tenantId: $tenant->id,
        ));
    }

    private function stateFor(Tenant $tenant, string $listingId): ListingSyncState
    {
        return $this->asTenant($tenant, fn () => ListingSyncState::query()
            ->where('listing_id', $listingId)
            ->where('domain', SyncDomain::INVENTORY->value)
            ->firstOrFail());
    }
}
