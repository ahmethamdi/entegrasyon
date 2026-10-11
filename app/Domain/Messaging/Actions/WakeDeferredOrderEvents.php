<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Actions;

use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sipariş alındı — onu bekleyen ertelenmiş iptal/iade olaylarını uyandırır.
 *
 * Mimari denetim P0-1. Erteleme takvimi tek başına da olayı sonunda işler;
 * bu sınıf yalnız BEKLEMEYİ kısaltır (dakikalar yerine hemen). Bu yüzden
 * kaçırdığı bir satır kaybolmaz:
 *
 *   · Ertelenen olay o an hâlâ `processing` ise (aynı anda işleniyor) bu
 *     UPDATE onu görmez; olay kendi takvimiyle bir dakika sonra işlenir.
 *   · Pencere dolup `expired` olmuş olay da uyandırılır: sipariş günler
 *     sonra (yeniden oynatmayla) gelse bile iptal/iade uygulanır.
 *
 * Uyandırılan olayın İŞLENMESİ idempotenttir (`order_events` çıpası +
 * kalan adete kırpma); iki kez uyandırılması zararsızdır.
 */
final class WakeDeferredOrderEvents
{
    /**
     * `expired`: pencere dolmuş, uygulanmamış olay. `processing` YOK: o an
     * işlenen satıra dokunulmaz. `processed` YOK: uygulanan olayın
     * `awaiting_order_ref`'i zaten temizlenir.
     */
    private const WAKEABLE = ['pending', 'failed', 'expired'];

    /** @return int uyandırılan mesaj sayısı */
    public function run(string $connectionId, string $externalOrderId, ?string $exceptMessageId = null): int
    {
        $tenantId = TenantContext::idOrFail();

        // `DB::table()` global scope'a tabi DEĞİLDİR — kiracı filtresi açık.
        $query = DB::table('inbox_messages')
            ->where('tenant_id', $tenantId)
            ->where('channel_connection_id', $connectionId)
            ->where('awaiting_order_ref', $externalOrderId)
            ->whereIn('status', self::WAKEABLE);

        if ($exceptMessageId !== null) {
            $query->where('id', '!=', $exceptMessageId);
        }

        $ids = $query->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        if ($ids === []) {
            return 0;
        }

        DB::table('inbox_messages')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->whereIn('status', self::WAKEABLE)
            ->update([
                'status' => 'pending',
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);

        foreach ($ids as $id) {
            ProcessInboxMessage::dispatch($tenantId, $id)->onQueue('inbox:process');
        }

        Log::info('inbox.deferred_events_woken', [
            'connection' => $connectionId,
            'external_order_id' => $externalOrderId,
            'count' => count($ids),
        ]);

        return count($ids);
    }
}
