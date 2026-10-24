<?php

declare(strict_types=1);

namespace App\Support\Backup;

use App\Support\Observability\OpsNotifier;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * S13 · haftalık geri yükleme provası — "yedek var" değil "yedek AÇILIYOR".
 *
 * En yeni TAM off-site yedeği (manifesti olan) indirir → sha256'yı doğrular
 * → age ile çözer → geçici veritabanına (`restore_check_…`) yükler → her
 * tablonun satırını manifestle karşılaştırır → geçici veritabanını siler.
 * Süre ölçülür: RTO'nun "indir + çöz + yükle" kısmı (docs/RUNBOOK.md).
 *
 * Sonuç her durumda 34Devs'e gider (başarı haftada bir bilgi, başarısızlık
 * alarm). Geçici veritabanı ve dosyalar `finally`'de temizlenir.
 */
final class RestoreDrill
{
    public function __construct(
        private readonly BackupConfig $config,
        private readonly ScratchDatabase $scratch,
        private readonly OpsNotifier $notifier,
    ) {}

    /** @return array{status: 'ok'|'not_configured'|'failed', stamp?: string, seconds?: float, tables?: int, rows?: int, error?: string, missing?: list<string>} */
    public function run(): array
    {
        $missing = $this->config->missing();
        if ($missing !== []) {
            // Yapılandırılmamış durumun alarmı gece yedek işinden gider; burada tekrar ötmez.
            return ['status' => 'not_configured', 'missing' => $missing];
        }

        $started = microtime(true);
        $keys = new BackupKeys($this->config->prefix());
        $dir = rtrim($this->config->workDir(), '/');
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $scratchName = ScratchDatabase::nameFor(new DateTimeImmutable);
        $encrypted = "{$dir}/restore-check.dump.age";
        $plain = "{$dir}/restore-check.dump";
        $created = false;

        try {
            $s3 = new S3Client($this->config);
            $complete = array_filter($keys->group($s3->listKeys($this->config->prefix())), static fn (array $g) => $g['dump'] && $g['manifest']);
            if ($complete === []) {
                throw new BackupException('Off-site bucket\'ta tam (manifestli) yedek yok.');
            }
            krsort($complete, SORT_STRING);
            $stamp = (string) array_key_first($complete);

            $manifest = json_decode($s3->getString($keys->manifest($stamp)), true, 512, JSON_THROW_ON_ERROR);
            $s3->download($keys->dump($stamp), $encrypted);

            if (! hash_equals((string) ($manifest['sha256'] ?? ''), (string) hash_file('sha256', $encrypted))) {
                throw new BackupException("sha256 tutmuyor ({$stamp}) — dosya bozulmuş ya da değiştirilmiş.");
            }

            $this->decrypt($encrypted, $plain);
            @unlink($encrypted);

            $this->scratch->create($scratchName);
            $created = true;
            $this->pgRestore($scratchName, $plain);

            /** @var array<string, int> $expected */
            $expected = $manifest['tables'] ?? [];
            $actual = $this->scratch->countRows($scratchName, array_keys($expected));

            $mismatch = [];
            foreach ($expected as $table => $rows) {
                if ($actual[$table] !== (int) $rows) {
                    $mismatch[] = sprintf('%s: beklenen %d, gelen %s', $table, $rows, $actual[$table] === null ? 'tablo yok' : (string) $actual[$table]);
                }
            }
            if ($expected === []) {
                $mismatch[] = 'manifestte tablo listesi yok';
            }
            if ($mismatch !== []) {
                throw new BackupException('Satır sayıları tutmuyor: '.implode('; ', array_slice($mismatch, 0, 5)).(count($mismatch) > 5 ? ' …' : ''));
            }

            $seconds = round(microtime(true) - $started, 1);
            $rows = array_sum($expected);
            $summary = sprintf('Yedek %s açıldı: %d tablo, %d satır birebir, %.1f sn (indir + çöz + yükle).', $stamp, count($expected), $rows, $seconds);

            Log::info('backup.restore_drill.ok', ['stamp' => $stamp, 'seconds' => $seconds, 'tables' => count($expected)]);
            $this->notifier->send('Yedek geri yükleme provası ✓', $summary, 'white_check_mark', 'backup:drill-ok', 6 * 24 * 3600);

            return ['status' => 'ok', 'stamp' => $stamp, 'seconds' => $seconds, 'tables' => count($expected), 'rows' => $rows];
        } catch (Throwable $e) {
            $error = $e->getMessage();
            Log::error('backup.restore_drill.failed', ['error' => $error]);
            $this->notifier->send('Yedek geri yükleme provası BAŞARISIZ', "Off-site yedek açılamadı ya da eksik: {$error}", 'rotating_light', 'backup:drill-failed', 6 * 3600);

            return ['status' => 'failed', 'error' => $error];
        } finally {
            @unlink($encrypted);
            @unlink($plain);
            if ($created) {
                try {
                    $this->scratch->drop($scratchName);
                } catch (Throwable $e) {
                    Log::error('backup.restore_drill.drop_failed', ['database' => $scratchName, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    private function decrypt(string $encrypted, string $plain): void
    {
        $result = Process::timeout(1800)->run([
            'age', '--decrypt', '--identity', $this->config->identityFile(), '--output', $plain, $encrypted,
        ]);

        if (! $result->successful() || ! is_file($plain) || filesize($plain) === 0) {
            throw new BackupException('age çözme başarısız: '.trim((string) strtok($result->errorOutput() ?: 'çıktı yok', "\n")));
        }
    }

    private function pgRestore(string $database, string $plain): void
    {
        $db = config('database.connections.pgsql');

        $result = Process::timeout(3600)
            ->env(['PGPASSWORD' => (string) ($db['password'] ?? '')])
            ->run([
                'pg_restore',
                '--no-owner',
                '--no-privileges',
                '--exit-on-error',
                '--host='.$db['host'],
                '--port='.$db['port'],
                '--username='.$db['username'],
                '--dbname='.$database,
                $plain,
            ]);

        if (! $result->successful()) {
            throw new BackupException('pg_restore başarısız: '.trim((string) strtok($result->errorOutput() ?: 'çıktı yok', "\n")));
        }
    }
}
