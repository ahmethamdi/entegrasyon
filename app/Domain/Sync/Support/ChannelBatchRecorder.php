<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\SupportsBatchStatus;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\ChannelBatch;
use App\Domain\Sync\Models\ChannelBatchItem;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push sonucundaki kanal iş kimliğini SAKLAR — yoklama sonra okur.
 *
 * `PushInventory` / `PushPrices` başarı yolunda, `recordSuccess`'ten SONRA
 * çağrılır. Push kararına KARIŞMAZ.
 *
 * ═════════════════════════════════════════════════════════════════════
 * DEĞİŞMEZ KURAL — BU SINIF İSTİSNA FIRLATMAZ
 * ═════════════════════════════════════════════════════════════════════
 * Push işindeki tek try/catch her istisnayı KANAL HATASI sayar: buradan bir
 * istisna sızsaydı kanalda zaten kabul edilmiş yük "başarısız" yazılır,
 * devre kesiciye hata sayılır ve iş yeniden denenirdi. Kimlik saklamak bir
 * YAN İŞTİR; düşerse günlüğe yazılır ve push sonucu değişmez
 * (`api_calls` günlükleme kuralının aynısı).
 *
 * YALNIZ GERÇEKTEN GİDEN SATIRLAR: kısmi başarıda adapter'ın hiç
 * GÖNDERMEDİĞİ kalemler (N11 TL dışı ürün, Çiçeksepeti sıfır fiyat) zaten
 * ölü yazıldı; onları iş satırı yapmak, kanalın hiç görmediği bir satır için
 * hüküm beklemek olurdu.
 *
 * İDEMPOTENT: (bağlantı, alan, iş kimliği) ve (iş, operasyon) tekil;
 * `insertOrIgnore` kullanılır, istisnaya güvenilmez.
 */
final class ChannelBatchRecorder
{
    /**
     * @param  list<SyncOperation>  $operations  Yükteki operasyonlar
     * @param  array<string, string>  $externalIds  listing_id → kanala giden kimlik
     */
    public function remember(
        ChannelAdapter $adapter,
        SyncDomain $domain,
        string $connectionId,
        array $operations,
        array $externalIds,
        AdapterResult $result,
    ): void {
        try {
            $this->store($adapter, $domain, $connectionId, $operations, $externalIds, $result);
        } catch (Throwable $e) {
            Log::warning('batch.remember_failed', [
                'connection' => $connectionId,
                'domain' => $domain->value,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }
    }

    /**
     * @param  list<SyncOperation>  $operations
     * @param  array<string, string>  $externalIds
     */
    private function store(
        ChannelAdapter $adapter,
        SyncDomain $domain,
        string $connectionId,
        array $operations,
        array $externalIds,
        AdapterResult $result,
    ): void {
        if (! $adapter instanceof SupportsBatchStatus || $result->failed()) {
            return;
        }

        $batchId = $adapter->batchIdFrom($result);

        if ($batchId === null || trim($batchId) === '') {
            return;
        }

        $rows = [];

        foreach ($operations as $operation) {
            // Kanala hiç gitmeyen kalem: adapter kısmi başarıda ayıkladı.
            if (isset($result->failedOperations[$operation->id])) {
                continue;
            }

            $externalId = $externalIds[$operation->entity_id] ?? null;

            if ($externalId === null || $externalId === '') {
                continue;
            }

            $rows[] = [$operation, $externalId];
        }

        if ($rows === []) {
            return;
        }

        $tenantId = $rows[0][0]->tenant_id;

        DB::transaction(function () use ($adapter, $domain, $connectionId, $batchId, $rows, $tenantId): void {
            DB::table('channel_batches')->insertOrIgnore([
                'id' => ChannelBatch::generateUuidV7(),
                'tenant_id' => $tenantId,
                'channel_connection_id' => $connectionId,
                'domain' => $domain->value,
                'external_batch_id' => $batchId,
                'status' => ChannelBatch::STATUS_PENDING,
                'item_count' => count($rows),
                'failed_count' => 0,
                'poll_count' => 0,
                'expires_at' => now()->addSeconds(max(60, $adapter->batchRetentionSeconds($domain))),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $batch = ChannelBatch::query()
                ->where('channel_connection_id', $connectionId)
                ->where('domain', $domain->value)
                ->where('external_batch_id', $batchId)
                ->firstOrFail();

            $items = [];

            foreach ($rows as [$operation, $externalId]) {
                $items[] = [
                    'id' => ChannelBatchItem::generateUuidV7(),
                    'tenant_id' => $operation->tenant_id,
                    'channel_batch_id' => $batch->id,
                    'listing_id' => $operation->entity_id,
                    'sync_operation_id' => $operation->id,
                    'external_id' => $externalId,
                    'entity_version' => $operation->entity_version,
                    'outcome' => ChannelBatchItem::OUTCOME_PENDING,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('channel_batch_items')->insertOrIgnore($items);
        });
    }
}
