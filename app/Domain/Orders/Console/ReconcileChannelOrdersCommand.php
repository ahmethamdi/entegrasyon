<?php

declare(strict_types=1);

namespace App\Domain\Orders\Console;

use App\Domain\Orders\Support\ReconcileChannelOrders;
use Illuminate\Console\Command;

/**
 * Webhook kanallarında kaçan siparişleri kurtarır (P0-3). Zamanlaması
 * routes/console.php içinde (30 dk).
 */
final class ReconcileChannelOrdersCommand extends Command
{
    protected $signature = 'reconcile:orders';

    protected $description = 'Webhook kanallarında kaybolan siparişleri kanaldan okuyup içeri alır';

    public function handle(ReconcileChannelOrders $reconcile): int
    {
        $summary = $reconcile->run();

        $this->line("connections={$summary['connections']} recovered={$summary['recovered']} failed={$summary['failed']}");

        return self::SUCCESS;
    }
}
