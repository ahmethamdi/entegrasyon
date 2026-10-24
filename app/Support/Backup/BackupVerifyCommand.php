<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Illuminate\Console\Command;

/**
 * S13 · haftalık geri yükleme provası. Zamanlaması routes/console.php.
 */
final class BackupVerifyCommand extends Command
{
    protected $signature = 'backup:verify';

    protected $description = 'En yeni off-site yedeği geçici veritabanına geri yükler, satır sayılarını manifestle karşılaştırır, siler (S13)';

    public function handle(RestoreDrill $drill): int
    {
        $result = $drill->run();

        if ($result['status'] === 'ok') {
            $this->info(sprintf('açıldı: %s · %d tablo · %d satır · %.1f sn', $result['stamp'], $result['tables'], $result['rows'], $result['seconds']));

            return self::SUCCESS;
        }

        $this->error($result['status'] === 'not_configured'
            ? 'yapılandırılmadı: '.implode(', ', $result['missing'])
            : 'başarısız: '.$result['error']);

        return self::FAILURE;
    }
}
