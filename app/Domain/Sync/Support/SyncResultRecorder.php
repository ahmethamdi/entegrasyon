<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncAttempt;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Senkron sonucunu yazan tek yer — deneme + operasyon + sync state.
 *
 * Mimari Karar Dokümanı v2.2 · §8 · Sorumluluk dağılımı, §12.
 *
 * DEĞİŞMEZ KURAL — ADAPTER DURUM YAZMAZ:
 *   Adapter yan etkisizdir; girdi alır, kanalla konuşur, AdapterResult döner.
 *   Durumu BURASI yazar. Bu ayrım olmadan her adapter kendi durum yazma
 *   mantığını taşır ve "hangi adapter sync state'i nasıl güncelliyor"
 *   sorusu cevapsız kalırdı.
 *
 * DEĞİŞMEZ KURAL — HATA İZOLASYONU:
 *   Yazma OPERASYON BAZLIDIR. Bir batch'te üç operasyon varsa üçünün de
 *   durumu ayrı satırlarda yaşar; bir kanalın 429'u diğer bağlantıların
 *   satırlarına DOKUNMAZ. Yükte olmayan operasyona hiç yazılmaz.
 *
 * SÜRÜM KAPISI BAŞARIDA DA GEÇERLİ:
 *   synced_version geriye ALINMAZ. İş kuyrukta beklerken daha yeni bir sürüm
 *   gönderilmiş olabilir; eski sonucun onu geri sarması, kanalda doğru veri
 *   varken sistemin "kirli" sanmasına ve gereksiz yeniden gönderime yol açar.
 */
final class SyncResultRecorder
{
    /**
     * Denemeyi açar — attempt_count BURADA artar.
     *
     * Artış ilk denemede yapıldığı için seviye 2 bütünlük taraması
     * (attempt_count = 0 ve eski) yalnızca worker'ın HİÇ çalışmadığı
     * operasyonları yakalar; çalışıp başarısız olanları değil (§12).
     *
     * Deneme TETİKLEYİCİ operasyona açılır. Gruplamaya dahil olan diğer
     * operasyonlar da kendi denemelerini alır — sonuç yazımı sırasında.
     */
    public function openAttempt(SyncOperation $operation): SyncAttempt
    {
        return DB::transaction(function () use ($operation): SyncAttempt {
            $operation->increment('attempt_count');
            $operation->refresh();

            return SyncAttempt::query()->create([
                'tenant_id' => $operation->tenant_id,
                'sync_operation_id' => $operation->id,
                'attempt_number' => $operation->attempt_count,
                'outcome' => 'pending',
                'started_at' => now(),
            ]);
        });
    }

    /**
     * Başarı — operasyon tamamlanır, sync state ilerler.
     *
     * @param  list<SyncOperation>  $operations  Yükteki TÜM operasyonlar
     * @param  SyncAttempt  $attempt  Tetikleyicinin denemesi
     */
    public function recordSuccess(array $operations, SyncAttempt $attempt, AdapterResult $result): void
    {
        DB::transaction(function () use ($operations, $attempt, $result): void {
            $this->closeAttempt($attempt, outcome: 'success');

            foreach ($operations as $operation) {
                // Tetikleyici dışındaki operasyonlar da bu çağrıda gitti;
                // denemeleri kendi satırlarına yazılır, yoksa "hiç denenmedi"
                // görünür ve seviye 2 taraması onları yanlışlıkla toplar.
                if ($operation->id !== $attempt->sync_operation_id) {
                    $this->recordPiggybackAttempt($operation, outcome: 'success');
                }

                $operation->forceFill([
                    'status' => SyncOperationStatus::COMPLETED->value,
                    'completed_at' => now(),
                    'last_error_class' => null,
                ])->save();

                $this->advanceSyncState($operation, $result);
            }
        });
    }

    /**
     * Başarısızlık — operasyon retrying kalır, sync state hata durumuna geçer.
     *
     * Operasyon durumu burada TERMİNAL YAPILMAZ: yeniden denenip
     * denenmeyeceğine çekirdek (RetryPolicy) karar verir ve denenmeyecekse
     * çağıran markDead() çağırır.
     *
     * @param  list<SyncOperation>  $operations
     */
    public function recordFailure(
        array $operations,
        SyncAttempt $attempt,
        ErrorClass $class,
        Throwable $error,
    ): void {
        DB::transaction(function () use ($operations, $attempt, $class, $error): void {
            $this->closeAttempt($attempt, outcome: $class->isPermanent() ? 'permanent' : 'transient',
                class: $class, message: $error->getMessage());

            foreach ($operations as $operation) {
                if ($operation->id !== $attempt->sync_operation_id) {
                    $this->recordPiggybackAttempt($operation, outcome: $class->isPermanent() ? 'permanent' : 'transient',
                        class: $class, message: $error->getMessage());
                }

                $operation->forceFill([
                    'status' => SyncOperationStatus::RETRYING->value,
                    'last_error_class' => $class->value,
                ])->save();

                $this->markSyncStateFailed($operation, $class, $error->getMessage());
            }
        });
    }

