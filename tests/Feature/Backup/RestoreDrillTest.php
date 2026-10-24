<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Support\Backup\RestoreDrill;
use App\Support\Backup\ScratchDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Backup\FakeBackupWorld;
use Tests\Support\Backup\FakeScratchDatabase;
use Tests\TestCase;

/**
 * S13 · haftalık geri yükleme provası — akış (sahte S3 / age / pg_restore /
 * geçici DB). Geçici DB'nin kendisi `ScratchDatabaseTest`'te gerçek
 * PostgreSQL ile sınanır.
 */
final class RestoreDrillTest extends TestCase
{
    private FakeBackupWorld $world;

    private string $identity;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'entegrasyon.beta.ntfy_topic' => 'test-ops',
            'entegrasyon.beta.ntfy_base_url' => 'https://ntfy.example',
            'entegrasyon.beta.notify_email' => '',
        ]);
        Mail::fake();

        $this->identity = tempnam(sys_get_temp_dir(), 'age');
        $this->world = new FakeBackupWorld;
        $this->world->install($this->identity);
    }

    protected function tearDown(): void
    {
        @unlink($this->identity);
        parent::tearDown();
    }

    private function drill(FakeScratchDatabase $scratch): RestoreDrill
    {
        $this->app->instance(ScratchDatabase::class, $scratch);

        return $this->app->make(RestoreDrill::class);
    }

    #[Test]
    public function newest_complete_backup_is_restored_compared_and_scratch_dropped(): void
    {
        $this->world->seedComplete('20261018T020000Z', ['tenants' => 1]);
        $this->world->seedComplete('20261025T020000Z', ['tenants' => 2, 'orders' => 7]);
        // Daha yeni ama yarım (manifest yok): seçilmemeli.
        $this->world->objects['34pazar/34pazar-20261026T020000Z.dump.age'] = 'yarim';

        $scratch = $this->world->scratch(['tenants' => 2, 'orders' => 7]);
        $result = $this->drill($scratch)->run();

        $this->assertSame('ok', $result['status'], $result['error'] ?? '');
        $this->assertSame('20261025T020000Z', $result['stamp']);
        $this->assertSame(9, $result['rows']);
        $this->assertCount(1, $scratch->created);
        $this->assertSame($scratch->created, $scratch->dropped, 'geçici veritabanı silinmedi');

        $restore = collect($this->world->commands)->first(fn ($c) => $c[0] === 'pg_restore');
        $this->assertContains('--dbname='.$scratch->created[0], $restore);
        $this->assertNotContains('--dbname='.config('database.connections.pgsql.database'), $restore);

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://ntfy.example') && $r['title'] === 'Yedek geri yükleme provası ✓');
        $this->assertSame([], glob(config('entegrasyon.backup.work_dir').'/*') ?: []);
    }

    #[Test]
    public function row_count_mismatch_fails_alarms_and_still_drops_scratch(): void
    {
        $this->world->seedComplete('20261025T020000Z', ['tenants' => 2, 'orders' => 7]);

        $scratch = $this->world->scratch(['tenants' => 2, 'orders' => 6]);
        $result = $this->drill($scratch)->run();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('orders: beklenen 7, gelen 6', $result['error']);
        $this->assertSame($scratch->created, $scratch->dropped);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://ntfy.example') && $r['title'] === 'Yedek geri yükleme provası BAŞARISIZ');
    }

    #[Test]
    public function missing_table_in_restore_is_a_failure_not_a_zero(): void
    {
        $this->world->seedComplete('20261025T020000Z', ['tenants' => 0, 'orders' => 0]);

        $result = $this->drill($this->world->scratch(['tenants' => 0]))->run();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('orders: beklenen 0, gelen tablo yok', $result['error']);
    }

    #[Test]
    public function tampered_dump_is_rejected_before_decrypting(): void
    {
        $this->world->seedComplete('20261025T020000Z');
        $this->world->objects['34pazar/34pazar-20261025T020000Z.dump.age'] = 'DEGISTIRILMIS';

        $scratch = $this->world->scratch(['tenants' => 2]);
        $result = $this->drill($scratch)->run();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('sha256 tutmuyor', $result['error']);
        $this->assertNotContains('age', array_column($this->world->commands, 0));
        $this->assertSame([], $scratch->created);
    }

    #[Test]
    public function failed_pg_restore_fails_and_drops_scratch(): void
    {
        $this->world->seedComplete('20261025T020000Z');
        $this->world->pgRestoreFails = true;

        $scratch = $this->world->scratch(['tenants' => 2]);
        $result = $this->drill($scratch)->run();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('pg_restore başarısız', $result['error']);
        $this->assertSame($scratch->created, $scratch->dropped);
    }

    #[Test]
    public function empty_bucket_is_a_failure(): void
    {
        $result = $this->drill($this->world->scratch([]))->run();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('tam (manifestli) yedek yok', $result['error']);
        $this->artisan('backup:verify')->assertExitCode(1);
    }
}
