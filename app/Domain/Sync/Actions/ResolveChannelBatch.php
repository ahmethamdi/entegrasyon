<?php

declare(strict_types=1);

namespace App\Domain\Sync\Actions;

use App\Domain\Channels\Contracts\SupportsBatchStatus;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Sync\Models\ChannelBatch;
use App\Domain\Sync\Models\ChannelBatchItem;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Support\BatchItemFailure;
use App\Domain\Sync\Support\BatchStatus;
use App\Domain\Sync\Support\ChannelErrorText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tek bir kanal toplu işini yoklar ve satır hükmünü listing'lere yazar.
 *
 * `TrackApprovalStatus`'un kardeşi: zamanlanmış tur (`sync:poll-batches`)
 * her bekleyen işi KENDİ kiracısının bağlamında buraya verir.
 *
 * ═════════════════════════════════════════════════════════════════════
 * HÜKÜM MEVCUT LISTING HATA MEKANİZMASINA YAZILIR — YENİ EKRAN YOK
 * ═════════════════════════════════════════════════════════════════════
 *   failed (kalıcı sınıf)   → sync state `error_permanent` + `last_error`
 *                             → ürün listesinde "Sorun var" çipi
 *   failed (geçici sınıf)   → `error_transient` (+ error_count) → mutabakat
 *                             onu aday seçer ve onarır
 *   awaiting_approval       → `error_transient`, error_count ARTMAZ →
 *                             "Bekliyor" çipi; kanal onaylayana kadar eski
 *                             değer geçerli (Pazarama %70 fiyat onayı)
 *   succeeded               → satırdaki hata TOPLU İŞTEN geldiyse temizlenir
 *   unknown / expired       → satıra DOKUNULMAZ: bilinmeyen sonuç red değildir
 *
 * ═════════════════════════════════════════════════════════════════════
 * DEĞİŞMEZ KURAL — BAYAT HÜKÜM YENİSİNİ EZMEZ
 * ═════════════════════════════════════════════════════════════════════
 * Satırın sürümü (`entity_version`) sync state'in istenen VE gönderilen
 * sürümünden geride kaldıysa hüküm YAZILMAZ: arada daha yeni bir değer
 * gönderildi ya da yolda; onun kendi işi kendi hükmünü verir. Eski işin
 * "reddedildi"si yeni ve geçerli değerin üstüne "Sorun var" yazardı; eski
 * işin "başarılı"sı yeni işin gerçek hatasını silerdi
 * (`SyncResultRecorder::advanceSyncState` sürüm kapısının aynısı).
 *
 * ═════════════════════════════════════════════════════════════════════
 * DEĞİŞMEZ KURAL — BAŞARI YALNIZ TOPLU İŞİN YAZDIĞI HATAYI SİLER
 * ═════════════════════════════════════════════════════════════════════
 * Satırdaki `last_error` başka bir yoldan da gelmiş olabilir (push
 * istisnası, kısmi başarı). Toplu işin "bu satır geçti" demesi O hatayı
 * düzeltmez. Temizlik yalnız `last_error`, bu listing+alan için daha önce
 * bir toplu iş satırına yazılmış `reason` ile BİREBİR aynıysa yapılır.
 *
 * ═════════════════════════════════════════════════════════════════════
 * İDEMPOTENT — AYNI SONUÇ İKİ KEZ İŞLENSE DE TEK HÜKÜM
 * ═════════════════════════════════════════════════════════════════════
 * İş satırı `FOR UPDATE` ile kilitlenir ve durumu yeniden okunur: `pending`
 * değilse (başka bir tur bitirdi) hiçbir şey yazılmaz. Yalnız `pending`
 * satırlar hükmedilir; `error_count` iki kez artmaz.
 *
 * Adapter yan etkisizdir (§7): okur ve `BatchStatus` döner; yazan BURASI.
 */
final class ResolveChannelBatch
{
    public const RESULT_PENDING = 'pending';

    public const RESULT_COMPLETED = 'completed';

    public const RESULT_EXPIRED = 'expired';

    public const RESULT_SKIPPED = 'skipped';

    public const RESULT_ERROR = 'error';

