<?php

declare(strict_types=1);

namespace App\Domain\Channels\Certification;

use Illuminate\Console\Command;

/**
 * S15 · test taramasını yeniden üretir → resources/certification/test-coverage.json.
 *
 * Adaptör testi eklenince/değişince çalıştırılır; dosya güncel değilse
 * `ChannelCertificationContractTest` kırılır ve bu komutu söyler.
 */
final class ScanTestCoverageCommand extends Command
{
    protected $signature = 'certification:scan-tests {--check : Yazmadan karşılaştır; güncel değilse çıkış 1}';

    protected $description = 'Adaptör testlerini tarayıp kanal × yetenek MOCK_TESTED kanıtını üretir (S15)';

    public function handle(TestCoverageScanner $scanner): int
    {
        if (! is_dir(base_path('tests'))) {
            $this->error('tests/ klasörü yok (üretim imajında testler bulunmaz) — bu komut geliştirme ortamında koşar.');

            return self::FAILURE;
        }

        $coverage = $scanner->scan();
        $fresh = TestCoverageScanner::encode($coverage);
        $current = is_file(base_path(TestCoverageScanner::LEDGER)) ? (string) file_get_contents(base_path(TestCoverageScanner::LEDGER)) : '';

        if ($this->option('check')) {
            if ($fresh !== $current) {
                $this->error('test-coverage.json güncel değil: php artisan certification:scan-tests');

                return self::FAILURE;
            }
            $this->info('güncel');

            return self::SUCCESS;
        }

        $scanner->write($coverage);
        $cells = array_sum(array_map('count', $coverage));
        $this->info(sprintf('%d kanal, %d hücre testli → %s', count($coverage), $cells, TestCoverageScanner::LEDGER));

        return self::SUCCESS;
    }
}
