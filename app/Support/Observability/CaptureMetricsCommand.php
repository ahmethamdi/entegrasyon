<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Illuminate\Console\Command;

/**
 * Metrik toplama turunun komut kabuğu.
 *
 * Mimari Karar Dokümanı v2.2 · §15 · CaptureMetrics (saatlik,
 * maintenance kuyruğu), §11 · Ölçülecek metrikler.
 *
 * Mantık `CaptureMetrics`'te yaşar; bu sınıf yalnızca sonucu basar.
 * Ayrım ZORUNLUDUR: `Command::run()` REZERVE İMZADIR — tarama sınıfı
 * Command'dan türeyip kendi `run()`'ını tanımlasa fatal error verir.
 * Aynı ayrım `PruneApiCallsCommand` ve `OutboxRelayCommand`'da da var.
 */
final class CaptureMetricsCommand extends Command
{
    protected $signature = 'metrics:capture';

    protected $description = '§11 metriklerinin saatlik anlık görüntüsünü alır';

    public function handle(CaptureMetrics $scan): int
    {
        $this->line((string) $scan->run());

        return self::SUCCESS;
    }
}
