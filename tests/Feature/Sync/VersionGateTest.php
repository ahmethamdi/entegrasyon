<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

/**
 * Sürüm kapısı ve onarım niyeti — T7, T8 hazırlığı.
 *
 * Mimari Karar Dokümanı v2.2 · §8 · Sürüm kapısı, §1 · Kararlar 16–17.
 *
 * DEĞİŞMEZ KURAL:
 *   NORMAL_SYNC → kapı UYGULANIR, desired_version ilerletilir
 *   REPAIR      → kapı ATLANIR, desired_version ARTIRILMAZ
 */
final class VersionGateTest extends TestCase
{
    use RefreshDatabase;

    /** Zaten gönderilmiş bir sürüm yeniden istenmez. */
    #[Test]
    public function already_synced_version_is_rejected(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->setState($tenant, $listing, desired: 182, synced: 182);

        $operation = $this->open($tenant, $listing, eventVersion: 182);

        $this->assertNull($operation, 'synced_version >= eventVersion → elenmeli.');
        $this->assertSame(0, $this->operationCount($tenant));
    }

    /** Daha yeni bir sürüm zaten istenmişse eski olay elenir. */
    #[Test]
    public function obsolete_version_is_rejected(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->setState($tenant, $listing, desired: 200, synced: 100);

        $operation = $this->open($tenant, $listing, eventVersion: 150);

        $this->assertNull($operation, 'desired_version > eventVersion → elenmeli.');

        // İstenen sürüm ESKİ olayla geriye çekilmemeli.
        $this->assertSame(200, $this->stateFor($tenant, $listing)->desired_version);
    }

    /** Sıra dışı gelen eski olay yeni sürümün üzerine YAZAMAZ. */
    #[Test]
    public function out_of_order_event_cannot_overwrite_newer_version(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->open($tenant, $listing, eventVersion: 182);
        $this->assertSame(182, $this->stateFor($tenant, $listing)->desired_version);

        // Ağdan geç gelen eski olay.
        $late = $this->open($tenant, $listing, eventVersion: 90);

        $this->assertNull($late);
        $this->assertSame(182, $this->stateFor($tenant, $listing)->desired_version);
    }

    /** Yeni sürüm bekleyen eski normal operasyonları geçersiz kılar. */
    #[Test]
    public function newer_version_supersedes_pending_normal_operations(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $first = $this->open($tenant, $listing, eventVersion: 100);
        $this->assertNotNull($first);

        $second = $this->open($tenant, $listing, eventVersion: 101);
        $this->assertNotNull($second);

        $this->assertSame(
            SyncOperationStatus::SUPERSEDED,
            $first->fresh()->status,
            'Eski bekleyen operasyon geçersiz kılınmalı — iki kez gönderilmemeli.',
        );
        $this->assertNotNull($first->fresh()->completed_at);
        $this->assertSame(SyncOperationStatus::PENDING, $second->fresh()->status);
    }

    /**
     * T7 — onarım sürüm kapısına TAKILMAZ.
     *
     * Mutabakat uzak durumu okumuş ve gerçek farkı kanıtlamıştır;
     * "bu sürüm zaten gönderildi" bilgisi orada yanlıştır.
     */
    #[Test]
    public function repair_skips_the_version_gate(): void
    {
        [$tenant, $listing] = $this->makeListing();

        // Normal senkron bu sürümü çoktan göndermiş.
        $this->setState($tenant, $listing, desired: 182, synced: 182);

        // Normal niyet elenir...
        $this->assertNull($this->open($tenant, $listing, eventVersion: 182));

        // ...ama onarım geçer.
        $repair = $this->open(
            $tenant,
            $listing,
            eventVersion: 182,
            intent: SyncIntent::REPAIR,
            reconciliationItemId: (string) new UuidV7,
        );

        $this->assertNotNull($repair, 'REPAIR sürüm kapısını atlamalı.');
        $this->assertSame(SyncIntent::REPAIR, $repair->intent);
        $this->assertSame(182, $repair->entity_version);
    }

    /**
     * DEĞİŞMEZ KURAL — onarım desired_version'ı ARTIRMAZ.
     *
     * Yapay sürüm artışı sıra dışı olay elemesini bozar ve gerçek bir
     * değişikliği bayat gösterir.
     */
    #[Test]
    public function repair_does_not_advance_desired_version(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->setState($tenant, $listing, desired: 182, synced: 182);

        $this->open(
            $tenant,
            $listing,
            eventVersion: 182,
            intent: SyncIntent::REPAIR,
            reconciliationItemId: (string) new UuidV7,
        );

        $state = $this->stateFor($tenant, $listing);

        $this->assertSame(182, $state->desired_version, 'Onarım sürümü ARTIRMAZ.');
        $this->assertSame(182, $state->synced_version);
        $this->assertFalse($state->hasPendingWork(), 'Onarım satırı kirli göstermemeli.');
        $this->assertNotNull($state->last_requested_at, 'Yalnızca last_requested_at tazelenir.');
    }

    /** Onarım bekleyen normal operasyonları geçersiz KILMAZ — bağımsız yaşarlar. */
    #[Test]
    public function repair_does_not_supersede_pending_normal_operations(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $normal = $this->open($tenant, $listing, eventVersion: 182);
        $this->assertNotNull($normal);

        $this->open(
            $tenant,
            $listing,
            eventVersion: 182,
            intent: SyncIntent::REPAIR,
            reconciliationItemId: (string) new UuidV7,
        );

        $this->assertSame(
            SyncOperationStatus::PENDING,
            $normal->fresh()->status,
            'Onarım devam eden normal akışı iptal ETMEZ.',
        );

        // Aynı listing ve aynı sürüm için ikisi BİRLİKTE var olabilir.
        $this->assertSame(2, $this->operationCount($tenant));
    }

