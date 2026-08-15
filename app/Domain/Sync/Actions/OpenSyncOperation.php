<?php

declare(strict_types=1);

namespace App\Domain\Sync\Actions;

use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Senkron operasyonu açar — sürüm kapısı burada uygulanır.
 *
 * Mimari Karar Dokümanı v2.2 · §8 · Sürüm kapısı, §1 · Kararlar 16–17.
 *
 * DEĞİŞMEZ KURAL — SÜRÜM KAPISI NİYETE GÖRE:
 *   NORMAL_SYNC: eski bir sürüm hiçbir koşulda yeninin üzerine yazamaz.
 *                synced_version >= eventVersion  → ele (zaten gönderilmiş)
 *                desired_version > eventVersion  → ele (daha yenisi istenmiş)
 *   REPAIR:      kapı ATLANIR. Mutabakat uzak durumu okumuş ve gerçek farkı
 *                kanıtlamıştır; "bu sürüm zaten gönderildi" bilgisi orada
 *                yanlıştır. desired_version ARTIRILMAZ — yapay sürüm artışı
 *                sıra dışı olay elemesini bozar ve gerçek bir değişikliği
 *                bayat gösterir.
 *
 * YARATMA IDEMPOTENTTİR: insertOrIgnore kullanılır, istisnaya güvenilmez.
 * Aynı mutabakat kalemi iki kez işlense bile anahtar aynı kaldığı için tek
 * onarım operasyonu oluşur.
 */
final class OpenSyncOperation
{
    /**
     * @param  int  $eventVersion  Olayın taşıdığı iş sürümü
     * @param  string|null  $reconciliationItemId  YALNIZCA intent = REPAIR
     * @return SyncOperation|null null = sürüm kapısı eledi veya iş zaten bitmiş
     */
    public function run(
        Listing $listing,
        SyncDomain $domain,
        int $eventVersion,
        SyncIntent $intent = SyncIntent::NORMAL_SYNC,
        ?OutboxEvent $sourceEvent = null,
        ?string $reconciliationItemId = null,
    ): ?SyncOperation {
        if ($intent === SyncIntent::REPAIR && $reconciliationItemId === null) {
            throw new InvalidArgumentException(
                'REPAIR niyeti reconciliation_item_id taşımak zorundadır: '.
                'onarım anahtarı bu kimlikten türetilir ve aynı sürüklenme '.
                'kaydının iki kez işlenmesi tek operasyon üretmelidir.'
            );
        }

        return DB::transaction(function () use (
            $listing, $domain, $eventVersion, $intent, $sourceEvent, $reconciliationItemId,
        ): ?SyncOperation {

            // (1) Sync state satırını KİLİTLE.
            //     Listing × domain başına tek satır olduğu için bu doğal
            //     serileştirme noktasıdır ve deadlock riskini düşürür.
            $state = $this->lockState($listing, $domain);

            // (2) SÜRÜM KAPISI — yalnızca NORMAL_SYNC için.
            if ($intent === SyncIntent::NORMAL_SYNC) {
                if ($state->synced_version >= $eventVersion) {
                    return null;                    // bu sürüm veya yenisi gitmiş
                }

                if ($state->desired_version > $eventVersion) {
                    return null;                    // daha yeni sürüm zaten istenmiş
                }

                // (3) İstenen durumu ilerlet — YALNIZCA normal senkronda.
                $state->forceFill([
                    'desired_version' => $eventVersion,
                    'status' => 'pending',
                    'last_requested_at' => now(),
                ])->save();

                // (4) Bekleyen ESKİ normal operasyonları geçersiz kıl.
                //     Kilit (1)'de alındığı için bu UPDATE serileşmiş durumda.
                //     REPAIR operasyonlarına DOKUNULMAZ — onlar bağımsız yaşar.
                SyncOperation::query()
                    ->where('entity_type', 'listing')
                    ->where('entity_id', $listing->id)
                    ->where('operation_type', $domain->operationType())
                    ->where('intent', SyncIntent::NORMAL_SYNC->value)
                    ->whereIn('status', [
                        SyncOperationStatus::PENDING->value,
                        SyncOperationStatus::RETRYING->value,
                    ])
                    ->where('entity_version', '<', $eventVersion)
                    ->update([
                        'status' => SyncOperationStatus::SUPERSEDED->value,
                        'completed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            // REPAIR: desired_version DEĞİŞTİRİLMEZ, eski operasyonlar
            // geçersiz kılınmaz. Yalnızca last_requested_at tazelenir.
            if ($intent === SyncIntent::REPAIR) {
                $state->forceFill(['last_requested_at' => now()])->save();
            }

            // (5) Anahtar niyete göre — iki biçim çakışmaz.
            $key = $this->idempotencyKey($listing, $domain, $eventVersion, $intent, $reconciliationItemId);

            // (6) IDEMPOTENT yaratma — istisnaya güvenme.
            DB::table('sync_operations')->insertOrIgnore([
                'id' => SyncOperation::generateUuidV7(),
                'tenant_id' => $listing->tenant_id,
                'channel_connection_id' => $listing->channel_connection_id,
                'operation_type' => $domain->operationType(),
                'intent' => $intent->value,
                'entity_type' => 'listing',
                'entity_id' => $listing->id,
                'entity_version' => $eventVersion,
                'idempotency_key' => $key,
                'outbox_event_id' => $sourceEvent?->id,
                'reconciliation_item_id' => $reconciliationItemId,
                'status' => SyncOperationStatus::PENDING->value,
                'attempt_count' => 0,
                'priority' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $operation = SyncOperation::query()
                ->where('tenant_id', $listing->tenant_id)
                ->where('idempotency_key', $key)
                ->first();

            // (7) Zaten tamamlanmışsa iş yaratma.
            if ($operation === null || $operation->status->isTerminal()) {
                return null;
            }

            return $operation;
        });
    }

    /**
     * İdempotency anahtarı — niyete göre iki biçim.
     *
     * NORMAL_SYNC  inv:{listing_id}:{version}
     * REPAIR       inv:{listing_id}:{version}:repair:{reconciliation_item_id}
     *
     * Biçimler çakışmaz: aynı listing ve aynı iş sürümü için hem normal hem
     * onarım operasyonu birlikte var olabilir. Bu bilinçlidir — onarım devam
     * eden normal akışı iptal etmez.
     */
    private function idempotencyKey(
        Listing $listing,
        SyncDomain $domain,
        int $eventVersion,
        SyncIntent $intent,
        ?string $reconciliationItemId,
    ): string {
        $base = "{$domain->keyPrefix()}:{$listing->id}:{$eventVersion}";

        return $intent === SyncIntent::REPAIR
            ? "{$base}:repair:{$reconciliationItemId}"
            : $base;
    }

    /**
     * Sync state satırını kilitler; yoksa yaratır.
     *
     * Satır yoksa kilitlenecek bir şey de yoktur. İlk senkronda satır burada
     * doğar; insertOrIgnore yarış durumunda ikinci yazıcıyı sessizce eler ve
     * ardından gelen kilit ikisini de aynı satıra bağlar.
     */
    private function lockState(Listing $listing, SyncDomain $domain): ListingSyncState
    {
        DB::table('listing_sync_states')->insertOrIgnore([
            'id' => ListingSyncState::generateUuidV7(),
            'tenant_id' => $listing->tenant_id,
            'listing_id' => $listing->id,
            'domain' => $domain->value,
            'desired_version' => 0,
            'synced_version' => 0,
            'status' => 'pending',
            'error_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ListingSyncState::query()
            ->where('listing_id', $listing->id)
            ->where('domain', $domain->value)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
