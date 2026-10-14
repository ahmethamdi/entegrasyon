<?php

declare(strict_types=1);

namespace App\Domain\BulkEdit\Actions;

use App\Domain\BulkEdit\Enums\BulkEditReason;
use App\Domain\BulkEdit\Models\BulkEditImport;
use App\Domain\BulkEdit\Models\BulkEditRow;
use App\Domain\BulkEdit\Support\BulkEditFileException;
use App\Domain\BulkEdit\Support\BulkEditSheet;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Sync\Models\Listing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;

/**
 * Excel yüklemesini ÖNİZLEMEYE çevirir — stok ve fiyat DEĞİŞMEZ.
 *
 * Satır yorumu (ADR-012 · §11):
 *   - Kimlik: 34Pazar ID (kiracı kapsamında) ve/veya SKU. İkisi farklı
 *     varyantı gösterirse ENGEL; dosyadaki kimliğe güvenilmez, hesapta
 *     aranır (başka hesabın kimliği "bulunamadı" olur — var olduğu sızmaz).
 *   - Formül hücresi reddedilir (değer yerine hesap kaynağı olur).
 *   - Stok tam sayı ≥ 0, fiyat > 0 ve en çok iki ondalık; boş = değişmez.
 *   - Aynı varyant birden çok satırda → hepsi ENGEL (hangisi doğru, tahmin yok).
 *   - Dışa aktarımdan sonra stok/fiyat değiştiyse (arada satış) → ENGEL:
 *     eski dosya satılanı geri eklemez.
 */
final class ParseBulkEdit
{
    public function __construct(private readonly BulkEditSheet $sheet) {}

    public function run(string $path, string $filename, string $warehouseId, ?string $userId): BulkEditImport
    {
        try {
            $rows = $this->sheet->read($path);
        } catch (BulkEditFileException $e) {
            return BulkEditImport::query()->create([
                'user_id' => $userId,
                'warehouse_id' => $warehouseId,
                'filename' => mb_substr($filename, 0, 255),
                'status' => BulkEditImport::REJECTED,
                'reason_code' => $e->reason()->value,
                'counts' => [],
            ]);
        }

        $interpreted = $this->interpret($rows, $warehouseId);

        return DB::transaction(function () use ($interpreted, $filename, $warehouseId, $userId): BulkEditImport {
            $import = BulkEditImport::query()->create([
                'user_id' => $userId,
                'warehouse_id' => $warehouseId,
                'filename' => mb_substr($filename, 0, 255),
                'status' => BulkEditImport::PARSED,
                'counts' => $this->counts($interpreted),
            ]);

            $now = Carbon::now();

            // SABİT SÜTUN SIRASI: toplu insert sütunları ilk satırın
            // anahtarlarından, değerleri her satırın KENDİ sırasından alır —
            // sıra farklı satırlarda değerler yanlış sütuna sessizce kayardı.
            $columns = ['row_number', 'variant_id', 'sku', 'label', 'status', 'reason_code', 'stock_file', 'stock_current', 'stock_new', 'price_file', 'price_current', 'price_new', 'currency', 'stock_channels', 'price_channels', 'applied_at'];

            foreach (array_chunk($interpreted, 500) as $chunk) {
                BulkEditRow::query()->insert(array_map(function (array $row) use ($columns, $import, $now): array {
                    $ordered = [
                        'id' => BulkEditRow::generateUuidV7(),
                        'tenant_id' => $import->tenant_id,
                        'bulk_edit_import_id' => $import->id,
                    ];

                    foreach ($columns as $column) {
                        $ordered[$column] = $row[$column] ?? null;
                    }

                    return $ordered + ['created_at' => $now, 'updated_at' => $now];
                }, $chunk));
            }

            return $import;
        });
    }

