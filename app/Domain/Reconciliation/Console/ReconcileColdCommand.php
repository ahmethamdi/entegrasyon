<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Console;

use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Reconciliation\Support\ReconcileActiveConnections;
use Illuminate\Console\Command;

/**
 * §10 · SOĞUK KATMAN mutabakatı — GÜNLÜK, örneklemeli uzun kuyruk.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · bütçe tablosu, §15 · zamanlanmış işler.
 *
 * İNCE KABUK: mantık `ReconcileActiveConnections` içinde. `Command::run()`
 * rezerve imzadır ve `Command`'dan türeyen sınıf kendi `run(...)` metodunu
 * tanımlayamaz.
 *
 * KAPSAM (§10): rastgele örneklem — uzun kuyruk. Bütçe aktif listing'lerin
 * %2'si, ÜST SINIR 500.
 *
 * TEK BU KATMAN TETİKLEYİCİSİZ SÜRÜKLENMEYİ GÖRÜR: sıcak ve ılık katmanların
 * dört sebep sorgusu bir OLAY arar (taze satış, geçici hata, bekleyen iş,
 * sürüklenme geçmişi). Satmayan, hata almamış, bekleyen işi olmayan bir
 * listing kanal panelinden elle değiştirildiğinde o dört sorgunun HİÇBİRİNE
 * takılmaz ve sürüklenmesi sonsuza kadar görünmez. Bu tur onu yakalar.
 *
 * `--budget` YALNIZCA ÜST SINIRI değiştirir; oransal hesap her koşulda
 * uygulanır. Sabit bir sayı geçmek bile küçük katalogda tam tarama üretmez.
 */
final class ReconcileColdCommand extends Command
{
    protected $signature = 'reconcile:cold {--budget= : Örneklem üst sınırı (varsayılan §10 tablosu: 500)}';

    protected $description = 'Soğuk katman stok mutabakatı — örneklemeli uzun kuyruk, günlük';

    public function handle(ReconcileActiveConnections $sweeper): int
    {
        $budget = $this->option('budget');

        $processed = $sweeper->sweep(
            scope: ReconciliationScope::COLD,
            budget: $budget === null ? null : (int) $budget,
        );

        $this->info("Soğuk mutabakat turu bitti: {$processed} bağlantı.");

        return self::SUCCESS;
    }
}