    /** sync state `last_error` kolonuna sığan, panelde okunur uzunluk. */
    private const MAX_REASON = 2000;

    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly ChannelErrorText $errorText,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function run(ChannelBatch $batch): string
    {
        if (! $batch->isPending()) {
            return self::RESULT_SKIPPED;
        }

        // SAKLAMA SÜRESİ DOLDU — kanal sonucu artık vermez; yoklamak kotayı
        // boşa yer. Satırlara hüküm YAZILMAZ.
        if ($batch->expires_at !== null && $batch->expires_at->isPast()) {
            return $this->expire($batch, 'retention_elapsed');
        }

        $connection = ChannelConnection::query()
            ->with('channelType:code,name,adapter_class')
            ->find($batch->channel_connection_id);

        if ($connection === null) {
            return $this->expire($batch, 'connection_missing');
        }

        // Her çağrıda YENİ örnek (§7 · P0).
        $adapter = $this->registry->for($connection);

        if (! $adapter instanceof SupportsBatchStatus) {
            return $this->expire($batch, 'channel_lacks_batch_status');
        }

        // Kanal ölü sayılıyorsa yoklama da yapılmaz; iş bekler.
        if (! $this->breaker->allows($connection->id)) {
            return self::RESULT_SKIPPED;
        }

        try {
            $status = $adapter->fetchBatchStatus($batch->external_batch_id, $batch->domain);
        } catch (Throwable $e) {
            $batch->forceFill([
                'poll_count' => $batch->poll_count + 1,
                'last_polled_at' => now(),
                'last_poll_error' => $this->errorText->redact($connection, mb_substr($e->getMessage(), 0, self::MAX_REASON)),
            ])->save();

            return self::RESULT_ERROR;
        }

        if ($status->isExpired()) {
            return $this->expire($batch, 'channel_forgot_batch');
        }

        if ($status->isPending()) {
            $batch->forceFill([
                'poll_count' => $batch->poll_count + 1,
                'last_polled_at' => now(),
                'last_poll_error' => null,
            ])->save();

            return self::RESULT_PENDING;
        }

        return $this->complete($batch, $connection, $status);
    }

    private function complete(ChannelBatch $batch, ChannelConnection $connection, BatchStatus $status): string
    {
        // Hükümler ve MASKELEME transaction DIŞINDA hesaplanır: maskeleme
        // kasa okur (DB işi) ve kanal gövdesi sır yansıtabilir
        // (`SyncResultRecorder::recordFailure` gerekçesi).
        $verdicts = [];

        foreach ($batch->items()->where('outcome', ChannelBatchItem::OUTCOME_PENDING)->get() as $item) {
            [$outcome, $failure] = $status->outcomeFor($item->external_id);

            $reason = $failure !== null
                ? mb_substr((string) $this->errorText->redact($connection, $failure->reason), 0, self::MAX_REASON)
                : null;

            $verdicts[$item->id] = [$outcome, $failure, $reason];
        }

        $failedCount = 0;

        $result = DB::transaction(function () use ($batch, $verdicts, &$failedCount): string {
            $locked = ChannelBatch::query()->lockForUpdate()->find($batch->id);

            // Başka bir tur bu işi bitirdi: İKİNCİ KEZ YAZILMAZ.
            if ($locked === null || ! $locked->isPending()) {
                return self::RESULT_SKIPPED;
            }

            $items = $locked->items()
                ->where('outcome', ChannelBatchItem::OUTCOME_PENDING)
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                if (! isset($verdicts[$item->id])) {
                    continue;
                }

                [$outcome, $failure, $reason] = $verdicts[$item->id];

                $item->forceFill([
                    'outcome' => $outcome,
                    'reason' => $reason,
                    'error_class' => $failure?->class->value,
                    'resolved_at' => now(),
                ])->save();

                if ($outcome === BatchStatus::OUTCOME_FAILED) {
                    $failedCount++;
                }

                $this->applyToState($locked, $item, $outcome, $failure, $reason);
            }

            $locked->forceFill([
                'status' => ChannelBatch::STATUS_COMPLETED,
                'completed_at' => now(),
                'failed_count' => $locked->failed_count + $failedCount,
                'poll_count' => $locked->poll_count + 1,
                'last_polled_at' => now(),
                'last_poll_error' => null,
            ])->save();

            return self::RESULT_COMPLETED;
        });

