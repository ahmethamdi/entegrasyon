<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use Illuminate\Console\Command;

/**
 * Ziyaret sayaçlarının saklama taraması — 90 günden eski günleri siler.
 *
 * Saklama süresi gizlilik metninde yazılı; silen bir şey olmadan o süre
 * yalnızca bir NİYETTİR (api-calls:prune ile aynı gerekçe). Zamanlaması
 * routes/console.php içinde.
 */
final class PruneVisitStatsCommand extends Command
{
    protected $signature = 'visits:prune {--days=90 : Bu kadar günden eski satırlar silinir}';

    protected $description = 'Süresi geçmiş günlük ziyaret sayaçlarını siler';

    public function handle(VisitStats $stats): int
    {
        $this->line((string) $stats->prune(max(1, (int) $this->option('days'))));

        return self::SUCCESS;
    }
}
