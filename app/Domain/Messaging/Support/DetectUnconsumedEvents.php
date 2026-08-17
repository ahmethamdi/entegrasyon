<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seviye 1 bütünlük taraması — outbox teslimi: tüketici hiç çalışmadı.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · İki bütünlük taraması, §18 · T5.
 * Her 10 dakikada, maintenance kuyruğunda.
 *
 * KAPATILAN BOŞLUK: relay olayı kuyruğa verir ve published_at damgalar. O
 * andan sonra olayın kaderi Redis'in elindedir. Redis işi düşürürse
 * (bellek baskısı, flush, çökme) olay "yayınlanmış" görünür ama fan-out hiç
 * çalışmaz ve hiçbir sync_operation yaratılmaz. Seviye 2 taraması da onu
 * bulamaz — bulacağı operasyon hiç var olmadı. Bu taramanın görmediğini
 * HİÇBİR mekanizma görmez.
 *
 * YENİDEN YAYIN GÜVENLİDİR: published_at = NULL yazmak olayı relay'in
 * kapsamına geri sokar. Tüketici idempotenttir ve OpenSyncOperation hem
 * sürüm kapısı hem (tenant_id, idempotency_key) tekilliğiyle korunur; olay
 * ikinci kez tüketilse bile yeni operasyon yaratılmaz.
 *
 * consumed_at DOLU OLAN OLAY YENİDEN YAYINLANMAZ. Kalıcı kanal hatası
 * (AUTHENTICATION → dead) bu alanı boş bırakmaz; o hata sync_operations
 * seviyesinde yaşar ve panelde çözülür. Yeniden yayınlamak, kullanıcı
 * müdahalesi bekleyen bir hatayı sonsuz döngüye çevirirdi.
 *
 * outbox_unconsumed_idx kısmi indeksi bu taramayı besler: tarama yalnızca
 * yayınlanmış-ama-tüketilmemiş satırlara dokunur.
 */
final class DetectUnconsumedEvents
{
    /** Bu eşiği aşan yeniden yayın sayısı artık Redis kaybı değil, sistematik hatadır. */
    public const ATTEMPT_ALARM_THRESHOLD = 5;

    /** Varsayılan eşik: bu süreden eski yayınlar tüketilmemiş sayılır (§6 · 5 dakika). */
    public const DEFAULT_STALE_SECONDS = 300;

    /**
     * Tek tur: kayıp olayları bulur, günlükler ve yeniden yayına alır.
     *
     * @return Collection<int, string> Yeniden yayına alınan olay kimlikleri
     */
    public function run(int $staleAfterSeconds = self::DEFAULT_STALE_SECONDS, int $limit = 500): Collection
    {
        // Tarama TÜM kiracıları görmek zorundadır: kiracı bağlamıyla
        // çalışsaydı yalnızca birinin kayıp olayları kurtarılır, diğerleri
        // sessizce kaybolurdu. Erişim bilinçli ve açıktır.
        return TenantContext::runAsSystem(
            fn (): Collection => DB::transaction(
                fn (): Collection => $this->republishBatch($staleAfterSeconds, $limit),
            ),
        );
    }

    /** @return Collection<int, string> */
    private function republishBatch(int $staleAfterSeconds, int $limit): Collection
    {
        // clock_timestamp() KULLANILIYOR, now() DEĞİL. Bu sorgu bir
        // transaction içinde çalışır ve now() transaction'ın BAŞLAMA anında
        // donar; eşik de o ana göre hesaplanırdı. Zaman damgaları saniye
        // hassasiyetli olduğu için donma tam sınırdaki olayı eler.
        //
        // FOR UPDATE SKIP LOCKED: iki tarama kopyası aynı olayı iki kez
        // yeniden yayına almaz — publish_attempts sayacı iki kat hızlı
        // tükenir ve kritik alarm eşiği anlamsız kalırdı.
        //
        // published_at IS NOT NULL yükleme DAVRANIŞ KATMAZ ve bu bilinçlidir:
        // NULL < clock_timestamp() karşılaştırması NULL döner, SQL bunu
        // "doğru değil" sayar ve satır zaten elenir. Cümle iki nedenle durur:
        // (1) §6'daki sorgu tanımı böyle, (2) outbox_unconsumed_idx kısmi
        // indeksinin yüklemiyle birebir eşleşir ve planlayıcının indeksi
        // seçmesini garanti eder. Mutasyonla kaldırıldığında hiçbir test
        // kırılmaz — bu bir test boşluğu değil, yapısal sınırdır; sahte test
        // YAZILMADI (bkz. DEVIR.md · davranışla sınanamayan kurallar).
        $events = DB::select(<<<'SQL'
            SELECT id, tenant_id, event_type, published_at, publish_attempts
              FROM outbox_events
             WHERE published_at IS NOT NULL
               AND consumed_at IS NULL
               AND published_at < clock_timestamp() - (? * interval '1 second')
             ORDER BY published_at
             LIMIT ?
               FOR UPDATE SKIP LOCKED
        SQL, [$staleAfterSeconds, $limit]);

        if ($events === []) {
            return collect();
        }

        foreach ($events as $event) {
            $context = [
                'event' => $event->id,
                'tenant' => $event->tenant_id,
                'event_type' => $event->event_type,
                'published_at' => $event->published_at,
                'publish_attempts' => (int) $event->publish_attempts,
            ];

            Log::warning('outbox.consumer_never_ran', $context);

            // Eşiği aşan olay artık Redis kaybı değildir: tüketicide
            // sistematik bir hata vardır ve sessiz yeniden yayın onu
            // sonsuza kadar gizler. Yeniden yayın YİNE yapılır — olayı
            // bırakmak veri kaybıdır; ama gürültü çıkarılır.
            if ((int) $event->publish_attempts >= self::ATTEMPT_ALARM_THRESHOLD) {
                Log::critical('outbox.consumer_never_ran_exhausted', $context);
            }
        }

        $ids = array_map(static fn (object $event): string => $event->id, $events);

        // Yeniden yayına al: published_at = NULL olayı relay'in kapsamına
        // geri sokar. consumed_at'e DOKUNULMAZ — planlamayı tüketici yapar.
        DB::table('outbox_events')
            ->whereIn('id', $ids)
            ->update([
                'published_at' => null,
                'publish_attempts' => DB::raw('publish_attempts + 1'),
                'updated_at' => now(),
            ]);

        return collect($ids);
    }
}
