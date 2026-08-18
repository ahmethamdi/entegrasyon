<?php

declare(strict_types=1);

namespace App\Domain\Sync\Console;

use App\Domain\Sync\Support\TrackApprovalForConnections;
use Illuminate\Console\Command;

/**
 * §13 · Faz 2 · onay durumu turu.
 *
 * İNCE KABUK: mantık `TrackApprovalForConnections` içinde.
 * `Command::run()` rezerve imzadır.
 *
 * SAATLİK, DAKİKALIK DEĞİL: Trendyol'un onay süreci saatler sürer.
 * Dakikalık yoklama kotayı tüketir ve hiçbir şey kazandırmaz — satıcı
 * ürününü zaten dakikalar içinde beklemiyordur.
 */
final class TrackApprovalStatusCommand extends Command
{
    protected $signature = 'approval:track';

    protected $description = 'Kanaldaki onay durumlarını okur ve listing’lere yazar';

    public function handle(TrackApprovalForConnections $sweeper): int
    {
        $result = $sweeper->sweep();

        $this->info(sprintf(
            'Onay turu bitti: %d bağlantı · %d onaylandı · %d reddedildi.',
            $result['connections'],
            $result['approved'],
            $result['rejected'],
        ));

        return self::SUCCESS;
    }
}
