<?php

declare(strict_types=1);

namespace App\Domain\Sync\Console;

use App\Domain\Sync\Support\PollChannelBatches;
use Illuminate\Console\Command;

/**
 * Asenkron kanal toplu işlerinin sonucunu okur.
 *
 * İNCE KABUK: mantık `PollChannelBatches` içinde. `Command::run()`
 * rezerve imzadır.
 *
 * BEŞ DAKİKA: kanallar stok/fiyat işini dakikalar içinde bitirir (en geç
 * 4 saat — Pazarama, Çiçeksepeti). Dakikalık koşmak aynı bekleyen işi beş
 * kat sorar; saatlik koşmak ise 4 saatlik pencerede yalnız dört şans
 * bırakır ve red satıcıya saatler sonra görünürdü.
 */
final class PollChannelBatchesCommand extends Command
{
    protected $signature = 'sync:poll-batches';

    protected $description = 'Kanal toplu işlerinin (stok/fiyat) satır sonuçlarını okur ve listing’lere yazar';

    public function handle(PollChannelBatches $poller): int
    {
        $counts = $poller->sweep();

        $this->info(sprintf(
            'Toplu iş turu bitti: %d tamamlandı · %d bekliyor · %d süresi doldu · %d hata.',
            $counts['completed'] ?? 0,
            $counts['pending'] ?? 0,
            $counts['expired'] ?? 0,
            $counts['error'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
