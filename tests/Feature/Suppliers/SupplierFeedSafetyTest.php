<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Support\OutboundUrlGuard;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Suppliers\Actions\StartSupplierSync;
use App\Domain\Suppliers\Enums\RunStatus;
use App\Domain\Suppliers\Enums\SupplierReason;
use App\Domain\Suppliers\Jobs\RunSupplierSyncJob;
use App\Domain\Suppliers\Models\SupplierRecord;
use App\Domain\Suppliers\Models\SupplierSource;
use App\Domain\Suppliers\Models\SupplierSyncRun;
use App\Domain\Suppliers\Support\SupplierFeedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * S5 · Tedarikçi akışı — SSRF sınırı, XML güvenliği, tamlık, eşzamanlılık,
 * çökme sonrası idempotens, önizleme, kota, varyant grupları (ADR-012).
 */
final class SupplierFeedSafetyTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use BuildsSupplierScenario;
    use RefreshDatabase;

    // ─────────────────────────────────────────── SSRF

    #[Test]
    public function http_scheme_is_rejected_before_any_request(): void
    {
        Http::fake();
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant, ['feed_url' => 'http://feed.example.com/x.xml']);

        $run = $this->sync($tenant, $source);

        $this->assertSame(RunStatus::FETCH_FAILED, $run->status);
        $this->assertSame(SupplierReason::SUPPLIER_URL_BLOCKED->value, $run->reason_code);
        Http::assertNothingSent();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenUrls(): iterable
    {
        yield 'localhost' => ['https://localhost/feed.xml'];
        yield 'loopback ip' => ['https://127.0.0.1/feed.xml'];
        yield 'private ip' => ['https://10.1.2.3/feed.xml'];
        yield 'metadata' => ['https://169.254.169.254/latest/meta-data'];
        yield 'ipv6 loopback' => ['https://[::1]/feed.xml'];
        yield 'docker service name' => ['https://postgres/feed.xml'];
        yield 'internal suffix' => ['https://api.internal/feed.xml'];
        yield 'non 443 port' => ['https://feed.example.com:8080/feed.xml'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'ftp scheme' => ['ftp://feed.example.com/feed.xml'];
    }

    #[Test]
    #[DataProvider('forbiddenUrls')]
    public function forbidden_destinations_are_blocked(string $url): void
    {
        Http::fake();
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant, ['feed_url' => $url]);

        $run = $this->sync($tenant, $source);

        $this->assertSame(RunStatus::FETCH_FAILED, $run->status);
        $this->assertSame(SupplierReason::SUPPLIER_URL_BLOCKED->value, $run->reason_code);
        Http::assertNothingSent();
    }

    #[Test]
    public function public_name_resolving_to_private_ip_is_blocked(): void
    {
        Http::fake();
        $this->app->instance(OutboundUrlGuard::class, new OutboundUrlGuard(static fn (): array => ['10.0.0.8']));
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);

        $run = $this->sync($tenant, $source);

        $this->assertSame(SupplierReason::SUPPLIER_URL_BLOCKED->value, $run->reason_code);
        Http::assertNothingSent();
    }

    #[Test]
    public function redirect_into_internal_network_is_blocked(): void
    {
        Http::fake([
            'feed.example.com/*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data']),
            '*' => Http::response('SIZINTI', 200),
        ]);
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);

        $run = $this->sync($tenant, $source);

        $this->assertSame(SupplierReason::SUPPLIER_URL_BLOCKED->value, $run->reason_code);
        Http::assertSentCount(1);
    }

    #[Test]
    public function redirect_to_http_is_blocked(): void
    {
        Http::fake([
            'feed.example.com/*' => Http::response('', 301, ['Location' => 'http://cdn.example.com/feed.xml']),
            '*' => Http::response('x', 200),
        ]);
        [$tenant] = $this->makeTenant();

        $run = $this->sync($tenant, $this->makeSource($tenant));

        $this->assertSame(SupplierReason::SUPPLIER_URL_BLOCKED->value, $run->reason_code);
        Http::assertSentCount(1);
    }

    #[Test]
    public function public_https_redirect_is_followed(): void
    {
        $body = $this->xml([$this->product('R1')]);
        Http::fake([
            'feed.example.com/*' => Http::response('', 302, ['Location' => 'https://cdn.example.net/real.xml']),
            'cdn.example.net/*' => Http::response($body, 200),
        ]);
        [$tenant] = $this->makeTenant();

        $run = $this->sync($tenant, $this->makeSource($tenant));

        $this->assertSame(RunStatus::COMPLETE, $run->status);
        $this->assertNotNull($this->variantBySku($tenant, 'R1'));
    }

    #[Test]
    public function redirect_loop_stops(): void
    {
        Http::fake(['feed.example.com/*' => Http::response('', 302, ['Location' => 'https://feed.example.com/again.xml'])]);
        [$tenant] = $this->makeTenant();

        $run = $this->sync($tenant, $this->makeSource($tenant));

        $this->assertSame(RunStatus::FETCH_FAILED, $run->status);
        Http::assertSentCount(4);
    }

    // ─────────────────────────────────────────── indirme hataları

    #[Test]
    public function http_500_is_fetch_failed_and_changes_nothing(): void
    {
        [$tenant, , $warehouseId] = $this->makeTenant();
        $this->fakeFeed();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('F1', ['Stok' => '5'])]);
        $this->sync($tenant, $source);
        $succeededAt = $source->refresh()->last_succeeded_at;

        $this->feedStatus = 500;
        $run = $this->sync($tenant, $source);

        $this->assertSame(RunStatus::FETCH_FAILED, $run->status);
        $this->assertSame(SupplierReason::SUPPLIER_FETCH_FAILED->value, $run->reason_code);
        $this->assertStringContainsString('500', (string) $run->error_detail);
        $this->assertSame(5, $this->onHand($tenant, $this->variantBySku($tenant, 'F1')->id));
        $this->assertEquals($succeededAt, $source->refresh()->last_succeeded_at);
        $this->assertSame('fetch_failed', $source->last_status);
        $this->assertSame(SupplierSource::STATE_IDLE, $source->sync_state);
    }

    #[Test]
    public function timeout_is_fetch_failed(): void
    {
        Http::fake(['feed.example.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        [$tenant] = $this->makeTenant();

        $run = $this->sync($tenant, $this->makeSource($tenant));

        $this->assertSame(RunStatus::FETCH_FAILED, $run->status);
        $this->assertStringContainsString('timed out', (string) $run->error_detail);
    }

    #[Test]
    public function oversized_feed_is_rejected_without_applying(): void
    {
        config(['entegrasyon.supplier_feed_max_bytes' => 2000]);
        $this->fakeFeed();
        [$tenant] = $this->makeTenant();
        $products = [];

        for ($i = 0; $i < 50; $i++) {
            $products[] = $this->product("BIG{$i}");
        }

        $this->feedBody = $this->xml($products);

        $run = $this->sync($tenant, $this->makeSource($tenant));

        $this->assertSame(RunStatus::FETCH_FAILED, $run->status);
        $this->assertSame(SupplierReason::SUPPLIER_FEED_TOO_LARGE->value, $run->reason_code);
        $this->assertSame(0, $this->asTenant($tenant, fn () => Product::query()->count()));
    }

    // ─────────────────────────────────────────── XML güvenliği ve tamlık

    #[Test]
    public function xml_broken_halfway_applies_nothing(): void
    {
        $this->fakeFeed();
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $valid = $this->xml(array_map(fn ($i) => $this->product("H{$i}"), range(1, 40)));
        // 41. kayıtta kapanmayan etiket.
        $this->feedBody = str_replace('</Urunler>', '<Urun><Kod>H41</Kod><Ad>Kırık</Urun></Urunler>', $valid);

        $run = $this->sync($tenant, $source);

        $this->assertSame(RunStatus::PARSE_FAILED, $run->status);
        $this->assertSame(SupplierReason::SUPPLIER_XML_INVALID->value, $run->reason_code);
        $this->assertSame(0, $this->asTenant($tenant, fn () => Product::query()->count()));
        $this->assertSame(0, $this->asTenant($tenant, fn () => SupplierRecord::query()->count()));
        $this->assertNull($source->refresh()->last_succeeded_at);
    }

    #[Test]
    public function truncated_feed_after_a_good_run_neither_applies_nor_marks_missing(): void
    {
        $this->fakeFeed();
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('T1', ['Stok' => '3']), $this->product('T2', ['Stok' => '4'])]);
        $this->sync($tenant, $source);

        $full = $this->xml([$this->product('T1', ['Stok' => '1']), $this->product('T2', ['Stok' => '2'])]);
        $this->feedBody = substr($full, 0, (int) (strlen($full) * 0.6));
        $run = $this->sync($tenant, $source);

        $this->assertSame(RunStatus::PARSE_FAILED, $run->status);
        $this->assertSame(3, $this->onHand($tenant, $this->variantBySku($tenant, 'T1')->id));
        $this->assertSame(4, $this->onHand($tenant, $this->variantBySku($tenant, 'T2')->id));
        $this->assertSame(0, $this->asTenant($tenant, fn () => SupplierRecord::query()->where('presence', 'missing')->count()));
    }

    #[Test]
    public function doctype_and_external_entities_are_rejected(): void
    {
        $this->fakeFeed();
        [$tenant] = $this->makeTenant();
        $this->feedBody = '<?xml version="1.0"?><!DOCTYPE Urunler [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<Urunler><Urun><Kod>X1</Kod><Ad>&xxe;</Ad><Satis>10</Satis></Urun></Urunler>';

        $run = $this->sync($tenant, $this->makeSource($tenant));

        $this->assertSame(RunStatus::PARSE_FAILED, $run->status);
        $this->assertSame(0, $this->asTenant($tenant, fn () => Product::query()->count()));
    }

    #[Test]
    public function entity_expansion_bomb_is_rejected(): void
    {
        $this->fakeFeed();
        [$tenant] = $this->makeTenant();
        $this->feedBody = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">'
            .'<!ENTITY lol3 "&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;">]><Urunler><Urun><Kod>&lol3;</Kod></Urun></Urunler>';

        $run = $this->sync($tenant, $this->makeSource($tenant));

        $this->assertSame(RunStatus::PARSE_FAILED, $run->status);
    }

    #[Test]
    public function wrong_record_path_is_validation_failed(): void
    {
        $this->fakeFeed();
        [$tenant] = $this->makeTenant();
        $this->feedBody = $this->xml([$this->product('W1')]);

        $run = $this->sync($tenant, $this->makeSource($tenant, ['record_path' => 'Urunler/Item']));

        $this->assertSame(RunStatus::VALIDATION_FAILED, $run->status);
        $this->assertSame(SupplierReason::SUPPLIER_NO_RECORDS->value, $run->reason_code);
    }

    #[Test]
    public function mapping_without_identity_is_validation_failed_without_fetching(): void
    {
        Http::fake();
        [$tenant] = $this->makeTenant();

        $run = $this->sync($tenant, $this->makeSource($tenant, ['mapping' => ['fields' => ['name' => 'Ad']]]));

        $this->assertSame(RunStatus::VALIDATION_FAILED, $run->status);
        $this->assertSame(SupplierReason::SUPPLIER_MAPPING_INVALID->value, $run->reason_code);
        Http::assertNothingSent();
    }

    // ─────────────────────────────────────────── eşzamanlılık, çökme

    #[Test]
    public function a_second_run_cannot_start_while_one_is_running(): void
    {
        Queue::fake();
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);

        $this->asTenant($tenant, fn () => app(StartSupplierSync::class)->run($source, SupplierSyncRun::MODE_APPLY, SupplierSyncRun::TRIGGER_MANUAL));

        try {
            $this->asTenant($tenant, fn () => app(StartSupplierSync::class)->run($source->refresh(), SupplierSyncRun::MODE_APPLY, SupplierSyncRun::TRIGGER_MANUAL));
            $this->fail('İkinci tur başlamamalıydı.');
        } catch (SupplierFeedException $e) {
            $this->assertSame(SupplierReason::SUPPLIER_SYNC_ALREADY_RUNNING, $e->reason);
        }

        $this->assertSame(1, $this->asTenant($tenant, fn () => SupplierSyncRun::query()->count()));
    }

    #[Test]
    public function different_sources_run_independently(): void
    {
        Queue::fake();
        [$tenant] = $this->makeTenant();
        $a = $this->makeSource($tenant);
        $b = $this->makeSource($tenant, ['name' => 'İkinci']);

        $this->asTenant($tenant, fn () => app(StartSupplierSync::class)->run($a, SupplierSyncRun::MODE_APPLY, SupplierSyncRun::TRIGGER_MANUAL));
        $this->asTenant($tenant, fn () => app(StartSupplierSync::class)->run($b, SupplierSyncRun::MODE_APPLY, SupplierSyncRun::TRIGGER_MANUAL));

        $this->assertSame(2, $this->asTenant($tenant, fn () => SupplierSyncRun::query()->count()));
    }

    #[Test]
    public function crash_after_ledger_write_rolls_back_and_retry_writes_each_change_once(): void
    {
        $this->fakeFeed();
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml(array_map(fn ($i) => $this->product("CR{$i}", ['Stok' => '10']), range(1, 6)));
        $this->sync($tenant, $source);

        $succeededAt = $source->refresh()->last_succeeded_at;
        $this->travel(5)->minutes();
        $this->feedBody = $this->xml(array_map(fn ($i) => $this->product("CR{$i}", ['Stok' => '4']), range(1, 6)));

        // CR4: defter hareketi YAZILDIKTAN sonra, kayıt kaydedilirken süreç
        // ölüyor (aynı transaction). Hareket de geri alınmalı.
        $crashed = false;
        SupplierRecord::saving(function (SupplierRecord $record) use (&$crashed): void {
            if ($record->source_key === 'CR4' && ! $crashed) {
                $crashed = true;

                throw new RuntimeException('süreç öldü');
            }
        });

        $first = $this->sync($tenant, $source);

        $this->assertTrue($crashed);
        $this->assertSame(RunStatus::PARTIAL, $first->status);
        $this->assertSame(1, $first->counts['errors']);
        // PARTIAL başarı damgasını İLERLETMEZ.
        $this->assertEquals($succeededAt, $source->refresh()->last_succeeded_at);
        $cr4 = $this->variantBySku($tenant, 'CR4');
        $this->assertSame(10, $this->onHand($tenant, $cr4->id));
        $this->assertSame(0, $this->asTenant($tenant, fn () => InventoryMovement::query()
            ->where('variant_id', $cr4->id)->where('source_type', 'supplier_sync')->count()));

        // Yeniden deneme: hepsi 4, her varyantta TEK tedarikçi hareketi.
        $retry = $this->sync($tenant, $source);
        $this->assertSame(RunStatus::COMPLETE, $retry->status);
        $this->assertSame(1, $retry->counts['stock_changed']);

        foreach (range(1, 6) as $i) {
            $variant = $this->variantBySku($tenant, "CR{$i}");
            $this->assertSame(4, $this->onHand($tenant, $variant->id), "CR{$i}");
            $this->assertSame(1, $this->asTenant($tenant, fn () => InventoryMovement::query()
                ->where('variant_id', $variant->id)->where('source_type', 'supplier_sync')->count()), "CR{$i}");
        }

        $this->assertLedgerMatchesProjectionForTenant($tenant->id);
    }

    #[Test]
    public function redelivered_job_for_a_finished_run_does_nothing(): void
    {
        $this->fakeFeed();
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('RD1', ['Stok' => '3'])]);
        $run = $this->sync($tenant, $source);
        $movements = $this->asTenant($tenant, fn () => InventoryMovement::query()->count());

        (new RunSupplierSyncJob($tenant->id, $run->id))->handle();

        $this->assertSame($movements, $this->asTenant($tenant, fn () => InventoryMovement::query()->count()));
        $this->assertSame(1, $this->asTenant($tenant, fn () => SupplierSyncRun::query()->count()));
    }

    #[Test]
    public function stale_running_run_is_closed_and_lock_released_by_the_scheduler_command(): void
    {
        Queue::fake();
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $run = $this->asTenant($tenant, fn () => app(StartSupplierSync::class)->run($source, SupplierSyncRun::MODE_APPLY, SupplierSyncRun::TRIGGER_SCHEDULE));
        $this->asTenant($tenant, fn () => SupplierSyncRun::query()->whereKey($run->id)->update(['created_at' => now()->subHour()]));

        Artisan::call('suppliers:sync');

        $run = $this->asTenant($tenant, fn () => $run->refresh());
        $this->assertSame(RunStatus::FAILED, $run->status);
        $this->assertSame(SupplierReason::SUPPLIER_SYNC_INCOMPLETE->value, $run->reason_code);
        $this->assertSame(SupplierSource::STATE_IDLE, $this->asTenant($tenant, fn () => $source->refresh()->sync_state));
        $this->assertNull($this->asTenant($tenant, fn () => $source->refresh()->last_succeeded_at));
    }

    // ─────────────────────────────────────────── zamanlama

    #[Test]
    public function scheduler_starts_only_due_active_sources_and_sets_next_run(): void
    {
        $this->fakeFeed();
        $this->feedBody = $this->xml([$this->product('SCH1')]);
        [$tenant] = $this->makeTenant();
        $due = $this->makeSource($tenant);
        $inactive = $this->makeSource($tenant, ['name' => 'Kapalı']);
        $future = $this->makeSource($tenant, ['name' => 'Sonra']);
        $this->asTenant($tenant, function () use ($due, $future): void {
            SupplierSource::query()->whereKey($due->id)->update(['is_active' => true, 'next_run_at' => now()->subMinute()]);
            SupplierSource::query()->whereKey($future->id)->update(['is_active' => true, 'next_run_at' => now()->addMinutes(30)]);
        });

        Artisan::call('suppliers:sync');

        $this->asTenant($tenant, function () use ($due, $inactive, $future): void {
            $this->assertSame(1, SupplierSyncRun::query()->where('supplier_source_id', $due->id)->count());
            $this->assertSame(0, SupplierSyncRun::query()->where('supplier_source_id', $inactive->id)->count());
            $this->assertSame(0, SupplierSyncRun::query()->where('supplier_source_id', $future->id)->count());

            $due->refresh();
            $this->assertSame('complete', $due->last_status);
            $this->assertTrue($due->next_run_at->between(now()->addMinutes(59), now()->addMinutes(61)));
            $run = SupplierSyncRun::query()->where('supplier_source_id', $due->id)->first();
            $this->assertSame(SupplierSyncRun::TRIGGER_SCHEDULE, $run->trigger);
        });
    }

    // ─────────────────────────────────────────── önizleme

    #[Test]
    public function preview_reports_exact_expected_changes_and_writes_nothing(): void
    {
        $this->fakeFeed();
        [$tenant, , $warehouseId] = $this->makeTenant();
        $existing = $this->stockedVariant($tenant, $warehouseId, 9, ['sku' => 'EX-1', 'price' => 10, 'currency' => 'TRY']);
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([
            $this->product('EX-1', ['Stok' => '4', 'Satis' => '15.00']),
            $this->product('NEW-1'),
            $this->product('NEW-2'),
        ]);

        $movements = $this->asTenant($tenant, fn () => InventoryMovement::query()->count());
        $preview = $this->sync($tenant, $source, SupplierSyncRun::MODE_PREVIEW);

        $this->assertSame(RunStatus::COMPLETE, $preview->status);
        $this->assertSame(2, $preview->counts['created_products']);
        $this->assertSame(1, $preview->counts['matched']);
        $this->assertSame(1, $preview->counts['stock_changed']);
        $this->assertSame(1, $preview->counts['price_changed']);
        $this->assertSame($movements, $this->asTenant($tenant, fn () => InventoryMovement::query()->count()));
        $this->assertSame(1, $this->asTenant($tenant, fn () => Variant::query()->count()));
        $this->assertSame(0, $this->asTenant($tenant, fn () => SupplierRecord::query()->count()));
        $this->assertSame(0, $this->asTenant($tenant, fn () => OutboxEvent::query()->where('event_type', 'VariantPriceChanged')->count()));
        $this->assertSame(9, $this->onHand($tenant, $existing->id));
        $this->assertNull($source->refresh()->last_succeeded_at);

        // Uygulama önizlemenin söylediğini yapar.
        $apply = $this->sync($tenant, $source);
        foreach (['created_products', 'matched', 'stock_changed', 'price_changed'] as $key) {
            $this->assertSame($preview->counts[$key], $apply->counts[$key], $key);
        }
    }

    // ─────────────────────────────────────────── kota

    #[Test]
    public function quota_stops_new_products_explicitly_and_marks_run_partial(): void
    {
        $this->fakeFeed();
        [$tenant, , $warehouseId] = $this->makeTenant();
        $this->onPlan($tenant, ['products' => 3, 'channels' => 1]);
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml(array_map(fn ($i) => $this->product("QT{$i}"), range(1, 5)));

        $run = $this->sync($tenant, $source);

        $this->assertSame(RunStatus::PARTIAL, $run->status);
        $this->assertSame(SupplierReason::SUPPLIER_QUOTA_REACHED->value, $run->reason_code);
        $this->assertSame(3, $run->counts['created_products']);
        $this->assertSame(2, $run->counts['quota']);
        $this->assertSame(3, $this->asTenant($tenant, fn () => Product::query()->count()));
        $this->assertNull($source->refresh()->last_succeeded_at);
    }

    // ─────────────────────────────────────────── varyant grupları

    #[Test]
    public function flat_group_key_builds_one_product_with_variants(): void
    {
        $this->fakeFeed();
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant, ['mapping' => ['fields' => [
            'key' => 'Kod', 'sku' => 'Kod', 'group_key' => 'Ana', 'name' => 'Ad', 'stock' => 'Stok', 'price' => 'Satis',
        ], 'options' => [['name' => 'Beden', 'path' => 'Beden']]]]);
        $this->feedBody = $this->xml([
            ['Kod' => 'TS-S', 'Ana' => 'TS', 'Ad' => 'Tişört', 'Beden' => 'S', 'Stok' => '1', 'Satis' => '100'],
            ['Kod' => 'TS-M', 'Ana' => 'TS', 'Ad' => 'Tişört', 'Beden' => 'M', 'Stok' => '2', 'Satis' => '100'],
            ['Kod' => 'TS-L', 'Ana' => 'TS', 'Ad' => 'Tişört', 'Beden' => 'L', 'Stok' => '3', 'Satis' => '110'],
        ]);

        $run = $this->sync($tenant, $source);

        $this->assertSame(1, $run->counts['created_products']);
        $this->assertSame(2, $run->counts['created_variants']);
        $this->assertSame(1, $this->asTenant($tenant, fn () => Product::query()->count()));
        $this->assertSame(3, $this->asTenant($tenant, fn () => Variant::query()->count()));
        $this->assertSame(3, $this->onHand($tenant, $this->variantBySku($tenant, 'TS-L')->id));
        $this->assertSame('110.00', (string) $this->variantBySku($tenant, 'TS-L')->price);

        // Tekrar: yeni ürün/varyant yok, değişen tek varyant.
        $this->feedBody = str_replace('<Kod>TS-M</Kod><Ana>TS</Ana><Ad>Tişört</Ad><Beden>M</Beden><Stok>2</Stok>', '<Kod>TS-M</Kod><Ana>TS</Ana><Ad>Tişört</Ad><Beden>M</Beden><Stok>0</Stok>', $this->feedBody);
        $second = $this->sync($tenant, $source);
        $this->assertSame(0, $second->counts['created_products'] + $second->counts['created_variants']);
        $this->assertSame(1, $second->counts['stock_changed']);
        $this->assertSame(2, $second->counts['unchanged']);
        $this->assertSame(0, $this->onHand($tenant, $this->variantBySku($tenant, 'TS-M')->id));
    }

    #[Test]
    public function nested_variants_inherit_parent_fields(): void
    {
        $this->fakeFeed();
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant, ['mapping' => [
            'variant_path' => 'Varyantlar/Varyant',
            'fields' => [
                'key' => 'Varyantlar/Varyant/VKod', 'sku' => 'Varyantlar/Varyant/VKod', 'group_key' => 'Kod',
                'name' => 'Ad', 'stock' => 'Varyantlar/Varyant/Stok', 'price' => 'Satis', 'barcode' => 'Varyantlar/Varyant/Barkod',
            ],
            'options' => [['name' => 'Renk', 'path' => 'Varyantlar/Varyant/Renk']],
        ]]);
        $this->feedBody = '<?xml version="1.0"?><Urunler><Urun><Kod>AYK</Kod><Ad>Ayakkabı</Ad><Satis>499,90</Satis>'
            .'<Varyantlar><Varyant><VKod>AYK-SYH</VKod><Renk>Siyah</Renk><Stok>4</Stok><Barkod>111</Barkod></Varyant>'
            .'<Varyant><VKod>AYK-BYZ</VKod><Renk>Beyaz</Renk><Stok>0</Stok><Barkod>222</Barkod></Varyant></Varyantlar></Urun></Urunler>';
        $this->asTenant($tenant, fn () => SupplierSource::query()->whereKey($source->id)->update(['number_format' => 'comma']));

        $run = $this->sync($tenant, $source);

        $this->assertSame(1, $run->counts['created_products']);
        $this->assertSame(1, $run->counts['created_variants']);
        $black = $this->variantBySku($tenant, 'AYK-SYH');
        $white = $this->variantBySku($tenant, 'AYK-BYZ');
        $this->assertSame($black->product_id, $white->product_id);
        $this->assertSame('499.90', (string) $white->price);
        $this->assertSame('222', $white->barcode);
        $this->assertSame(4, $this->onHand($tenant, $black->id));
        $this->assertSame(0, $this->onHand($tenant, $white->id));
    }

    #[Test]
    public function records_without_group_key_are_never_grouped_by_title(): void
    {
        $this->fakeFeed();
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('SAME-1', ['Ad' => 'Aynı Ad']), $this->product('SAME-2', ['Ad' => 'Aynı Ad'])]);

        $this->sync($tenant, $source);

        $this->assertSame(2, $this->asTenant($tenant, fn () => Product::query()->count()));
    }

    // ─────────────────────────────────────────── kiracı ve fan-out

    #[Test]
    public function supplier_sync_never_binds_another_tenants_variant(): void
    {
        $this->fakeFeed();
        [$tenantA, , $warehouseA] = $this->makeTenant('A');
        [$tenantB] = $this->makeTenant('B');
        $foreign = $this->stockedVariant($tenantA, $warehouseA, 5, ['sku' => 'CROSS-1', 'barcode' => '999']);
        $source = $this->makeSource($tenantB, ['create_products' => false]);
        $this->feedBody = $this->xml([$this->product('CROSS-1', ['Barkod' => '999', 'Stok' => '0'])]);

        $run = $this->sync($tenantB, $source);

        $this->assertSame(1, $run->counts['unmatched']);
        $this->assertSame(5, $this->onHand($tenantA, $foreign->id));
        $this->assertNull($this->asTenant($tenantB, fn () => SupplierRecord::query()->value('variant_id')));
    }

    #[Test]
    public function supplier_stock_change_fans_out_only_through_the_outbox(): void
    {
        $this->fakeFeed();
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('FO1', ['Stok' => '5'])]);
        $this->sync($tenant, $source);

        $this->feedBody = $this->xml([$this->product('FO1', ['Stok' => '2', 'Satis' => '150'])]);
        $this->sync($tenant, $source);

        $variant = $this->variantBySku($tenant, 'FO1');
        $events = $this->asTenant($tenant, fn () => OutboxEvent::query()->where('aggregate_id', $variant->id)->pluck('event_type')->all());
        $this->assertContains('VariantPriceChanged', $events);
        // Stok olayı seviye üzerinde (aggregate = seviye); en az bir InventoryLevelChanged.
        $this->assertGreaterThan(0, $this->asTenant($tenant, fn () => OutboxEvent::query()->where('event_type', 'InventoryLevelChanged')->count()));
        // Akış dışında HİÇBİR HTTP isteği yok (kanal API'sine doğrudan çağrı yok).
        Http::assertSent(fn ($request) => str_contains($request->url(), 'feed.example.com'));
        Http::assertNotSent(fn ($request) => ! str_contains($request->url(), 'feed.example.com'));
    }
}
