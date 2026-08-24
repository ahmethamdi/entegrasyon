<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Console;

use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Reconciliation\Support\ReconcileActiveConnections;
use App\Domain\Sync\Enums\SyncDomain;
use Illuminate\Console\Command;

/**
 * §9 · FİYAT ÇAKIŞMASI TURU — satıcının kanal panelinden yaptığı değişikliği
 * bulan TEK mekanizma.
 *
 * Mimari Karar Dokümanı v2.2 · §9 (domain başına çakışma politikası),
 * §10 (mutabakat akışı).
 *
 * İNCE KABUK: mantık `ReconcileActiveConnections` içinde — stok
 * komutlarıyla aynı kalıp.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN AYRI KOMUT — KATMAN DEĞİL DOMAIN AYRIMI
 * ─────────────────────────────────────────────────────────────────────
 * `reconcile:hot/warm/cold` üçü de STOK turudur ve aralarındaki fark
 * KATMANDIR (aday seçimi ve bütçe). Bu komutun farkı DOMAIN'dir: aynı beş
 * adımlı akışı fiyat için yürütür ve REPAIR adımını atlar (§9 · PRICE:
 * "üzerine yazma, kullanıcı seçer").
 *
 * Stok komutlarına bir `--domain` seçeneği eklenseydi zamanlama da ortak
 * olurdu; oysa iki domain FARKLI RİTİM ister (aşağıda).
 *
 * ─────────────────────────────────────────────────────────────────────
 * SAATLİK — STOK GİBİ BEŞ DAKİKADA BİR DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 * Ritim, sürüklenmenin BEDELİYLE ölçülür. Yanlış stok her satışta fazla
 * satış riski doğurur ve dakikalar içinde müşteri şikâyetine dönüşür; bu
 * yüzden sıcak katman beş dakikadadır.
 *
 * Fiyat çakışmasının bedeli farklıdır: satıcının kampanyası zaten kanalda
 * YÜRÜYOR ve tespit onu durdurmaz — bulunca yalnızca SORULUR. Bir saat
 * gecikmenin maliyeti, satıcının rozeti bir saat geç görmesidir. Beş
 * dakikada bir okunsaydı kanal kotası on iki katına çıkar ve o kota
 * stok turlarından çalınırdı.
 *
 * ILIK KAPSAM KULLANILIR (24 saatlik pencereler) ve bu ritimle tutarlıdır:
 * saatte bir koşan bir turun 30 dakikalık pencereye bakması, pencereler
 * arasında yarım saatlik KÖR NOKTA bırakırdı.
 *
 * ─────────────────────────────────────────────────────────────────────
 * `SupportsPricing` OLMAYAN KANAL SESSİZCE ATLANIR
 * ─────────────────────────────────────────────────────────────────────
 * Kapı `ReconcileActiveConnections` içinde ve `instanceof` ile okunur;
 * istisnaya bırakılsaydı her tur, desteklemeyen her bağlantı için bir uyarı
 * satırı yazar ve gerçek arızalar o gürültüde kaybolurdu.
 */
final class ReconcilePricesCommand extends Command
{
    protected $signature = 'reconcile:prices {--budget= : Bağlantı başına listing sınırı (varsayılan §10 tablosu)}';

    protected $description = 'Fiyat mutabakatı — kanaldaki fiyat değişikliğini çakışma olarak tespit eder';

    public function handle(ReconcileActiveConnections $sweeper): int
    {
        // Bütçe SEÇENEKTEN GELMEZSE katmanın kendi değeri kullanılır —
        // stok komutlarıyla aynı gerekçe: §10 bütçe tablosunun ikinci bir
        // kopyası burada yaşamamalı.
        $budget = $this->option('budget');

        $processed = $sweeper->sweep(
            scope: ReconciliationScope::WARM,
            budget: $budget === null ? null : (int) $budget,
            domain: SyncDomain::PRICE,
        );

        $this->info("Fiyat mutabakatı turu bitti: {$processed} bağlantı.");

        return self::SUCCESS;
    }
}
