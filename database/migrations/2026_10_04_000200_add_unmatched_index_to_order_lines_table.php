<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * orders:resolve-unmatched taramasının kısmi indeksi (A12).
 *
 * Tarama her 5 dakikada bir çalışır; indeks yalnızca eşleşmemiş bekleyen
 * satırları içerir, yani neredeyse hep boştur ve tarama tabloyu taramaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX order_lines_unmatched_idx
                ON order_lines (tenant_id, sku)
                WHERE variant_id IS NULL AND stock_status = 'PENDING'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS order_lines_unmatched_idx');
    }
};
