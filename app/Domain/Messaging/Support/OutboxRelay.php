<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use App\Domain\Messaging\Jobs\ConsumeOutboxEvent;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Outbox yayınlayıcı — veritabanından kuyruğa köprü.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Outbox relay.
 *
 * SORUMLULUK SINIRI: relay yalnızca YAYINLAR. Fan-out tüketicinin işidir,
 * consumed_at'i relay YAZMAZ. Bu ayrım iki teslim zincirinin birbirinden
 * bağımsız izlenmesini sağlar (§6 · iki bütünlük taraması).
 *
 * FOR UPDATE SKIP LOCKED: birden fazla relay çalıştırıldığında ikinci süreç
 * birincinin kilitlediği satırları ATLAR — bekler değil. Tek relay durumunda
 * maliyeti yoktur, çok relay durumunda çakışmayı tamamen ortadan kaldırır.
 *
 * SIRALAMA: ORDER BY created_at ile olay sırası korunur. UUIDv7 zaten zaman
 * sıralı olduğu için ikincil bir sıralama anahtarına gerek yoktur.
 *
 * ÇÖKME SENARYOLARI — hepsi zararsız:
 *   Dispatch öncesi        → olay yayınlanmamış kalır, sonraki tur alır
 *   Dispatch sonrası, UPDATE öncesi → olay iki kez yayınlanır; tüketici
 *                            idempotent, sürüm kapısı ikinciyi eler
 *   UPDATE sonrası, COMMIT öncesi   → UPDATE geri alınır, yukarıdakiyle aynı
 *
 * Olay SİLİNMEZ, işaretlenir. Yayınlanmış olaylar yedi gün sonra temizlenir;
 * yayınlanmamış eski bir olay ASLA temizlenmez — o zaten alarm konusudur.
 */
final class OutboxRelay
{
    /** @var Closure(string, string): void */
    private readonly Closure $dispatcher;

    /**
     * @param  (Closure(string, string): void)|null  $dispatcher  Test için enjekte edilebilir
     */
    public function __construct(?Closure $dispatcher = null)
    {
        $this->dispatcher = $dispatcher ?? static function (string $tenantId, string $eventId): void {
            ConsumeOutboxEvent::dispatch($tenantId, $eventId)->onQueue('outbox:consume');
        };
    }

    /**
     * Tek tur: yayınlanmamış olayları alır, kuyruğa verir, damgalar.
     *
     * @return int Yayınlanan olay sayısı
     */
    public function run(int $batchSize = 100): int
    {
        // Relay TÜM kiracıların olaylarını görmek zorundadır; kiracı
        // bağlamıyla çalışsaydı yalnızca tek kiracının olayları yayınlanır,
        // diğerleri sessizce beklerdi. Erişim bilinçli ve açıktır.
        return TenantContext::runAsSystem(fn (): int => DB::transaction(
            fn (): int => $this->publishBatch($batchSize)
        ));
    }

    private function publishBatch(int $batchSize): int
    {
        // İkinci bir relay bu satırları ATLAR, beklemez.
        //
        // clock_timestamp() KULLANILIYOR, now() DEĞİL. PostgreSQL'de now()
        // transaction'ın BAŞLAMA anını döndürür ve transaction boyunca
        // donmuş kalır. Bu sorgu bir transaction içinde çalıştığı için
        // now() ile karşılaştırma, transaction başladıktan SONRA yazılmış
        // olayları "geleceğe planlanmış" sayıp eler. clock_timestamp()
        // gerçek duvar saatini verir ve bu pencereyi kapatır.
        $events = DB::select(<<<'SQL'
            SELECT id, tenant_id
              FROM outbox_events
             WHERE published_at IS NULL
               AND available_at <= clock_timestamp()
             ORDER BY created_at
             LIMIT ?
               FOR UPDATE SKIP LOCKED
        SQL, [$batchSize]);

        if ($events === []) {
            return 0;
        }

        // Dispatch DAMGADAN ÖNCE yapılır. Ters sırada olsaydı ve dispatch
        // patlasaydı olay yayınlanmış görünür ve sonsuza kadar kaybolurdu.
        // Bu sırada en kötü ihtimal olayın iki kez yayınlanmasıdır ve
        // tüketici buna dayanıklıdır.
        foreach ($events as $event) {
            ($this->dispatcher)($event->tenant_id, $event->id);
        }

        $ids = array_map(static fn (object $event): string => $event->id, $events);

        DB::table('outbox_events')
            ->whereIn('id', $ids)
            ->update([
                'published_at' => now(),
                'publish_attempts' => DB::raw('publish_attempts + 1'),
                'updated_at' => now(),
            ]);

        return count($ids);
    }
}
