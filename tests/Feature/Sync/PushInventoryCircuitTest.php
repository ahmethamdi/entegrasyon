<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Messaging\Consumers\InventoryLevelChangedConsumer;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushInventory;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncAttempt;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\InventoryBatchBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Support\Channels\ProgrammableInventoryAdapter;
use Tests\TestCase;

/**
 * Devre kesici ve hız sınırının giden hatta bağlanması.
 *
 * Mimari Karar Dokümanı v2.2 · §12 · Devre kesici, §13 · faz 1.7.
 *
 * Devre kesici ve limiter kendi dosyalarında birim olarak sınanıyor;
 * buradaki iddia bunların GERÇEKTEN devrede olduğudur — sınıfın var olması
 * onu kimsenin çağırdığı anlamına gelmez.
 *
 * DEĞİŞMEZ KURAL — DEVRE AÇIKKEN DENEME AÇILMAZ:
 *   attempt_count = 0 kalmalı. Devre yüzünden gönderilmeyen bir operasyon
 *   "denendi ve başarısız oldu" değildir; sayacı artırmak hem yeniden
 *   deneme bütçesini boşa harcar hem seviye 2 taramasının anlamını bozar.
 */
final class PushInventoryCircuitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Redis::connection()->flushdb();
        ProgrammableInventoryAdapter::reset();
    }

    protected function tearDown(): void
    {
        Redis::connection()->flushdb();
        ProgrammableInventoryAdapter::reset();

        parent::tearDown();
    }

    /**
     * Devre AÇIKKEN kanala çağrı yapılmaz ve deneme AÇILMAZ.
     */
    #[Test]
    public function open_circuit_skips_the_push_without_opening_an_attempt(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        ProgrammableInventoryAdapter::succeedOn('woocommerce');

        $operation = $this->openOperation($tenant, $variant, version: 5);

        // Kanal ölü sayılıyor.
        app(CircuitBreaker::class)->openFor($connection->id, seconds: 300);

        $this->runJob($tenant, $operation->id);

        $this->assertSame(
            [],
            ProgrammableInventoryAdapter::pushesFor('woocommerce'),
            'Devre açıkken kanala çağrı YAPILMAMALI.',
        );

        $fresh = $this->asTenant($tenant, fn () => $operation->fresh());

        $this->assertSame(0, $fresh->attempt_count, 'Devre açıkken deneme AÇILMAMALI.');
        $this->assertSame(0, $this->asTenant($tenant, fn () => SyncAttempt::query()->count()));

        // Operasyon TERMİNAL DEĞİL: iş ertelendi, kaybolmadı.
        $this->assertNotSame(SyncOperationStatus::COMPLETED, $fresh->status);
        $this->assertNotSame(SyncOperationStatus::DEAD, $fresh->status);
    }

    /** Devre KAPALIYKEN normal akış işler. */
    #[Test]
    public function closed_circuit_lets_the_push_through(): void
    {
        [$tenant, $variant] = $this->makeContext();

        ProgrammableInventoryAdapter::succeedOn('woocommerce');

        $operation = $this->openOperation($tenant, $variant, version: 5);

        $this->runJob($tenant, $operation->id);

        $this->assertCount(1, ProgrammableInventoryAdapter::pushesFor('woocommerce'));

        $this->assertSame(
            SyncOperationStatus::COMPLETED,
            $this->asTenant($tenant, fn () => $operation->fresh())->status,
        );
    }

    /**
     * Ardışık hatalar devreyi açar — onuncu hatadan sonra kanal duraklatılır.
     *
     * Bu, birim testin değil ENTEGRASYONUN kanıtı: iş gerçekten
     * recordFailure() çağırıyor mu?
     */
    #[Test]
    public function repeated_failures_eventually_open_the_circuit(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        ProgrammableInventoryAdapter::failOn('woocommerce', ErrorClass::SERVER_ERROR);

        $breaker = app(CircuitBreaker::class);

        // Her tur yeni operasyon: yeniden deneme değil, ardışık hata sayısı sınanıyor.
        for ($i = 1; $i <= CircuitBreaker::FAILURE_THRESHOLD; $i++) {
            $operation = $this->openOperation($tenant, $variant, version: $i);

            $this->runJob($tenant, $operation->id);
        }

        $this->assertFalse(
            $breaker->allows($connection->id),
            'Ardışık hatalar devreyi açmalı — iş recordFailure çağırmıyor olabilir.',
        );
    }

    /** Başarı devreyi kapalı tutar — sayaç sıfırlanır. */
    #[Test]
    public function success_keeps_the_circuit_closed(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        ProgrammableInventoryAdapter::succeedOn('woocommerce');

        $breaker = app(CircuitBreaker::class);

        // Dokuz hata biriktir, sonra başarı.
        for ($i = 1; $i <= 9; $i++) {
            $breaker->recordFailure($connection->id, ErrorClass::SERVER_ERROR);
        }

        $operation = $this->openOperation($tenant, $variant, version: 3);

        $this->runJob($tenant, $operation->id);

        $this->assertSame('closed', $breaker->state($connection->id));

        // Dokuz hata daha devreyi açmamalı — sayaç sıfırlandı.
        for ($i = 1; $i <= 9; $i++) {
            $breaker->recordFailure($connection->id, ErrorClass::SERVER_ERROR);
        }

        $this->assertTrue($breaker->allows($connection->id));
    }

    /**
     * AUTHENTICATION hatası devreyi TEK seferde ve süresiz açar.
     *
     * Token geçersizken sonraki tüm işler hızlıca ertelenir; kanal boşuna
     * dövülmez ve kullanıcı panelde durumu görür.
     */
    #[Test]
    public function authentication_failure_opens_the_circuit_for_the_whole_connection(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        ProgrammableInventoryAdapter::failOn('woocommerce', ErrorClass::AUTHENTICATION);

        $operation = $this->openOperation($tenant, $variant, version: 4);

        $this->runJob($tenant, $operation->id);

        $breaker = app(CircuitBreaker::class);

        $this->assertFalse(
            $breaker->allows($connection->id),
            'AUTHENTICATION tek hatada devreyi açmalı.',
        );

        // Kalıcı hata: operasyon ölür ve sync state error_permanent olur.
        $this->assertSame(
            SyncOperationStatus::DEAD,
            $this->asTenant($tenant, fn () => $operation->fresh())->status,
        );

        $listing = $this->asTenant($tenant, fn () => Listing::query()
            ->where('variant_id', $variant->id)->firstOrFail());

        $this->assertSame(
            'error_permanent',
            $this->asTenant($tenant, fn () => ListingSyncState::query()
                ->where('listing_id', $listing->id)
                ->where('domain', SyncDomain::INVENTORY->value)
                ->firstOrFail())->status,
        );
    }

    /**
     * Hız sınırı tükendiğinde çağrı yapılmaz ve deneme AÇILMAZ.
     *
     * Kanal 429 döndürmeden önce biz duruyoruz; 429 almak kotayı da harcar
     * ve bazı kanallarda ceza süresi başlatır.
     */
    #[Test]
    public function exhausted_rate_limit_defers_the_push_without_an_attempt(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        ProgrammableInventoryAdapter::succeedOn('woocommerce');

        $operation = $this->openOperation($tenant, $variant, version: 6);

        // Kovayı tüket — profil bir jetonluk.
        $limiter = app(ChannelRateLimiter::class);
        $profile = new RateLimitProfile(
            requestsPerSecond: 1,
            burstCapacity: 1,
        );

        $this->assertTrue($limiter->attempt($connection->id, $profile));

        // Kanal tipinin profili de tek jetonluk olsun ki iş aynı kovayı görsün.
        $this->asSystem(fn () => ChannelType::query()->where('code', 'woocommerce')->update([
            'rate_limit_profile' => json_encode(['requests_per_second' => 1, 'burst_capacity' => 1]),
        ]));

        $this->runJob($tenant, $operation->id);

        $this->assertSame(
            [],
            ProgrammableInventoryAdapter::pushesFor('woocommerce'),
            'Kova boşken kanala çağrı YAPILMAMALI.',
        );

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn () => $operation->fresh())->attempt_count,
            'Hız sınırı yüzünden ertelenen iş deneme AÇMAMALI.',
        );
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: Variant, 2: ChannelConnection} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Circuit '.uniqid(),
            owner: User::factory()->create(),
        );

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'store',
                'adapter_class' => ProgrammableInventoryAdapter::class,
                'is_active' => true,
                // Bol kotalı varsayılan: limiter testleri dışında yolu açar.
                'rate_limit_profile' => ['requests_per_second' => 100, 'burst_capacity' => 100],
            ],
        ));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'channel_type_code' => 'woocommerce',
        ]));

        $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
        ]));

        // Açılış stoğu LEDGER üzerinden.
        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: 10,
            idempotencyKey: 'import:'.$variant->id,
            sourceType: 'test',
        ));

        return [$tenant, $variant, $connection];
    }

    /** Fan-out ile operasyon açar ve onu döner. */
    private function openOperation(Tenant $tenant, Variant $variant, int $version): SyncOperation
    {
        $event = $this->asTenant($tenant, fn () => OutboxEvent::record(
            aggregateType: 'inventory_level',
            aggregateId: (string) new UuidV7,
            eventType: 'InventoryLevelChanged',
            payload: [
                'warehouse_id' => (string) new UuidV7,
                'variant_id' => $variant->id,
                'on_hand' => 10,
                'reserved' => 0,
                'available' => 10,
                'version' => $version,
                'origin_connection_id' => null,
            ],
            tenantId: $tenant->id,
        ));

        $this->asTenant($tenant, fn () => app(InventoryLevelChangedConsumer::class)->handle($event));

        return $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('entity_version', $version)
            ->firstOrFail());
    }

    private function runJob(Tenant $tenant, string $operationId): void
    {
        // Worker'daki gibi: bağlam sarmalayıcısı YOK. İş kiracı bağlamını
        // kendi kurar ve bitişte bırakır; asTenant() ile sarmak gerçek
        // worker'ı taklit etmez ve işin finally'si çağıranın bağlamını da
        // temizlerdi.
        (new PushInventory($operationId, $tenant->id))->handle(
            app(InventoryBatchBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );
    }
}
