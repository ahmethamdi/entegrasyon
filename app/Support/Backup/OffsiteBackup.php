<?php

declare(strict_types=1);

namespace App\Support\Backup;

use App\Support\Observability\OpsNotifier;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * S13 · gece off-site yedeği: döküm → şifrele → S3 → saklama.
 *
 *  1. Tutarlı an (`DatabaseSnapshot`): satır sayıları + `pg_dump --snapshot`.
 *  2. `age` ile şifreleme (kimlik dosyası; düz döküm diskte ANCAK şifreleme
 *     süresince durur ve `finally`'de silinir).
 *  3. Şifreli döküm + manifest (tablo => satır sayısı, sha256) S3'e.
 *  4. Saklama (`RetentionPolicy`): yalnız bizim desenimize uyan anahtarlar.
 *
 * Yerel 03:30 dökümü (sunucu cron'u) DURUR: hızlı geri yükleme için o,
 * sunucu kaybına karşı bu. İkisi birbirinin yerine geçmez.
 *
 * YAPILANDIRILMAMIŞSA: iş çalışmaz, 34Devs'e "off-site yedek yapılandırılmadı"
 * gider (günde bir). Sessizce "yedek yok" kalınmaz.
 */
final class OffsiteBackup
{
    public function __construct(
        private readonly BackupConfig $config,
        private readonly DatabaseSnapshot $snapshot,
        private readonly HealthcheckPinger $pinger,
        private readonly OpsNotifier $notifier,
        private readonly RetentionPolicy $retention = new RetentionPolicy,
    ) {}

    /** @return array{status: 'ok'|'not_configured'|'failed', key?: string, bytes?: int, tables?: int, rows?: int, pruned?: int, error?: string, missing?: list<string>} */
    public function run(?DateTimeImmutable $now = null): array
    {
        $missing = $this->config->missing();
        if ($missing !== []) {
            $this->notifier->send(
                'Off-site yedek yapılandırılmadı',
                'Gece yedeği sunucu dışına ALINMIYOR. Eksik: '.implode(', ', $missing).'. Kurulum: docs/RUNBOOK.md → "Off-site yedek kurulumu".',
                'rotating_light',
                'backup:not-configured',
                23 * 3600,
            );
            Log::warning('backup.offsite.not_configured', ['missing' => $missing]);

            return ['status' => 'not_configured', 'missing' => $missing];
        }

        $now ??= new DateTimeImmutable;
        $keys = new BackupKeys($this->config->prefix());
        $stamp = $keys->stamp($now);
        $hc = $this->config->healthcheckUrl();
        $this->pinger->ping($hc, 'start');

        $dir = $this->workDir();
        $plain = "{$dir}/34pazar-{$stamp}.dump";
        $encrypted = "{$plain}.age";

        try {
            $manifest = $this->snapshot->capture(function (string $snapshotId, array $counts) use ($plain, $stamp, $now): array {
                $this->pgDump($snapshotId, $plain);

                return [
                    'version' => 1,
                    'stamp' => $stamp,
                    'created_at' => $now->format(DATE_ATOM),
                    'database' => (string) config('database.connections.pgsql.database'),
                    'tables' => $counts,
                    'total_rows' => array_sum($counts),
                ];
            });

            $this->encrypt($plain, $encrypted);
            @unlink($plain);

            $manifest['dump_key'] = $keys->dump($stamp);
            $manifest['sha256'] = hash_file('sha256', $encrypted);
            $manifest['bytes'] = (int) filesize($encrypted);

            $s3 = new S3Client($this->config);
            $s3->put($keys->dump($stamp), $encrypted);
            // Manifest EN SON yüklenir: manifesti olan yedek "tam" sayılır.
            // Döküm yarıda kalırsa manifest yoktur ve prova onu seçmez.
            $s3->putString($keys->manifest($stamp), (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'application/json');

            $pruned = $this->prune($s3, $keys);

            $summary = sprintf('%s · %s · %d tablo · %d satır · %d eski yedek silindi',
                $keys->dump($stamp), self::humanBytes($manifest['bytes']), count($manifest['tables']), $manifest['total_rows'], $pruned);
            Log::info('backup.offsite.ok', ['key' => $keys->dump($stamp), 'bytes' => $manifest['bytes'], 'pruned' => $pruned]);
            $this->pinger->ping($hc, '', $summary);

            return [
                'status' => 'ok',
                'key' => $keys->dump($stamp),
                'bytes' => $manifest['bytes'],
                'tables' => count($manifest['tables']),
                'rows' => $manifest['total_rows'],
                'pruned' => $pruned,
            ];
        } catch (Throwable $e) {
            $error = $e->getMessage();
            Log::error('backup.offsite.failed', ['error' => $error]);
            $this->pinger->ping($hc, 'fail', $error);
            $this->notifier->send('Off-site yedek BAŞARISIZ', "Gece yedeği sunucu dışına alınamadı: {$error}", 'rotating_light', 'backup:failed', 6 * 3600);

            return ['status' => 'failed', 'error' => $error];
        } finally {
            @unlink($plain);
            @unlink($encrypted);
        }
    }

    private function pgDump(string $snapshotId, string $target): void
    {
        $db = config('database.connections.pgsql');

        $result = Process::timeout(1800)
            ->env(['PGPASSWORD' => (string) ($db['password'] ?? '')])
            ->run([
                'pg_dump',
                '--format=custom',
                '--snapshot='.$snapshotId,
                '--host='.$db['host'],
                '--port='.$db['port'],
                '--username='.$db['username'],
                '--dbname='.$db['database'],
                '--file='.$target,
            ]);

        if (! $result->successful() || ! is_file($target) || filesize($target) === 0) {
            throw new BackupException('pg_dump başarısız: '.self::firstLine($result->errorOutput()));
        }
    }

    private function encrypt(string $plain, string $encrypted): void
    {
        $result = Process::timeout(1800)->run([
            'age', '--encrypt', '--identity', $this->config->identityFile(), '--output', $encrypted, $plain,
        ]);

        if (! $result->successful() || ! is_file($encrypted) || filesize($encrypted) === 0) {
            throw new BackupException('age şifreleme başarısız: '.self::firstLine($result->errorOutput()));
        }
    }

    private function prune(S3Client $s3, BackupKeys $keys): int
    {
        $groups = $keys->group($s3->listKeys($this->config->prefix()));

        // Yalnız TAM yedekler (manifesti olan) saklama hesabına girer; yarım
        // kalmış bir döküm en yeni yedek sayılıp tam olanı sildirmesin.
        $complete = array_map(static fn (array $g) => $g['at'], array_filter($groups, static fn (array $g) => $g['dump'] && $g['manifest']));

        $deleted = 0;

        // Yarım kalmış çiftler (yükleme ortada koptu): 2 günden eskiyse temizlenir,
        // yoksa her kopuş bucket'ta sonsuza kadar sahipsiz bir dosya bırakırdı.
        $cutoff = (new DateTimeImmutable)->modify('-2 days');
        foreach ($groups as $stamp => $g) {
            if (($g['dump'] xor $g['manifest']) && $g['at'] < $cutoff) {
                $s3->delete($g['dump'] ? $keys->dump((string) $stamp) : $keys->manifest((string) $stamp));
                $deleted++;
            }
        }

        foreach ($this->retention->prune($complete) as $stamp) {
            $s3->delete($keys->manifest($stamp));
            $s3->delete($keys->dump($stamp));
            $deleted++;
        }

        return $deleted;
    }

    private function workDir(): string
    {
        $dir = rtrim($this->config->workDir(), '/');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new BackupException("Çalışma klasörü açılamadı: {$dir}");
        }
        @chmod($dir, 0700);

        return $dir;
    }

    private static function firstLine(string $text): string
    {
        $line = trim(strtok($text, "\n") ?: '');

        return $line === '' ? 'çıktı yok' : mb_substr($line, 0, 300);
    }

    public static function humanBytes(int $bytes): string
    {
        return $bytes >= 1_048_576 ? round($bytes / 1_048_576, 1).' MB' : round($bytes / 1024).' KB';
    }
}
