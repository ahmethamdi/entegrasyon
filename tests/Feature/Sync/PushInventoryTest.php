<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
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
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\Support\Channels\ProgrammableInventoryAdapter;
use Tests\TestCase;

/**
 * P0 testi T4 — bir kanalın hatası diğerlerini etkilemez.
 *
 * Mimari Karar Dokümanı v2.2 · §18 · T4 (faz 1.7), §8 · Fan-out ve gruplama,
 * §12 · Yeniden deneme politikaları.
 *
 * DEĞİŞMEZ KURAL — İZOLASYON:
 *   Üç kanalda listelenen bir varyantın stok değişimi ÜÇ ayrı operasyon
 *   üretir. Biri 429 alıp retrying'e girdiğinde diğer ikisi completed olur ve
 *   KENDİ listing_sync_states satırlarını ilerletir. Tek operasyon modelinde
 *   bir kanalın hatası diğerlerinin durumunu kirletir ve başarılı kanalların
 *   sürümleri hiç ilerlemezdi.
 *
 * DEĞİŞMEZ KURAL — GRUPLAMA FAN-OUT DEĞİLDİR:
 *   InventoryBatchBuilder aynı bağlantıya ait bekleyen operasyonları tek yüke
 *   birleştirir; operasyon SAYISI değişmez ve her operasyon kendi durumunu
 *   taşımaya devam eder.
 */
