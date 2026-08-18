<?php

declare(strict_types=1);

namespace App\Domain\Orders\Support;

use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Messaging\Actions\IngestInboxMessage;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhook göndermeyen kanallardan sipariş yoklar.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 ("Sipariş yoklaması"),
 * §6 · Inbox, §14 · Trendyol, §1 · Karar 24.
 *
 * DEĞİŞMEZ KURAL — YOKLAMA WEBHOOK'LA AYNI İNBOX'A YAZAR:
 *   `IngestInboxMessage` TEK gelen hattır; `source` alanı ayrımı taşır
 *   (`webhook` | `polling`). İkinci bir yol açılsaydı tekilleştirme iki
 *   kez yazılır ve biri unutulurdu; `inbox:recover` kurtarma taraması da
 *   iki yeri bilmek zorunda kalırdı.
 *
 * DEĞİŞMEZ KURAL — YALNIZCA YENİ SATIR KUYRUĞA GİRER:
 *   Webhook yolundaki `wasRecentlyCreated` kontrolünün aynısı. Yoklama
 *   doğası gereği aynı siparişi tekrar tekrar görür (pencere geriye
 *   bakar); her turda yeniden kuyruğa atılsaydı `ProcessInboxMessage`'ın
 *   koşullu geçişi TEK savunma olarak kalırdı ve savunmayı tek katmana
 *   indirmek yanlıştır.
 *
 * DEĞİŞMEZ KURAL — PENCERE GERİYE BAKAR:
 *   İmleç son turun bitiş anına EŞİT yazılsaydı, istek sürerken kanalda
 *   oluşan sipariş iki pencerenin ARASINA düşer ve HİÇ görülmezdi.
 *   Örtüşme kasıtlıdır ve bedeli yoktur: tekilleştirme kopyayı zaten eler.
 *
 * DEĞİŞMEZ KURAL — TEK BOZUK BAĞLANTI TURU DURDURMAZ:
 *   Bağlantılar SIRAYLA denenir ve hata yalnızca o bağlantıyı düşürür.
 *   İlk hatada pes edilseydi o kanaldaki tüm satıcılar siparişsiz kalır
 *   ve sorun kendi bağlantılarında olmadığı için hiçbiri düzeltemezdi
 *   (taksonomide birebir aynı hata yaşandı).
 *
 * DEĞİŞMEZ KURAL — BAŞARISIZ TURDA İMLEÇ İLERLEMEZ:
 *   İlerleseydi hata anındaki pencere sonsuza kadar atlanır ve o
 *   penceredeki siparişler bir daha HİÇ sorulmazdı. Sipariş kaybı en
 *   pahalı hata biçimidir (Karar 24).
 */
final class PollChannelOrders
{
    /** İmlecin `settings` içindeki yeri — öğrenilen hız sınırıyla aynı mantık. */
    public const CURSOR_KEY = 'orders_polled_through';

    /**
     * Pencere örtüşmesi.
     *
     * İstek süresi + kanalın kendi yazma gecikmesini kapsayacak kadar
     * geniş, aynı siparişi gereksiz yere yüzlerce kez çekmeyecek kadar
     * dar olmalı.
     */
    private const OVERLAP_MINUTES = 5;

    /** İlk turda ne kadar geriye bakılır — imleç henüz yokken. */
    private const INITIAL_LOOKBACK_HOURS = 24;

    /** Sonsuz döngü koruması: bozuk bir `totalPages` turu kilitlemesin. */
    private const MAX_PAGES = 50;

    public function __construct(
        private readonly AdapterRegistry $adapters = new AdapterRegistry,
        private readonly IngestInboxMessage $ingest = new IngestInboxMessage,
    ) {}

    /**
     * Tüm uygun bağlantıları yoklar.
     *
     * @return int Inbox'a YENİ yazılan mesaj sayısı
     */
    public function run(): int
    {
        // Tarama TÜM kiracıları görmek zorundadır; sistem erişimi açıktır.
        $connections = TenantContext::runAsSystem(fn () => ChannelConnection::query()
            ->where('status', 'active')
            // `supports_webhooks` SEÇİLMEK ZORUNDA: eager-load'da atlanırsa
            // aşağıdaki webhook kapısı null okur, hiç çalışmaz ve webhook
            // gönderen kanallar da her turda boşuna yoklanır. Bu projede
            // aynı tuzak `adapter_class` ile bir kez yaşandı ve yetenekler
            // sessizce boşalmıştı.
            ->with('channelType:code,name,adapter_class,supports_webhooks')
            ->get());

        $ingested = 0;

        foreach ($connections as $connection) {
            $ingested += $this->pollConnection($connection);
        }

        return $ingested;
    }

