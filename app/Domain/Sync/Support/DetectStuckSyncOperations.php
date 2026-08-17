<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Jobs\PushInventory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seviye 2 bütünlük taraması — sync teslimi: operasyon yaratıldı, worker çalışmadı.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · İki bütünlük taraması, §18 · T6.
 * Her 5 dakikada, maintenance kuyruğunda.
 *
 * SEVİYE 1 BUNU GÖREMEZ, iki tarama bu yüzden ayrıdır. Fan-out çalıştı:
 * operasyonlar açıldı ve olay consumed damgalandı. O zincir tamamlandı.
 * Kayıp bir sonraki halkada: PushInventory işi kuyruğa girdi ve Redis onu
 * düşürdü. Olaya bakan tarama hiçbir sorun görmez — consumed_at dolu,
 * published_at dolu. Operasyon veritabanında bekler, kuyrukta karşılığı
 * yoktur ve stok kanala hiç gitmez.
 *
 * İMZA: status = 'pending' AND attempt_count = 0. Sayaç İLK denemede
 * artırılır (SyncResultRecorder::openAttempt), dolayısıyla sıfır kalması
 * tek şey demektir: worker bu operasyona HİÇ dokunmadı.
 *
 * DEVRE KESİCİ VE HIZ SINIRI BU İMZAYI KORUR: ikisi de erteleme yaparken
 * deneme AÇMAZ ve durumu DEĞİŞTİRMEZ. Ertelenen operasyon bu taramaya da
 * takılır ve yeniden dispatch edilir; iş yine ertelenir. Maliyet bir boş
 * turdur — alternatif, gerçekten kaybolmuş işi hiç görmemektir.
 *
 * OUTBOX OLAYI YENİDEN YAYINLANMAZ. Yayınlamak fan-out'u tekrarlatırdı; o
 * zincir zaten başarıyla tamamlandı ve operasyonlar duruyor.
 *
 * TARAMA DENEME AÇMAZ: attempt_count'u artırmak bu taramanın kendi imzasını
 * yok eder — bir kez taranan operasyon artık "hiç denenmedi" görünmez ve
 * gerçekten kaybolduğunda bulunamaz.
 *
 * sync_ops_never_attempted_idx kısmi indeksi bu taramayı besler.
 */
final class DetectStuckSyncOperations
{
    /** Varsayılan eşik: bu süreden eski hiç denenmemiş operasyonlar alınır (§6 · 5 dakika). */
    public const DEFAULT_STALE_SECONDS = 300;

    /**
     * Tek tur: takılı operasyonları bulur ve yeniden dispatch eder.
     *
     * @return Collection<int, string> Yeniden dispatch edilen operasyon kimlikleri
     */
    public function run(int $staleAfterSeconds = self::DEFAULT_STALE_SECONDS, int $limit = 500): Collection
    {
        // Tarama TÜM kiracıları görmek zorundadır; erişim açıktır. İş
        // yalnızca kimlik taşır ve PushInventory bağlamı kendi kurar —
        // kiracı kimliği bu yüzden yükte TAŞINIR (mutasyonla bulundu:
        // taşınmazsa iş bağlamsız düşer ve tarama hiçbir şey kurtarmaz).
        $stuck = TenantContext::runAsSystem(
            fn (): array => $this->findStuck($staleAfterSeconds, $limit),
        );

        $redispatched = [];

        foreach ($stuck as $operation) {
            $context = [
                'operation' => $operation->id,
                'tenant' => $operation->tenant_id,
                'connection' => $operation->channel_connection_id,
                'operation_type' => $operation->operation_type,
            ];

            // operation_type işin hangi sınıf olduğunu belirler. Yanlış iş
            // atmak yanlış yükü gönderir; sessizce yutmak operasyonu
            // sonsuza kadar takılı bırakır. Doğru davranış: gürültü çıkar
            // ve kurtarılmış SAYMA.
            if ($operation->operation_type !== SyncDomain::INVENTORY->operationType()) {
                Log::warning('sync.stuck_operation_no_job', $context);

                continue;
            }

            Log::info('sync.stuck_operation_redispatched', $context);

            PushInventory::dispatch($operation->id, $operation->tenant_id)->onQueue('inventory:high');

            $redispatched[] = $operation->id;
        }

        return collect($redispatched);
    }

    /** @return list<object> */
    private function findStuck(int $staleAfterSeconds, int $limit): array
    {
        // clock_timestamp() KULLANILIYOR, now() DEĞİL: zaman damgaları
        // saniye hassasiyetli ve now() transaction başında donar.
        //
        // FOR UPDATE veya damgalama YAPILMAZ: tarama satıra yazmaz, bu
        // yüzden kilide de gerek yoktur. İki kopya aynı operasyonu iki kez
        // dispatch etse bile zarar yoktur — stok MUTLAK değer gönderilir,
        // ikinci iş boş yükle erken çıkar ve deneme açmaz.
        return DB::select(<<<'SQL'
            SELECT id, tenant_id, channel_connection_id, operation_type
              FROM sync_operations
             WHERE status        = 'pending'
               AND attempt_count = 0
               AND created_at    < clock_timestamp() - (? * interval '1 second')
             ORDER BY created_at
             LIMIT ?
        SQL, [$staleAfterSeconds, $limit]);
    }
}
