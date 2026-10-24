<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Support\Backup\BackupException;
use App\Support\Backup\PostgresSnapshot;
use App\Support\Backup\ScratchDatabase;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S13 · gerçek PostgreSQL ile: geçici veritabanı aç/say/sil, üretim adı
 * kilidi, tutarlı an (`pg_export_snapshot`).
 *
 * RefreshDatabase YOK: CREATE/DROP DATABASE işlem içinde koşamaz. Test
 * veritabanının şemasına dokunulmaz; yalnız `restore_check_…` açılıp silinir.
 */
final class ScratchDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Bu sınıf RefreshDatabase kullanmaz; test DB'si boşsa (ilk koşu) şema kurulsun.
        $this->artisan('migrate', ['--force' => true]);
    }

    #[Test]
    public function scratch_database_is_created_counted_and_dropped(): void
    {
        $scratch = new ScratchDatabase;
        $name = ScratchDatabase::nameFor(new DateTimeImmutable('2026-10-25 02:40:00'));

        try {
            $scratch->create($name);
            $this->assertSame(1, DB::selectOne('SELECT count(*) AS n FROM pg_database WHERE datname = ?', [$name])->n);

            $this->assertSame(['tenants' => null], $scratch->countRows($name, ['tenants']), 'boş veritabanında tablo yok = null, 0 değil');
        } finally {
            $scratch->drop($name);
        }

        $this->assertSame(0, DB::selectOne('SELECT count(*) AS n FROM pg_database WHERE datname = ?', [$name])->n);
    }

    #[Test]
    public function production_database_name_can_never_be_dropped(): void
    {
        $scratch = new ScratchDatabase;
        $production = (string) config('database.connections.pgsql.database');

        foreach ([$production, 'entegrasyon', 'restore_check_', 'restore_check_2026; DROP DATABASE x', 'postgres'] as $name) {
            try {
                $scratch->drop($name);
                $this->fail("'{$name}' reddedilmeliydi");
            } catch (BackupException $e) {
                $this->assertStringContainsString('reddedildi', $e->getMessage());
            }
        }

        $this->assertSame(1, DB::selectOne('SELECT count(*) AS n FROM pg_database WHERE datname = ?', [$production])->n);
    }

    #[Test]
    public function snapshot_exports_an_id_and_counts_every_public_table(): void
    {
        $result = (new PostgresSnapshot)->capture(fn (string $id, array $counts) => [$id, $counts]);

        [$id, $counts] = $result;
        $this->assertMatchesRegularExpression('/^[0-9A-F]+-[0-9A-F]+-\d+$/', $id);
        $this->assertArrayHasKey('migrations', $counts);
        $this->assertArrayHasKey('tenants', $counts);
        $this->assertGreaterThan(0, $counts['migrations']);
    }

    #[Test]
    public function snapshot_transaction_is_read_only(): void
    {
        $this->expectException(\PDOException::class);

        (new PostgresSnapshot)->capture(function () {
            DB::connection('backup_snapshot')->getPdo()->exec('CREATE TABLE yedek_yazmamali (id int)');
        });
    }
}
