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
use App\Domain\Sync\Support\ContentHasher;
use App\Domain\Sync\Support\ListingPayloadBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

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
    public function __construct(
        private readonly ContentHasher $hasher = new ContentHasher,
        private readonly ListingPayloadBuilder $payloadBuilder = new ListingPayloadBuilder,
    ) {}

    /**
     * @param  int  $eventVersion  Olayın taşıdığı iş sürümü
     * @param  string|null  $reconciliationItemId  Mutabakat kaynaklı REPAIR'de zorunlu
     * @param  string|null  $resyncAnchor  Resync kaynaklı REPAIR'de zorunlu (§9)
     * @return SyncOperation|null null = sürüm kapısı eledi veya iş zaten bitmiş
     */
    public function run(
        Listing $listing,
        SyncDomain $domain,
        int $eventVersion,
        SyncIntent $intent = SyncIntent::NORMAL_SYNC,
        ?OutboxEvent $sourceEvent = null,
        ?string $reconciliationItemId = null,
        ?string $resyncAnchor = null,
    ): ?SyncOperation {
        // REPAIR SÜRÜM KAPISINI ATLADIĞI İÇİN AYIRT EDİCİ BİR ÇIPA ZORUNLUDUR.
        //
        // Kapı atlandığında anahtar tekilliği, "aynı tetik iki kez işlenirse
        // tek operasyon oluşur" garantisini taşıyan TEK mekanizmadır. Çıpasız
        // bir REPAIR, sürüm zaten anahtarda olduğu için aynı listing+sürüm
        // için üretilen her onarımı tek anahtara çöker ve ikinci meşru talep
        // sessizce yutulurdu.
        //
        // İKİ MEŞRU KAYNAK, İKİ AYRI ÇIPA:
        //   mutabakat → reconciliation_item_id (§10)
        //   resync    → olay kimliği (§9 · error_permanent'tan çıkış)
        // İkisi AYNI ANDA verilemez: hangi tetikten doğduğu belirsiz bir
        // onarımın izi sürülemez.
        if ($intent === SyncIntent::REPAIR && $reconciliationItemId === null && $resyncAnchor === null) {
            throw new InvalidArgumentException(
                'REPAIR niyeti ayırt edici bir çıpa taşımak zorundadır '.
                '(reconciliation_item_id veya resync çıpası): onarım anahtarı '.
                'o kimlikten türetilir ve aynı tetiğin iki kez işlenmesi tek '.
                'operasyon üretmelidir.'
            );
        }

        if ($reconciliationItemId !== null && $resyncAnchor !== null) {
            throw new InvalidArgumentException(
                'Bir onarım operasyonu tek bir tetikten doğar: '.
                'reconciliation_item_id ve resync çıpası birlikte verilemez.'
            );
        }

        return DB::transaction(function () use (
            $listing, $domain, $eventVersion, $intent, $sourceEvent, $reconciliationItemId, $resyncAnchor,
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
                //
                //     desired_hash "ne gönderilmek isteniyor" sorusunu
                //     cevaplar (§9). Sürümle birlikte yazılır: sürüm hangi
                //     olayı, hash hangi içeriği gösterir ve çakışma tespiti
                //     ikisinin ayrı durmasına dayanır.
                $state->forceFill([
                    'desired_version' => $eventVersion,
                    'desired_hash' => $this->desiredHash($listing, $domain, $eventVersion),
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

            // (5) Anahtar niyete göre — biçimler çakışmaz.
            $key = $this->idempotencyKey(
                $listing, $domain, $eventVersion, $intent, $reconciliationItemId, $resyncAnchor,
            );

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
     * İdempotency anahtarı — niyete ve tetiğe göre üç biçim.
     *
     * NORMAL_SYNC       inv:{listing_id}:{version}
     * REPAIR/mutabakat  inv:{listing_id}:{version}:repair:{reconciliation_item_id}
     * REPAIR/resync     inv:{listing_id}:{version}:resync:{olay_kimliği}
     *
     * Biçimler çakışmaz: aynı listing ve aynı iş sürümü için normal, onarım ve
     * resync operasyonu birlikte var olabilir. Bu bilinçlidir — onarım devam
     * eden normal akışı iptal etmez, resync de mutabakatın onarımını ezmez.
     *
     * `repair` ve `resync` ayrı ön eklerdir: tek ön ek paylaşsalardı bir
     * mutabakat kalemi kimliği ile bir olay kimliği teorik olarak aynı
     * anahtara düşebilir ve iki farklı tetikten biri sessizce yutulurdu.
     */
    private function idempotencyKey(
        Listing $listing,
        SyncDomain $domain,
        int $eventVersion,
        SyncIntent $intent,
        ?string $reconciliationItemId,
        ?string $resyncAnchor = null,
    ): string {
        $base = "{$domain->keyPrefix()}:{$listing->id}:{$eventVersion}";

        if ($intent !== SyncIntent::REPAIR) {
            return $base;
        }

        return $resyncAnchor !== null
            ? "{$base}:resync:{$resyncAnchor}"
            : "{$base}:repair:{$reconciliationItemId}";
    }

    /**
     * İstenen içeriğin parmak izi — yalnızca hash'i tanımlı alanlarda.
     *
     * CONTENT dışındaki alanların kendi yükleri vardır (stok mutlak sayı,
     * fiyat varyant alanı) ve onların hash'i kendi yollarında hesaplanır.
     * Burada uydurma bir değer yazmak, mutabakatın karşılaştırdığı iki
     * tarafı anlamsız kılardı.
     *
     * Hash hesaplaması senkron kapısını DÜŞÜREMEZ: içerik okunamıyorsa
     * (ürün silinmiş, ilişki kopuk) operasyon yine açılır ve hata iş
     * tarafında, kendi denemesiyle görünür.
     */
    private function desiredHash(Listing $listing, SyncDomain $domain, int $eventVersion): ?string
    {
        if ($domain !== SyncDomain::CONTENT) {
            return null;
        }

        try {
            return $this->hasher->hash($this->payloadBuilder->build($listing, $eventVersion));
        } catch (Throwable) {
            return null;
        }
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
