<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Console;

use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Takılı gelen mesajları kurtarır — dakikalık tarama.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Bekleyen mesaj kurtarma, §17 · P1.
 *
 * KAPATILAN BOŞLUK: kayıt ile kuyruğa atma arasında süreç ölürse mesaj
 * sonsuza kadar pending kalır ve SİPARİŞ SESSİZCE KAYBOLUR. Webhook 202
 * döndüğü için kanal da yeniden göndermez.
 *
 * Bu iş idempotenttir: aynı mesaj birden çok kez kuyruğa girse bile
 * ProcessInboxMessage koşullu durum geçişiyle tek işleyiciyi seçer.
 *
 * inbox_pending_idx kısmi indeksi bu taramayı besler; tarama yalnızca
 * bekleyen satırlara dokunur.
 */
final class RecoverPendingInbox extends Command
{
    protected $signature = 'inbox:recover
        {--minutes=2 : Bu süreden eski bekleyen mesajlar alınır}
        {--limit=200 : Tur başına mesaj sayısı}';

    protected $description = 'Kuyruğa hiç girmemiş bekleyen inbox mesajlarını yeniden dağıtır';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));

        // Tarama TÜM kiracıları görmek zorundadır; sistem erişimi açıktır.
        $stuck = TenantContext::runAsSystem(fn () => InboxMessage::query()
            ->where('status', 'pending')
            ->where('received_at', '<', now()->subMinutes($minutes))
            ->orderBy('received_at')
            ->limit($limit)
            ->get(['id', 'tenant_id']));

        foreach ($stuck as $message) {
            ProcessInboxMessage::dispatch($message->tenant_id, $message->id)
                ->onQueue('inbox:process');
        }

        if ($stuck->isNotEmpty()) {
            Log::info('inbox.recovered', ['count' => $stuck->count()]);
        }

        $this->line((string) $stuck->count());

        return self::SUCCESS;
    }
}
