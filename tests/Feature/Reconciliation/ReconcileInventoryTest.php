<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Reconciliation\Actions\QueueRepair;
use App\Domain\Reconciliation\Actions\ReconcileConnection;
use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Reconciliation\Models\ReconciliationItem;
use App\Domain\Reconciliation\Models\ReconciliationRun;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\Support\Channels\ProgrammableInventoryAdapter;
use Tests\TestCase;

/**
 * §10 · Mutabakat — sürüklenme tespiti ve onarım.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · Reconciliation Engine, §9 · çakışma
 * politikası, §1 · Kararlar 16–17.
 *
 * DEĞİŞMEZ KURAL — KARŞILAŞTIRMA GİDEN DEĞERLE YAPILIR:
 *   Beklenen uzak değer `max(available, 0)`'dır. Kanonik bakiye fazla satış
 *   nedeniyle negatifse kanaldaki 0 DOĞRUDUR ve sürüklenme DEĞİLDİR. Ham
 *   kanonik değerle karşılaştırılsaydı her fazla satış kalıcı sürüklenme
 *   olarak raporlanır ve SONSUZ ONARIM DÖNGÜSÜ doğardı.
 *
 * DEĞİŞMEZ KURAL — ONARIM SÜRÜM KAPISINI ATLAR, SÜRÜMÜ ARTIRMAZ:
 *   Mutabakat uzak durumu okumuş ve farkı KANITLAMIŞTIR; "bu sürüm zaten
 *   gönderildi" bilgisi orada yanlıştır. Yapay sürüm artışı sıra dışı olay
 *   elemesini bozar ve gerçek bir değişikliği bayat gösterir.
 *
 * DEĞİŞMEZ KURAL — `error_permanent` ADAY SEÇİLMEZ:
 *   Düzeltilemeyecek listing her turda yeniden kontrol edilirse mutabakat
 *   bütçesi boşa gider ve gerçek sürüklenmeler geç fark edilir.
 */
