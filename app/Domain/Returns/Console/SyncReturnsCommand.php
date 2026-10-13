<?php

declare(strict_types=1);

namespace App\Domain\Returns\Console;

use App\Domain\Returns\Actions\IngestReturns;
use Illuminate\Console\Command;

/**
 * Kanalların iade taleplerini yoklar, kanonik kayda yazar ve kabul edilip
 * depoya döndüğü kanıtlanmış iadelerin stoğunu geri getirir. Dışarı
 * YAZMAZ; tekrar çalıştırmak güvenlidir (idempotent).
 */
final class SyncReturnsCommand extends Command
{
    protected $signature = 'returns:sync';

    protected $description = 'Kanal iade taleplerini içe alır ve stok iadesini uzlaştırır';

    public function handle(IngestReturns $ingest): int
    {
        $s = $ingest->run();

        $this->line("connections={$s['connections']} stored={$s['stored']} restored={$s['restored']} failed={$s['failed']}");

        return self::SUCCESS;
    }
}