    /**
     * Deneme bütçesi tükendi veya hata kalıcı — operasyon ölür.
     *
     * DEĞİŞMEZ KURAL: kaynak outbox_event consumed KALIR. Kalıcı hata
     * operasyon seviyesinde yaşar ve panelde görünür. Bu ayrım olmadan
     * yetkisiz tek bir bağlantı, her stok değişiminde olayı sonsuza kadar
     * yeniden yayınlatırdı (§12).
     *
     * @param  list<SyncOperation>  $operations
     */
    public function markDead(array $operations, ErrorClass $class): void
    {
        DB::transaction(function () use ($operations, $class): void {
            foreach ($operations as $operation) {
                $operation->forceFill([
                    'status' => SyncOperationStatus::DEAD->value,
                    'completed_at' => now(),
                    'last_error_class' => $class->value,
                ])->save();
            }
        });
    }

    /**
     * Gönderilecek bir şey yoktu — operasyon sessizce kapanır.
     *
     * Listing delist edilmişse veya stok satırı yoksa kanal çağrısı
     * anlamsızdır. Deneme AÇILMAZ: hiçbir şey denenmedi. Operasyon
     * completed olur, yoksa sonsuza kadar bekleyen iş olarak taranır.
     *
     * sync_state İLERLETİLMEZ: kanala bir şey gitmedi, synced_version
     * ilerletmek yalan olurdu ve mutabakat gerçek farkı göremezdi.
     */
    public function recordSkipped(SyncOperation $operation, string $reason): void
    {
        $operation->forceFill([
            'status' => SyncOperationStatus::COMPLETED->value,
            'completed_at' => now(),
            'last_error_class' => null,
        ])->save();
    }

    // ---------------------------------------------------------------- iç

    private function closeAttempt(
        SyncAttempt $attempt,
        string $outcome,
        ?ErrorClass $class = null,
        ?string $message = null,
    ): void {
        $finishedAt = now();

        $attempt->forceFill([
            'outcome' => $outcome,
            'error_class' => $class?->value,
            'error_message' => $message,
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, (int) $attempt->started_at?->diffInMilliseconds($finishedAt)),
        ])->save();
    }

    /**
     * Gruplamaya dahil olan operasyonun deneme kaydı.
     *
     * Kendi işi kuyrukta bekliyor olabilir ama yükü bu çağrıda gitti;
     * kaydı olmazsa hem "hiç denenmedi" görünür hem de hata geçmişi kaybolur.
     */
    private function recordPiggybackAttempt(
        SyncOperation $operation,
        string $outcome,
        ?ErrorClass $class = null,
        ?string $message = null,
    ): void {
        $operation->increment('attempt_count');
        $operation->refresh();

        SyncAttempt::query()->create([
            'tenant_id' => $operation->tenant_id,
            'sync_operation_id' => $operation->id,
            'attempt_number' => $operation->attempt_count,
            'outcome' => $outcome,
            'error_class' => $class?->value,
            'error_message' => $message,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 0,
        ]);
    }

    /**
     * synced_version ilerletilir — ama ASLA geriye alınmaz.
     */
    private function advanceSyncState(SyncOperation $operation, AdapterResult $result): void
    {
        $state = $this->lockState($operation);

        if ($state === null) {
            return;
        }

        // Daha yenisi gönderilmişse bu sonuç bayattır; geri sarma.
        // İki iş yarışabilir ve eski olan sonra bitebilir; geri sarma
        // kanalda doğru veri dururken satırı kirli gösterir ve gereksiz
        // yeniden gönderim başlatır.
        if ($state->synced_version >= $operation->entity_version) {
            return;
        }

        $state->forceFill([
            'synced_version' => $operation->entity_version,
            'status' => $this->statusAfterSuccess($state, $operation),
            'last_synced_at' => now(),
            'last_error' => null,
            'error_count' => 0,
        ])->save();
    }

    /**
     * Başarıdan sonraki durum — is_dirty hâlâ true olabilir.
     *
     * Bu sürüm gönderildi ama arada daha yenisi istenmiş olabilir; o zaman
     * satır 'synced' değil 'pending' olmalıdır, yoksa bekleyen iş bitmiş
     * görünür ve mutabakat onu aday seçmez.
     */
    private function statusAfterSuccess(ListingSyncState $state, SyncOperation $operation): string
    {
        return $state->desired_version > $operation->entity_version ? 'pending' : 'synced';
    }

    private function markSyncStateFailed(SyncOperation $operation, ErrorClass $class, string $message): void
    {
        $state = $this->lockState($operation);

        if ($state === null) {
            return;
        }

        // Başarılı bir sonuç bu sürümü zaten geçmişse hata bayattır;
        // temiz satırı kirletme.
        if ($state->synced_version >= $operation->entity_version) {
            return;
        }

        $state->forceFill([
            'status' => $class->syncStateStatus(),
            'last_error' => $message,
            'error_count' => $state->error_count + 1,
        ])->save();
    }

    /**
     * Operasyonun listing × domain sync state satırını kilitler.
     *
     * Satır OpenSyncOperation'da yaratılmış olmalıdır; yoksa operasyon
     * kapıdan geçmemiş demektir ve yazacak bir şey yoktur.
     */
    private function lockState(SyncOperation $operation): ?ListingSyncState
    {
        return ListingSyncState::query()
            ->where('listing_id', $operation->entity_id)
            ->where('domain', $this->domainOf($operation)->value)
            ->lockForUpdate()
            ->first();
    }

    private function domainOf(SyncOperation $operation): SyncDomain
    {
        foreach (SyncDomain::cases() as $domain) {
            if ($domain->operationType() === $operation->operation_type) {
                return $domain;
            }
        }

        throw new \RuntimeException(
            "Bilinmeyen operasyon türü: {$operation->operation_type}."
        );
    }
}