    /**
     * T8 — aynı onarım kalemi iki kez işlense bile TEK operasyon oluşur.
     */
    #[Test]
    public function same_reconciliation_item_yields_single_operation(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $itemId = (string) new UuidV7;

        $first = $this->open($tenant, $listing, eventVersion: 182,
            intent: SyncIntent::REPAIR, reconciliationItemId: $itemId);

        $second = $this->open($tenant, $listing, eventVersion: 182,
            intent: SyncIntent::REPAIR, reconciliationItemId: $itemId);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id, 'Aynı kalem tek operasyon üretmeli.');
        $this->assertSame(1, $this->operationCount($tenant));
    }

    /** Farklı mutabakat kalemleri ayrı operasyon üretir. */
    #[Test]
    public function different_reconciliation_items_yield_separate_operations(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->open($tenant, $listing, eventVersion: 182, intent: SyncIntent::REPAIR,
            reconciliationItemId: (string) new UuidV7);

        $this->open($tenant, $listing, eventVersion: 182, intent: SyncIntent::REPAIR,
            reconciliationItemId: (string) new UuidV7);

        $this->assertSame(2, $this->operationCount($tenant));
    }

    /** REPAIR niyeti mutabakat kalemi kimliği olmadan açılamaz. */
    #[Test]
    public function repair_without_reconciliation_item_throws(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->expectException(\InvalidArgumentException::class);

        $this->open($tenant, $listing, eventVersion: 1, intent: SyncIntent::REPAIR);
    }

    /** Normal ve onarım anahtarları çakışmaz — iki ayrı biçim. */
    #[Test]
    public function normal_and_repair_keys_do_not_collide(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $itemId = (string) new UuidV7;

        $normal = $this->open($tenant, $listing, eventVersion: 182);
        $repair = $this->open($tenant, $listing, eventVersion: 182,
            intent: SyncIntent::REPAIR, reconciliationItemId: $itemId);

        $this->assertSame("inv:{$listing->id}:182", $normal->idempotency_key);
        $this->assertSame("inv:{$listing->id}:182:repair:{$itemId}", $repair->idempotency_key);
    }

    /** Tamamlanmış bir operasyon için yeni iş yaratılmaz. */
    #[Test]
    public function completed_operation_does_not_produce_new_work(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $itemId = (string) new UuidV7;

        $first = $this->open($tenant, $listing, eventVersion: 182,
            intent: SyncIntent::REPAIR, reconciliationItemId: $itemId);

        $this->asTenant($tenant, fn () => $first->forceFill([
            'status' => SyncOperationStatus::COMPLETED->value,
            'completed_at' => now(),
        ])->save());

        $again = $this->open($tenant, $listing, eventVersion: 182,
            intent: SyncIntent::REPAIR, reconciliationItemId: $itemId);

        $this->assertNull($again, 'Zaten tamamlanmış operasyon için iş yaratılmaz.');
        $this->assertSame(1, $this->operationCount($tenant));
    }

    /** İlk senkronda sync state satırı yoksa yaratılır. */
    #[Test]
    public function missing_sync_state_row_is_created_on_first_open(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->assertSame(0, $this->asTenant($tenant, fn () => ListingSyncState::query()->count()));

        $this->open($tenant, $listing, eventVersion: 1);

        $state = $this->stateFor($tenant, $listing);

        $this->assertSame(1, $state->desired_version);
        $this->assertSame(0, $state->synced_version);
        $this->assertTrue($state->hasPendingWork());
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: Listing} */
    private function makeListing(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Sürüm Kapısı '.uniqid(),
            owner: User::factory()->create(),
        );

        $listing = $this->asTenant($tenant, function () {
            $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
                ['code' => 'woocommerce'],
                [
                    'name' => 'WooCommerce',
                    'kind' => 'storefront',
                    'adapter_class' => 'App\\Domain\\Channels\\Adapters\\WooCommerceAdapter',
                    'is_active' => true,
                ],
            ));

            return Listing::factory()->create([
                'channel_connection_id' => ChannelConnection::factory()->create()->id,
                'variant_id' => Variant::factory()->create()->id,
            ]);
        });

        return [$tenant, $listing];
    }

    private function open(
        Tenant $tenant,
        Listing $listing,
        int $eventVersion,
        SyncIntent $intent = SyncIntent::NORMAL_SYNC,
        ?string $reconciliationItemId = null,
    ): ?SyncOperation {
        return $this->asTenant($tenant, fn () => (new OpenSyncOperation)->run(
            listing: $listing,
            domain: SyncDomain::INVENTORY,
            eventVersion: $eventVersion,
            intent: $intent,
            reconciliationItemId: $reconciliationItemId,
        ));
    }

    private function setState(Tenant $tenant, Listing $listing, int $desired, int $synced): void
    {
        $this->asTenant($tenant, fn () => ListingSyncState::query()->updateOrCreate(
            ['listing_id' => $listing->id, 'domain' => SyncDomain::INVENTORY->value],
            [
                'tenant_id' => $tenant->id,
                'desired_version' => $desired,
                'synced_version' => $synced,
                'status' => 'synced',
            ],
        ));
    }

    private function stateFor(Tenant $tenant, Listing $listing): ListingSyncState
    {
        return $this->asTenant($tenant, fn () => ListingSyncState::query()
            ->where('listing_id', $listing->id)
            ->where('domain', SyncDomain::INVENTORY->value)
            ->firstOrFail());
    }

    private function operationCount(Tenant $tenant): int
    {
        return $this->asTenant($tenant, fn () => SyncOperation::query()->count());
    }
}
