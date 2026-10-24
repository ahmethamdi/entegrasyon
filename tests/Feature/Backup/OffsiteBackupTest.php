<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Support\Backup\DatabaseSnapshot;
use App\Support\Backup\OffsiteBackup;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Backup\FakeBackupWorld;
use Tests\TestCase;

/**
 * S13 · off-site yedek akışı — sahte S3, sahte pg_dump/age (gerçek S3 ve
 * gerçek age ayrıca canlıda elle doğrulanır: docs/RUNBOOK.md).
 */
final class OffsiteBackupTest extends TestCase
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
        $this->app->instance(DatabaseSnapshot::class, $this->world->snapshot());
    }

    protected function tearDown(): void
    {
        @unlink($this->identity);
        parent::tearDown();
    }

    private function backup(): OffsiteBackup
    {
        return $this->app->make(OffsiteBackup::class);
    }

    #[Test]
    public function nightly_backup_uploads_encrypted_dump_and_manifest_from_one_snapshot(): void
    {
        $result = $this->backup()->run(new DateTimeImmutable('2026-10-25T02:00:00Z'));

        $this->assertSame('ok', $result['status']);
        $dumpKey = '34pazar/34pazar-20261025T020000Z.dump.age';
        $this->assertSame($dumpKey, $result['key']);

        // Bucket'a giden döküm şifreli olandır; düz döküm hiçbir yere gitmez.
        $this->assertSame('SIFRELI:PGDMP-DUZ-DOKUM', $this->world->objects[$dumpKey]);
        foreach ($this->world->objects as $body) {
            $this->assertStringNotContainsString('PGDMP-DUZ-DOKUM', str_replace('SIFRELI:PGDMP-DUZ-DOKUM', '', $body));
        }

        $manifest = json_decode($this->world->objects['34pazar/34pazar-20261025T020000Z.manifest.json'], true);
        $this->assertSame(['tenants' => 2, 'orders' => 5], $manifest['tables']);
        $this->assertSame(hash('sha256', 'SIFRELI:PGDMP-DUZ-DOKUM'), $manifest['sha256']);

        // Sayımları alan an ile pg_dump'ın okuduğu an AYNI.
        $pgDump = collect($this->world->commands)->first(fn ($c) => $c[0] === 'pg_dump');
        $this->assertContains('--snapshot=00000003-0000001B-1', $pgDump);
        $this->assertContains('--format=custom', $pgDump);

        $age = collect($this->world->commands)->first(fn ($c) => $c[0] === 'age');
        $this->assertContains('--encrypt', $age);
        $this->assertContains($this->identity, $age);

        $this->assertFalse($this->world->unsignedRequestSeen, 'S3 isteği imzasız gitti');

        // Geçici dosyalar silindi.
        $this->assertSame([], glob(config('entegrasyon.backup.work_dir').'/*') ?: []);

        // Dead-man: başla + başarı.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://hc.test/ping/yedek/start');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://hc.test/ping/yedek' && $r->method() === 'POST');
    }

    #[Test]
    public function retention_prunes_only_our_complete_backups_and_never_foreign_files(): void
    {
        // 20 gece geriye tam yedek + bir yabancı dosya + yarım kalmış eski bir döküm.
        $base = new DateTimeImmutable('2026-10-24T02:00:00Z');
        for ($i = 0; $i < 20; $i++) {
            $this->world->seedComplete($base->modify("-{$i} days")->format('Ymd\THis\Z'));
        }
        $this->world->objects['34pazar/elle-konmus-not.txt'] = 'dokunma';
        $this->world->objects['34pazar/34pazar-20261001T020000Z.dump.age'] = 'yarim';

        $result = $this->backup()->run(new DateTimeImmutable('2026-10-25T02:00:00Z'));

        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('34pazar/elle-konmus-not.txt', $this->world->objects);
        $this->assertNotContains('34pazar/elle-konmus-not.txt', $this->world->deleted);
        $this->assertContains('34pazar/34pazar-20261001T020000Z.dump.age', $this->world->deleted, 'sahipsiz yarım döküm temizlenmeli');

        // Bugün + en yeni 6 gün kalır; 7 günden eski günlükler haftalık kovaya girmiyorsa silinir.
        $this->assertArrayHasKey('34pazar/34pazar-20261025T020000Z.dump.age', $this->world->objects);
        $this->assertArrayHasKey('34pazar/34pazar-20261019T020000Z.dump.age', $this->world->objects);
        $this->assertArrayNotHasKey('34pazar/34pazar-20261017T020000Z.dump.age', $this->world->objects);
        $this->assertArrayNotHasKey('34pazar/34pazar-20261017T020000Z.manifest.json', $this->world->objects, 'döküm silinince manifesti de gider');
        $this->assertGreaterThan(0, $result['pruned']);
    }

    #[Test]
    public function missing_configuration_alarms_instead_of_silently_skipping(): void
    {
        config(['entegrasyon.backup.s3.bucket' => '', 'entegrasyon.backup.age_identity_file' => '/yok/backup-age.key']);

        $result = $this->backup()->run();

        $this->assertSame('not_configured', $result['status']);
        $this->assertContains('BACKUP_S3_BUCKET', $result['missing']);
        $this->assertSame([], $this->world->commands, 'yapılandırılmadan pg_dump koşmamalı');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://ntfy.example')
            && $r['title'] === 'Off-site yedek yapılandırılmadı'
            && ! str_contains((string) $r['message'], 'GIZLI-S3-SECRET'));

        $this->artisan('backup:offsite')->assertExitCode(1);
    }

    #[Test]
    public function failed_upload_reports_failure_pings_fail_and_leaves_no_manifest(): void
    {
        $this->world->putStatus = 503;

        $result = $this->backup()->run(new DateTimeImmutable('2026-10-25T02:00:00Z'));

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('HTTP 503', $result['error']);
        $this->assertStringNotContainsString('GIZLI-S3-SECRET', $result['error']);
        $this->assertArrayNotHasKey('34pazar/34pazar-20261025T020000Z.manifest.json', $this->world->objects);
        $this->assertSame([], glob(config('entegrasyon.backup.work_dir').'/*') ?: [], 'düz döküm diskte kalmamalı');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://hc.test/ping/yedek/fail');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://ntfy.example') && $r['title'] === 'Off-site yedek BAŞARISIZ');
    }

    #[Test]
    public function failed_pg_dump_uploads_nothing(): void
    {
        $this->world->pgDumpFails = true;

        $result = $this->backup()->run();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('pg_dump başarısız: pg_dump: error: connection refused', $result['error']);
        $this->assertSame([], $this->world->objects);
        $this->assertNotContains('age', array_column($this->world->commands, 0), 'döküm yoksa şifreleme de koşmamalı');
    }

    #[Test]
    public function backup_and_drill_and_heartbeat_are_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events());
        $find = fn (string $cmd) => $events->first(fn ($e) => str_contains((string) $e->command, $cmd));

        $this->assertSame('0 2 * * *', $find('backup:offsite')?->expression, 'gece yedeği zamanlanmamış');
        $this->assertSame('40 2 * * 0', $find('backup:verify')?->expression, 'haftalık prova zamanlanmamış');
        $this->assertSame('*/5 * * * *', $find('ops:heartbeat')?->expression, 'nabız zamanlanmamış');
    }
}
