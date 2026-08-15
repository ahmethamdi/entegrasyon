<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Actions;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Messaging\Models\InboxMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Gelen mesajı tekilleştirerek kaydeder — TEK gelen hat.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Inbox, §1 · Karar 23, §4 · inbox_messages.
 *
 * WEBHOOK VE YOKLAMA AYNI TABLOYA YAZAR. İki ayrı yol olsaydı tekilleştirme
 * iki kez yazılır, biri unutulurdu; kurtarma taraması da iki yeri bilmek
 * zorunda kalırdı.
 *
 * HAM GÖVDE ÖNCE YAZILIR, SONRA AYRIŞTIRILIR: ayrıştırma hatası siparişin
 * kaybolmasına değil, satırın failed durumuna düşmesine yol açar ve mesaj
 * yeniden işlenebilir.
 *
 * ÇİFT TEKİLLEŞTİRME:
 *   Birincil  external_event_id — kanalın gerçek olay kimliği
 *   Son çare  payload_hash + saatlik pencere
 *
 * Hash yolu BİLİNÇLİ OLARAK SON ÇAREDİR: 10:59:59 ve 11:00:01'de gelen aynı
 * mesaj farklı pencerelere düşer. Woo (X-WC-Webhook-Delivery-ID), Shopify
 * (X-Shopify-Event-Id) ve Trendyol (sipariş numarası) kimlik verdiği için o
 * yol pratikte hiç çalışmaz. Yeni kanal eklerken olay kimliği aramak ilk iş
 * olmalıdır.
 */
final class IngestInboxMessage
{
    /**
     * Mesajı kaydeder; zaten varsa mevcut satırı döner.
     *
     * Dönen nesnenin wasRecentlyCreated bayrağı çağıranın kuyruğa atıp
     * atmayacağını belirler — tekrar gelen webhook ikinci kez işlenmez.
     *
     * @param  string  $payload  HAM gövde; ayrıştırılmadan saklanır
     */
    public function run(
        ChannelConnection $connection,
        string $source,
        ?string $externalEventId,
        string $eventType,
        string $payload,
        bool $signatureValid = true,
    ): InboxMessage {
        $tenantId = $connection->tenant_id;

        return TenantContext::runFor($tenantId, function () use (
            $connection, $source, $externalEventId, $eventType, $payload, $signatureValid, $tenantId,
        ): InboxMessage {
            $hash = InboxMessage::hashPayload($payload);
            $now = now();
            $id = InboxMessage::generateUuidV7();

            // Tek INSERT. İki kısmi tekillik indeksinden hangisi devreye
            // girerse girsin, çakışma sessizce yutulur ve mevcut satır okunur.
            //
            // insertOrIgnore kullanılıyor, istisna yakalamaya güvenilmiyor:
            // PostgreSQL'de tekillik ihlali transaction'ı kirletir ve sonraki
            // her sorgu hata verir.
            DB::table('inbox_messages')->insertOrIgnore([
                'id' => $id,
                'tenant_id' => $tenantId,
                'channel_connection_id' => $connection->id,
                'source' => $source,
                'external_event_id' => $externalEventId,
                'event_type' => $eventType,
                'payload' => $this->encodePayload($payload),
                'payload_hash' => $hash,
                'signature_valid' => $signatureValid,
                'received_at' => $now,
                'status' => 'pending',
                'attempt_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $message = $this->find($connection, $externalEventId, $hash);

            // wasRecentlyCreated Eloquent'in bayrağıdır ve DB::table ile
            // yazdığımız için kendiliğinden dolmaz; çağıranın kuyruğa atma
            // kararı buna dayandığı için elle kuruluyor.
            //
            // Ölçüt satırın KİMLİĞİDİR, zaman damgası değil: zaman damgaları
            // saniye hassasiyetlidir ve aynı saniyede yazılan iki mesaj
            // ayırt edilemezdi. Bizim ürettiğimiz uuid geri geldiyse INSERT
            // gerçekten bu çağrıda oldu demektir.
            $message->wasRecentlyCreated = $message->id === $id;

            return $message;
        });
    }

    /**
     * Kaydı hangi tekilleştirme yolundan geldiyse ondan bulur.
     *
     * Olay kimliği varsa birincil indeks; yoksa hash + saatlik pencere.
     * Pencere veritabanında üretiliyor (date_trunc), bu yüzden burada
     * yeniden hesaplanmaz — iki taraf sapabilirdi.
     */
    private function find(
        ChannelConnection $connection,
        ?string $externalEventId,
        string $hash,
    ): InboxMessage {
        $query = InboxMessage::query()
            ->where('channel_connection_id', $connection->id);

        if ($externalEventId !== null) {
            return $query->where('external_event_id', $externalEventId)->firstOrFail();
        }

        return $query
            ->whereNull('external_event_id')
            ->where('payload_hash', $hash)
            ->orderByDesc('received_at')
            ->firstOrFail();
    }

    /**
     * Ham gövdeyi jsonb kolonuna hazırlar.
     *
     * Geçersiz JSON gelirse ham metin sarmalanır: mesaj KAYBEDİLMEZ,
     * ayrıştırma hatası işleme aşamasında görülür ve satır failed olur.
     */
    private function encodePayload(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $payload;
        }

        return json_encode(['_raw' => $payload], JSON_THROW_ON_ERROR);
    }
}
