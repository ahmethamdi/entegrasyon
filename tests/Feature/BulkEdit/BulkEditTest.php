<?php

declare(strict_types=1);

namespace Tests\Feature\BulkEdit;

use App\Domain\BulkEdit\Enums\BulkEditReason;
use App\Domain\BulkEdit\Jobs\ApplyBulkEditJob;
use App\Domain\BulkEdit\Models\BulkEditImport;
use App\Domain\BulkEdit\Models\BulkEditRow;
use App\Domain\BulkEdit\Support\BulkEditSheet;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Models\Listing;
use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\Feature\Suppliers\BuildsSupplierScenario;
use Tests\TestCase;

/**
 * S5 · Excel toplu fiyat/stok — iki aşama (önizleme → açık uygulama),
 * SET anlamı, satır hataları, kiracı yalıtımı, tek seferlik uygulama.
 */
final class BulkEditTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use BuildsSupplierScenario;
    use RefreshDatabase;

    // ─────────────────────────────────────────── dışa aktarma

    #[Test]
    public function export_lists_only_own_variants_with_current_stock_and_price(): void
    {
        [$tenantA, $userA, $warehouseA] = $this->makeTenant('A');
        [$tenantB, , $warehouseB] = $this->makeTenant('B');
        $mine = $this->stockedVariant($tenantA, $warehouseA, 7, ['sku' => 'MINE-1', 'price' => 19.9]);
        $this->stockedVariant($tenantB, $warehouseB, 3, ['sku' => 'THEIRS-1']);

        $response = $this->actingAs($userA)->get('/products/bulk-edit/export');
        $response->assertOk();
        $rows = $this->readXlsx($response->baseResponse->getFile()->getPathname());

        $this->assertSame(BulkEditSheet::HEADERS, $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame($mine->id, $rows[1][0]);
        $this->assertSame('MINE-1', $rows[1][1]);
        $this->assertEquals(7, $rows[1][5]);
        $this->assertEquals(19.9, $rows[1][7]);
    }

    #[Test]
    public function export_neutralises_formula_like_text(): void
    {
        [$tenant, $user, $warehouse] = $this->makeTenant();
        $this->stockedVariant($tenant, $warehouse, 1, ['sku' => '=HYPERLINK("x")']);

        $response = $this->actingAs($user)->get('/products/bulk-edit/export');
        $rows = $this->readXlsx($response->baseResponse->getFile()->getPathname());

        $this->assertSame("'=HYPERLINK(\"x\")", $rows[1][1]);
    }

    // ─────────────────────────────────────────── önizleme

    #[Test]
    public function upload_only_previews_and_changes_nothing(): void
    {
        Queue::fake();
        [$tenant, $user, $warehouse] = $this->makeTenant();
        $variant = $this->stockedVariant($tenant, $warehouse, 7, ['sku' => 'P-1', 'price' => 100]);
        $movements = $this->asTenant($tenant, fn () => InventoryMovement::query()->count());

        $import = $this->upload($user, [$this->row($variant, 7, 5, '100', '120')]);

        $this->assertSame(BulkEditImport::PARSED, $import->status);
        $this->assertSame(1, $import->counts['stock_changes']);
        $this->assertSame(1, $import->counts['price_changes']);
        $this->assertSame(7, $this->onHand($tenant, $variant->id));
        $this->assertSame('100.00', (string) $variant->refresh()->price);
        $this->assertSame($movements, $this->asTenant($tenant, fn () => InventoryMovement::query()->count()));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function apply_sets_stock_seven_to_five_with_exactly_minus_two_once(): void
    {
        [$tenant, $user, $warehouse] = $this->makeTenant();
        $variant = $this->stockedVariant($tenant, $warehouse, 7, ['sku' => 'S-1']);
        $import = $this->upload($user, [$this->row($variant, 7, 5)]);

        $this->actingAs($user)->post("/products/bulk-edit/{$import->id}/apply")->assertSessionHasNoErrors();

        $this->assertSame(5, $this->onHand($tenant, $variant->id));
        $moves = $this->asTenant($tenant, fn () => InventoryMovement::query()->where('source_type', 'bulk_edit')->get());
        $this->assertCount(1, $moves);
        $this->assertSame(-2, $moves->first()->on_hand_delta);
        $this->assertSame(BulkEditImport::APPLIED, $import->refresh()->status);
        $this->assertSame(1, $import->counts['applied']);

        // Aynı yüklemeyi ikinci kez uygulamak reddedilir; hareket tek.
        $this->actingAs($user)->post("/products/bulk-edit/{$import->id}/apply")->assertSessionHasErrors('apply');
        $this->assertSame(1, $this->asTenant($tenant, fn () => InventoryMovement::query()->where('source_type', 'bulk_edit')->count()));
        $this->assertLedgerMatchesProjectionForTenant($tenant->id);
    }

    #[Test]
    public function a_retried_apply_job_does_not_duplicate_stock_movement(): void
    {
        [$tenant, $user, $warehouse] = $this->makeTenant();
        $variant = $this->stockedVariant($tenant, $warehouse, 10, ['sku' => 'R-1']);
        $import = $this->upload($user, [$this->row($variant, 10, 4)]);
        $this->actingAs($user)->post("/products/bulk-edit/{$import->id}/apply");

        // Kuyruk işi yeniden teslim edilirse (durum hâlâ applying sayalım).
        $this->asTenant($tenant, fn () => BulkEditImport::query()->whereKey($import->id)->update(['status' => BulkEditImport::APPLYING]));
        $this->asTenant($tenant, fn () => BulkEditRow::query()->where('bulk_edit_import_id', $import->id)->update(['status' => BulkEditRow::CHANGE]));
        (new ApplyBulkEditJob($tenant->id, $import->id))->handle();

        $this->assertSame(4, $this->onHand($tenant, $variant->id));
        $this->assertSame(1, $this->asTenant($tenant, fn () => InventoryMovement::query()->where('source_type', 'bulk_edit')->count()));
    }

    #[Test]
    public function stock_zero_is_a_valid_set_value(): void
    {
        [$tenant, $user, $warehouse] = $this->makeTenant();
        $variant = $this->stockedVariant($tenant, $warehouse, 3, ['sku' => 'Z-1']);
        $import = $this->upload($user, [$this->row($variant, 3, 0)]);
        $this->actingAs($user)->post("/products/bulk-edit/{$import->id}/apply");

        $this->assertSame(0, $this->onHand($tenant, $variant->id));
    }

    #[Test]
    public function price_change_goes_through_canonical_price_and_outbox_only(): void
    {
        Http::fake();
        [$tenant, $user, $warehouse] = $this->makeTenant();
        $variant = $this->stockedVariant($tenant, $warehouse, 1, ['sku' => 'PR-1', 'price' => 100]);
        $import = $this->upload($user, [$this->row($variant, 1, null, '100', '149,90')]);
        $this->actingAs($user)->post("/products/bulk-edit/{$import->id}/apply");

        $this->assertSame('149.90', (string) $variant->refresh()->price);
        $this->assertSame(1, $this->asTenant($tenant, fn () => OutboxEvent::query()->where('event_type', 'VariantPriceChanged')->where('aggregate_id', $variant->id)->count()));
        Http::assertNothingSent();
    }

    #[Test]
    public function preview_counts_only_channels_whose_write_permission_is_on(): void
    {
        $this->seed(ChannelTypeSeeder::class);
        [$tenant, $user, $warehouse] = $this->makeTenant();
        $variant = $this->stockedVariant($tenant, $warehouse, 5, ['sku' => 'CH-1', 'price' => 10]);

        $this->asTenant($tenant, function () use ($variant): void {
            $open = ChannelConnection::factory()->create(['external_account_id' => 'a.example.com']);
            $closed = ChannelConnection::factory()->create(['external_account_id' => 'b.example.com']);
            $open->forceFill(['stock_sync_enabled' => true, 'price_sync_enabled' => false])->save();
            $closed->forceFill(['stock_sync_enabled' => false, 'price_sync_enabled' => false])->save();

            foreach ([$open, $closed] as $connection) {
                Listing::factory()->create([
                    'channel_connection_id' => $connection->id,
                    'variant_id' => $variant->id,
                    'external_id' => 'X-'.$connection->id,
                    'lifecycle_status' => 'live',
                ]);
            }
        });

        $import = $this->upload($user, [$this->row($variant, 5, 4, 10, '12')]);
        $row = $this->asTenant($tenant, fn () => BulkEditRow::query()->where('bulk_edit_import_id', $import->id)->firstOrFail());

        $this->assertSame(1, $row->stock_channels, 'Yalnız stok izni açık bağlantı sayılır.');
        $this->assertSame(0, $row->price_channels, 'Fiyat izni hiçbir bağlantıda açık değil.');
    }

    // ─────────────────────────────────────────── satır hataları

    #[Test]
    public function row_errors_are_explicit_and_never_applied(): void
    {
        [$tenantA, $userA, $warehouseA] = $this->makeTenant('A');
        [$tenantB, , $warehouseB] = $this->makeTenant('B');
        $v = [];

        foreach (['OK', 'NEG', 'DEC', 'TXT', 'P0', 'P3', 'DUP', 'MIX', 'SAME'] as $sku) {
            $v[$sku] = $this->stockedVariant($tenantA, $warehouseA, 5, ['sku' => $sku, 'price' => 10]);
        }

        $foreign = $this->stockedVariant($tenantB, $warehouseB, 9, ['sku' => 'FOREIGN']);

        $rows = [
            $this->row($v['OK'], 5, 6),                                   // 2 değişir
            $this->row($v['NEG'], 5, -1),                                 // 3 negatif stok
            $this->row($v['DEC'], 5, 3.5),                                // 4 ondalık stok
            $this->row($v['TXT'], 5, 'beş'),                              // 5 metin stok
            $this->row($v['P0'], 5, null, 10, '0'),                       // 6 fiyat 0
            $this->row($v['P3'], 5, null, 10, '12.345'),                  // 7 fiyat 3 ondalık
            $this->row($v['DUP'], 5, 1),                                  // 8 tekrar
            $this->row($v['DUP'], 5, 2),                                  // 9 tekrar
            [$foreign->id, 'FOREIGN', '', '', '', 9, 0, 10, '', 'TRY'],   // 10 başka hesabın kimliği
            [$v['OK']->id, 'MIX', '', '', '', 5, 1, 10, '', 'TRY'],       // 11 ID/SKU uyuşmuyor
            ['', '', '', '', '', 5, 1, 10, '', 'TRY'],                    // 12 kimlik yok
            ['', 'NOPE', '', '', '', 5, 1, 10, '', 'TRY'],                // 13 bilinmeyen SKU
            $this->row($v['SAME'], 5, 5),                                 // 14 değişmez
            ['', 'MIX', '', '', '', 5, 'x', 10, '', 'TRY'],                // 15 MIX geçersiz stok (yalnız SKU)
            ['', 'MIX', '', '', '', 5, 2, 10, '', 'TRY'],                  // 16 MIX geçerli ama aynı varyant 15'te de var
        ];

        $import = $this->upload($userA, $rows);
        $byRow = $this->asTenant($tenantA, fn () => BulkEditRow::query()->where('bulk_edit_import_id', $import->id)->get()->keyBy('row_number'));

        $expect = [
            3 => [BulkEditRow::INVALID, BulkEditReason::BULK_STOCK_INVALID],
            4 => [BulkEditRow::INVALID, BulkEditReason::BULK_STOCK_INVALID],
            5 => [BulkEditRow::INVALID, BulkEditReason::BULK_STOCK_INVALID],
            6 => [BulkEditRow::INVALID, BulkEditReason::BULK_PRICE_INVALID],
            7 => [BulkEditRow::INVALID, BulkEditReason::BULK_PRICE_INVALID],
            8 => [BulkEditRow::CONFLICT, BulkEditReason::BULK_DUPLICATE_ROW],
            9 => [BulkEditRow::CONFLICT, BulkEditReason::BULK_DUPLICATE_ROW],
            10 => [BulkEditRow::INVALID, BulkEditReason::BULK_VARIANT_UNKNOWN],
            11 => [BulkEditRow::INVALID, BulkEditReason::BULK_ID_SKU_MISMATCH],
            12 => [BulkEditRow::INVALID, BulkEditReason::BULK_IDENTITY_MISSING],
            13 => [BulkEditRow::INVALID, BulkEditReason::BULK_VARIANT_UNKNOWN],
            15 => [BulkEditRow::INVALID, BulkEditReason::BULK_STOCK_INVALID],
            16 => [BulkEditRow::CONFLICT, BulkEditReason::BULK_DUPLICATE_ROW],
        ];

        foreach ($expect as $row => [$status, $reason]) {
            $this->assertSame($status, $byRow[$row]->status, "satır {$row}");
            $this->assertSame($reason->value, $byRow[$row]->reason_code, "satır {$row}");
        }

        $this->assertSame(BulkEditRow::CHANGE, $byRow[2]->status);
        $this->assertSame(BulkEditRow::UNCHANGED, $byRow[14]->status);
        $this->assertSame(1, $import->counts['change']);

        $this->actingAs($userA)->post("/products/bulk-edit/{$import->id}/apply");

        $this->assertSame(6, $this->onHand($tenantA, $v['OK']->id));

        foreach (['NEG', 'DEC', 'TXT', 'P0', 'P3', 'DUP', 'MIX', 'SAME'] as $sku) {
            $this->assertSame(5, $this->onHand($tenantA, $v[$sku]->id), $sku);
            $this->assertSame('10.00', (string) $v[$sku]->refresh()->price, $sku);
        }

        $this->assertSame(9, $this->onHand($tenantB, $foreign->id));
        $this->assertSame(1, $this->asTenant($tenantA, fn () => InventoryMovement::query()->where('source_type', 'bulk_edit')->count()));
    }

    #[Test]
    public function decimal_prices_are_accepted_in_both_cell_types(): void
    {
        [$tenant, $user, $warehouse] = $this->makeTenant();
        $a = $this->stockedVariant($tenant, $warehouse, 1, ['sku' => 'D-A', 'price' => 10]);
        $b = $this->stockedVariant($tenant, $warehouse, 1, ['sku' => 'D-B', 'price' => 10]);

        $import = $this->upload($user, [$this->row($a, 1, null, 10, 19.9), $this->row($b, 1, null, 10, '24,50')]);
        $this->actingAs($user)->post("/products/bulk-edit/{$import->id}/apply");

        $this->assertSame('19.90', (string) $a->refresh()->price);
        $this->assertSame('24.50', (string) $b->refresh()->price);
    }

    #[Test]
    public function formula_cells_are_rejected(): void
    {
        [$tenant, $user, $warehouse] = $this->makeTenant();
        $variant = $this->stockedVariant($tenant, $warehouse, 4, ['sku' => 'F-1']);
        $row = $this->row($variant, 4, null);
        $row[6] = new Cell\FormulaCell('=F2+1', null, 5);

        $import = $this->upload($user, [$row]);

        $saved = $this->asTenant($tenant, fn () => BulkEditRow::query()->where('bulk_edit_import_id', $import->id)->firstOrFail());
        $this->assertSame(BulkEditReason::BULK_FORMULA_NOT_ALLOWED->value, $saved->reason_code);
        $this->assertSame(0, $import->counts['change']);
    }

    #[Test]
    public function stock_changed_after_export_blocks_the_row(): void
    {
        [$tenant, $user, $warehouse] = $this->makeTenant();
        $variant = $this->stockedVariant($tenant, $warehouse, 9, ['sku' => 'X-1']);

        // Dosyada "mevcut 10" yazıyor ama arada satış oldu: gerçek 9.
        $import = $this->upload($user, [$this->row($variant, 10, 12)]);
        $row = $this->asTenant($tenant, fn () => BulkEditRow::query()->where('bulk_edit_import_id', $import->id)->firstOrFail());

        $this->assertSame(BulkEditRow::CONFLICT, $row->status);
        $this->assertSame(BulkEditReason::BULK_STOCK_CHANGED_SINCE_EXPORT->value, $row->reason_code);
    }

    #[Test]
    public function sale_between_preview_and_apply_blocks_the_row(): void
    {
        [$tenant, $user, $warehouse] = $this->makeTenant();
        $variant = $this->stockedVariant($tenant, $warehouse, 7, ['sku' => 'SP-1']);
        $import = $this->upload($user, [$this->row($variant, 7, 5)]);

        $this->sellOne($tenant, $warehouse, $variant->id);

        $this->actingAs($user)->post("/products/bulk-edit/{$import->id}/apply");

        $this->assertSame(6, $this->onHand($tenant, $variant->id));
        $row = $this->asTenant($tenant, fn () => BulkEditRow::query()->where('bulk_edit_import_id', $import->id)->firstOrFail());
        $this->assertSame(BulkEditReason::BULK_CHANGED_SINCE_PREVIEW->value, $row->reason_code);
        $this->assertSame(1, $import->refresh()->counts['apply_conflicts']);
    }

    // ─────────────────────────────────────────── dosya düzeyi

    #[Test]
    public function wrong_header_is_rejected_as_a_file(): void
    {
        [, $user] = $this->makeTenant();
        $path = $this->xlsx([['SKU', 'Stok'], ['A', 1]]);

        $this->actingAs($user)->post('/products/bulk-edit', ['file' => new UploadedFile($path, 'x.xlsx', null, null, true)]);

        $import = $this->asSystem(fn () => BulkEditImport::query()->firstOrFail());
        $this->assertSame(BulkEditImport::REJECTED, $import->status);
        $this->assertSame(BulkEditReason::BULK_FILE_INVALID->value, $import->reason_code);
        $this->actingAs($user)->post("/products/bulk-edit/{$import->id}/apply")->assertSessionHasErrors('apply');
    }

    #[Test]
    public function too_many_rows_are_rejected(): void
    {
        [$tenant, $user] = $this->makeTenant();
        $rows = [BulkEditSheet::HEADERS];

        for ($i = 0; $i <= BulkEditSheet::MAX_ROWS; $i++) {
            $rows[] = ['', "S{$i}", '', '', '', 1, 2, 10, '', 'TRY'];
        }

        $path = $this->xlsx($rows);
        $this->actingAs($user)->post('/products/bulk-edit', ['file' => new UploadedFile($path, 'buyuk.xlsx', null, null, true)]);

        $import = $this->asTenant($tenant, fn () => BulkEditImport::query()->firstOrFail());
        $this->assertSame(BulkEditReason::BULK_TOO_MANY_ROWS->value, $import->reason_code);
        $this->assertSame(0, $this->asTenant($tenant, fn () => BulkEditRow::query()->count()));
    }

    #[Test]
    public function non_xlsx_upload_is_refused(): void
    {
        [, $user] = $this->makeTenant();

        $this->actingAs($user)->post('/products/bulk-edit', ['file' => UploadedFile::fake()->createWithContent('x.csv', "a,b\n1,2")])
            ->assertSessionHasErrors('file');
    }

    #[Test]
    public function foreign_and_unknown_import_ids_look_the_same(): void
    {
        Queue::fake();
        [$tenantA, $userA, $warehouseA] = $this->makeTenant('A');
        [, $userB] = $this->makeTenant('B');
        $variant = $this->stockedVariant($tenantA, $warehouseA, 2, ['sku' => 'T-1']);
        $import = $this->upload($userA, [$this->row($variant, 2, 1)]);

        foreach ([$import->id, '01900000-0000-7000-8000-000000000000'] as $id) {
            $this->actingAs($userB)->get("/products/bulk-edit/{$id}")->assertNotFound();
            $this->actingAs($userB)->post("/products/bulk-edit/{$id}/apply")->assertNotFound();
        }

        Queue::assertNothingPushed();
        $this->assertSame(BulkEditImport::PARSED, $this->asTenant($tenantA, fn () => $import->refresh()->status));
    }

    // ─────────────────────────────────────────── yardımcılar

    /**
     * @return list<mixed>
     */
    private function row(Variant $variant, int|string|null $fileStock, mixed $newStock, int|float|string $filePrice = '', mixed $newPrice = ''): array
    {
        return [
            $variant->id,
            $variant->sku,
            '',
            'Ürün',
            '',
            $fileStock ?? '',
            $newStock ?? '',
            $filePrice === '' ? (float) $variant->price : $filePrice,
            $newPrice,
            'TRY',
        ];
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function upload(User $user, array $rows): BulkEditImport
    {
        $path = $this->xlsx([BulkEditSheet::HEADERS, ...$rows]);

        $response = $this->actingAs($user)->post('/products/bulk-edit', ['file' => new UploadedFile($path, 'liste.xlsx', null, null, true)]);
        $response->assertSessionHasNoErrors();

        $tenantId = $user->tenants()->first()?->id ?? Tenant::query()->first()->id;

        return $this->asTenant($tenantId, fn () => BulkEditImport::query()->latest('created_at')->firstOrFail());
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bulk-test-').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($rows as $values) {
            $writer->addRow(new Row(array_map(fn ($v) => $v instanceof Cell ? $v : Cell::fromValue($v), $values)));
        }

        $writer->close();

        return $path;
    }

    /**
     * @return list<list<mixed>>
     */
    private function readXlsx(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $out = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $out[] = array_map(fn (Cell $c) => $c->getValue(), $row->getCells());
            }

            break;
        }

        $reader->close();

        return $out;
    }

    private function sellOne(Tenant $tenant, string $warehouseId, string $variantId): void
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
}
