<?php

declare(strict_types=1);

namespace App\Support\Backup;

/**
 * Tutarlı bir veritabanı anı: satır sayıları ve döküm AYNI anı görür.
 *
 * Sayımlar dökümden ayrı bir sorguda alınsaydı arada gelen bir sipariş
 * sayıyı döküme göre bir fazla gösterir ve geri yükleme provası her gece
 * "satır sayısı tutmuyor" diye yanlış alarm verirdi. Gerçek uygulama
 * (`PostgresSnapshot`) `pg_export_snapshot()` ile anı dışa açar; pg_dump
 * `--snapshot` ile aynı anı okur.
 */
interface DatabaseSnapshot
{
    /**
     * @template T
     *
     * @param  callable(string $snapshotId, array<string, int> $rowCounts): T  $work
     * @return T
     */
    public function capture(callable $work): mixed;
}
