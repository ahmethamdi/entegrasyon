<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Console;

use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

    /** Bu süreden uzun `processing` kalan satırın işleyicisi ölmüştür. */
    public const ABANDONED_AFTER_MINUTES = 10;

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));

        $this->reclaimAbandoned();

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

    /**
     * Worker'ın yarıda bıraktığı `processing` satırlarını geri alır.
     *
     * ⚠️ KAPATILAN İKİNCİ BOŞLUK: işleyici satırı `processing` yaptıktan
     * sonra süreç ölürse (zaman aşımı, OOM, deploy) `catch` HİÇ çalışmaz
     * ve satır sonsuza kadar `processing` kalırdı — bu tarama yalnızca
     * `pending` topladığı için o sipariş HİÇ işlenmezdi.
     *
     * Eşik iş zaman aşımının (60 sn, `config/horizon.php`) çok üstündedir:
     * hâlâ çalışan bir işleyicinin satırı ELİNDEN ALINMAZ.
     *
     * Geçiş KOŞULLUDUR (`status = 'processing'` + eski `updated_at`): bu
     * arada işleyici bitirdiyse satır `processed`'tir ve dokunulmaz.
     * Bütçesi tükenmiş satır geri alınmaz, `failed` olur.
     */
    private function reclaimAbandoned(): void
    {
        $cutoff = now()->subMinutes(self::ABANDONED_AFTER_MINUTES);

        TenantContext::runAsSystem(function () use ($cutoff): void {
            $failed = DB::table('inbox_messages')
                ->where('status', 'processing')
                ->where('updated_at', '<', $cutoff)
                ->where('attempt_count', '>=', ProcessInboxMessage::MAX_ATTEMPTS)
                ->update([
                    'status' => 'failed',
                    'last_error' => 'İşleyici yarıda kaldı ve deneme bütçesi tükendi.',
                    'updated_at' => now(),
                ]);

            $reclaimed = DB::table('inbox_messages')
                ->where('status', 'processing')
                ->where('updated_at', '<', $cutoff)
                ->update(['status' => 'pending', 'updated_at' => now()]);

            if ($reclaimed > 0 || $failed > 0) {
                Log::warning('inbox.abandoned_reclaimed', [
                    'reclaimed' => $reclaimed,
                    'failed' => $failed,
                ]);
            }
        });
    }
}
