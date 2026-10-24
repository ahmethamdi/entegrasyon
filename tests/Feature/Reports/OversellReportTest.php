<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Enums\TenantRole;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Sync\Models\Listing;
use Carbon\CarbonImmutable;
use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S11 · "Fazla satış yok" kanıt ekranı — rakamlar kayıttan, tanımla.
 *
 * Satışta ekran görüntüsü olarak kullanıldığı için her sayının neyi
 * saydığı sınanır: yanlış bir sayı satıcıya yanlış vaat demektir.
 */
final class OversellReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ChannelConnection $a;

    private ChannelConnection $b;

    private string $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-24 12:00:00');
        $this->seed(ChannelTypeSeeder::class);

        $this->owner = User::factory()->create();
        $this->tenant = (new CreateTenant)->run(name: 'Kanıt '.uniqid(), owner: $this->owner);

        [$this->a, $this->b, $this->warehouse] = $this->asTenant($this->tenant, fn (): array => [
            ChannelConnection::factory()->create(['status' => 'active']),
            ChannelConnection::factory()->create(['status' => 'active']),
            Warehouse::factory()->create()->id,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * İki kanalda satılan ürün: biri sıfıra indi ve sıfır öbür kanala
     * 4 sn'de ulaştı (tükenme koruması), biri ulaşmadı (beklemede).
     * Tek kanalda satılan ürün süreye ve korumaya GİRMEZ.
     */
    #[Test]
    public function the_numbers_follow_the_written_definitions(): void
    {
        $shared = $this->variant();
        $listingB = $this->listing($this->b, $shared, '2026-10-01 00:00:00');
        $this->listing($this->a, $shared, '2026-10-01 00:00:00');

        // 1) Son birim A'da satıldı → B'ye sıfır 4 sn sonra gitti.
        $e1 = $this->sale($this->a, $shared, at: '2026-10-20 10:00:00', availableAfter: 0);
        $this->push($listingB, created: '2026-10-20 10:00:01', completed: '2026-10-20 10:00:04', event: $e1);

        // 2) Bir satış daha: B'ye giden gönderim ölü — ulaşmadı.
        $e2 = $this->sale($this->a, $shared, at: '2026-10-21 09:00:00', availableAfter: 3);
        $this->push($listingB, created: '2026-10-21 09:00:01', completed: null, status: 'dead', event: $e2);

        // 3) Yalnız A'da satılan ürün.
        $solo = $this->variant();
        $this->listing($this->a, $solo, '2026-10-01 00:00:00');
        $this->sale($this->a, $solo, at: '2026-10-22 09:00:00', availableAfter: 0);

        // 4) Pencere DIŞI satış sayılmaz.
        $this->sale($this->a, $shared, at: '2026-09-01 09:00:00', availableAfter: 0);

        $this->order($this->a, '2026-10-20 09:59:00');
        $this->order($this->b, '2026-10-21 08:00:00');
        $this->order($this->b, '2026-09-01 08:00:00');

        $this->reconciliation($listingB, ['MATCHED', 'MATCHED', 'REPAIRED', 'MANUAL_REVIEW', 'REMOTE_UNREACHABLE']);

        $report = $this->report();

        $this->assertSame(['count' => 2, 'channels' => 2], $report['orders']);
        $this->assertSame(['total' => 3, 'multi_channel' => 2, 'reached' => 1, 'pending' => 1, 'not_targeted' => 0], $report['sales']);
        $this->assertSame(4.0, (float) $report['latency']['p95']);
        $this->assertSame(['protected' => 1, 'unprotected' => 0], $report['stockouts']);
        $this->assertSame(['checked' => 4, 'drift' => 2, 'repaired' => 1, 'review' => 1, 'unreachable' => 1], $report['reconciliation']);
    }

    /**
     * ⚠️ SATIŞTAN ÖNCE YARATILMIŞ GÖNDERİM SATIŞI TAŞIMAZ.
     *
     * Eski stoğu taşıyan gönderim sayılsaydı "ulaştı" denir ve süre
     * negatif ya da sıfır çıkardı.
     */
    #[Test]
    public function a_push_created_before_the_sale_does_not_count(): void
    {
        $shared = $this->variant();
        $listingB = $this->listing($this->b, $shared, '2026-10-01 00:00:00');
        $event = $this->sale($this->a, $shared, at: '2026-10-20 10:00:00', availableAfter: 0);
        $this->push($listingB, created: '2026-10-20 09:59:00', completed: '2026-10-20 10:00:02');
        $this->push($listingB, created: '2026-10-20 10:00:01', completed: null, status: 'pending', event: $event);

        $report = $this->report();

        $this->assertSame(0, $report['sales']['reached']);
        $this->assertSame(['protected' => 0, 'unprotected' => 1], $report['stockouts']);
        $this->assertNull($report['latency']['p95']);
    }

    /**
     * ⚠️ SATIŞ ANINDA HEDEF OLMAYAN İLAN SÜREYE GİRMEZ (24 Ekim, canlı).
     *
     * Test kiracısında Trendyol ilanı satış günü pasifti; fan-out ona iş
     * açmadı, stok 23 saat sonra elle düzeltmeyle gitti. Bugünkü canlılığa
     * bakan sorgu bunu "23 sa gecikme" diye gösteriyordu. Hedef olmayan
     * ilan ayrı sayılır — fan-out'un bir ilanı atlaması da görünsün.
     */
    #[Test]
    public function a_listing_the_sale_did_not_target_is_counted_apart_not_as_latency(): void
    {
        $shared = $this->variant();
        $listingB = $this->listing($this->b, $shared, '2026-10-01 00:00:00');

        // Önceki satış B'ye ulaştı — aynı stok satırının ESKİ olayı sonraki
        // satışı "hedefledi" saydırmamalı (olay hareketle eşlenir).
        $earlier = $this->sale($this->a, $shared, at: '2026-10-06 10:00:00', availableAfter: 2);
        $this->push($listingB, created: '2026-10-06 10:00:01', completed: '2026-10-06 10:00:02', event: $earlier);

        $this->sale($this->a, $shared, at: '2026-10-07 07:57:26', availableAfter: 1);
        $this->push($listingB, created: '2026-10-08 07:03:42', completed: '2026-10-08 07:03:45');

        $report = $this->report();

        $this->assertSame(['total' => 2, 'multi_channel' => 1, 'reached' => 1, 'pending' => 0, 'not_targeted' => 1], $report['sales']);
        $this->assertSame(2.0, (float) $report['latency']['max']);
    }

    /** Bir ilan hedef, öbürü değilse süre yalnız hedeften ölçülür. */
    #[Test]
    public function latency_is_measured_over_targeted_listings_only(): void
    {
        $shared = $this->variant();
        $listingB = $this->listing($this->b, $shared, '2026-10-01 00:00:00');
        $c = $this->asTenant($this->tenant, fn (): ChannelConnection => ChannelConnection::factory()->create(['status' => 'active']));
        $listingC = $this->listing($c, $shared, '2026-10-01 00:00:00');

        $event = $this->sale($this->a, $shared, at: '2026-10-20 10:00:00', availableAfter: 0);
        $this->push($listingB, created: '2026-10-20 10:00:01', completed: '2026-10-20 10:00:03', event: $event);
        $this->push($listingC, created: '2026-10-21 10:00:00', completed: '2026-10-21 10:00:02');

        $report = $this->report();

        $this->assertSame(['total' => 1, 'multi_channel' => 1, 'reached' => 1, 'pending' => 0, 'not_targeted' => 1], $report['sales']);
        $this->assertSame(3.0, (float) $report['latency']['max']);
        $this->assertSame(['protected' => 1, 'unprotected' => 0], $report['stockouts']);
    }

    /** Satıştan SONRA açılan ilan "diğer ilan" değildir. */
    #[Test]
    public function a_listing_opened_after_the_sale_is_not_counted(): void
    {
        $shared = $this->variant();
        $this->listing($this->b, $shared, '2026-10-22 00:00:00');
        $this->sale($this->a, $shared, at: '2026-10-20 10:00:00', availableAfter: 0);

        $this->assertSame(0, $this->report()['sales']['multi_channel']);
    }

    /** Başka kiracının satışı ve siparişi görünmez. */
    #[Test]
    public function another_tenants_records_are_invisible(): void
    {
        $stranger = (new CreateTenant)->run(name: 'Yabancı '.uniqid(), owner: User::factory()->create());
        $this->asTenant($stranger, function (): void {
            $c = ChannelConnection::factory()->create(['status' => 'active']);
            DB::table('orders')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $c->tenant_id, 'channel_connection_id' => $c->id, 'external_id' => 'x-'.uniqid(), 'placed_at' => '2026-10-20 10:00:00', 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->assertSame(0, $this->report()['orders']['count']);
    }

    /** Sahip ve salt okunur üye görür; depo üyesi görmez. */
    #[Test]
    public function the_screen_is_for_owner_admin_and_viewer_not_warehouse(): void
    {
        $this->actingAs($this->owner)->get('/reports/oversell')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Reports/Oversell')->has('report.latency'));

        foreach ([TenantRole::VIEWER->value => 200, TenantRole::WAREHOUSE->value => 403] as $role => $status) {
            $member = User::factory()->create();
            $this->tenant->users()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => $role, 'accepted_at' => now()]);
            $this->actingAs($member)->get('/reports/oversell')->assertStatus($status);
        }
    }

    // ───────────────────────────────────────────── kurulum

    /** @return array<string, mixed> */
    private function report(): array
    {
        return $this->actingAs($this->owner)->get('/reports/oversell')->assertOk()->viewData('page')['props']['report'];
    }

    private function variant(): Variant
    {
        return $this->asTenant($this->tenant, fn (): Variant => Variant::factory()->create());
    }

    private function listing(ChannelConnection $connection, Variant $variant, string $created): Listing
    {
        return $this->asTenant($this->tenant, fn (): Listing => Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'created_at' => $created,
        ]));
    }

    /** Satış hareketi + fan-out'un okuduğu stok olayı; olay kimliğini döner. */
    private function sale(ChannelConnection $connection, Variant $variant, string $at, int $availableAfter): string
    {
        $level = DB::table('inventory_levels')
            ->where('warehouse_id', $this->warehouse)->where('variant_id', $variant->id)->value('id');

        if ($level === null) {
            $level = (string) Str::uuid();
            DB::table('inventory_levels')->insert([
                'id' => $level, 'tenant_id' => $this->tenant->id, 'warehouse_id' => $this->warehouse,
                'variant_id' => $variant->id, 'created_at' => $at, 'updated_at' => $at,
            ]);
        }

        $movement = (string) Str::uuid();
        $event = (string) Str::uuid();

        DB::table('inventory_movements')->insert([
            'id' => $movement,
            'tenant_id' => $this->tenant->id,
            'warehouse_id' => $this->warehouse,
            'variant_id' => $variant->id,
            'type' => 'SALE',
            'on_hand_delta' => -1,
            'reserved_delta' => 0,
            'on_hand_after' => $availableAfter,
            'reserved_after' => 0,
            'source_type' => 'order_line',
            'channel_connection_id' => $connection->id,
            'idempotency_key' => 'sale:'.uniqid(),
            'occurred_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        DB::table('outbox_events')->insert([
            'id' => $event, 'tenant_id' => $this->tenant->id, 'aggregate_type' => 'inventory_level',
            'aggregate_id' => $level, 'event_type' => 'InventoryLevelChanged',
            'payload' => json_encode(['movement_id' => $movement, 'variant_id' => $variant->id]),
            'available_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);

        return $event;
    }

    /** `$event` verilirse gönderim o satışın fan-out'undan açılmıştır. */
    private function push(Listing $listing, string $created, ?string $completed, string $status = 'completed', ?string $event = null): void
    {
        DB::table('sync_operations')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $listing->channel_connection_id,
            'operation_type' => 'INVENTORY_PUSH',
            'entity_type' => 'listing',
            'entity_id' => $listing->id,
            'entity_version' => 1,
            'idempotency_key' => 'inv:'.uniqid(),
            'status' => $status,
            'outbox_event_id' => $event,
            'completed_at' => $completed,
            'created_at' => $created,
            'updated_at' => $created,
        ]);
    }

    private function order(ChannelConnection $connection, string $placed): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'channel_connection_id' => $connection->id,
            'external_id' => 'o-'.uniqid(),
            'placed_at' => $placed,
            'created_at' => $placed,
            'updated_at' => $placed,
        ]);
    }

    /** @param list<string> $statuses */
    private function reconciliation(Listing $listing, array $statuses): void
    {
        $run = (string) Str::uuid();
        DB::table('reconciliation_runs')->insert([
            'id' => $run, 'tenant_id' => $this->tenant->id, 'channel_connection_id' => $listing->channel_connection_id,
            'scope' => 'hot', 'started_at' => '2026-10-20 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($statuses as $status) {
            DB::table('reconciliation_items')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'reconciliation_run_id' => $run,
                'listing_id' => $listing->id, 'domain' => 'INVENTORY', 'priority_reason' => 'recently_sold',
                'status' => $status, 'checked_at' => '2026-10-20 12:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
