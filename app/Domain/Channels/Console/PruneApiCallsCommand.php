<?php

declare(strict_types=1);

namespace App\Domain\Channels\Console;

use App\Domain\Channels\Support\PruneApiCalls;
use Illuminate\Console\Command;

/**
 * api_calls saklama taramasının komut kabuğu.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · saklama politikası, §15 · zamanlanmış
 * işler. Günlük, maintenance kuyruğunda.
 *
 * Mantık PruneApiCalls'ta yaşar; bu sınıf yalnızca seçenekleri çevirir.
 * Ayrım OutboxRelay / OutboxRelayCommand ile aynı gerekçeye dayanır ve
 * ZORUNLUDUR: `Command::run()` REZERVE İMZADIR — tarama sınıfı Command'dan
 * türeyip kendi `run()`'ını tanımlasa fatal error verir.
 */
final class PruneApiCallsCommand extends Command
{
    protected $signature = 'api-calls:prune
        {--chunk=5000 : Tek DELETE ile silinecek satır sayısı}
        {--max=500000 : Tur başına üst sınır}';

    protected $description = 'Süresi geçmiş api_calls satırlarını partileyerek siler';

    public function handle(PruneApiCalls $scan): int
    {
        $deleted = $scan->run(
            chunkSize: max(1, (int) $this->option('chunk')),
            maxRows: max(1, (int) $this->option('max')),
        );

        $this->line((string) $deleted);

        return self::SUCCESS;
    }
}
