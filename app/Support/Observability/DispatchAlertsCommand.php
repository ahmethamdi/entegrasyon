<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Illuminate\Console\Command;

/**
 * Uyarı gönderim turunun komut kabuğu.
 *
 * Mimari Karar Dokümanı v2.2 · §11 ("eşik aşımında e-posta"), §12
 * ("günlük özet: kiracı başına 10'dan fazla ölü iş → e-posta").
 *
 * Mantık `DispatchAlerts`'te yaşar; bu sınıf yalnızca sonucu basar —
 * `CaptureMetricsCommand` ile aynı ayrım ve aynı gerekçe
 * (`Command::run()` REZERVE İMZADIR).
 *
 * KOMUT `bootstrap/app.php` İÇİNDE AÇIKÇA KAYDEDİLMELİ ve
 * `routes/console.php` İÇİNDE ZAMANLANMALIDIR — ikisi AYRI koşuldur.
 * Domain/Support klasörlerindeki komutlar otomatik keşfedilmez; kayıt
 * olmadan `schedule:list` kusursuz görünür ama artisan komutu bulamaz.
 * `ScheduledScansTest` ikisini AYRI AYRI doğrular (bu boşluk projede
 * gerçekten yaşandı: `inbox:recover` yazılmış, testleri yeşil ve HİÇ
 * zamanlanmamıştı).
 */
final class DispatchAlertsCommand extends Command
{
    protected $signature = 'alerts:dispatch';

    protected $description = 'Eşiği aşan metrikleri e-posta uyarısına çevirir (§11 · §12)';

    public function handle(DispatchAlerts $scan): int
    {
        $result = $scan->run();

        $this->line(sprintf(
            'gönderildi: %d · bastırıldı: %d · alıcısız: %d',
            $result['sent'],
            $result['suppressed'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