        if ($status->unmatched !== []) {
            // Kimliği çözülemeyen hata: hangi satıra ait olduğu bilinmiyor.
            // Uydurulmaz; görünür kalsın diye günlüğe yazılır.
            Log::warning('batch.unmatched_failures', [
                'batch' => $batch->id,
                'connection' => $connection->id,
                'count' => count($status->unmatched),
            ]);
        }

        return $result;
    }

    /**
     * Satır hükmünü listing × alan sync state'ine yazar.
     */
    private function applyToState(
        ChannelBatch $batch,
        ChannelBatchItem $item,
        string $outcome,
        ?BatchItemFailure $failure,
        ?string $reason,
    ): void {
        if ($outcome === BatchStatus::OUTCOME_UNKNOWN) {
            return;
        }

        $state = ListingSyncState::query()
            ->where('listing_id', $item->listing_id)
            ->where('domain', $batch->domain->value)
            ->lockForUpdate()
            ->first();

        if ($state === null) {
            return;
        }

        // BAYAT HÜKÜM: arada daha yeni bir değer istendi ya da gönderildi.
        if (max($state->desired_version, $state->synced_version) > $item->entity_version) {
            return;
        }

        if ($outcome === BatchStatus::OUTCOME_FAILED && $failure !== null) {
            $state->forceFill([
                'status' => $failure->class->syncStateStatus(),
                'last_error' => $reason,
                'error_count' => $state->error_count + 1,
            ])->save();

            return;
        }

        if ($outcome === BatchStatus::OUTCOME_AWAITING_APPROVAL) {
            // error_count ARTMAZ: bu bir hata değil, kanalın kendi onay
            // sırası. Artsaydı mutabakatın "geçici hata" adayı olur ve
            // onaydaki fiyat her turda yeniden gönderilirdi.
            $state->forceFill([
                'status' => 'error_transient',
                'last_error' => $reason,
            ])->save();

            return;
        }

        if ($outcome === BatchStatus::OUTCOME_SUCCEEDED && $this->errorCameFromBatch($state, $batch)) {
            $state->forceFill([
                'status' => $state->desired_version > $state->synced_version ? 'pending' : 'synced',
                'last_error' => null,
                'error_count' => 0,
            ])->save();
        }
    }

    /**
     * Satırdaki hata bir toplu iş hükmünden mi geliyor?
     *
     * Yalnız hata durumundaki ve metni bu listing+alan için daha önce bir
     * toplu iş satırına yazılmış `reason` ile BİREBİR aynı olan satır.
     */
    private function errorCameFromBatch(ListingSyncState $state, ChannelBatch $batch): bool
    {
        if (! in_array($state->status, ['error_permanent', 'error_transient'], true)
            || $state->last_error === null) {
            return false;
        }

        return ChannelBatchItem::query()
            ->join('channel_batches', 'channel_batches.id', '=', 'channel_batch_items.channel_batch_id')
            ->where('channel_batch_items.listing_id', $state->listing_id)
            ->where('channel_batches.domain', $batch->domain->value)
            ->whereIn('channel_batch_items.outcome', [
                BatchStatus::OUTCOME_FAILED,
                BatchStatus::OUTCOME_AWAITING_APPROVAL,
            ])
            ->where('channel_batch_items.reason', $state->last_error)
            ->exists();
    }

    /**
     * Yoklama bırakılır — satırlar `expired`, sync state'e DOKUNULMAZ.
     */
    private function expire(ChannelBatch $batch, string $why): string
    {
        DB::transaction(function () use ($batch, $why): void {
            $locked = ChannelBatch::query()->lockForUpdate()->find($batch->id);

            if ($locked === null || ! $locked->isPending()) {
                return;
            }

            $locked->items()
                ->where('outcome', ChannelBatchItem::OUTCOME_PENDING)
                ->update([
                    'outcome' => ChannelBatchItem::OUTCOME_EXPIRED,
                    'resolved_at' => now(),
                    'updated_at' => now(),
                ]);

            $locked->forceFill([
                'status' => ChannelBatch::STATUS_EXPIRED,
                'completed_at' => now(),
                'last_poll_error' => $why,
            ])->save();
        });

        Log::info('batch.expired', ['batch' => $batch->id, 'reason' => $why]);

        return self::RESULT_EXPIRED;
    }
}
