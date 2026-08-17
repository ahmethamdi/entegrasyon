<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Console;

use App\Domain\Messaging\Support\DetectUnconsumedEvents;
use Illuminate\Console\Command;

/**
 * Seviye 1 bütünlük taramasının komut kabuğu.
 *
 * Mimari Karar Dokümanı v2.2 · §6, §15 · zamanlanmış işler.
 * Her 10 dakikada, maintenance kuyruğunda.
 *
 * Mantık DetectUnconsumedEvents'te yaşar; bu sınıf yalnızca seçenekleri
 * çevirir. Ayrım OutboxRelay / OutboxRelayCommand ile aynı gerekçeye
 * dayanır: tarama testten ve zamanlayıcıdan doğrudan çağrılabilir olmalıdır.
 */
final class DetectUnconsumedEventsCommand extends Command
{
    protected $signature = 'outbox:detect-unconsumed
        {--minutes=5 : Bu süreden eski yayınlar tüketilmemiş sayılır}
        {--limit=500 : Tur başına olay sayısı}';

    protected $description = 'Yayınlanmış ama tüketilmemiş outbox olaylarını yeniden yayına alır';

    public function handle(DetectUnconsumedEvents $scan): int
    {
        $found = $scan->run(
            staleAfterSeconds: max(1, (int) $this->option('minutes')) * 60,
            limit: max(1, (int) $this->option('limit')),
        );

        $this->line((string) $found->count());

        return self::SUCCESS;
    }
}
