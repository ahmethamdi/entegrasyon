<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Illuminate\Console\Command;

/**
 * S7 · işletme izleme turu. Zamanlaması routes/console.php (5 dk).
 */
final class OpsWatchCommand extends Command
{
    protected $signature = 'ops:watch';

    protected $description = 'Kuyruk, devre kesici, 429 ve ölü işlemleri canlı ölçer; eşik aşımını 34Devs\'e bildirir (S7)';

    public function handle(OpsWatch $watch): int
    {
        $result = $watch->run();

        $this->line(sprintf('bulgu: %d · gönderildi: %d', $result['found'], $result['sent']));

        return self::SUCCESS;
    }
}