final class PushInventoryTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Kuyruk SAHTE. Gerekçe: QUEUE_CONNECTION=sync olduğu için dispatch
        // işi DERHAL çalıştırır ve kuyruk kancaları (Queue::looping,
        // JobProcessing) her iş sınırında kiracı bağlamını temizler —
        // çağıranın bağlamı da yok olur. Gerçek worker asenkrondur; sync
        // sürücü o modeli taklit etmez. Bu testler işleri KENDİ çağırır
        // (runJob), böylece her operasyon ayrı iş örneğinde çalışır.
        Queue::fake();

        ProgrammableInventoryAdapter::reset();
    }

    protected function tearDown(): void
    {
        ProgrammableInventoryAdapter::reset();

        parent::tearDown();
    }

    /**
     * T4 — üç kanal, biri 429; diğer ikisi bağımsız tamamlanır.
     */
    #[Test]
    public function one_channel_failure_does_not_block_others(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $this->listVariantOn($tenant, $variant, ['woocommerce', 'trendyol', 'shopify']);

        ProgrammableInventoryAdapter::succeedOn('woocommerce');
        ProgrammableInventoryAdapter::succeedOn('shopify');
        ProgrammableInventoryAdapter::failOn('trendyol', ErrorClass::RATE_LIMITED, '429 Too Many Requests');

        $this->seedStock($tenant, $variant, 9);

        $this->dispatchInventoryChange($tenant, $variant, version: 182);
        $this->workQueue($tenant);

        $byChannel = $this->operationsByChannel($tenant);

        $this->assertSame(SyncOperationStatus::COMPLETED, $byChannel['woocommerce']->status);
        $this->assertSame(SyncOperationStatus::COMPLETED, $byChannel['shopify']->status);
        $this->assertSame(SyncOperationStatus::RETRYING, $byChannel['trendyol']->status);

        // Başarılı olanların sync state'i ilerledi.
        foreach (['woocommerce', 'shopify'] as $code) {
            $state = $this->syncState($tenant, $variant, $code);

            $this->assertSame(182, $state->synced_version, "{$code} synced_version ilerlemeli.");
            $this->assertSame('synced', $state->status);
            $this->assertFalse($state->is_dirty, "{$code} temiz olmalı.");
            $this->assertNotNull($state->last_synced_at);
        }

        // Başarısız olan GERİDE ve geçici hata.
        $trendyol = $this->syncState($tenant, $variant, 'trendyol');

        $this->assertSame(0, $trendyol->synced_version, 'Başarısız kanal ilerlememeli.');
        $this->assertSame('error_transient', $trendyol->status);
        $this->assertTrue($trendyol->is_dirty, 'desired > synced iken kirli kalmalı.');
        $this->assertSame(1, $trendyol->error_count);

        // Hata YALNIZCA kendi operasyonunda yazılı.
        $this->assertSame(ErrorClass::RATE_LIMITED->value, $byChannel['trendyol']->last_error_class);
        $this->assertNull($byChannel['woocommerce']->last_error_class);
        $this->assertNull($byChannel['shopify']->last_error_class);

        // Her operasyon TEK deneme yaptı; hata sayacı diğerlerine bulaşmadı.
        foreach ($byChannel as $operation) {
            $this->assertSame(1, $operation->attempt_count);
        }
    }

    /**
     * Hata izolasyonu deneme kayıtlarında da geçerlidir.
     *
     * sync_attempts operasyon × deneme granülerliğindedir; 429 alan kanalın
     * deneme kaydı transient, diğerlerininki success olmalıdır.
     */
    #[Test]
    public function each_operation_records_its_own_attempt(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $this->listVariantOn($tenant, $variant, ['woocommerce', 'trendyol', 'shopify']);

        ProgrammableInventoryAdapter::succeedOn('woocommerce');
        ProgrammableInventoryAdapter::succeedOn('shopify');
        ProgrammableInventoryAdapter::failOn('trendyol', ErrorClass::RATE_LIMITED);

        $this->seedStock($tenant, $variant, 4);

        $this->dispatchInventoryChange($tenant, $variant, version: 7);
        $this->workQueue($tenant);

        $byChannel = $this->operationsByChannel($tenant);

        $attempts = $this->asTenant($tenant, fn () => SyncAttempt::query()->get());

        $this->assertCount(3, $attempts, 'Operasyon başına bir deneme yazılmalı.');

        foreach ($attempts as $attempt) {
            $this->assertSame(1, $attempt->attempt_number);
            $this->assertNotNull($attempt->started_at);
            $this->assertNotNull($attempt->finished_at);
        }

        $outcomeOf = fn (string $code): string => $attempts
            ->firstWhere('sync_operation_id', $byChannel[$code]->id)
            ->outcome;

        $this->assertSame('success', $outcomeOf('woocommerce'));
        $this->assertSame('success', $outcomeOf('shopify'));
        $this->assertSame('transient', $outcomeOf('trendyol'));

        $failed = $attempts->firstWhere('sync_operation_id', $byChannel['trendyol']->id);

        $this->assertSame(ErrorClass::RATE_LIMITED->value, $failed->error_class);
        $this->assertNotNull($failed->error_message);
    }

    /**
     * Kalıcı hata yeniden DENENMEZ — operasyon ölür, sync state kalıcı hataya düşer.
     *
     * VALIDATION kullanıcı müdahalesi gerektirir; yeniden denemek bütçe israfıdır
     * ve mutabakat da bu satırı aday seçmez (§12 · hata sınıfları).
     */
    #[Test]
    public function permanent_error_kills_the_operation_without_retry(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $this->listVariantOn($tenant, $variant, ['woocommerce', 'trendyol']);

        ProgrammableInventoryAdapter::succeedOn('woocommerce');
        ProgrammableInventoryAdapter::failOn('trendyol', ErrorClass::VALIDATION, 'stok alanı geçersiz');

        $this->seedStock($tenant, $variant, 6);

        $this->dispatchInventoryChange($tenant, $variant, version: 12);
        $this->workQueue($tenant);

        $byChannel = $this->operationsByChannel($tenant);

        $this->assertSame(SyncOperationStatus::COMPLETED, $byChannel['woocommerce']->status);
        $this->assertSame(SyncOperationStatus::DEAD, $byChannel['trendyol']->status);
        $this->assertNotNull($byChannel['trendyol']->completed_at);

        $state = $this->syncState($tenant, $variant, 'trendyol');

        $this->assertSame('error_permanent', $state->status);
        $this->assertSame(0, $state->synced_version);

        // Sağlıklı kanal etkilenmedi.
        $this->assertSame(12, $this->syncState($tenant, $variant, 'woocommerce')->synced_version);

        // DEĞİŞMEZ KURAL: kalıcı hata olayı yeniden YAYINLATMAZ.
        $event = $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'InventoryLevelChanged')
            ->latest('id')->firstOrFail());

        $this->assertNotNull($event->consumed_at, 'Kalıcı hata consumed_at damgasını geri almaz.');
    }

    /**
     * Superseded operasyon GÖNDERİLMEZ — erken çıkış.
     *
     * Eski sürümün kanala yazılması, yeni sürümün üzerine bayat veri yazmak
     * demektir. İş kuyrukta beklerken sürüm ilerlemişse gönderme.
     */
    #[Test]
    public function superseded_operation_is_not_pushed(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $this->listVariantOn($tenant, $variant, ['woocommerce']);

        ProgrammableInventoryAdapter::succeedOn('woocommerce');

        $this->seedStock($tenant, $variant, 4);

        $operation = $this->asTenant($tenant, function () use ($tenant, $variant) {
            app(InventoryLevelChangedConsumer::class)->handle(
                $this->inventoryChangedEvent($tenant, $variant, version: 5)
            );

            return SyncOperation::query()->firstOrFail();
        });

        // Kuyrukta beklerken yeni sürüm geldi → eski operasyon superseded.
        $this->asTenant($tenant, fn () => app(InventoryLevelChangedConsumer::class)->handle(
            $this->inventoryChangedEvent($tenant, $variant, version: 6)
        ));

        $this->assertSame(
            SyncOperationStatus::SUPERSEDED,
            $this->asTenant($tenant, fn () => $operation->fresh())->status,
        );

        // Bayat iş şimdi çalışıyor.
        $this->runJob($tenant, $operation->id);

        $this->assertSame(
            SyncOperationStatus::SUPERSEDED,
            $this->asTenant($tenant, fn () => $operation->fresh())->status,
            'Superseded operasyon çalıştırılınca durumu değişmemeli.',
        );

        $this->assertSame(0, $this->asTenant($tenant, fn () => SyncAttempt::query()
            ->where('sync_operation_id', $operation->id)->count()),
            'Superseded operasyon için deneme AÇILMAMALI.');

        // Kanala yalnızca YENİ sürüm gitti (yeni operasyon üzerinden değil,
        // bayat iş hiç göndermedi).
        $sent = collect(ProgrammableInventoryAdapter::pushesFor('woocommerce'))->flatten(1);

        $this->assertFalse(
            $sent->contains(fn (array $item): bool => $item['version'] === 5),
            'Bayat sürüm kanala gönderilmemeli.',
        );
    }

    /**
     * GRUPLAMA — aynı bağlantıdaki iki operasyon TEK API çağrısında gider.
     *
     * Operasyon sayısı DEĞİŞMEZ: ikisi de ayrı satır olarak kalır, ikisi de
     * completed olur, her biri kendi sync state'ini ilerletir.
     */
    #[Test]
    public function operations_on_same_connection_are_grouped_into_one_call(): void
    {
        [$tenant, $variantA] = $this->makeContext();

        $variantB = $this->asTenant($tenant, fn () => Variant::factory()->create());

        $connection = $this->asTenant($tenant, fn () => $this->connection('woocommerce'));

        $listings = $this->asTenant($tenant, fn (): array => [
            Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => $variantA->id,
            ]),
            Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => $variantB->id,
            ]),
        ]);

        ProgrammableInventoryAdapter::succeedOn('woocommerce');

        $this->seedStock($tenant, $variantA, 5);
        $this->seedStock($tenant, $variantB, 8);

        $this->asTenant($tenant, function () use ($tenant, $variantA, $variantB): void {
            app(InventoryLevelChangedConsumer::class)->handle(
                $this->inventoryChangedEvent($tenant, $variantA, version: 1)
            );
            app(InventoryLevelChangedConsumer::class)->handle(
                $this->inventoryChangedEvent($tenant, $variantB, version: 1)
            );
        });

        $this->workQueue($tenant);

        // İki operasyon, TEK çağrı.
        $operations = $this->asTenant($tenant, fn () => SyncOperation::query()->get());

        $this->assertCount(2, $operations, 'Gruplama operasyon sayısını DEĞİŞTİRMEZ.');

        foreach ($operations as $operation) {
            $this->assertSame(SyncOperationStatus::COMPLETED, $operation->status);
        }

        $calls = ProgrammableInventoryAdapter::pushesFor('woocommerce');

        $this->assertCount(1, $calls, 'Aynı bağlantıdaki iki operasyon tek çağrıda gitmeli.');
        $this->assertCount(2, $calls[0], 'Tek çağrı iki kalem taşımalı.');

        // Her listing kendi sync state'ini ilerletti.
        foreach ($listings as $listing) {
            $state = $this->stateFor($tenant, $listing->id);

            $this->assertSame(1, $state->synced_version);
            $this->assertSame('synced', $state->status);
        }
    }

    /**
     * Gruplama adapter'ın sınırına UYAR — sınır aşılırsa fazlası bu turda gitmez.
     */
    #[Test]
    public function grouping_respects_adapter_batch_size_limit(): void
    {
        [$tenant, $variantA] = $this->makeContext();

        $variantB = $this->asTenant($tenant, fn () => Variant::factory()->create());

        $connection = $this->asTenant($tenant, fn () => $this->connection('woocommerce'));

        $this->asTenant($tenant, function () use ($connection, $variantA, $variantB): void {
            Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => $variantA->id,
            ]);
            Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => $variantB->id,
            ]);
        });

        ProgrammableInventoryAdapter::succeedOn('woocommerce');
        ProgrammableInventoryAdapter::batchSizeFor('woocommerce', 1);

        $this->seedStock($tenant, $variantA, 5);
        $this->seedStock($tenant, $variantB, 8);

        $this->asTenant($tenant, function () use ($tenant, $variantA, $variantB): void {
            app(InventoryLevelChangedConsumer::class)->handle(
                $this->inventoryChangedEvent($tenant, $variantA, version: 1)
            );
            app(InventoryLevelChangedConsumer::class)->handle(
                $this->inventoryChangedEvent($tenant, $variantB, version: 1)
            );
        });

        $this->workQueue($tenant);

        // Sınır 1 olduğu için her çağrı tek kalem taşır.
        foreach (ProgrammableInventoryAdapter::pushesFor('woocommerce') as $call) {
            $this->assertCount(1, $call, 'Adapter sınırı aşılmamalı.');
        }
    }

    /**
     * Giden yükte KIRPMA uygulanır — kanonik negatif, gönderilen sıfır.
     *
     * Kırpmanın tek meşru yeri OutboundQuantity'dir; kanonik bakiye
     * DEĞİŞMEZ ve ledger ↔ projeksiyon eşitliği korunur.
     */
    #[Test]
    public function oversold_variant_is_sent_as_zero_but_canonical_stays_negative(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $this->listVariantOn($tenant, $variant, ['woocommerce']);

        ProgrammableInventoryAdapter::succeedOn('woocommerce');

        // 3 adet açılış, 5 satış → available = −2.
        $this->seedStock($tenant, $variant, 3);
        $this->sell($tenant, $variant, 5);

        $level = $this->levelFor($tenant, $variant);

        $this->assertSame(-2, $level->available, 'Kanonik bakiye negatif kalmalı.');

        $this->dispatchInventoryChange($tenant, $variant, version: $level->version);
        $this->workQueue($tenant);

        $sent = ProgrammableInventoryAdapter::pushesFor('woocommerce');

        $this->assertCount(1, $sent);
        $this->assertSame(0, $sent[0][0]['quantity'], 'Kanala kırpılmış değer gitmeli.');

        // Kanonik durum DEĞİŞMEDİ.
        $this->assertSame(-2, $this->levelFor($tenant, $variant)->available);

        $this->assertLedgerMatchesProjectionForTenant($tenant->id);
    }

    /**
     * Canlı olmayan listing yüke GİRMEZ.
     *
     * Fan-out'tan sonra listing delist edilmiş olabilir; kanal onu tanımaz ve
     * çağrı hata döner.
     */
    #[Test]
    public function delisted_listing_is_skipped_and_operation_is_not_sent(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->listVariantOn($tenant, $variant, ['woocommerce'])[0];

        ProgrammableInventoryAdapter::succeedOn('woocommerce');

        $this->seedStock($tenant, $variant, 7);

        $this->dispatchInventoryChange($tenant, $variant, version: 3);

        // Fan-out olduktan SONRA listeden çıkarıldı.
        $this->asTenant($tenant, fn () => $listing->forceFill([
            'lifecycle_status' => 'delisted',
            'delisted_at' => now(),
        ])->save());

        $this->workQueue($tenant);

        $this->assertSame([], ProgrammableInventoryAdapter::pushesFor('woocommerce'));

        $operation = $this->asTenant($tenant, fn () => SyncOperation::query()->firstOrFail());

        $this->assertSame(SyncOperationStatus::COMPLETED, $operation->status);
        $this->assertSame(0, $operation->attempt_count, 'Gönderilecek bir şey yoksa deneme açılmaz.');

        $this->assertSame(0, $this->asTenant($tenant, fn () => SyncAttempt::query()->count()));
    }

    /**
     * Gruplama BAŞKA KİRACININ operasyonlarını asla toplamaz.
     *
     * İki kiracı aynı kanal tipini kullanır ama ayrı bağlantılardır; gruplama
     * bağlantı bazlı olduğu için sızıntı olmamalıdır. Bu kural bağlantı
     * filtresi kaldırıldığında da tutmalıdır — bu yüzden ayrıca sınanır.
     */
    #[Test]
    public function grouping_never_crosses_tenants(): void
    {
        [$tenantA, $variantA] = $this->makeContext();
        [$tenantB, $variantB] = $this->makeContext();

        $this->listVariantOn($tenantA, $variantA, ['woocommerce']);
        $this->listVariantOn($tenantB, $variantB, ['woocommerce']);

        ProgrammableInventoryAdapter::succeedOn('woocommerce');

        $this->seedStock($tenantA, $variantA, 3);
        $this->seedStock($tenantB, $variantB, 3);

        $this->dispatchInventoryChange($tenantA, $variantA, version: 2);
        $this->dispatchInventoryChange($tenantB, $variantB, version: 2);

        $this->workQueue($tenantA);

        // A çalıştı; B'nin operasyonu HÂLÂ bekliyor.
        $this->assertSame(
            SyncOperationStatus::PENDING,
            $this->asTenant($tenantB, fn () => SyncOperation::query()->firstOrFail())->status,
            'Başka kiracının operasyonu gruplamaya girmemeli.',
        );

        // Giden yükte yalnızca A'nın kalemi var.
        $calls = ProgrammableInventoryAdapter::pushesFor('woocommerce');

        $this->assertCount(1, $calls);
        $this->assertCount(1, $calls[0]);
    }

    /**
     * Gruplama AYNI KİRACININ başka bağlantısını da toplamaz.
     *
     * Çapraz kiracı testi bu boşluğu YAKALAMAZ: kiracı global scope'u zaten
     * eler ve bağlantı filtresi kaldırılsa bile test yeşil kalır. Sızıntının
     * gerçek biçimi tek kiracı içinde iki bağlantıdır — Woo'nun yükü
     * Trendyol'un external_id'lerini taşırsa kanal onları tanımaz.
     *
     * (Bu test mutasyonla bulundu: bağlantı filtresi kaldırıldığında
     * çapraz kiracı testi yeşil kalıyordu.)
     */
    #[Test]
    public function grouping_never_crosses_connections_within_a_tenant(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $this->listVariantOn($tenant, $variant, ['woocommerce', 'trendyol']);

        ProgrammableInventoryAdapter::succeedOn('woocommerce');
        ProgrammableInventoryAdapter::succeedOn('trendyol');

        $this->seedStock($tenant, $variant, 5);

        $this->dispatchInventoryChange($tenant, $variant, version: 11);

        // YALNIZCA Woo operasyonunu çalıştır.
        $wooOperation = $this->operationsByChannel($tenant)['woocommerce'];

        $this->runJob($tenant, $wooOperation->id);

        // Woo tek kalem gönderdi — Trendyol'un listing'i yüke GİRMEDİ.
        $calls = ProgrammableInventoryAdapter::pushesFor('woocommerce');

        $this->assertCount(1, $calls);
        $this->assertCount(1, $calls[0], 'Başka bağlantının listing’i yüke girmemeli.');

        // Trendyol'a hiç çağrı gitmedi ve operasyonu HÂLÂ bekliyor.
        $this->assertSame([], ProgrammableInventoryAdapter::pushesFor('trendyol'));

        $this->assertSame(
            SyncOperationStatus::PENDING,
            $this->operationsByChannel($tenant)['trendyol']->status,
            'Başka bağlantının operasyonu bu turda kapanmamalı.',
        );

        // Gönderilen kalem Woo listing’inin external_id’sini taşımalı.
        $wooListing = $this->asTenant($tenant, fn () => Listing::query()
            ->where('channel_connection_id', $wooOperation->channel_connection_id)
            ->firstOrFail());

        $this->assertSame($wooListing->external_id, $calls[0][0]['external_id']);
    }

    /**
     * synced_version GERİYE ALINMAZ — bayat başarı yeniyi ezmez.
     *
     * İki iş yarışabilir: v6 önce tamamlanır, kuyrukta bekleyen v5 sonra
     * biter. v5'in sonucu synced_version'ı geri sararsa kanalda doğru veri
     * dururken sistem satırı kirli sanar ve gereksiz yeniden gönderim
     * başlatır. Mutabakat da yanlış sürüklenme raporlar.
     */
    #[Test]
    public function stale_success_does_not_rewind_synced_version(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->listVariantOn($tenant, $variant, ['woocommerce'])[0];

        ProgrammableInventoryAdapter::succeedOn('woocommerce');

        $this->seedStock($tenant, $variant, 5);

        // v5 için operasyon açıldı ama HENÜZ çalışmadı.
        $this->dispatchInventoryChange($tenant, $variant, version: 5);

        $stale = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('entity_version', 5)->firstOrFail());

        // v6 geldi ve TAMAMLANDI.
        $this->dispatchInventoryChange($tenant, $variant, version: 6);

        $fresh = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('entity_version', 6)->firstOrFail());

        $this->runJob($tenant, $fresh->id);

        $this->assertSame(6, $this->stateFor($tenant, $listing->id)->synced_version);

        // Şimdi bayat v5 operasyonunu ZORLA çalıştır. Superseded erken çıkışı
        // devrede olduğu için normalde buraya gelinmez; kayıt katmanının
        // KENDİ kapısı olduğunu doğrulamak için durumu geri alıyoruz.
        // Not: $stale supersede'den ÖNCE okundu; bellekteki durumu hâlâ
        // pending. Önce tazelenmezse save() hiçbir alanı kirli görmez ve
        // UPDATE hiç çalışmaz — satır superseded kalır.
        $this->asTenant($tenant, function () use ($stale): void {
            $stale->refresh();

            $stale->forceFill([
                'status' => SyncOperationStatus::PENDING->value,
                'completed_at' => null,
            ])->save();
        });

        $this->runJob($tenant, $stale->id);

        // Bayat operasyon GERÇEKTEN gönderildi mi? Gönderilmediyse test
        // kapıyı değil erken çıkışı sınıyor olurdu.
        $sent = collect(ProgrammableInventoryAdapter::pushesFor('woocommerce'))->flatten(1);
        $this->assertTrue(
            $sent->contains(fn (array $i): bool => $i['version'] === 5),
            'Bayat operasyon yükte olmalı — yoksa test sürüm kapısını sınamıyor.',
        );

        $this->assertSame(
            6,
            $this->stateFor($tenant, $listing->id)->synced_version,
            'Bayat başarı synced_version’ı geri sarmamalı.',
        );
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: Variant} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Push '.uniqid(),
            owner: User::factory()->create(),
        );

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        return [$tenant, $variant];
    }

    /**
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

    /** Programlanabilir adapter'a bağlı kanal bağlantısı. */
    private function connection(string $channelTypeCode): ChannelConnection
    {
        $this->asSystem(function () use ($channelTypeCode): void {
            ChannelType::query()->updateOrCreate(
                ['code' => $channelTypeCode],
                [
                    'name' => ucfirst($channelTypeCode),
                    'kind' => 'marketplace',
                    'adapter_class' => ProgrammableInventoryAdapter::class,
                    'is_active' => true,
                ],
            );
        });

        return ChannelConnection::factory()->create(['channel_type_code' => $channelTypeCode]);
    }

    /** Açılış stoğu LEDGER üzerinden girer — projeksiyona doğrudan yazılmaz. */
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

    /** Fan-out'u çalıştırır — operasyonlar pending kalır, iş kuyruğa girer. */
    private function dispatchInventoryChange(Tenant $tenant, Variant $variant, int $version): void
    {
        $this->asTenant($tenant, fn () => app(InventoryLevelChangedConsumer::class)->handle(
            $this->inventoryChangedEvent($tenant, $variant, version: $version)
        ));
    }

    /**
     * Bekleyen tüm operasyonları SENKRON çalıştırır.
     *
     * Gerçek kuyruk yerine doğrudan çağrı: T4'ün sınadığı şey kuyruk
     * mekaniği değil, operasyonlar arası izolasyondur. Her operasyon AYRI
     * iş örneğinde çalışır — worker'da olduğu gibi.
     */
    private function workQueue(Tenant $tenant): void
    {
        $ids = $this->asTenant($tenant, fn (): array => SyncOperation::query()
            ->whereIn('status', [
                SyncOperationStatus::PENDING->value,
                SyncOperationStatus::RETRYING->value,
            ])
            ->orderBy('created_at')
            ->pluck('id')
            ->all());

        foreach ($ids as $id) {
            $this->runJob($tenant, $id);
        }
    }

    /** Tek işi worker'daki gibi çalıştırır — her operasyon AYRI iş örneğinde. */
    private function runJob(Tenant $tenant, string $operationId): void
    {
        $this->asTenant($tenant, function () use ($operationId): void {
            (new PushInventory($operationId))->handle(
                app(InventoryBatchBuilder::class),
                app(SyncResultRecorder::class),
                app(AdapterRegistry::class),
            );
        });
    }

    /** @return array<string, SyncOperation> */
    private function operationsByChannel(Tenant $tenant): array
    {
        return $this->asTenant($tenant, fn (): array => SyncOperation::query()
            ->with('connection')
            ->get()
            ->keyBy(fn (SyncOperation $o): string => $o->connection->channel_type_code)
            ->all());
    }

    private function syncState(Tenant $tenant, Variant $variant, string $channelTypeCode): ListingSyncState
    {
        return $this->asTenant($tenant, function () use ($variant, $channelTypeCode) {
            $listing = Listing::query()
                ->where('variant_id', $variant->id)
                ->whereHas('connection', fn ($q) => $q->where('channel_type_code', $channelTypeCode))
                ->firstOrFail();

            return ListingSyncState::query()
                ->where('listing_id', $listing->id)
                ->where('domain', SyncDomain::INVENTORY->value)
                ->firstOrFail();
        });
    }

    private function stateFor(Tenant $tenant, string $listingId): ListingSyncState
    {
        return $this->asTenant($tenant, fn () => ListingSyncState::query()
            ->where('listing_id', $listingId)
            ->where('domain', SyncDomain::INVENTORY->value)
            ->firstOrFail());
    }

    private function inventoryChangedEvent(Tenant $tenant, Variant $variant, int $version): OutboxEvent
    {
        $level = $this->asTenant($tenant, fn () => InventoryLevel::query()
            ->where('variant_id', $variant->id)
            ->first());

        return $this->asTenant($tenant, fn () => OutboxEvent::record(
            aggregateType: 'inventory_level',
            aggregateId: $level?->id ?? (string) new UuidV7,
            eventType: 'InventoryLevelChanged',
            payload: [
                'warehouse_id' => $level?->warehouse_id ?? (string) new UuidV7,
                'variant_id' => $variant->id,
                'on_hand' => $level?->on_hand ?? 0,
                'reserved' => $level?->reserved ?? 0,
                'available' => $level?->available ?? 0,
                'version' => $version,
                'origin_connection_id' => null,
            ],
            tenantId: $tenant->id,
        ));
    }
}
