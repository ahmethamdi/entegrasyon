<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `DatabaseSnapshot` — PostgreSQL. Ayrı bir bağlantı açar (uygulamanın
 * kendi bağlantısındaki açık işlemle karışmasın), REPEATABLE READ + READ
 * ONLY işlem başlatır, anı dışa açar, `public` şemasındaki her tablonun
 * satırını sayar, işi çalıştırır ve işlemi kapatır. Anı, işlem açık kaldığı
 * sürece geçerlidir — pg_dump bu süre içinde koşar.
 */
final class PostgresSnapshot implements DatabaseSnapshot
{
    private const CONNECTION = 'backup_snapshot';

    public function __construct(private readonly string $baseConnection = 'pgsql') {}

    public function capture(callable $work): mixed
    {
        config(['database.connections.'.self::CONNECTION => config("database.connections.{$this->baseConnection}")]);
        DB::purge(self::CONNECTION);
        $pdo = DB::connection(self::CONNECTION)->getPdo();

        $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ, READ ONLY');

        try {
            $snapshotId = (string) $pdo->query('SELECT pg_export_snapshot()')->fetchColumn();

            $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")
                ->fetchAll(\PDO::FETCH_COLUMN);

            $counts = [];
            foreach ($tables as $table) {
                $quoted = '"'.str_replace('"', '""', (string) $table).'"';
                $counts[(string) $table] = (int) $pdo->query("SELECT count(*) FROM public.{$quoted}")->fetchColumn();
            }

            $result = $work($snapshotId, $counts);
            $pdo->exec('COMMIT');

            return $result;
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // Bağlantı zaten kopmuş olabilir; asıl hata aşağıda fırlar.
            }

            throw $e;
        } finally {
            DB::disconnect(self::CONNECTION);
        }
    }
}