    /**
     * Tek bağlantıyı yoklar — hata yalnızca BU bağlantıyı düşürür.
     */
    private function pollConnection(ChannelConnection $connection): int
    {
        $adapter = TenantContext::runAsSystem(
            fn () => $this->adapters->for($connection),
        );

        // Yetenek `instanceof` ile okunur; `if ($type === 'trendyol')` yazılmaz.
        if (! $adapter instanceof SupportsOrders) {
            return 0;
        }

        // Webhook gönderen kanal yoklanmaz: aynı sipariş iki yoldan gelir,
        // ikinci yol yalnızca kota harcar. Tekilleştirme kopyayı elese de
        // istek zaten yapılmış olurdu.
        if ($connection->channelType?->supports_webhooks === true) {
            return 0;
        }

        $since = $this->windowStart($connection);
        $startedAt = Carbon::now();

        try {
            $ingested = $this->drainPages($connection, $adapter, $since);
        } catch (Throwable $e) {
            // İMLEÇ İLERLETİLMEZ: bu pencere bir sonraki turda yeniden
            // sorulacak. Hata yutulmaz, görünür kalır.
            Log::warning('orders.polling_failed', [
                'connection' => $connection->id,
                'channel' => $connection->channel_type_code,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        $this->advanceCursor($connection, $startedAt);

        return $ingested;
    }

    /**
     * Sayfaları sonuna kadar çeker.
     *
     * İlk sayfayla yetinilseydi yoğun bir satıcının siparişlerinin çoğu
     * her turda görülmez ve hiç işlenmezdi.
     */
    private function drainPages(
        ChannelConnection $connection,
        SupportsOrders $adapter,
        Carbon $since,
    ): int {
        $ingested = 0;
        $cursor = null;
        $pages = 0;

        do {
            $page = $adapter->fetchOrders($since, $cursor);

            foreach ($page->orders as $order) {
                $ingested += $this->ingestOrder($connection, $order);
            }

            $cursor = $page->nextCursor;
            $pages++;
        } while ($page->hasMore && $cursor !== null && $pages < self::MAX_PAGES);

        if ($page->hasMore && $pages >= self::MAX_PAGES) {
            // Sessiz kırpma YAPILMAZ: kalan sayfalar görünür kalsın.
            Log::warning('orders.polling_page_limit_reached', [
                'connection' => $connection->id,
                'pages' => $pages,
            ]);
        }

        return $ingested;
    }

    /**
     * Tek siparişi inbox'a yazar ve YENİYSE kuyruğa atar.
     *
     * @param  array<string, mixed>  $order
     * @return int 1 = yeni yazıldı, 0 = zaten vardı
     */
    private function ingestOrder(ChannelConnection $connection, array $order): int
    {
        $eventId = $this->eventIdFor($order);

        $message = $this->ingest->run(
            connection: $connection,
            source: 'polling',
            externalEventId: $eventId,
            eventType: 'order.polled',
            payload: json_encode($order, JSON_THROW_ON_ERROR),
            // Yoklamada imza YOKTUR ve olmaması bir eksiklik değildir:
            // gövdeyi kanaldan BİZ istedik ve kimlikli bir çağrıyla aldık.
            // İmza, bize gönderilen bir gövdenin sahiciliğini kanıtlar;
            // burada sahicilik zaten TLS + kimlik doğrulamayla kurulmuştur.
            signatureValid: true,
        );

        if (! $message->wasRecentlyCreated) {
            return 0;
        }

        ProcessInboxMessage::dispatch($connection->tenant_id, $message->id)
            ->onQueue('inbox:process');

        return 1;
    }

    /**
     * Olay kimliği — SİPARİŞ NUMARASI + DURUM.
     *
     * KİMLİK YALNIZCA SİPARİŞ NUMARASINA BAĞLANAMAZ: birincil tekillik
     * indeksi `(channel_connection_id, external_event_id)` üzerinedir ve
     * aynı siparişin sonraki İPTALİ o indekse takılıp `insertOrIgnore`
     * tarafından SESSİZCE YUTULURDU — stok geri eklenmez, bakiye kalıcı
     * olarak eksik kalırdı. Karar 24'ün açıkça uyardığı hata biçimi budur.
     *
     * Kimlik üretilemezse `null` döner ve tekilleştirme hash + saatlik
     * pencere yoluna düşer: mesaj yine KAYBEDİLMEZ.
     *
     * @param  array<string, mixed>  $order
     */
    private function eventIdFor(array $order): ?string
    {
        $number = $order['orderNumber'] ?? $order['id'] ?? null;

        if ($number === null || (string) $number === '') {
            return null;
        }

        $status = (string) ($order['status'] ?? '');

        return $status === '' ? (string) $number : "{$number}:{$status}";
    }

    /**
     * Pencerenin başlangıcı — İMLEÇTEN GERİYE.
     *
     * İmleç yoksa ilk tur sabit bir geçmişe bakar; sınırsız geriye bakmak
     * ilk turda tüm sipariş geçmişini çeker ve kotayı tüketirdi.
     */
    private function windowStart(ChannelConnection $connection): Carbon
    {
        $cursor = $connection->settings[self::CURSOR_KEY] ?? null;

        if (! is_string($cursor) || $cursor === '') {
            return Carbon::now()->subHours(self::INITIAL_LOOKBACK_HOURS);
        }

        try {
            // ÖRTÜŞME: sınırda yazılan sipariş iki pencerenin arasına
            // düşmesin.
            return Carbon::parse($cursor)->subMinutes(self::OVERLAP_MINUTES);
        } catch (Throwable) {
            // Bozuk imleç turu durdurmaz; güvenli varsayılana düşülür.
            return Carbon::now()->subHours(self::INITIAL_LOOKBACK_HOURS);
        }
    }

    /**
     * İmleci turun BAŞLAMA anına yazar, bitiş anına DEĞİL.
     *
     * Tur sürerken oluşan siparişler bu turda görülmemiş olabilir; bitiş
     * anı yazılsaydı onlar iki pencerenin arasında kalırdı.
     *
     * İmleç bir SENKRON DURUMU değil, bağlantının yapılandırmasıdır —
     * öğrenilen hız sınırıyla aynı gerekçe.
     */
    private function advanceCursor(ChannelConnection $connection, Carbon $startedAt): void
    {
        TenantContext::runAsSystem(function () use ($connection, $startedAt): void {
            $connection->forceFill([
                'settings' => [
                    ...$connection->settings ?? [],
                    self::CURSOR_KEY => $startedAt->toIso8601String(),
                ],
            ])->save();
        });
    }
}
