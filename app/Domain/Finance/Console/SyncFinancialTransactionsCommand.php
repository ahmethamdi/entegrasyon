<?php

declare(strict_types=1);

namespace App\Domain\Finance\Console;

use App\Domain\Finance\Actions\IngestFinancialTransactions;
use Illuminate\Console\Command;

/**
 * Kanalların finansal işlem defterini (Trendyol cari hesap ekstresi) içe alır
 * ve siparişlerle eşler. Tekrar çalıştırmak güvenlidir (idempotent).
 */
final class SyncFinancialTransactionsCommand extends Command
{
    protected $signature = 'finance:sync';

    protected $description = 'Kanal hakediş/kesinti işlemlerini ham olarak içe alır ve siparişlerle eşler';

    public function handle(IngestFinancialTransactions $ingest): int
    {
        $s = $ingest->run();

        $this->line("connections={$s['connections']} inserted={$s['inserted']} duplicates={$s['duplicates']} conflicts={$s['conflicts']} failed={$s['failed']}");

        return self::SUCCESS;
    }
}
