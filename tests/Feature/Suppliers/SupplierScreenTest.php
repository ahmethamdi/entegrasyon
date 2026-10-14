<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use App\Domain\Suppliers\Enums\RunStatus;
use App\Domain\Suppliers\Jobs\RunSupplierSyncJob;
use App\Domain\Suppliers\Models\SupplierRecord;
use App\Domain\Suppliers\Models\SupplierSource;
use App\Domain\Suppliers\Models\SupplierSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S5 · Tedarikçi ekranları: kiracı yalıtımı, adres kuralı, örnek okuma,
 * önizle/uygula, saatlik güncelleme kapısı.
 */
final class SupplierScreenTest extends TestCase
{
    use BuildsSupplierScenario;
    use RefreshDatabase;

    #[Test]
    public function guest_cannot_see_suppliers(): void
    {
        $this->get('/suppliers')->assertRedirect('/login');
    }

    #[Test]
    public function store_rejects_http_and_internal_addresses(): void
    {
        [, $user] = $this->makeTenant();

        $this->actingAs($user)->post('/suppliers', ['name' => 'X', 'feed_url' => 'http://feed.example.com/a.xml'])
            ->assertSessionHasErrors('feed_url');
        $this->actingAs($user)->post('/suppliers', ['name' => 'X', 'feed_url' => 'https://127.0.0.1/a.xml'])
            ->assertSessionHasErrors('feed_url');

        $this->assertSame(0, $this->asSystem(fn () => SupplierSource::query()->count()));
    }

    #[Test]
    public function store_creates_an_inactive_source_with_encrypted_url(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->actingAs($user)->post('/suppliers', ['name' => 'Toptan A', 'feed_url' => 'https://feed.example.com/x.xml?token=GIZLI123'])
            ->assertRedirect();

        $source = $this->asTenant($tenant, fn () => SupplierSource::query()->firstOrFail());
        $this->assertFalse($source->is_active);
        $this->assertSame('feed.example.com', $source->feed_host);
        $raw = $this->asSystem(fn () => DB::table('supplier_sources')->value('feed_url'));
        $this->assertStringNotContainsString('GIZLI123', (string) $raw);

        $this->actingAs($user)->get('/suppliers')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Suppliers/Index')->has('sources', 1))
            ->assertDontSee('GIZLI123');
    }

    #[Test]
    public function foreign_and_unknown_supplier_ids_look_the_same(): void
    {
        Queue::fake();
        [$tenantA] = $this->makeTenant('A');
        [, $userB] = $this->makeTenant('B');
        $source = $this->makeSource($tenantA);
        $run = $this->asTenant($tenantA, fn () => SupplierSyncRun::query()->create([
            'supplier_source_id' => $source->id, 'mode' => 'preview', 'trigger' => 'manual', 'status' => RunStatus::COMPLETE, 'counts' => [],
        ]));
        $unknown = '01900000-0000-7000-8000-000000000000';

        foreach ([$source->id, $unknown] as $id) {
            $this->actingAs($userB)->get("/suppliers/{$id}")->assertNotFound();
            $this->actingAs($userB)->put("/suppliers/{$id}", ['name' => 'x'])->assertNotFound();
            $this->actingAs($userB)->post("/suppliers/{$id}/preview")->assertNotFound();
            $this->actingAs($userB)->post("/suppliers/{$id}/apply")->assertNotFound();
            $this->actingAs($userB)->post("/suppliers/{$id}/sample")->assertNotFound();
            $this->actingAs($userB)->put("/suppliers/{$id}/active", ['active' => true])->assertNotFound();
            $this->actingAs($userB)->delete("/suppliers/{$id}")->assertNotFound();
            $this->actingAs($userB)->get("/suppliers/{$id}/runs/{$run->id}")->assertNotFound();
        }

        // Kendi kaynağının altından başka kaynağın turu da açılmaz.
        [$tenantB2, $userB2] = $this->makeTenant('B2');
        $own = $this->makeSource($tenantB2);
        $this->actingAs($userB2)->get("/suppliers/{$own->id}/runs/{$run->id}")->assertNotFound();

        Queue::assertNothingPushed();
        $this->assertSame(1, $this->asTenant($tenantA, fn () => SupplierSyncRun::query()->count()));
        $this->assertTrue($this->asTenant($tenantA, fn () => SupplierSource::query()->whereKey($source->id)->exists()));
    }

