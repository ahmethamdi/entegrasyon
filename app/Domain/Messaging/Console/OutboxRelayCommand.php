<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Console;

use App\Domain\Messaging\Support\OutboxRelay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Outbox relay süreci — ayrı supervisor altında sürekli çalışır.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Outbox relay, §15.
 *
 * Bu bir KUYRUK İŞİ DEĞİLDİR: outbox'ın varlık nedeni Redis'e güvenmemektir,
 * dolayısıyla yayınlayıcının kendisi Redis'e bağımlı olamaz. Süreç
 * veritabanını yoklar ve yalnızca yayın anında kuyruğa dokunur.
 *
 * Birden fazla kopya güvenle çalıştırılabilir: FOR UPDATE SKIP LOCKED
 * sayesinde ikinci süreç birincinin aldığı satırları atlar.
 */
final class OutboxRelayCommand extends Command
{
    protected $signature = 'outbox:relay
        {--interval=200 : Turlar arası bekleme (ms)}
        {--batch=100 : Tur başına olay sayısı}
        {--once : Tek tur çalış ve çık}';

    protected $description = 'Yayınlanmamış outbox olaylarını kuyruğa aktarır';

    public function handle(OutboxRelay $relay): int
    {
        $batchSize = max(1, (int) $this->option('batch'));
        $intervalMicroseconds = max(0, (int) $this->option('interval')) * 1000;

        if ($this->option('once')) {
            $this->line((string) $relay->run($batchSize));

            return self::SUCCESS;
        }

        $this->info("Outbox relay başladı (parti {$batchSize}).");

        while (true) {
            try {
                $published = $relay->run($batchSize);
            } catch (\Throwable $e) {
                // Tek turun hatası süreci düşürmez; olaylar yayınlanmamış
                // kalır ve sonraki tur onları yeniden alır.
                Log::error('outbox.relay_failed', ['error' => $e->getMessage()]);

                usleep($intervalMicroseconds);

                continue;
            }

            // Parti dolduysa bekleme: geride birikmiş iş var demektir.
            if ($published < $batchSize) {
                usleep($intervalMicroseconds);
            }
        }
    }
}
