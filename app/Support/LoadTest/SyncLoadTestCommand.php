<?php

declare(strict_types=1);

namespace App\Support\LoadTest;

use Illuminate\Console\Command;

/**
 * `loadtest:sync` — senkron hattı yük testi (§11 · "yük testi").
 *
 * İNCE KABUK: mantık `SyncPipelineLoadTest` içinde. `Command::run()`
 * rezerve imzadır ve `Command`'dan türeyen sınıf kendi `run(...)` metodunu
 * tanımlayamaz — `OutboxRelay`/`OutboxRelayCommand` ayrımının aynısı.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ÜRETİMDE ONAY İSTER
 * ─────────────────────────────────────────────────────────────────────
 * Komut kiracı ve stok hareketi ÜRETİR. Üretim veritabanında çalıştırmak
 * gerçek kiracıların yanına test verisi koyar, outbox kuyruğunu yükler ve
 * gerçek senkron işlerini geciktirir. `--force` olmadan üretimde onay
 * ister; `ScheduledScansTest`'in korumadığı bir yol olduğu için kapı
 * komutun KENDİSİNDEDİR.
 *
 * ZAMANLANMAZ ve bu bilinçlidir: yük testi bir ÖLÇÜM aracıdır, bakım
 * turu değil. `routes/console.php`'ye eklenseydi her gece kendiliğinden
 * veri üretir ve kuyruğu meşgul ederdi.
 */
final class SyncLoadTestCommand extends Command
{
    protected $signature = 'loadtest:sync
        {--tenants=3 : Kaç kiracı üretilsin}
        {--variants=50 : Kiracı başına varyant}
        {--movements=1000 : Toplam stok hareketi}
        {--force : Üretimde onay sorma}';

    protected $description = 'Senkron hattı yük testi — ledger, outbox relay ve fan-out ölçümü';

    public function handle(SyncPipelineLoadTest $loadTest): int
    {
        if ($this->getLaravel()->isProduction() && ! $this->option('force')) {
            $this->warn('ÜRETİM ORTAMI: bu komut kiracı ve stok hareketi ÜRETİR.');

            if (! $this->confirm('Yine de çalıştırılsın mı?', default: false)) {
                $this->info('İptal edildi.');

                return self::SUCCESS;
            }
        }

        $tenants = max(1, (int) $this->option('tenants'));
        $variants = max(1, (int) $this->option('variants'));
        $movements = max(1, (int) $this->option('movements'));

        $result = $loadTest->run(
            tenants: $tenants,
            variantsPerTenant: $variants,
            movements: $movements,
            progress: fn (string $message) => $this->line($message),
        );

        $this->renderReport($result);

        // BÜTÜNLÜK BOZUKSA KOMUT BAŞARISIZ DÖNER.
        //
        // Yük testinin en değerli çıktısı hız değil bu kontroldür ve
        // `SUCCESS` dönmek onu bir günlük satırına indirgerdi: CI'da veya
        // bir kabuk betiğinde koşarsa kimse fark etmezdi.
        return ($result['integrity']['ledger_matches_projection'] ?? false)
            ? self::SUCCESS
            : self::FAILURE;
    }

    /** @param array<string, mixed> $result */
    private function renderReport(array $result): void
    {
        $production = $result['production'];
        $relay = $result['relay'];
        $fanOut = $result['fan_out'];
        $integrity = $result['integrity'];

        $this->newLine();
        $this->info('═══ ÜRETİM (ledger) ═══');
        $this->table(['Ölçüm', 'Değer'], [
            ['hareket', $production['movements']],
            ['süre (sn)', $production['seconds']],
            ['hareket/sn', $production['per_second']],
            ['gecikme p50 (ms)', $production['p50_ms']],
            ['gecikme p95 (ms)', $production['p95_ms']],
            ['gecikme p99 (ms)', $production['p99_ms']],
        ]);

        $this->info('═══ YAYIN (outbox relay) ═══');
        $this->table(['Ölçüm', 'Değer'], [
            // §11'in `outbox_consume_gap` metriğinin ta kendisi.
            ['kuyruk derinliği (tepe)', $relay['queue_depth_peak']],
            ['yayınlanan olay', $relay['published']],
            ['süre (sn)', $relay['seconds']],
            ['olay/sn', $relay['per_second']],
            ['yayın gecikmesi p95 (sn)', $relay['publish_lag_p95_s']],
        ]);

        $this->info('═══ FAN-OUT (olay → operasyon) ═══');
        $this->table(['Ölçüm', 'Değer'], [
            ['yayınlanmış olay', $fanOut['events']],
            ['açılan operasyon', $fanOut['operations']],
            ['operasyon/olay', $fanOut['ratio']],
        ]);

        $this->newLine();

        if ($integrity['ledger_matches_projection'] ?? false) {
            $this->info('✓ BÜTÜNLÜK: on_hand = Σ on_hand_delta — yük altında korundu.');

            return;
        }

        $this->error(
            '✗ BÜTÜNLÜK BOZUK: '.($integrity['mismatched_rows'] ?? '?').
            ' satırda on_hand ≠ Σ on_hand_delta. Kilit sırası veya '.
            'transaction sınırı yanlış — bu, hız sorunundan ÖNCE gelir.'
        );
    }
}
