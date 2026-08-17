<?php

declare(strict_types=1);

namespace App\Domain\Sync\Console;

use App\Domain\Sync\Support\DetectStuckSyncOperations;
use Illuminate\Console\Command;

/**
 * Seviye 2 bütünlük taramasının komut kabuğu.
 *
 * Mimari Karar Dokümanı v2.2 · §6, §15 · zamanlanmış işler.
 * Her 5 dakikada, maintenance kuyruğunda.
 *
 * Mantık DetectStuckSyncOperations'ta yaşar; bu sınıf yalnızca seçenekleri
 * çevirir.
 */
final class DetectStuckSyncOperationsCommand extends Command
{
    protected $signature = 'sync:detect-stuck
        {--minutes=5 : Bu süreden eski hiç denenmemiş operasyonlar alınır}
        {--limit=500 : Tur başına operasyon sayısı}';

    protected $description = 'Yaratılmış ama worker hiç çalışmamış senkron operasyonlarını yeniden dağıtır';

    public function handle(DetectStuckSyncOperations $scan): int
    {
        $found = $scan->run(
            staleAfterSeconds: max(1, (int) $this->option('minutes')) * 60,
            limit: max(1, (int) $this->option('limit')),
        );

        $this->line((string) $found->count());

        return self::SUCCESS;
    }
}
