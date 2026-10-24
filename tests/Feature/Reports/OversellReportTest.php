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
        $this->sale($this->a, $shared, at: '2026-10-20 10:00:00', availableAfter: 0);
        $this->push($listingB, created: '2026-10-20 10:00:01', completed: '2026-10-20 10:00:04');

        // 2) Bir satış daha: B'ye giden gönderim ölü — ulaşmadı.
        $this->sale($this->a, $shared, at: '2026-10-21 09:00:00', availableAfter: 3);
        $this->push($listingB, created: '2026-10-21 09:00:01', completed: null, status: 'dead');

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
        $this->assertSame(['total' => 3, 'multi_channel' => 2, 'reached' => 1, 'pending' => 1], $report['sales']);
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
        $this->sale($this->a, $shared, at: '2026-10-20 10:00:00', availableAfter: 0);
        $this->push($listingB, created: '2026-10-20 09:59:00', completed: '2026-10-20 10:00:02');

        $report = $this->report();

        $this->assertSame(0, $report['sales']['reached']);
        $this->assertSame(['protected' => 0, 'unprotected' => 1], $report['stockouts']);
        $this->assertNull($report['latency']['p95']);
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

    private function sale(ChannelConnection $connection, Variant $variant, string $at, int $availableAfter): void
    {
        DB::table('inventory_movements')->insert([
            'id' => (string) Str::uuid(),
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
    }

    private function push(Listing $listing, string $created, ?string $completed, string $status = 'completed'): void
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
