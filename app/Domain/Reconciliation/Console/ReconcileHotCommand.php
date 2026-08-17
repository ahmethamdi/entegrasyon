<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Console;

use App\Domain\Reconciliation\Support\ReconcileActiveConnections;
use Illuminate\Console\Command;

/**
 * §10 · sıcak katman mutabakatı — 5 dakikada bir.
 *
 * İNCE KABUK: mantık `ReconcileActiveConnections` içinde. `Command::run()`
 * rezerve imzadır ve `Command`'dan türeyen sınıf kendi `run(...)` metodunu
 * tanımlayamaz.
 *
 * Sıcak katman kapsamı (§10): son 30 dakikada satış olan, geçici hata almış,
 * bir saattir bekleyen listing'ler — bağlantı başına en fazla 50.
 */
final class ReconcileHotCommand extends Command
{
    protected $signature = 'reconcile:hot {--budget=50 : Bağlantı başına listing sınırı}';

    protected $description = 'Sıcak katman stok mutabakatı — sürüklenme tespiti ve onarım';

    public function handle(ReconcileActiveConnections $sweeper): int
    {
        $processed = $sweeper->sweep(scope: 'hot', budget: (int) $this->option('budget'));

        $this->info("Mutabakat turu bitti: {$processed} bağlantı.");

        return self::SUCCESS;
    }
}