final class ReconcileInventoryTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        ProgrammableInventoryAdapter::reset();
    }

    protected function tearDown(): void
    {
        ProgrammableInventoryAdapter::reset();

        parent::tearDown();
    }

    /**
     * Kanonik ile uzak aynıysa MATCHED — onarım açılmaz.
     */
    #[Test]
    public function matching_state_is_recorded_without_repair(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 7);
        $this->markRecentlySold($tenant, $variant);      // 7 → 6

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 6);

        $run = $this->reconcile($tenant, $connection);

        $this->assertSame(1, $run->checked_count);
        $this->assertSame(0, $run->drift_count);
        $this->assertSame('completed', $run->status);

        $item = $this->itemFor($tenant, $listing);

        $this->assertSame('MATCHED', $item->status);
        $this->assertNull($item->drift_magnitude);

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => SyncOperation::query()->count()),
            'Eşitlikte onarım operasyonu AÇILMAZ.',
        );
    }

    /**
     * Fark varsa DRIFT_DETECTED ve REPAIR niyetiyle onarım açılır.
     */
    #[Test]
    public function drift_is_detected_and_repair_operation_is_opened(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 17);
        $this->markRecentlySold($tenant, $variant);      // 17 → 16

        // Kanalda 19 var, bizde 16 — sürüklenme.
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 19);

        $run = $this->reconcile($tenant, $connection);

        $this->assertSame(1, $run->drift_count);

        $item = $this->itemFor($tenant, $listing);

        $this->assertSame('REPAIR_QUEUED', $item->status);
        $this->assertSame(3, $item->drift_magnitude, 'Sürüklenme büyüklüğü |16 − 19| olmalı.');

        // HAM girdi denetim için saklandı.
        $this->assertSame(16, $item->local_value['available']);
        $this->assertSame(16, $item->local_value['expected_remote']);
        $this->assertSame(19, $item->remote_value['quantity']);

        $operation = $this->asTenant($tenant, fn () => SyncOperation::query()->firstOrFail());

        $this->assertSame(SyncIntent::REPAIR, $operation->intent);
        $this->assertSame($item->id, $operation->reconciliation_item_id);
        $this->assertSame($operation->id, $item->repair_operation_id);

        // Onarım anahtarı kalem kimliğini taşır — normalle ÇAKIŞMAZ.
        $this->assertStringContainsString(':repair:'.$item->id, $operation->idempotency_key);
    }

    /**
     * FAZLA SATIŞ SÜRÜKLENME DEĞİLDİR — karşılaştırma `max(available, 0)` ile.
     *
     * Kanonik −2, kanalda 0: beklenen uzak değer de 0'dır ve ikisi EŞLEŞİR.
     * Ham kanonik değerle karşılaştırılsaydı her fazla satış kalıcı
     * sürüklenme olarak raporlanır ve sonsuz onarım döngüsü doğardı.
     */
    #[Test]
    public function oversold_variant_matching_zero_is_not_drift(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 3);
        $this->sell($tenant, $variant, 5);          // available = −2

        $this->assertSame(-2, $this->levelFor($tenant, $variant)->available);

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 0);

        $run = $this->reconcile($tenant, $connection);

        $item = $this->itemFor($tenant, $listing);

        $this->assertSame('MATCHED', $item->status, 'Fazla satış sürüklenme SAYILMAMALI.');
        $this->assertSame(0, $run->drift_count);

        // HAM kanonik değer yine de kaydedilir — denetim için.
        $this->assertSame(-2, $item->local_value['available']);
        $this->assertSame(0, $item->local_value['expected_remote']);

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => SyncOperation::query()->count()),
            'Fazla satış için onarım açılmamalı — sonsuz döngü olurdu.',
        );

        // Kanonik durum DEĞİŞMEDİ.
        $this->assertSame(-2, $this->levelFor($tenant, $variant)->available);
        $this->assertLedgerMatchesProjectionForTenant($tenant->id);
    }

    /**
     * ONARIM `desired_version`'ı ARTIRMAZ ve sürüm kapısına takılmaz.
     *
     * Aynı iş sürümü yeniden gönderilir. Yapay artış sıra dışı olay
     * elemesini bozar ve gerçek bir değişikliği bayat gösterir.
     */
    #[Test]
    public function repair_does_not_advance_desired_version(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 5);
        $this->markRecentlySold($tenant, $variant);      // aday olsun diye

        // Bu sürüm ZATEN gönderilmiş sayılıyor: normal kapı bunu elerdi
        // (synced_version >= eventVersion → "zaten gönderildi").
        $level = $this->levelFor($tenant, $variant);

        $this->asTenant($tenant, fn () => ListingSyncState::query()->create([
            'tenant_id' => $tenant->id,
            'listing_id' => $listing->id,
            'domain' => SyncDomain::INVENTORY->value,
            'desired_version' => $level->version,
            'synced_version' => $level->version,
            'status' => 'synced',
            'last_requested_at' => now()->subHours(2),
        ]));

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);

        $this->reconcile($tenant, $connection);

        $state = $this->stateFor($tenant, $listing);

        $this->assertSame(
            $level->version,
            $state->desired_version,
            'Onarım desired_version’ı ARTIRMAMALI.',
        );

        // Kapı atlandığı için operasyon YİNE DE açıldı.
        $operation = $this->asTenant($tenant, fn () => SyncOperation::query()->firstOrFail());

        $this->assertSame(SyncIntent::REPAIR, $operation->intent);
        $this->assertSame($level->version, $operation->entity_version);
    }

    /**
     * AYNI KALEM İKİ KEZ İŞLENSE TEK OPERASYON — anahtar kalem kimliğini taşır.
     */
    #[Test]
    public function repairing_the_same_item_twice_yields_one_operation(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 4);
        $this->markRecentlySold($tenant, $variant);

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 9);

        $this->reconcile($tenant, $connection);

        $item = $this->itemFor($tenant, $listing);

        // Kalemi yeniden onarıma sok — anahtar aynı kalmalı.
        $this->asTenant($tenant, fn () => app(QueueRepair::class)
            ->run($item->fresh()));

        $this->assertSame(
            1,
            $this->asTenant($tenant, fn (): int => SyncOperation::query()->count()),
            'Aynı mutabakat kalemi tek operasyon üretmeli.',
        );
    }

    /**
     * Kanalda ürün yoksa REMOTE_MISSING — otomatik onarım YAPILMAZ.
     *
     * Yeniden listeleme kullanıcı onayı ister; sessizce yeniden yaratmak
     * kanalda kopya ürün açardı.
     */
    #[Test]
    public function missing_remote_product_is_flagged_without_auto_repair(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 6);
        $this->markRecentlySold($tenant, $variant);

        // Kanal bu kimliği hiç döndürmüyor.
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '999', 3);

        $this->reconcile($tenant, $connection);

        $item = $this->itemFor($tenant, $listing);

        $this->assertSame('REMOTE_MISSING', $item->status);

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => SyncOperation::query()->count()),
            'REMOTE_MISSING otomatik onarım AÇMAZ — yeniden listeleme kullanıcı onayı ister.',
        );
    }

    /**
     * `error_permanent` satırı ADAY SEÇİLMEZ.
     *
     * Düzeltilemeyecek listing her turda kontrol edilirse bütçe boşa gider.
     */
    #[Test]
    public function permanently_failed_listing_is_never_a_candidate(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 5);
        $this->markRecentlySold($tenant, $variant);

        $this->asTenant($tenant, fn () => ListingSyncState::query()->create([
            'tenant_id' => $tenant->id,
            'listing_id' => $listing->id,
            'domain' => SyncDomain::INVENTORY->value,
            'desired_version' => 3,
            'synced_version' => 1,
            'status' => 'error_permanent',
            'last_error' => 'kimlik doğrulama başarısız',
        ]));

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);

        $run = $this->reconcile($tenant, $connection);

        $this->assertSame(0, $run->candidates_count, 'error_permanent aday olmamalı.');
        $this->assertSame(0, $run->checked_count);

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => ReconciliationItem::query()->count()),
        );
    }

    /**
     * Canlı olmayan listing aday DEĞİLDİR — kanalda karşılığı yok.
     */
    #[Test]
    public function non_live_listing_is_never_a_candidate(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $this->listing($tenant, $variant, $connection, externalId: '10', lifecycle: 'draft');

        $this->seedStock($tenant, $variant, 5);
        $this->markRecentlySold($tenant, $variant);

        $run = $this->reconcile($tenant, $connection);

        $this->assertSame(0, $run->candidates_count);
    }

    /**
     * BAŞKA BAĞLANTININ listing'i bu turda kontrol EDİLMEZ.
     *
     * Kova ve bütçe bağlantı başınadır; başka bağlantının satırını okumak
     * hem yanlış kanala sorar hem bütçeyi çalar.
     */
    #[Test]
    public function reconciliation_never_crosses_connections(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $other = $this->connection($tenant, 'trendyol');

        $this->listing($tenant, $variant, $connection, externalId: '10');

        $otherVariant = $this->asTenant($tenant, fn () => Variant::factory()->create());
        $this->listing($tenant, $otherVariant, $other, externalId: '55');

        $this->seedStock($tenant, $variant, 5);
        $this->seedStock($tenant, $otherVariant, 5);
        $this->markRecentlySold($tenant, $variant);
        $this->markRecentlySold($tenant, $otherVariant);

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 5);

        $run = $this->reconcile($tenant, $connection);

        $this->assertSame(1, $run->candidates_count, 'Yalnızca bu bağlantının listing’i aday olmalı.');
    }

    /**
     * BAŞKA KİRACININ listing'i asla görünmez.
     */
    #[Test]
    public function reconciliation_never_crosses_tenants(): void
    {
        [$tenantA, $variantA, $connectionA] = $this->makeContext();
        [$tenantB, $variantB, $connectionB] = $this->makeContext();

        $this->listing($tenantA, $variantA, $connectionA, externalId: '10');
        $this->listing($tenantB, $variantB, $connectionB, externalId: '20');

        $this->seedStock($tenantA, $variantA, 5);
        $this->seedStock($tenantB, $variantB, 5);
        $this->markRecentlySold($tenantA, $variantA);
        $this->markRecentlySold($tenantB, $variantB);

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 5);

        $run = $this->reconcile($tenantA, $connectionA);

        $this->assertSame(1, $run->candidates_count);

        $this->assertSame(
            0,
            $this->asTenant($tenantB, fn (): int => ReconciliationItem::query()->count()),
            'Başka kiracının kalemi yazılmamalı.',
        );
    }

    /**
     * Uzak durum okundu: `remote_hash` ve `last_observed_at` damgalanır.
     *
     * Bu iki alan §9'daki üç durumun üçüncüsüdür ve çakışma tespiti
     * bunların DOLU olmasına dayanır.
     */
    #[Test]
    public function remote_observation_is_stamped_on_sync_state(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 8);
        $this->markRecentlySold($tenant, $variant);      // 8 → 7

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 7);

        $this->reconcile($tenant, $connection);

        $state = $this->stateFor($tenant, $listing);

        $this->assertNotNull($state->remote_hash, 'Uzak gözlem hash’i yazılmalı.');
        $this->assertNotNull($state->last_observed_at, 'Gözlem anı damgalanmalı.');
    }

    /**
     * BÜTÇE AŞILMAZ — sıcak katman bağlantı başına 50 listing.
     */
    #[Test]
    public function candidate_budget_is_respected(): void
    {
        [$tenant, , $connection] = $this->makeContext();

        for ($i = 0; $i < 5; $i++) {
            $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

            $this->listing($tenant, $variant, $connection, externalId: (string) (100 + $i));
            $this->seedStock($tenant, $variant, 5);
            $this->markRecentlySold($tenant, $variant);
        }

        $run = $this->reconcile($tenant, $connection, budget: 2);

        $this->assertSame(2, $run->candidates_count, 'Bütçe aşılmamalı.');
        $this->assertSame(2, $run->checked_count);
    }

    /**
     * Kanal okunamıyorsa REMOTE_UNREACHABLE — sürüklenme DEĞİL, altyapı sorunu.
     *
     * Onarım açılmaz: uzak durum bilinmiyor ve "fark var" iddiası
     * kanıtlanmamıştır.
     */
    #[Test]
    public function unreachable_channel_is_not_treated_as_drift(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 5);
        $this->markRecentlySold($tenant, $variant);

        ProgrammableInventoryAdapter::failFetchOn('woocommerce');

        $run = $this->reconcile($tenant, $connection);

        $this->assertSame('failed', $run->status);
        $this->assertSame(0, $run->drift_count);

        $item = $this->itemFor($tenant, $listing);

        $this->assertSame('REMOTE_UNREACHABLE', $item->status);

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => SyncOperation::query()->count()),
            'Okunamayan kanal için onarım AÇILMAZ — fark kanıtlanmadı.',
        );
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: Variant, 2: ChannelConnection} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Mutabakat '.uniqid(),
            owner: User::factory()->create(),
        );

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        return [$tenant, $variant, $this->connection($tenant, 'woocommerce')];
    }

    private function connection(Tenant $tenant, string $code): ChannelConnection
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => ucfirst($code),
                'kind' => 'marketplace',
                'adapter_class' => ProgrammableInventoryAdapter::class,
                'is_active' => true,
            ],
        ));

        return $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'channel_type_code' => $code,
        ]));
    }

    private function listing(
        Tenant $tenant,
        Variant $variant,
        ChannelConnection $connection,
        string $externalId,
        string $lifecycle = 'live',
    ): Listing {
        return $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'external_id' => $externalId,
            'lifecycle_status' => $lifecycle,
        ]));
    }

    /** Açılış stoğu LEDGER üzerinden. */
    private function seedStock(Tenant $tenant, Variant $variant, int $quantity): void
    {
        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $this->warehouse($tenant)->id,
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: $quantity,
            idempotencyKey: 'import:'.$variant->id,
            sourceType: 'test',
        ));
    }

    private function sell(Tenant $tenant, Variant $variant, int $quantity): void
    {
        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $this->warehouse($tenant)->id,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: $quantity,
            idempotencyKey: 'sale:'.$variant->id,
            sourceType: 'test',
        ));
    }

    /**
     * "Son 30 dakikada satıldı" adayı yaratır.
     *
     * Aday sorgusu SALE hareketine bakar; açılış IMPORT'u yeterli değildir.
     */
    private function markRecentlySold(Tenant $tenant, Variant $variant): void
    {
        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $this->warehouse($tenant)->id,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 1,
            idempotencyKey: 'recent-sale:'.$variant->id,
            sourceType: 'test',
        ));
    }

    private function warehouse(Tenant $tenant): Warehouse
    {
        return $this->asTenant($tenant, fn () => Warehouse::query()
            ->where('is_default', true)
            ->firstOrFail());
    }

    private function levelFor(Tenant $tenant, Variant $variant): InventoryLevel
    {
        return $this->asTenant($tenant, fn () => InventoryLevel::query()
            ->where('variant_id', $variant->id)
            ->firstOrFail());
    }

    private function reconcile(
        Tenant $tenant,
        ChannelConnection $connection,
        int $budget = 50,
    ): ReconciliationRun {
        return $this->asTenant($tenant, fn () => app(ReconcileConnection::class)->run(
            connection: $connection,
            scope: ReconciliationScope::HOT,
            budget: $budget,
        ));
    }

    /**
     * SON KALEM `id` ÜZERİNDEN SEÇİLİR — `checked_at` SANİYE hassasiyetli
     * ve aynı saniyede yazılan iki kalemde sıra belirsiz kalır. `id`
     * UUIDv7'dir; zaman sıralı ve saniye içinde de ayırt edici.
     */
    private function itemFor(Tenant $tenant, Listing $listing): ReconciliationItem
    {
        return $this->asTenant($tenant, fn () => ReconciliationItem::query()
            ->where('listing_id', $listing->id)
            ->orderByDesc('id')
            ->firstOrFail());
    }

    private function stateFor(Tenant $tenant, Listing $listing): ListingSyncState
    {
        return $this->asTenant($tenant, fn () => ListingSyncState::query()
            ->where('listing_id', $listing->id)
            ->where('domain', SyncDomain::INVENTORY->value)
            ->firstOrFail());
    }
}