    #[Test]
    public function sample_reads_structure_without_touching_the_catalog(): void
    {
        $this->fakeFeed();
        [$tenant, $user] = $this->makeTenant();
        $source = $this->makeSource($tenant, ['record_path' => null, 'mapping' => []]);
        $this->feedBody = $this->xml([$this->product('S1', ['Satis' => '1.299,90']), $this->product('S2', ['Satis' => '49,90'])]);

        $this->actingAs($user)->post("/suppliers/{$source->id}/sample")->assertSessionHasNoErrors();

        $this->actingAs($user)->get("/suppliers/{$source->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Suppliers/Edit')
                ->where('sample.record_path', 'Urunler/Urun')
                ->where('sample.record_count', 2)
                ->where('sample.number_format_hint', 'comma')
                ->where('sample.fields', fn ($fields) => collect($fields)->pluck('path')->contains('Kod')));

        $this->assertSame(0, $this->asTenant($tenant, fn () => SupplierRecord::query()->count()));
        $this->assertSame(0, $this->asTenant($tenant, fn () => SupplierSyncRun::query()->count()));
    }

    #[Test]
    public function preview_and_apply_are_queued_and_redirect_to_the_run(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->makeTenant();
        $source = $this->makeSource($tenant);

        $this->actingAs($user)->post("/suppliers/{$source->id}/preview")->assertRedirect();
        Queue::assertPushed(RunSupplierSyncJob::class, 1);
        Http::assertNothingSent();

        // İkinci tur kilitliyken açılmaz.
        $this->actingAs($user)->post("/suppliers/{$source->id}/apply")->assertSessionHasErrors('run');
        Queue::assertPushed(RunSupplierSyncJob::class, 1);
    }

    #[Test]
    public function hourly_update_requires_a_complete_apply(): void
    {
        $this->fakeFeed();
        [$tenant, $user] = $this->makeTenant();
        $source = $this->makeSource($tenant);

        $this->actingAs($user)->put("/suppliers/{$source->id}/active", ['active' => true])->assertSessionHasErrors('active');
        $this->assertFalse($this->asTenant($tenant, fn () => $source->refresh()->is_active));

        $this->feedBody = $this->xml([$this->product('A1')]);
        $this->sync($tenant, $source, SupplierSyncRun::MODE_PREVIEW);
        $this->actingAs($user)->put("/suppliers/{$source->id}/active", ['active' => true])->assertSessionHasErrors('active');

        $this->sync($tenant, $source);
        $this->actingAs($user)->put("/suppliers/{$source->id}/active", ['active' => true])->assertSessionHasNoErrors();
        $source = $this->asTenant($tenant, fn () => $source->refresh());
        $this->assertTrue($source->is_active);
        $this->assertNotNull($source->next_run_at);
    }

    #[Test]
    public function changing_mapping_or_policy_stops_hourly_update(): void
    {
        [$tenant, $user] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->asTenant($tenant, fn () => SupplierSource::query()->whereKey($source->id)->update(['is_active' => true, 'next_run_at' => now()]));

        $payload = [
            'name' => 'Toptancı',
            'record_path' => 'Urunler/Urun',
            'mapping' => ['variant_path' => '', 'fields' => ['key' => 'Kod', 'stock' => 'Stok'], 'options' => []],
            'number_format' => 'dot',
            'currency' => 'TRY',
            'stock_policy' => 'informational',
            'price_policy' => 'none',
            'markup_percent' => '0',
            'markup_amount' => '0',
            'rounding' => 'none',
            'create_products' => false,
        ];

        $this->actingAs($user)->put("/suppliers/{$source->id}", $payload)->assertSessionHasNoErrors();

        $source = $this->asTenant($tenant, fn () => $source->refresh());
        $this->assertFalse($source->is_active);
        $this->assertSame('informational', $source->stock_policy);
        $this->assertSame(['key' => 'Kod', 'stock' => 'Stok'], $source->mapping['fields']);
    }

    #[Test]
    public function run_screen_shows_counts_and_changes(): void
    {
        $this->fakeFeed();
        [$tenant, $user] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('V1'), $this->product('V2', ['Stok' => 'yok'])]);
        $run = $this->sync($tenant, $source);

        $this->actingAs($user)->get("/suppliers/{$source->id}/runs/{$run->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Suppliers/Run')
                ->where('run.counts.created_products', 2)
                ->where('run.counts.skipped', 1)
                ->where('run.counts.records', 2)
                ->has('changes.data'));

        $this->actingAs($user)->get("/suppliers/{$source->id}/runs/{$run->id}?kind=skipped")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kind', 'skipped')
                ->where('changes.data.0.reasonCode', 'SUPPLIER_STOCK_INVALID')
                ->where('changes.data.0.sourceKey', 'V2'));
    }

    #[Test]
    public function deleting_a_source_keeps_products_and_ledger(): void
    {
        $this->fakeFeed();
        [$tenant, $user] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('DEL1', ['Stok' => '4'])]);
        $this->sync($tenant, $source);

        $this->actingAs($user)->delete("/suppliers/{$source->id}")->assertRedirect('/suppliers');

        $this->assertNotNull($this->variantBySku($tenant, 'DEL1'));
        $this->assertSame(4, $this->onHand($tenant, $this->variantBySku($tenant, 'DEL1')->id));
        $this->assertSame(0, $this->asTenant($tenant, fn () => SupplierRecord::query()->count()));
    }
}
