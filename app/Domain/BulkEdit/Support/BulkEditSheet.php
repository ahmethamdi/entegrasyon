<?php

declare(strict_types=1);

namespace App\Domain\BulkEdit\Support;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Inventory\Models\InventoryLevel;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Options as WriterOptions;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

/**
 * Excel toplu fiyat/stok ŞABLONU — dışa aktarma ve okuma (S5).
 *
 * SÜTUNLAR (sıra sabit, başlıkla doğrulanır):
 *   A 34Pazar ID   — teknik kimlik (varyant), DEĞİŞTİRİLMEZ
 *   B SKU          — ikinci kimlik; A ile çelişirse satır ENGELLENİR
 *   C Barkod · D Ürün · E Seçenekler      — yalnız okuma kolaylığı
 *   F Mevcut stok  — dışa aktarım anı; uygulamada karşılaştırılır
 *   G Yeni stok    — SET: "rafta olacak sayı"; boş = değişmez
 *   H Mevcut fiyat · I Yeni fiyat (boş = değişmez) · J Para birimi
 *
 * Satır numarası kimlik DEĞİLDİR: satıcı satırları sıralayabilir, silebilir.
 *
 * FORMÜL ENJEKSİYONU: metin hücreleri XLSX'te metin türüyle yazılır
 * (formül olarak değerlendirilmez); yine de `= + - @` ile başlayan metne
 * `'` eklenir — dosya CSV'ye çevrilip başka araçta açılırsa da güvenli.
 * Okurken bu önek geri alınır.
 */
final class BulkEditSheet
{
    public const SHEET_NAME = 'Fiyat-Stok';

    public const HEADERS = [
        '34Pazar ID',
        'SKU',
        'Barkod',
        'Ürün',
        'Seçenekler',
        'Mevcut stok',
        'Yeni stok',
        'Mevcut fiyat',
        'Yeni fiyat',
        'Para birimi',
    ];

    public const MAX_ROWS = 10_000;

    public const MAX_KILOBYTES = 5120;

    /**
     * Kiracının bütün varyantlarını şablona yazar; dosya yolunu döner.
     */
    public function export(string $warehouseId): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bulk-export-').'.xlsx';
        $options = new WriterOptions;
        $options->setColumnWidth(38, 1);
        $options->setColumnWidth(20, 2, 3);
        $options->setColumnWidth(40, 4);
        $options->setColumnWidth(22, 5);
        $options->setColumnWidth(14, 6, 7, 8, 9, 10);

        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(self::SHEET_NAME);
        $writer->addRow(Row::fromValues(self::HEADERS, (new Style)->setFontBold()));

        Variant::query()
            ->with(['product:id,title', 'options.definition', 'options.value'])
            ->orderBy('product_id')
            ->orderBy('sku')
            ->chunk(500, function ($variants) use ($writer, $warehouseId): void {
                $levels = InventoryLevel::query()
                    ->where('warehouse_id', $warehouseId)
                    ->whereIn('variant_id', $variants->pluck('id'))
                    ->pluck('on_hand', 'variant_id');

                foreach ($variants as $variant) {
                    $writer->addRow(new Row([
                        Cell::fromValue($variant->id),
                        Cell::fromValue(self::safeText($variant->sku)),
                        Cell::fromValue(self::safeText((string) $variant->barcode)),
                        Cell::fromValue(self::safeText((string) $variant->product?->title)),
                        Cell::fromValue(self::safeText(self::optionsLabel($variant))),
                        Cell::fromValue((int) ($levels[$variant->id] ?? 0)),
                        Cell::fromValue(''),
                        Cell::fromValue((float) $variant->price),
                        Cell::fromValue(''),
                        Cell::fromValue((string) $variant->currency),
                    ]));
                }
            });

        $writer->close();

        return $path;
    }

    /**
     * İlk sayfayı okur. Başlık şablonla aynı değilse istisna.
     *
     * @return list<array{row: int, cells: list<Cell>}>
     */
    public function read(string $path): array
    {
        $reader = new Reader;

        try {
            $reader->open($path);
        } catch (Throwable $e) {
            throw new BulkEditFileException('open: '.$e->getMessage());
        }

        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $number = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    $cells = $row->getCells();

                    if ($number === 1) {
                        $this->assertHeader($cells);

                        continue;
                    }

                    if ($this->isBlank($cells)) {
                        continue;
                    }

                    if (count($rows) >= self::MAX_ROWS) {
                        throw new BulkEditFileException('rows', tooMany: true);
                    }

                    $rows[] = ['row' => $number, 'cells' => $cells];
                }

                // Yalnız ilk sayfa: başka sayfalar (not, hesap) yok sayılır.
                break;
            }
        } catch (BulkEditFileException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BulkEditFileException('read: '.$e->getMessage());
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /** Metin hücre değeri, enjeksiyon öneki geri alınmış. */
    public static function text(?Cell $cell): ?string
    {
        if ($cell === null || $cell instanceof Cell\EmptyCell) {
            return null;
        }

        $value = $cell instanceof Cell\FormulaCell ? null : $cell->getValue();

        if ($value === null) {
            return null;
        }

        $text = trim(is_float($value) && floor($value) === $value ? (string) (int) $value : (string) $value);

        if (preg_match("/^'[=+\-@]/", $text) === 1) {
            $text = substr($text, 1);
        }

        return $text === '' ? null : $text;
    }

    public static function safeText(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    private static function optionsLabel(Variant $variant): string
    {
        return $variant->options
            ->map(fn ($option) => ($option->definition?->name ?? '').': '.($option->value?->value ?? ''))
            ->implode(', ');
    }

    /** @param list<Cell> $cells */
    private function assertHeader(array $cells): void
    {
        $found = array_map(fn (Cell $cell) => trim((string) $cell->getValue()), array_slice($cells, 0, count(self::HEADERS)));

        if ($found !== self::HEADERS) {
            throw new BulkEditFileException('header: '.implode('|', $found));
        }
    }

    /** @param list<Cell> $cells */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (! $cell instanceof Cell\EmptyCell && trim((string) ($cell instanceof Cell\FormulaCell ? $cell->getValue() : $cell->getValue())) !== '') {
                return false;
            }
        }

        return true;
    }
}
