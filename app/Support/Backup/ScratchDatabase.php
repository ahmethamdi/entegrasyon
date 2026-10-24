<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Illuminate\Support\Facades\DB;

/**
 * Geri yükleme provasının geçici veritabanı: aç, say, sil.
 *
 * ÜRETİM VERİTABANINA DOKUNMA KİLİDİ: ad yalnız `restore_check_` + 14 rakam
 * olabilir ve uygulamanın kendi veritabanı adıyla aynı olamaz. Bir hata
 * yüzünden `DROP DATABASE entegrasyon` kurulsa bile burada durur.
 *
 * `final` değil: provanın akış testleri gerçek CREATE DATABASE'i (işlem
 * içinde koşamaz) sahte bir alt sınıfla değiştirir; gerçek sınıf ayrıca
 * kendi testinde gerçek veritabanıyla sınanır.
 */
class ScratchDatabase
{
    private const NAME_PATTERN = '/^restore_check_\d{14}$/';

    private const CONNECTION = 'backup_restore_check';

    public function __construct(private readonly string $baseConnection = 'pgsql') {}

    public static function nameFor(\DateTimeInterface $at): string
    {
        return 'restore_check_'.$at->format('YmdHis');
    }

    public function create(string $name): void
    {
        $this->guard($name);
        DB::connection($this->baseConnection)->statement('CREATE DATABASE "'.$name.'" TEMPLATE template0');
    }

    public function drop(string $name): void
    {
        $this->guard($name);
        DB::purge(self::CONNECTION);
        DB::connection($this->baseConnection)->statement('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int|null> tablo => satır (tablo yoksa null)
     */
    public function countRows(string $name, array $tables): array
    {
        $this->guard($name);

        config(['database.connections.'.self::CONNECTION => array_merge(
            config("database.connections.{$this->baseConnection}"),
            ['database' => $name],
        )]);
        DB::purge(self::CONNECTION);
        $connection = DB::connection(self::CONNECTION);

        $existing = array_flip(array_map(
            static fn ($row) => (string) $row->tablename,
            $connection->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"),
        ));

        $counts = [];
        foreach ($tables as $table) {
            if (! isset($existing[$table])) {
                $counts[$table] = null;

                continue;
            }
            $quoted = '"'.str_replace('"', '""', $table).'"';
            $counts[$table] = (int) $connection->selectOne("SELECT count(*) AS n FROM public.{$quoted}")->n;
        }

        DB::disconnect(self::CONNECTION);

        return $counts;
    }

    private function guard(string $name): void
    {
        $production = (string) config("database.connections.{$this->baseConnection}.database");

        if (preg_match(self::NAME_PATTERN, $name) !== 1 || $name === $production) {
            throw new BackupException("Geçici veritabanı adı reddedildi: {$name}");
        }
    }
}
