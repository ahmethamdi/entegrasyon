<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

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
use App\Domain\Messaging\Jobs\ConsumeOutboxEvent;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Messaging\Support\DetectUnconsumedEvents;
use App\Domain\Messaging\Support\OutboxRelay;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushInventory;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\DetectStuckSyncOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * KAYIP İŞ KURTARILIYOR MU? — iki taramanın uçtan uca sınanması.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · iki bütünlük taraması, §18 · T5, T6.
 *
 * DetectUnconsumedEventsTest ve DetectStuckSyncOperationsTest taramaların
 * doğru satırları bulduğunu sınar. Bu test bir adım ötesini sorar: bulunan
 * satır GERÇEKTEN kurtuluyor mu? Parçalar tek tek doğruyken aralarındaki
 * sözleşme yanlış olabilir — seviye 1 olayı yeniden yayına alıyor ama relay
 * onu tekrar görüyor mu? Tüketici ikinci turda operasyon açıyor mu?
 *
 * Senaryolar Redis kaybını taklit eder: iş kuyruğa girdi ve düştü.
 */
final class LostWorkRecoveryTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    /**
     * SEVİYE 1 · Redis yayınlanmış işi düşürdü → tarama zinciri yeniden kurar.
     *
     * Stok hareketi oldu, olay yazıldı, relay onu yayınladı ve ConsumeOutboxEvent
     * kuyruğa girdi. Sonra Redis işi düşürdü: fan-out HİÇ çalışmadı, hiçbir
     * sync_operation yaratılmadı, stok kanala hiç gitmedi. Panelde her şey
     * yolunda görünür — olay "yayınlanmış"tır.
     *
     * Tarama olmadan bu stok sonsuza kadar kanalda yanlış kalır.
     */
    #[Test]
    public function level_one_scan_restores_a_chain_that_redis_dropped(): void
    {
        // Bu test PLANLAMAYI sınar: kurtarılan olayın fan-out edilmesi.
        // Kuyruk sahte olmazsa sync sürücü PushInventory'yi DERHAL çalıştırır
        // ve kuyruk kancaları kiracı bağlamını temizler — tüketicinin kalan
        // turları bağlamsız kalır (P0 izolasyon korumasının doğru davranışı).
        Queue::fake();

        [$tenant, $variant, $warehouseId] = $this->makeContext();
        $this->listVariantOn($tenant, $variant, ['woocommerce', 'trendyol']);

        $this->applySale($tenant, $warehouseId, $variant);

        // (1) Relay yayınlar ama tüketici ÇALIŞMAZ — iş Redis'te kayboldu.
        $published = $this->publishWithoutConsuming();
        $this->assertSame(1, $published);

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn () => SyncOperation::query()->count()),
            'Ön koşul: fan-out çalışmadı, hiçbir operasyon yok.',
        );

        // (2) Olayı eskit: tarama bekleme süresi dolmuş olayları alır.
        $this->ageEventsBy(minutes: 10);

        // (3) SEVİYE 1 TARAMASI — olayı yeniden yayına alır.
        $found = app(DetectUnconsumedEvents::class)->run();
        $this->assertCount(1, $found);

        // (4) Relay olayı TEKRAR görür ve bu kez tüketici çalışır.
        //     Kurtarmanın gerçek iddiası budur: yeniden yayına alınan olay
        //     relay'in kapsamına geri girmiş olmalı.
        $this->runRelayInline();

        $operations = $this->asTenant($tenant, fn () => SyncOperation::query()->get());

        $this->assertCount(
            2,
            $operations,
            'Kurtarılan olay fan-out edilmeli: iki canlı listing, iki operasyon.',
        );
        $this->assertCount(2, $operations->where('status', SyncOperationStatus::PENDING));

        // (5) Olay artık tüketilmiş ve yayınlanmış: zincir tamamlandı.
        $event = $this->asSystem(fn () => OutboxEvent::query()->firstOrFail());
        $this->assertNotNull($event->consumed_at);
        $this->assertNotNull($event->published_at);
        $this->assertSame(2, $event->operations_planned);

        // (6) İkinci tarama artık bir şey bulmaz — kurtarma bir kez olur.
        $this->assertCount(
            0,
            app(DetectUnconsumedEvents::class)->run(),
            'Tüketilmiş olay bir daha yeniden yayınlanmaz.',
        );

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * SEVİYE 2 · Redis PushInventory işini düşürdü → tarama işi yeniden atar.
     *
     * Bu kez fan-out ÇALIŞTI: operasyonlar açıldı, olay consumed damgalandı.
     * Kayıp bir sonraki halkada. Seviye 1 buraya bakmaz ve bakmamalı; olay
     * tarafında hiçbir sorun yoktur.
     */
    #[Test]
    public function level_two_scan_redispatches_work_that_redis_dropped(): void
    {
        [$tenant, $variant, $warehouseId] = $this->makeContext();
        $this->listVariantOn($tenant, $variant, ['woocommerce', 'trendyol']);

        $this->applySale($tenant, $warehouseId, $variant);

        // (1) Tam zincir çalışır: yayın + fan-out. PushInventory işleri
        //     kuyruğa girer ve Queue::fake() onları YUTAR — Redis kaybı.
        Queue::fake();
        $this->runRelayInline();

        $operations = $this->asTenant($tenant, fn () => SyncOperation::query()->get());
        $this->assertCount(2, $operations, 'Ön koşul: fan-out iki operasyon açtı.');

        // Ön koşul: hiçbiri denenmedi — worker hiç çalışmadı.
        $this->assertTrue($operations->every(fn (SyncOperation $o): bool => $o->attempt_count === 0));

        // (2) SEVİYE 1 BURAYA BAKMAZ: olay tarafı kusursuz.
        $this->ageEventsBy(minutes: 10);
        $this->assertCount(
            0,
            app(DetectUnconsumedEvents::class)->run(),
            'consumed_at dolu; seviye 1 taraması bu kaybı GÖREMEZ — seviye 2 bu yüzden var.',
        );

        // (3) Operasyonları eskit ve SEVİYE 2 TARAMASINI çalıştır.
        $this->ageOperationsBy(minutes: 10);

        $found = app(DetectStuckSyncOperations::class)->run();

        $this->assertCount(2, $found, 'İki takılı operasyon da bulunmalı.');

        Queue::assertPushed(PushInventory::class, function (PushInventory $job) use ($operations): bool {
            return $operations->pluck('id')->contains($job->operationId);
        });

        // (4) Olay YENİDEN YAYINLANMADI — o zincir zaten tamamlanmıştı.
        $event = $this->asSystem(fn () => OutboxEvent::query()->firstOrFail());
        $this->assertNotNull($event->published_at, 'Seviye 2 olaya dokunmaz.');
        $this->assertNotNull($event->consumed_at);

        $this->assertLedgerMatchesProjection($tenant->id, $warehouseId, $variant->id);
    }

    /**
     * İKİ TARAMA BİRBİRİNİN ALANINA GİRMEZ.
     *
     * Aynı anda iki farklı kayıp: biri olay seviyesinde (tüketici çalışmadı),
     * biri operasyon seviyesinde (worker çalışmadı). Her tarama YALNIZCA
     * kendi kaybını görmeli. Biri diğerinin alanına girerse ya olay iki kez
     * fan-out edilir ya operasyon iki kez dispatch edilir.
     */
    #[Test]
    public function the_two_scans_do_not_overlap_in_scope(): void
    {
        // Kayıp A: olay yayınlandı, tüketici hiç çalışmadı.
        [$tenantA, $variantA, $warehouseA] = $this->makeContext();
        $this->listVariantOn($tenantA, $variantA, ['woocommerce']);
        $this->applySale($tenantA, $warehouseA, $variantA);
        $this->publishWithoutConsuming();

        // Kayıp B: fan-out çalıştı, worker hiç çalışmadı.
        [$tenantB, $variantB, $warehouseB] = $this->makeContext();
        $this->listVariantOn($tenantB, $variantB, ['woocommerce']);
        $this->applySale($tenantB, $warehouseB, $variantB);
        Queue::fake();
        $this->runRelayInline();

        $this->ageEventsBy(minutes: 10);
        $this->ageOperationsBy(minutes: 10);

        $eventA = $this->asTenant($tenantA, fn () => OutboxEvent::query()->firstOrFail());
        $operationB = $this->asTenant($tenantB, fn () => SyncOperation::query()->firstOrFail());

        // Seviye 1 YALNIZCA A'yı görür.
        $levelOne = app(DetectUnconsumedEvents::class)->run();
        $this->assertTrue($levelOne->contains($eventA->id));
        $this->assertCount(1, $levelOne, 'Seviye 1 yalnızca tüketilmemiş olayı görür.');

        // Seviye 2 YALNIZCA B'yi görür. A'nın operasyonu hiç yaratılmadı;
        // seviye 2'nin o kaybı görmesi yapısal olarak imkânsızdır.
        $levelTwo = app(DetectStuckSyncOperations::class)->run();
        $this->assertTrue($levelTwo->contains($operationB->id));
        $this->assertCount(1, $levelTwo, 'Seviye 2 yalnızca hiç denenmemiş operasyonu görür.');

        $this->assertLedgerMatchesProjection($tenantA->id, $warehouseA, $variantA->id);
        $this->assertLedgerMatchesProjection($tenantB->id, $warehouseB, $variantB->id);
    }

    // ---------------------------------------------------------------- yardımcılar

    /** Olayı yayınlar ama TÜKETMEZ — Redis'in işi düşürmesini taklit eder. */
    private function publishWithoutConsuming(): int
    {
        return (new OutboxRelay(dispatcher: static function (): void {
            // İş kuyruğa girdi ve kayboldu. Hiçbir şey yapılmaz.
        }))->run();
    }

    /** Relay'i çalıştırır ve yayınlanan olayları AYNI süreçte tüketir. */
    private function runRelayInline(): int
    {
        $jobs = [];

        $published = (new OutboxRelay(
            dispatcher: static function (string $tenantId, string $eventId) use (&$jobs): void {
                $jobs[] = new ConsumeOutboxEvent($tenantId, $eventId);
            },
        ))->run();

        foreach ($jobs as $job) {
            $job->handle();
        }

        return $published;
    }

    private function applySale(Tenant $tenant, string $warehouseId, Variant $variant): void
    {
        $this->asTenant($tenant, fn () => DB::transaction(function () use ($warehouseId, $variant): void {
            (new LockInventoryRows)->run($warehouseId, [$variant->id]);

            (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variant->id,
                type: MovementType::SALE,
                quantity: 2,
                idempotencyKey: MovementKey::sale((string) new UuidV7),
                sourceType: 'order_line',
            );
        }));
    }

    /** Zaman damgalarını geriye alır — tarama eşiğini aşmak için. */
    private function ageEventsBy(int $minutes): void
    {
        $this->asSystem(fn () => DB::statement(
            "UPDATE outbox_events
                SET published_at = published_at - (? * interval '1 minute')
              WHERE published_at IS NOT NULL",
            [$minutes],
        ));
    }

    private function ageOperationsBy(int $minutes): void
    {
        $this->asSystem(fn () => DB::statement(
            "UPDATE sync_operations SET created_at = created_at - (? * interval '1 minute')",
            [$minutes],
        ));
    }

    /** @return array{0: Tenant, 1: Variant, 2: string} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Kurtarma '.uniqid(),
            owner: User::factory()->create(),
        );

        $warehouseId = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);
        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        return [$tenant, $variant, $warehouseId];
    }

    /** @param  list<string>  $channelTypeCodes */
    private function listVariantOn(Tenant $tenant, Variant $variant, array $channelTypeCodes): void
    {
        $this->asTenant($tenant, function () use ($variant, $channelTypeCodes): void {
            foreach ($channelTypeCodes as $code) {
                $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
                    ['code' => $code],
                    [
                        'name' => ucfirst($code),
                        'kind' => 'marketplace',
                        'adapter_class' => 'App\\Domain\\Channels\\Adapters\\'.ucfirst($code).'Adapter',
                        'is_active' => true,
                    ],
                ));

                Listing::factory()->create([
                    'channel_connection_id' => ChannelConnection::factory()
                        ->create(['channel_type_code' => $code])->id,
                    'variant_id' => $variant->id,
                ]);
            }
        });
    }
}
