<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Console;

use App\Domain\Reconciliation\Enums\ReconciliationScope;
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
    protected $signature = 'reconcile:hot {--budget= : Bağlantı başına listing sınırı (varsayılan §10 tablosu)}';

    protected $description = 'Sıcak katman stok mutabakatı — sürüklenme tespiti ve onarım';

    public function handle(ReconcileActiveConnections $sweeper): int
    {
        // Bütçe SEÇENEKTEN GELMEZSE katmanın kendi değeri kullanılır.
        // Varsayılanı burada `50` diye yazmak, §10 bütçe tablosunun ikinci
        // bir kopyasını üretirdi; tablo değişince biri güncellenir, öteki
        // sessizce eski sayıyı uygulardı.
        $budget = $this->option('budget');

        $processed = $sweeper->sweep(
            scope: ReconciliationScope::HOT,
            budget: $budget === null ? null : (int) $budget,
        );

        $this->info("Sıcak mutabakat turu bitti: {$processed} bağlantı.");

        return self::SUCCESS;
    }
}
