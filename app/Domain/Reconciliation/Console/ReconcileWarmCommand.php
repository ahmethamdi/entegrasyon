<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Console;

use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Reconciliation\Support\ReconcileActiveConnections;
use Illuminate\Console\Command;

/**
 * §10 · ILIK KATMAN mutabakatı — SAATLİK.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · bütçe tablosu, §15 · zamanlanmış işler.
 *
 * İNCE KABUK: mantık `ReconcileActiveConnections` içinde. `Command::run()`
 * rezerve imzadır ve `Command`'dan türeyen sınıf kendi `run(...)` metodunu
 * tanımlayamaz.
 *
 * KAPSAM (§10): son 24 saatte satış olan, 24 saattir bakılmamış listing'ler
 * — bağlantı başına en fazla 300.
 *
 * SICAK KATMANIN KOPYASI DEĞİLDİR: pencereler genişler (30 dk → 24 saat,
 * 1 saat → 24 saat) ve bütçe altı katına çıkar. Sıcak katmanın dar
 * penceresine sığmayan satış — örneğin altı saat önce satılıp o turun
 * bütçesine giremeyen listing — yalnızca burada yakalanır.
 */
final class ReconcileWarmCommand extends Command
{
    protected $signature = 'reconcile:warm {--budget= : Bağlantı başına listing sınırı (varsayılan §10 tablosu)}';

    protected $description = 'Ilık katman stok mutabakatı — geniş kapsam, saatlik';

    public function handle(ReconcileActiveConnections $sweeper): int
    {
        $budget = $this->option('budget');

        $processed = $sweeper->sweep(
            scope: ReconciliationScope::WARM,
            budget: $budget === null ? null : (int) $budget,
        );

        $this->info("Ilık mutabakat turu bitti: {$processed} bağlantı.");

        return self::SUCCESS;
    }
}
