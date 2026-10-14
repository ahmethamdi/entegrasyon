<?php

declare(strict_types=1);

namespace App\Domain\BulkEdit\Actions;

use App\Domain\BulkEdit\Enums\BulkEditReason;
use App\Domain\BulkEdit\Models\BulkEditImport;
use App\Domain\BulkEdit\Models\BulkEditRow;
use App\Domain\Catalog\Actions\SetVariantPrice;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Support\MovementKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Önizlenmiş Excel yüklemesini UYGULAR (S5, ADR-012 · §11).
 *
 * Yalnız `change` satırları. Satır başına TEK transaction:
 *   1. Varyantın stok satırı kilitlenir; stok/fiyat ÖNİZLEME anındakiyle
 *      aynı değilse satır uygulanmaz (BULK_CHANGED_SINCE_PREVIEW) — arada
 *      gelen satış "stok = 5" ile geri eklenmez.
 *   2. Stok: SET — fark kilit altında hesaplanır, defter hareketi
 *      (`bulk:{yükleme}:{satır}` anahtarı; iş yeniden denense de bir kez).
 *   3. Fiyat: `SetVariantPrice` (outbox → kanal, ADR-010 izniyle).
 *   4. Satır `applied`.
 *
 * Kanala doğrudan istek ATILMAZ: stok ve fiyat outbox'tan, bağlantının
 * yazma izni hem planlamada hem iş başında sorulur.
 */
final class ApplyBulkEdit
{
    public function __construct(
        private readonly LockInventoryRows $lockRows,
        private readonly ApplyMovement $applyMovement,
        private readonly SetVariantPrice $setPrice,
        private readonly RecordAuditLog $audit,
    ) {}

    public function run(BulkEditImport $import): BulkEditImport
    {
        $applied = 0;
        $conflicts = 0;
        $failed = 0;

        BulkEditRow::query()
            ->where('bulk_edit_import_id', $import->id)
            ->where('status', BulkEditRow::CHANGE)
            ->orderBy('row_number')
            ->chunkById(200, function ($rows) use ($import, &$applied, &$conflicts, &$failed): void {
                foreach ($rows as $row) {
                    try {
                        $outcome = DB::transaction(fn () => $this->applyRow($import, $row));
                    } catch (Throwable $e) {
                        $row->forceFill(['status' => BulkEditRow::FAILED, 'reason_code' => BulkEditReason::BULK_ROW_FAILED->value])->save();
                        Log::warning('bulk_edit.row_failed', ['import_id' => $import->id, 'row' => $row->row_number, 'error' => $e->getMessage()]);
                        $failed++;

                        continue;
                    }

                    $outcome ? $applied++ : $conflicts++;
                }
            });

        $counts = (array) $import->counts;
        $counts['applied'] = ($counts['applied'] ?? 0) + $applied;
        $counts['apply_conflicts'] = ($counts['apply_conflicts'] ?? 0) + $conflicts;
        $counts['apply_failed'] = ($counts['apply_failed'] ?? 0) + $failed;

        $import->forceFill([
            'status' => BulkEditImport::APPLIED,
            'counts' => $counts,
            'applied_at' => now(),
        ])->save();

        return $import;
    }

    /** true = uygulandı, false = önizlemeden sonra değişmiş (uygulanmadı). */
    private function applyRow(BulkEditImport $import, BulkEditRow $row): bool
    {
        $row = BulkEditRow::query()->lockForUpdate()->findOrFail($row->id);

        if ($row->status !== BulkEditRow::CHANGE) {
            return true;
        }

        $variant = $row->variant_id !== null ? Variant::query()->find($row->variant_id) : null;

        if ($variant === null) {
            $row->forceFill(['status' => BulkEditRow::CONFLICT, 'reason_code' => BulkEditReason::BULK_VARIANT_UNKNOWN->value])->save();

            return false;
        }

        $this->lockRows->run($import->warehouse_id, [$variant->id]);

        $onHand = (int) InventoryLevel::query()
            ->where('warehouse_id', $import->warehouse_id)
            ->where('variant_id', $variant->id)
            ->value('on_hand');

        $stockWanted = $row->stock_new !== null && $row->stock_new !== $row->stock_current;
        $priceWanted = $row->price_new !== null && bccomp((string) $row->price_new, (string) $row->price_current, 2) !== 0;

        $stale = ($stockWanted && $onHand !== $row->stock_current)
            || ($priceWanted && bccomp(number_format((float) $variant->price, 2, '.', ''), (string) $row->price_current, 2) !== 0);

        if ($stale) {
            $row->forceFill(['status' => BulkEditRow::CONFLICT, 'reason_code' => BulkEditReason::BULK_CHANGED_SINCE_PREVIEW->value])->save();

            return false;
        }

        if ($stockWanted) {
            $delta = (int) $row->stock_new - $onHand;

            if ($delta !== 0) {
                $movement = $this->applyMovement->run(
                    warehouseId: $import->warehouse_id,
                    variantId: $variant->id,
                    type: $delta > 0 ? MovementType::MANUAL_ADJUSTMENT : MovementType::MANUAL_REDUCTION,
                    quantity: abs($delta),
                    idempotencyKey: MovementKey::bulkEdit($import->id, $row->row_number),
                    sourceType: 'bulk_edit',
                    sourceId: $import->id,
                    note: __('Excel toplu düzenleme: :file', ['file' => $import->filename]),
                );

                $this->audit->run(
                    action: AuditAction::STOCK_ADJUSTED,
                    subjectType: 'variants',
                    subjectId: $variant->id,
                    changes: [
                        'warehouse_id' => $import->warehouse_id,
                        'from' => $onHand,
                        'to' => $row->stock_new,
                        'quantity' => $delta,
                        'movement_id' => $movement->id,
                        'bulk_edit_import_id' => $import->id,
                        'row' => $row->row_number,
                    ],
                    userId: $import->user_id,
                );
            }
        }

        if ($priceWanted) {
            $this->setPrice->run($variant, (float) $row->price_new);
        }

        $row->forceFill(['status' => BulkEditRow::APPLIED, 'applied_at' => now()])->save();

        return true;
    }
}