    /**
     * @param  list<array{row: int, cells: list<Cell>}>  $rows
     * @return list<array<string, mixed>>
     */
    private function interpret(array $rows, string $warehouseId): array
    {
        // Toplu ön yükleme — satır başına sorgu yok.
        $ids = [];
        $skus = [];

        foreach ($rows as $row) {
            $id = BulkEditSheet::text($row['cells'][0] ?? null);
            $sku = BulkEditSheet::text($row['cells'][1] ?? null);

            if ($id !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1) {
                $ids[] = strtolower($id);
            }

            if ($sku !== null) {
                $skus[] = $sku;
            }
        }

        $byId = Variant::query()->with('product:id,title')->whereIn('id', array_unique($ids))->get()->keyBy('id');
        $bySku = Variant::query()->with('product:id,title')->whereIn('sku', array_unique($skus))->get()->keyBy('sku');
        $variantIds = $byId->keys()->merge($bySku->pluck('id'))->unique()->values();
        $levels = InventoryLevel::query()->where('warehouse_id', $warehouseId)->whereIn('variant_id', $variantIds)->pluck('on_hand', 'variant_id');
        $stockChannels = $this->channelCounts($variantIds->all(), 'stock_sync_enabled');
        $priceChannels = $this->channelCounts($variantIds->all(), 'price_sync_enabled');

        $out = [];

        foreach ($rows as $row) {
            $cells = $row['cells'];
            $id = BulkEditSheet::text($cells[0] ?? null);
            $sku = BulkEditSheet::text($cells[1] ?? null);

            $entry = [
                'row_number' => $row['row'],
                'variant_id' => null,
                'sku' => $sku !== null ? mb_substr($sku, 0, 191) : null,
                'label' => mb_substr((string) BulkEditSheet::text($cells[3] ?? null), 0, 500) ?: null,
                'status' => BulkEditRow::INVALID,
                'reason_code' => null,
                'stock_file' => null,
                'stock_current' => null,
                'stock_new' => null,
                'price_file' => null,
                'price_current' => null,
                'price_new' => null,
                'currency' => null,
                'stock_channels' => 0,
                'price_channels' => 0,
                'applied_at' => null,
            ];

            // Formül: kimlik, yeni stok ya da yeni fiyat hücresinde kabul edilmez.
            foreach ([0, 1, 6, 8] as $index) {
                if (($cells[$index] ?? null) instanceof Cell\FormulaCell) {
                    $out[] = ['reason_code' => BulkEditReason::BULK_FORMULA_NOT_ALLOWED->value] + $entry;

                    continue 2;
                }
            }

            if ($id === null && $sku === null) {
                $out[] = ['reason_code' => BulkEditReason::BULK_IDENTITY_MISSING->value] + $entry;

                continue;
            }

            $variant = null;

            if ($id !== null) {
                $variant = $byId->get(strtolower($id));

                if ($variant === null) {
                    $out[] = ['reason_code' => BulkEditReason::BULK_VARIANT_UNKNOWN->value] + $entry;

                    continue;
                }

                if ($sku !== null && $variant->sku !== $sku) {
                    // Hangi ürünün kastedildiği belli değil: hiçbirine bağlanmaz
                    // (tekrar sayımına da girmez).
                    $out[] = ['reason_code' => BulkEditReason::BULK_ID_SKU_MISMATCH->value] + $entry;

                    continue;
                }
            } else {
                $variant = $bySku->get($sku);

                if ($variant === null) {
                    $out[] = ['reason_code' => BulkEditReason::BULK_VARIANT_UNKNOWN->value] + $entry;

                    continue;
                }
            }

            $entry['variant_id'] = $variant->id;
            $entry['sku'] = $variant->sku;
            $entry['label'] = $entry['label'] ?? mb_substr((string) $variant->product?->title, 0, 500);
            $entry['currency'] = $variant->currency;
            $entry['stock_current'] = (int) ($levels[$variant->id] ?? 0);
            $entry['price_current'] = number_format((float) $variant->price, 2, '.', '');
            $entry['stock_file'] = $this->integer($cells[5] ?? null);
            $entry['price_file'] = $this->price($cells[7] ?? null);
            $entry['stock_channels'] = $stockChannels[$variant->id] ?? 0;
            $entry['price_channels'] = $priceChannels[$variant->id] ?? 0;

            $stockText = BulkEditSheet::text($cells[6] ?? null);
            $priceText = BulkEditSheet::text($cells[8] ?? null);

            if ($stockText !== null) {
                $stock = $this->integer($cells[6]);

                if ($stock === null) {
                    $out[] = ['reason_code' => BulkEditReason::BULK_STOCK_INVALID->value] + $entry;

                    continue;
                }

                $entry['stock_new'] = $stock;
            }

            if ($priceText !== null) {
                $price = $this->price($cells[8]);

                if ($price === null || bccomp($price, '0', 2) <= 0) {
                    $out[] = ['reason_code' => BulkEditReason::BULK_PRICE_INVALID->value] + $entry;

                    continue;
                }

                $entry['price_new'] = $price;
            }

            $stockChanges = $entry['stock_new'] !== null && $entry['stock_new'] !== $entry['stock_current'];
            $priceChanges = $entry['price_new'] !== null && bccomp((string) $entry['price_new'], (string) $entry['price_current'], 2) !== 0;

            if (! $stockChanges && ! $priceChanges) {
                $out[] = ['status' => BulkEditRow::UNCHANGED] + $entry;

                continue;
            }

            if ($stockChanges && $entry['stock_file'] !== null && $entry['stock_file'] !== $entry['stock_current']) {
                $out[] = ['status' => BulkEditRow::CONFLICT, 'reason_code' => BulkEditReason::BULK_STOCK_CHANGED_SINCE_EXPORT->value] + $entry;

                continue;
            }

            if ($priceChanges && $entry['price_file'] !== null && bccomp((string) $entry['price_file'], (string) $entry['price_current'], 2) !== 0) {
                $out[] = ['status' => BulkEditRow::CONFLICT, 'reason_code' => BulkEditReason::BULK_PRICE_CHANGED_SINCE_EXPORT->value] + $entry;

                continue;
            }

            $out[] = ['status' => BulkEditRow::CHANGE] + $entry;
        }

        return $this->blockDuplicates($out);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function blockDuplicates(array $rows): array
    {
        $seen = [];

        foreach ($rows as $row) {
            if ($row['variant_id'] !== null) {
                $seen[$row['variant_id']] = ($seen[$row['variant_id']] ?? 0) + 1;
            }
        }

        // Geçersiz satır kendi (daha belirli) nedenini korur; aynı varyantı
        // gösteren GEÇERLİ satır yine engellenir — niyet belirsiz.
        foreach ($rows as $i => $row) {
            if ($row['variant_id'] !== null && $seen[$row['variant_id']] > 1 && $row['status'] !== BulkEditRow::INVALID) {
                $rows[$i]['status'] = BulkEditRow::CONFLICT;
                $rows[$i]['reason_code'] = BulkEditReason::BULK_DUPLICATE_ROW->value;
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function counts(array $rows): array
    {
        $counts = ['rows' => count($rows), 'change' => 0, 'unchanged' => 0, 'invalid' => 0, 'conflict' => 0, 'stock_changes' => 0, 'price_changes' => 0];

        foreach ($rows as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;

            if ($row['status'] === BulkEditRow::CHANGE) {
                if ($row['stock_new'] !== null && $row['stock_new'] !== $row['stock_current']) {
                    $counts['stock_changes']++;
                }

                if ($row['price_new'] !== null && bccomp((string) $row['price_new'], (string) $row['price_current'], 2) !== 0) {
                    $counts['price_changes']++;
                }
            }
        }

        return $counts;
    }

    /**
     * Varyant başına, ilgili yazma izni AÇIK bağlantılardaki canlı ilan sayısı.
     *
     * @param  list<string>  $variantIds
     * @return array<string, int>
     */
    private function channelCounts(array $variantIds, string $column): array
    {
        if ($variantIds === []) {
            return [];
        }

        $connections = ChannelConnection::query()->where($column, true)->pluck('id');

        return Listing::query()
            ->whereIn('variant_id', $variantIds)
            ->where('lifecycle_status', 'live')
            ->whereIn('channel_connection_id', $connections)
            ->selectRaw('variant_id, count(*) as total')
            ->groupBy('variant_id')
            ->pluck('total', 'variant_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function integer(?Cell $cell): ?int
    {
        if ($cell === null || $cell instanceof Cell\FormulaCell) {
            return null;
        }

        $value = $cell->getValue();

        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value)) {
            return $value >= 0 && floor($value) === $value && $value <= 2_000_000_000 ? (int) $value : null;
        }

        $text = trim((string) $value);

        return preg_match('/^\d{1,10}$/', $text) === 1 && (int) $text <= 2_000_000_000 ? (int) $text : null;
    }

    private function price(?Cell $cell): ?string
    {
        if ($cell === null || $cell instanceof Cell\FormulaCell) {
            return null;
        }

        $value = $cell->getValue();

        if (is_int($value) || is_float($value)) {
            $rounded = round((float) $value, 2);

            if ($value < 0 || abs($rounded - (float) $value) > 1e-9 || $rounded > 9_999_999_999) {
                return null;
            }

            return number_format($rounded, 2, '.', '');
        }

        $text = trim((string) $value);

        // Metin hücrede yalnız binlik ayırıcısız yazım: "1299,90" / "1299.90".
        if (preg_match('/^(\d{1,10})(?:[.,](\d{1,2}))?$/', $text, $m) !== 1) {
            return null;
        }

        return $m[1].'.'.str_pad($m[2] ?? '00', 2, '0');
    }
}
