<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Illuminate\Console\Command;

/**
 * S13 · gece off-site yedeği. Zamanlaması routes/console.php.
 */
final class BackupOffsiteCommand extends Command
{
    protected $signature = 'backup:offsite';

    protected $description = 'Veritabanını tutarlı anda döker, age ile şifreler, S3\'e yükler ve saklama politikasını uygular (S13)';

    public function handle(OffsiteBackup $backup): int
    {
        $result = $backup->run();

        return match ($result['status']) {
            'ok' => $this->done(sprintf('yüklendi: %s · %s · %d tablo · %d eski yedek silindi',
                $result['key'], OffsiteBackup::humanBytes($result['bytes']), $result['tables'], $result['pruned'])),
            'not_configured' => $this->failed('yapılandırılmadı: '.implode(', ', $result['missing'])),
            default => $this->failed('başarısız: '.$result['error']),
        };
    }

    private function done(string $line): int
    {
        $this->info($line);

        return self::SUCCESS;
    }

    private function failed(string $line): int
    {
        $this->error($line);

        return self::FAILURE;
    }
}
