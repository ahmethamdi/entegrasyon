<?php

declare(strict_types=1);

namespace App\Domain\Channels\Console;

use App\Domain\Channels\Support\TokenRefresher;
use Illuminate\Console\Command;

/**
 * Token yenileme taramasının komut kabuğu.
 *
 * V3.0 · §03 · Delta 3 · §20 · P0-5.
 *
 * Mantık `TokenRefresher`'da yaşar; bu sınıf yalnızca seçenekleri çevirir.
 * Ayrım `PruneApiCallsCommand` / `PruneApiCalls` ile aynı gerekçeye dayanır
 * ve ZORUNLUDUR: `Command::run()` REZERVE İMZADIR — tarama sınıfı Command'dan
 * türeyip kendi `run()`'ını tanımlasa fatal error verir.
 *
 * ⚠️ ZAMANLAMA `routes/console.php` İÇİNDE ve `withoutOverlapping` ZORUNLUDUR:
 * paralel iki tur aynı bağlantıyı yenilerse kanal ilk token'ı iptal eder
 * (Etsy ve eBay'de refresh token TEK KULLANIMLIKTIR). İkinci koruma katmanı
 * `FOR UPDATE SKIP LOCKED`, `TokenRefresher` içinde.
 */
final class RefreshExpiringTokensCommand extends Command
{
    protected $signature = 'credentials:refresh
        {--limit=100 : Tur başına en fazla kaç bağlantı yenilensin}';

    protected $description = 'Süresi dolmak üzere olan kanal token\'larını yeniler';

    public function handle(TokenRefresher $refresher): int
    {
        $result = $refresher->run(limit: max(1, (int) $this->option('limit')));

        $this->line(sprintf(
            'refreshed=%d failed=%d skipped=%d',
            $result['refreshed'],
            $result['failed'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
