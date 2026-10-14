<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\BulkEdit\Actions\ParseBulkEdit;
use App\Domain\BulkEdit\Enums\BulkEditReason;
use App\Domain\BulkEdit\Jobs\ApplyBulkEditJob;
use App\Domain\BulkEdit\Models\BulkEditImport;
use App\Domain\BulkEdit\Models\BulkEditRow;
use App\Domain\BulkEdit\Support\BulkEditSheet;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel ile toplu fiyat/stok: indir → düzenle → yükle → ÖNİZLE → UYGULA.
 *
 * Yükleme yalnız ayrıştırır (stok/fiyat değişmez). Uygulama ayrı, açık ve
 * TEK seferlik eylemdir (`parsed → applying` koşullu geçiş); iki sekmeden
 * iki kez basmak ikinci uygulamayı başlatmaz.
 */
final class BulkEditController extends Controller
{
    public function index(): InertiaResponse
    {
        $imports = BulkEditImport::query()->latest('created_at')->limit(20)->get();

        return Inertia::render('Products/BulkEdit', [
            'imports' => $imports->map(fn (BulkEditImport $i) => $this->presentImport($i))->values(),
            'maxRows' => BulkEditSheet::MAX_ROWS,
            'maxKilobytes' => BulkEditSheet::MAX_KILOBYTES,
        ]);
    }

    public function export(BulkEditSheet $sheet): BinaryFileResponse
    {
        $path = $sheet->export($this->warehouseId());

        return response()
            ->download($path, '34pazar-fiyat-stok-'.now()->format('Ymd-Hi').'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    public function store(Request $request, ParseBulkEdit $parse): RedirectResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.BulkEditSheet::MAX_KILOBYTES,
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream',
            ],
        ]);

        $file = $request->file('file');

        if (strtolower((string) $file->getClientOriginalExtension()) !== 'xlsx') {
            throw ValidationException::withMessages(['file' => BulkEditReason::BULK_FILE_INVALID->label()]);
        }

        $import = $parse->run(
            path: (string) $file->getRealPath(),
            filename: (string) $file->getClientOriginalName(),
            warehouseId: $this->warehouseId(),
            userId: $request->user()?->id,
        );

        return redirect()->route('products.bulk-edit.show', $import->id);
    }

    public function show(Request $request, string $import): InertiaResponse
    {
        $model = BulkEditImport::query()->findOrFail($import);
        $status = (string) $request->query('status', '');

        $rows = BulkEditRow::query()
            ->where('bulk_edit_import_id', $model->id)
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderBy('row_number')
            ->paginate(100)
            ->withQueryString();

        return Inertia::render('Products/BulkEditShow', [
            'bulkImport' => $this->presentImport($model),
            'status' => $status,
            'rows' => $rows->through(fn (BulkEditRow $r) => [
                'id' => $r->id,
                'row' => $r->row_number,
                'sku' => $r->sku,
                'label' => $r->label,
                'status' => $r->status,
                'reason' => $r->reason_code !== null ? BulkEditReason::tryFrom($r->reason_code)?->label() : null,
                'reasonCode' => $r->reason_code,
                'stockCurrent' => $r->stock_current,
                'stockNew' => $r->stock_new,
                'priceCurrent' => $r->price_current,
                'priceNew' => $r->price_new,
                'currency' => $r->currency,
                'stockChannels' => $r->stock_channels,
                'priceChannels' => $r->price_channels,
            ]),
        ]);
    }

    public function apply(string $import): RedirectResponse
    {
        $model = BulkEditImport::query()->findOrFail($import);

        $claimed = BulkEditImport::query()
            ->whereKey($model->id)
            ->where('status', BulkEditImport::PARSED)
            ->update([
                'status' => BulkEditImport::APPLYING,
                'apply_requested_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            throw ValidationException::withMessages(['apply' => BulkEditReason::BULK_ALREADY_APPLIED->label()]);
        }

        ApplyBulkEditJob::dispatch($model->tenant_id, $model->id);

        return back()->with('success', __('Değişiklikler uygulanıyor.'));
    }

    private function warehouseId(): string
    {
        return (string) Warehouse::query()->where('is_default', true)->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentImport(BulkEditImport $import): array
    {
        return [
            'id' => $import->id,
            'filename' => $import->filename,
            'status' => $import->status,
            'reason' => $import->reason_code !== null ? BulkEditReason::tryFrom($import->reason_code)?->label() : null,
            'counts' => array_merge([
                'rows' => 0, 'change' => 0, 'unchanged' => 0, 'invalid' => 0, 'conflict' => 0,
                'stock_changes' => 0, 'price_changes' => 0, 'applied' => 0, 'apply_conflicts' => 0, 'apply_failed' => 0,
            ], (array) $import->counts),
            'createdAt' => $import->created_at?->toIso8601String(),
            'appliedAt' => $import->applied_at?->toIso8601String(),
        ];
    }
}
