<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use App\Domain\Catalog\Actions\SetVariantPrice;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLine;
use App\Domain\Suppliers\Enums\RunStatus;
use App\Domain\Suppliers\Enums\SupplierReason;
use App\Domain\Suppliers\Models\SupplierRecord;
use App\Domain\Suppliers\Models\SupplierRunChange;
use App\Domain\Suppliers\Models\SupplierSource;
use App\Domain\Suppliers\Models\SupplierSyncRun;
use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * S5 · Tedarikçi XML turu — çekirdek anlamlar (ADR-012).
 *
 * BİLİNMEYEN ≠ SIFIR · YOKLUK ≠ SIFIR · aynı tedarikçi değişimi defterde
 * TEK hareket · belirsiz eşleşmede yazma yok · önizleme hiçbir şey yazmaz.
 */
final class SupplierSyncTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use BuildsSupplierScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeFeed();
    }

    // ─────────────────────────────────────────── yeni ürün + 500 kayıt

    #[Test]
    public function five_hundred_records_create_five_hundred_products_with_opening_stock(): void
    {
        [$tenant] = $this->makeTenant();
        $this->onPlan($tenant, ['products' => null, 'channels' => null]);
        $source = $this->makeSource($tenant);

        $products = [];

        for ($i = 1; $i <= 500; $i++) {
            $products[] = $this->product(sprintf('TD-%04d', $i), ['Stok' => (string) ($i % 7)]);
        }

        $this->feedBody = $this->xml($products);

        $run = $this->sync($tenant, $source);

        $this->assertSame(RunStatus::COMPLETE, $run->status);
        $this->assertSame(500, $run->records_seen);
        $this->assertSame(500, $run->counts['created_products']);
        $this->assertSame(0, $run->counts['errors']);
        $this->assertSame(500, $this->asTenant($tenant, fn () => Product::query()->count()));
        $this->assertSame(3, $this->onHand($tenant, $this->variantBySku($tenant, 'TD-0003')->id));
        $this->assertSame(500, $this->asTenant($tenant, fn () => SupplierRecord::query()->where('match_status', 'bound')->count()));
        $this->assertNotNull($source->refresh()->last_succeeded_at);
        $this->assertLedgerMatchesProjectionForTenant($tenant->id);
    }

    #[Test]
    public function repeating_the_identical_feed_changes_nothing(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('A1'), $this->product('A2', ['Stok' => '0'])]);

        $this->sync($tenant, $source);
        $movements = $this->asTenant($tenant, fn () => InventoryMovement::query()->count());
        $outbox = $this->asTenant($tenant, fn () => OutboxEvent::query()->count());

        $second = $this->sync($tenant, $source);

        $this->assertSame(RunStatus::COMPLETE, $second->status);
        $this->assertSame(2, $second->counts['unchanged']);
        $this->assertSame(0, $second->counts['created_products']);
        $this->assertSame(0, $second->counts['stock_changed']);
        $this->assertSame(0, $second->counts['price_changed']);
        $this->assertSame($movements, $this->asTenant($tenant, fn () => InventoryMovement::query()->count()));
        $this->assertSame($outbox, $this->asTenant($tenant, fn () => OutboxEvent::query()->count()));
        $this->assertSame(0, $this->asTenant($tenant, fn () => SupplierRunChange::query()->where('supplier_sync_run_id', $second->id)->count()));
    }

    // ─────────────────────────────────────────── stok

    #[Test]
    public function stock_ten_to_seven_writes_exactly_minus_three_once(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('S1', ['Stok' => '10'])]);
        $this->sync($tenant, $source);
        $variant = $this->variantBySku($tenant, 'S1');

        $this->feedBody = $this->xml([$this->product('S1', ['Stok' => '7'])]);
        $run = $this->sync($tenant, $source);
        $this->sync($tenant, $source);
        $this->sync($tenant, $source);

        $this->assertSame(7, $this->onHand($tenant, $variant->id));
        $this->assertSame(1, $run->counts['stock_changed']);
        $supplierMoves = $this->asTenant($tenant, fn () => InventoryMovement::query()
            ->where('variant_id', $variant->id)->where('source_type', 'supplier_sync')->get());
        $this->assertCount(1, $supplierMoves);
        $this->assertSame(-3, $supplierMoves->first()->on_hand_delta);
        $this->assertLedgerMatchesProjectionForTenant($tenant->id);
    }

    #[Test]
    public function stock_seven_to_explicit_zero_writes_minus_seven(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('Z1', ['Stok' => '7'])]);
        $this->sync($tenant, $source);

        $this->feedBody = $this->xml([$this->product('Z1', ['Stok' => '0'])]);
        $run = $this->sync($tenant, $source);

        $this->assertSame(0, $this->onHand($tenant, $this->variantBySku($tenant, 'Z1')->id));
        $this->assertSame(1, $run->counts['stock_changed']);
        $this->assertSame('zero', $this->record($tenant, 'Z1')->stock_state);
    }

    #[Test]
    public function stock_increase_is_applied(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('U1', ['Stok' => '3'])]);
        $this->sync($tenant, $source);

        $this->feedBody = $this->xml([$this->product('U1', ['Stok' => '12'])]);
        $this->sync($tenant, $source);

        $this->assertSame(12, $this->onHand($tenant, $this->variantBySku($tenant, 'U1')->id));
    }

    #[Test]
    public function missing_stock_field_is_not_zero(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('M1', ['Stok' => '9'])]);
        $this->sync($tenant, $source);

        $this->feedBody = $this->xml([$this->product('M1', ['Stok' => null])]);
        $run = $this->sync($tenant, $source);

        $this->assertSame(9, $this->onHand($tenant, $this->variantBySku($tenant, 'M1')->id));
        $this->assertSame('missing', $this->record($tenant, 'M1')->stock_state);
        $this->assertNull($this->record($tenant, 'M1')->stock_value);
        $this->assertTrue($this->hasChange($tenant, $run, 'skipped', SupplierReason::SUPPLIER_STOCK_MISSING));
    }

    #[Test]
    public function empty_stock_element_is_missing_not_zero(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('E1', ['Stok' => '4'])]);
        $this->sync($tenant, $source);

        $this->feedBody = $this->xml([$this->product('E1', ['Stok' => ''])]);
        $this->sync($tenant, $source);

        $this->assertSame(4, $this->onHand($tenant, $this->variantBySku($tenant, 'E1')->id));
        $this->assertSame('missing', $this->record($tenant, 'E1')->stock_state);
    }

    #[Test]
    public function malformed_stock_changes_nothing(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('B1', ['Stok' => '5'])]);
        $this->sync($tenant, $source);

        foreach (['Var', '-2', '3.5'] as $bad) {
            $this->feedBody = $this->xml([$this->product('B1', ['Stok' => $bad])]);
            $run = $this->sync($tenant, $source);

            $this->assertSame(5, $this->onHand($tenant, $this->variantBySku($tenant, 'B1')->id), $bad);
            $this->assertSame('invalid', $this->record($tenant, 'B1')->stock_state, $bad);
            $this->assertTrue($this->hasChange($tenant, $run, 'skipped', SupplierReason::SUPPLIER_STOCK_INVALID), $bad);
        }
    }

    #[Test]
    public function informational_policy_never_touches_the_ledger(): void
    {
        [$tenant, , $warehouseId] = $this->makeTenant();
        $variant = $this->stockedVariant($tenant, $warehouseId, 20, ['sku' => 'OWN-1']);
        $source = $this->makeSource($tenant, ['stock_policy' => SupplierSource::STOCK_INFORMATIONAL]);

        $this->feedBody = $this->xml([$this->product('OWN-1', ['Stok' => '0'])]);
        $run = $this->sync($tenant, $source);

        $this->assertSame(20, $this->onHand($tenant, $variant->id));
        $this->assertSame(0, $run->counts['stock_changed']);
        $this->assertSame(0, $this->record($tenant, 'OWN-1')->stock_value);
        $this->assertSame(0, $this->asTenant($tenant, fn () => InventoryMovement::query()->where('source_type', 'supplier_sync')->count()));
    }

    #[Test]
    public function new_product_under_informational_policy_opens_with_zero_stock(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant, ['stock_policy' => SupplierSource::STOCK_INFORMATIONAL]);
        $this->feedBody = $this->xml([$this->product('N0', ['Stok' => '40'])]);

        $this->sync($tenant, $source);

        $this->assertSame(0, $this->onHand($tenant, $this->variantBySku($tenant, 'N0')->id));
    }

    #[Test]
    public function our_sale_is_not_added_back_while_supplier_repeats_the_same_value(): void
    {
        [$tenant, , $warehouseId] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('D1', ['Stok' => '10'])]);
        $this->sync($tenant, $source);
        $variant = $this->variantBySku($tenant, 'D1');

        // Bizim satışımız (defterden): 10 → 9.
        $this->sellOne($tenant, $warehouseId, $variant->id);

        $this->sync($tenant, $source);
        $this->assertSame(9, $this->onHand($tenant, $variant->id));

        // Tedarikçi siparişimizi işledi: 10 → 9 → bizde de 9, çift düşüş yok.
        $this->feedBody = $this->xml([$this->product('D1', ['Stok' => '9'])]);
        $this->sync($tenant, $source);
        $this->assertSame(9, $this->onHand($tenant, $variant->id));
    }

    // ─────────────────────────────────────────── yokluk

    #[Test]
    public function record_missing_from_next_feed_keeps_stock(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('G1', ['Stok' => '6']), $this->product('G2', ['Stok' => '8'])]);
        $this->sync($tenant, $source);

        $this->feedBody = $this->xml([$this->product('G1', ['Stok' => '6'])]);
        $run = $this->sync($tenant, $source);

        $this->assertSame(RunStatus::COMPLETE, $run->status);
        $this->assertSame(1, $run->counts['missing']);
        $this->assertSame(8, $this->onHand($tenant, $this->variantBySku($tenant, 'G2')->id));
        $this->assertSame('missing', $this->record($tenant, 'G2')->presence);
        $this->assertSame(SupplierReason::SUPPLIER_RECORD_MISSING->value, $this->record($tenant, 'G2')->reason_code);

        // Geri gelince yeniden "present".
        $this->feedBody = $this->xml([$this->product('G1', ['Stok' => '6']), $this->product('G2', ['Stok' => '8'])]);
        $this->sync($tenant, $source);
        $this->assertSame('present', $this->record($tenant, 'G2')->presence);
    }

    // ─────────────────────────────────────────── fiyat / maliyet

    #[Test]
    public function price_change_goes_through_set_variant_price_once(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('P1', ['Satis' => '100.00'])]);
        $this->sync($tenant, $source);

        $this->feedBody = $this->xml([$this->product('P1', ['Satis' => '119.90'])]);
        $run = $this->sync($tenant, $source);
        $again = $this->sync($tenant, $source);

        $variant = $this->variantBySku($tenant, 'P1');
        $this->assertSame('119.90', (string) $variant->price);
        $this->assertSame(1, $run->counts['price_changed']);
        $this->assertSame(0, $again->counts['price_changed']);
        $this->assertSame(1, $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'VariantPriceChanged')->where('aggregate_id', $variant->id)->count()));
    }

    #[Test]
    public function manual_price_edit_is_not_overwritten_while_supplier_input_is_unchanged(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('P2', ['Satis' => '100.00'])]);
        $this->sync($tenant, $source);
        $variant = $this->variantBySku($tenant, 'P2');
        $this->asTenant($tenant, fn () => app(SetVariantPrice::class)->run($variant, 89.90));

        $this->sync($tenant, $source);
        $this->assertSame('89.90', (string) $this->variantBySku($tenant, 'P2')->price);

        $this->feedBody = $this->xml([$this->product('P2', ['Satis' => '105.00'])]);
        $this->sync($tenant, $source);
        $this->assertSame('105.00', (string) $this->variantBySku($tenant, 'P2')->price);
    }

    #[Test]
    public function zero_missing_and_invalid_price_are_distinct_and_write_nothing(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('Q1', ['Satis' => '100.00'])]);
        $this->sync($tenant, $source);

        $cases = [
            ['0', 'zero', SupplierReason::SUPPLIER_PRICE_ZERO],
            [null, 'missing', SupplierReason::SUPPLIER_PRICE_MISSING],
            ['yüz lira', 'invalid', SupplierReason::SUPPLIER_PRICE_INVALID],
            ['-5', 'invalid', SupplierReason::SUPPLIER_PRICE_INVALID],
        ];

        foreach ($cases as [$value, $state, $reason]) {
            $this->feedBody = $this->xml([$this->product('Q1', ['Satis' => $value])]);
            $run = $this->sync($tenant, $source);

            $this->assertSame('100.00', (string) $this->variantBySku($tenant, 'Q1')->price, (string) $value);
            $this->assertSame($state, $this->record($tenant, 'Q1')->price_state, (string) $value);
            $this->assertTrue($this->hasChange($tenant, $run, 'skipped', $reason), (string) $value);
        }
    }

    #[Test]
    public function cost_markup_is_markup_on_cost_with_rounding(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant, [
            'price_policy' => SupplierSource::PRICE_COST_MARKUP,
            'markup_percent' => '25',
            'markup_amount' => '0',
            'rounding' => 'x90',
        ]);
        $this->feedBody = $this->xml([$this->product('K1', ['Alis' => '100.00', 'Satis' => null])]);

        $this->sync($tenant, $source);

        $variant = $this->variantBySku($tenant, 'K1');
        // MARKUP: 100 × 1,25 = 125,00 → x90 yukarı = 125,90. MARGIN olsaydı 133,34.
        $this->assertSame('125.90', (string) $variant->price);
        $this->assertSame('100.00', (string) $variant->cost_price);
    }

    #[Test]
    public function missing_or_zero_cost_does_not_fabricate_a_price(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant, ['price_policy' => SupplierSource::PRICE_COST_MARKUP, 'markup_percent' => '20']);
        $this->feedBody = $this->xml([$this->product('C1', ['Alis' => '100.00'])]);
        $this->sync($tenant, $source);

        foreach ([['0', SupplierReason::SUPPLIER_COST_ZERO], [null, SupplierReason::SUPPLIER_COST_MISSING], ['abc', SupplierReason::SUPPLIER_COST_INVALID]] as [$cost, $reason]) {
            $this->feedBody = $this->xml([$this->product('C1', ['Alis' => $cost])]);
            $run = $this->sync($tenant, $source);

            $variant = $this->variantBySku($tenant, 'C1');
            $this->assertSame('120.00', (string) $variant->price, (string) $cost);
            $this->assertSame('100.00', (string) $variant->cost_price, (string) $cost);
            $this->assertTrue($this->hasChange($tenant, $run, 'skipped', $reason), (string) $cost);
        }

        // Yeni ürün de geçerli maliyet olmadan AÇILMAZ.
        $this->feedBody = $this->xml([$this->product('C2', ['Alis' => null])]);
        $run = $this->sync($tenant, $source);
        $this->assertNull($this->variantBySku($tenant, 'C2'));
        $this->assertTrue($this->hasChange($tenant, $run, 'unmatched', SupplierReason::SUPPLIER_COST_MISSING));
    }

    #[Test]
    public function currency_mismatch_blocks_price_and_cost_but_not_stock(): void
    {
        [$tenant, , $warehouseId] = $this->makeTenant();
        $variant = $this->stockedVariant($tenant, $warehouseId, 2, ['sku' => 'EUR-1', 'currency' => 'EUR', 'price' => 10]);
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('EUR-1', ['Stok' => '5', 'Satis' => '300.00'])]);

        $run = $this->sync($tenant, $source);

        $variant->refresh();
        $this->assertSame('10.00', (string) $variant->price);
        $this->assertNull($variant->cost_price);
        $this->assertSame(5, $this->onHand($tenant, $variant->id));
        $this->assertTrue($this->hasChange($tenant, $run, 'skipped', SupplierReason::SUPPLIER_CURRENCY_MISMATCH));
    }

    #[Test]
    public function comma_number_format_is_explicit(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant, ['number_format' => SupplierSource::NUMBER_COMMA]);
        $this->feedBody = $this->xml([$this->product('TR1', ['Satis' => '1.299,90 TL', 'Alis' => '1.000,00', 'Stok' => '1.200'])]);

        $this->sync($tenant, $source);

        $variant = $this->variantBySku($tenant, 'TR1');
        $this->assertSame('1299.90', (string) $variant->price);
        $this->assertSame('1000.00', (string) $variant->cost_price);
        $this->assertSame(1200, $this->onHand($tenant, $variant->id));
    }

    // ─────────────────────────────────────────── kimlik ve eşleşme

    #[Test]
    public function missing_identity_is_reported_not_imported(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('OK1'), $this->product('', ['Ad' => 'Kimliksiz'])]);

        $run = $this->sync($tenant, $source);

        $this->assertSame(RunStatus::COMPLETE, $run->status);
        $this->assertSame(1, $run->counts['invalid']);
        $this->assertSame(1, $run->counts['created_products']);
        $this->assertTrue($this->hasChange($tenant, $run, 'invalid', SupplierReason::SUPPLIER_IDENTITY_MISSING));
    }

    #[Test]
    public function duplicate_supplier_identity_applies_neither_record(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('DUP', ['Stok' => '1']), $this->product('DUP', ['Stok' => '50']), $this->product('UNI')]);

        $run = $this->sync($tenant, $source);

        $this->assertNull($this->variantBySku($tenant, 'DUP'));
        // Kimlik başına bir "tekrar" — iki öğe de uygulanmadı.
        $this->assertSame(1, $run->counts['duplicate']);
        $this->assertSame(1, $this->asTenant($tenant, fn () => SupplierRunChange::query()
            ->where('supplier_sync_run_id', $run->id)->where('kind', 'duplicate')->count()));
        $this->assertSame(1, $run->counts['created_products']);
    }

    #[Test]
    public function duplicate_identity_on_a_bound_record_does_not_move_stock(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('DB1', ['Stok' => '5'])]);
        $this->sync($tenant, $source);

        $this->feedBody = $this->xml([$this->product('DB1', ['Stok' => '1']), $this->product('DB1', ['Stok' => '2'])]);
        $this->sync($tenant, $source);

        $this->assertSame(5, $this->onHand($tenant, $this->variantBySku($tenant, 'DB1')->id));
        $this->assertSame('duplicate', $this->record($tenant, 'DB1')->match_status);
    }

    #[Test]
    public function two_supplier_records_with_the_same_sku_do_not_share_a_variant(): void
    {
        [$tenant, , $warehouseId] = $this->makeTenant();
        $this->stockedVariant($tenant, $warehouseId, 3, ['sku' => 'SHARED']);
        $source = $this->makeSource($tenant, ['mapping' => ['fields' => ['key' => 'Id', 'sku' => 'Kod', 'stock' => 'Stok', 'price' => 'Satis', 'name' => 'Ad']]]);
        $this->feedBody = $this->xml([
            ['Id' => '1', 'Kod' => 'SHARED', 'Ad' => 'A', 'Stok' => '7', 'Satis' => '10'],
            ['Id' => '2', 'Kod' => 'SHARED', 'Ad' => 'B', 'Stok' => '99', 'Satis' => '10'],
        ]);

        $run = $this->sync($tenant, $source);

        $this->assertSame(1, $run->counts['matched']);
        $this->assertSame(1, $run->counts['conflict']);
        $this->assertSame(7, $this->onHand($tenant, $this->variantBySku($tenant, 'SHARED')->id));
        $this->assertTrue($this->hasChange($tenant, $run, 'conflict', SupplierReason::SUPPLIER_VARIANT_ALREADY_BOUND));
    }

    #[Test]
    public function sku_and_barcode_pointing_to_different_variants_is_ambiguous(): void
    {
        [$tenant, , $warehouseId] = $this->makeTenant();
        $bySku = $this->stockedVariant($tenant, $warehouseId, 4, ['sku' => 'AMB-SKU', 'barcode' => '111']);
        $byBarcode = $this->stockedVariant($tenant, $warehouseId, 6, ['sku' => 'OTHER', 'barcode' => '869000']);
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('AMB-SKU', ['Barkod' => '869000', 'Stok' => '0', 'Satis' => '1.00'])]);

        $run = $this->sync($tenant, $source);

        $this->assertSame(1, $run->counts['ambiguous']);
        $this->assertSame(4, $this->onHand($tenant, $bySku->id));
        $this->assertSame(6, $this->onHand($tenant, $byBarcode->id));
        $this->assertSame(0, $run->counts['created_products']);
        $this->assertSame('ambiguous', $this->record($tenant, 'AMB-SKU')->match_status);
        $this->assertNull($this->record($tenant, 'AMB-SKU')->variant_id);
    }

    #[Test]
    public function barcode_match_binds_an_existing_variant(): void
    {
        [$tenant, , $warehouseId] = $this->makeTenant();
        $variant = $this->stockedVariant($tenant, $warehouseId, 4, ['sku' => 'MY-OWN', 'barcode' => '8690001']);
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('SUP-77', ['Barkod' => '8690001', 'Stok' => '11'])]);

        $run = $this->sync($tenant, $source);

        $this->assertSame(1, $run->counts['matched']);
        $this->assertSame(0, $run->counts['created_products']);
        $this->assertSame($variant->id, $this->record($tenant, 'SUP-77')->variant_id);
        $this->assertSame(11, $this->onHand($tenant, $variant->id));
    }

    #[Test]
    public function an_existing_binding_wins_over_later_sku_changes(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant, ['mapping' => ['fields' => ['key' => 'Id', 'sku' => 'Kod', 'stock' => 'Stok', 'price' => 'Satis', 'name' => 'Ad']]]);
        $this->feedBody = $this->xml([['Id' => '9', 'Kod' => 'OLD', 'Ad' => 'X', 'Stok' => '2', 'Satis' => '10']]);
        $this->sync($tenant, $source);
        $variant = $this->variantBySku($tenant, 'OLD');

        // Tedarikçi SKU'yu değiştirdi; kimlik (Id) aynı → aynı bağ, yeni ürün YOK.
        $this->feedBody = $this->xml([['Id' => '9', 'Kod' => 'NEW', 'Ad' => 'X', 'Stok' => '3', 'Satis' => '10']]);
        $run = $this->sync($tenant, $source);

        $this->assertSame(0, $run->counts['created_products']);
        $this->assertSame(3, $this->onHand($tenant, $variant->id));
        $this->assertSame(1, $this->asTenant($tenant, fn () => Variant::query()->count()));
    }

    #[Test]
    public function product_sku_conflict_is_reported_not_guessed(): void
    {
        [$tenant] = $this->makeTenant();
        $this->asTenant($tenant, function (): void {
            $product = Product::factory()->create(['sku' => 'GROUP-SKU']);
            Variant::factory()->create(['product_id' => $product->id, 'sku' => 'GROUP-SKU-RED']);
        });
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('GROUP-SKU')]);

        $run = $this->sync($tenant, $source);

        $this->assertSame(1, $run->counts['conflict']);
        $this->assertTrue($this->hasChange($tenant, $run, 'conflict', SupplierReason::SUPPLIER_SKU_CONFLICT));
    }

    #[Test]
    public function new_product_uses_canonical_creation_with_images(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('IMG1', ['Resim' => 'https://cdn.example.com/a.jpg', 'Barkod' => '8691234'])]);

        $this->sync($tenant, $source);

        $variant = $this->variantBySku($tenant, 'IMG1');
        $this->assertSame('8691234', $variant->barcode);
        $this->assertSame('50.00', (string) $variant->cost_price);
        $this->assertSame(['https://cdn.example.com/a.jpg'], $this->asTenant($tenant, fn () => ProductImage::query()->pluck('storage_path')->all()));
    }

    #[Test]
    public function http_image_urls_are_not_imported(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('IMG2', ['Resim' => 'http://10.0.0.5/a.jpg'])]);

        $this->sync($tenant, $source);

        $this->assertSame(0, $this->asTenant($tenant, fn () => ProductImage::query()->count()));
    }

    #[Test]
    public function create_products_off_reports_unmatched(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant, ['create_products' => false]);
        $this->feedBody = $this->xml([$this->product('NOPE')]);

        $run = $this->sync($tenant, $source);

        $this->assertSame(1, $run->counts['unmatched']);
        $this->assertNull($this->variantBySku($tenant, 'NOPE'));
        $this->assertSame(RunStatus::COMPLETE, $run->status);
    }

    // ─────────────────────────────────────────── maliyet geçmişi

    #[Test]
    public function supplier_cost_change_does_not_rewrite_historical_order_line_cost(): void
    {
        [$tenant] = $this->makeTenant();
        $source = $this->makeSource($tenant);
        $this->feedBody = $this->xml([$this->product('H1', ['Alis' => '40.00'])]);
        $this->sync($tenant, $source);
        $variant = $this->variantBySku($tenant, 'H1');

        $lineId = $this->orderLineWithCost($tenant, $variant, '40.00');

        $this->feedBody = $this->xml([$this->product('H1', ['Alis' => '55.00'])]);
        $this->sync($tenant, $source);

        $this->assertSame('55.00', (string) $this->variantBySku($tenant, 'H1')->cost_price);
        $this->assertSame('40.0000', (string) $this->asTenant($tenant, fn () => OrderLine::query()->whereKey($lineId)->value('unit_cost')));
    }

    // ─────────────────────────────────────────── yardımcılar

    private function record($tenant, string $key): SupplierRecord
    {
        return $this->asTenant($tenant, fn () => SupplierRecord::query()->where('source_key', $key)->firstOrFail());
    }

    private function hasChange($tenant, SupplierSyncRun $run, string $kind, SupplierReason $reason): bool
    {
        return $this->asTenant($tenant, fn () => SupplierRunChange::query()
            ->where('supplier_sync_run_id', $run->id)
            ->where('kind', $kind)
            ->where('reason_code', $reason->value)
            ->exists());
    }

    private function sellOne($tenant, string $warehouseId, string $variantId): void
    {
        $this->asTenant($tenant, fn () => DB::transaction(function () use ($warehouseId, $variantId): void {
            (new LockInventoryRows)->run($warehouseId, [$variantId]);
            (new ApplyMovement)->run(
                warehouseId: $warehouseId,
                variantId: $variantId,
                type: MovementType::SALE,
                quantity: 1,
                idempotencyKey: 'sale:test-'.uniqid(),
                sourceType: 'order_line',
            );
        }));
    }

    private function orderLineWithCost($tenant, Variant $variant, string $cost): string
    {
        $this->seed(ChannelTypeSeeder::class);

        return $this->asTenant($tenant, function () use ($variant, $cost): string {
            $connection = ChannelConnection::factory()->create();
            $order = Order::query()->create([
                'channel_connection_id' => $connection->id,
                'external_id' => 'ORD-'.uniqid(),
                'status' => 'Created',
                'currency' => 'TRY',
                'grand_total' => '100.00',
                'placed_at' => now(),
            ]);

            return OrderLine::query()->create([
                'order_id' => $order->id,
                'variant_id' => $variant->id,
                'external_line_id' => 'L-'.uniqid(),
                'title' => 'Ürün',
                'sku' => $variant->sku,
                'quantity' => 1,
                'unit_price' => '100.00',
                'unit_cost' => $cost,
                'cost_currency' => 'TRY',
            ])->id;
        });
    }
}
