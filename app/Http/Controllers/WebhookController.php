<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Messaging\Actions\IngestInboxMessage;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Kanal webhook uç noktası.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Inbox HTTP katmanı, §11 · Güvenlik.
 *
 * SIRA KRİTİKTİR:
 *   1. Boyut sınırı (nginx + middleware)
 *   2. İmza doğrulama — HAM GÖVDE üzerinden, JSON AYRIŞTIRMADAN ÖNCE
 *   3. Tekilleştirme + kayıt, tek INSERT
 *   4. Yeni kayıtsa işleme kuyruğuna
 *   5. HER DURUMDA 202
 *
 * İMZA NEDEN HAM GÖVDEDEN:
 *   JSON ayrıştırıp yeniden serileştirmek baytları değiştirir (anahtar
 *   sırası, boşluk, sayı biçimi) ve imza tutmaz. Doğrulanmamış webhook
 *   sahte sipariş enjeksiyonu demektir.
 *
 * NEDEN HER DURUMDA 202:
 *   Kanal 2xx dışını başarısızlık sayar ve yeniden gönderir. Zaten
 *   aldığımız bir mesaj için hata dönmek kanalı gereksiz yere tekrar
 *   göndermeye iter; tekilleştirme zaten bizde.
 *
 * KİRACI BAĞLAMI: bağlantı global scope'suz okunur (istek kiracısız gelir),
 * ardından bağlam açıkça kurulur. Aksi halde tenant-scoped sorgu istisna
 * fırlatırdı.
 */
final class WebhookController extends Controller
{
    public function __invoke(Request $request, string $connectionId): Response
    {
        // Bağlantı kiracı bağlamı olmadan okunur — istek anonimdir.
        $connection = TenantContext::runAsSystem(
            fn () => ChannelConnection::query()->find($connectionId)
        );

        if ($connection === null) {
            // Var olmayan bağlantı için 404: bilgi sızdırmaz, kanal da
            // yeniden denemez.
            Log::warning('webhook.unknown_connection', ['connection' => $connectionId]);

            return response()->noContent(404);
        }

        // (1) HAM gövde — ayrıştırma YAPILMADAN.
        $raw = $request->getContent();

        $adapter = app(AdapterRegistry::class)->for($connection);

        // (2) İmza doğrulama. Başarısızsa 401 ve KAYIT YOK.
        if (! $adapter->verifyWebhookSignature($raw, $request->headers->all())) {
            Log::warning('webhook.signature_invalid', [
                'connection' => $connection->id,
                'channel' => $connection->channel_type_code,
            ]);

            return response()->noContent(401);
        }

        // (3) Tekilleştirme + kayıt.
        $message = app(IngestInboxMessage::class)->run(
            connection: $connection,
            source: 'webhook',
            externalEventId: $adapter->extractEventId($request->headers->all()),
            eventType: $adapter->extractEventType($request->headers->all()),
            payload: $raw,
            signatureValid: true,
        );

        // (4) Yalnızca YENİ kayıt kuyruğa girer; tekrar gelen webhook
        //     ikinci kez işlenmez.
        if ($message->wasRecentlyCreated) {
            ProcessInboxMessage::dispatch($connection->tenant_id, $message->id)
                ->onQueue('inbox:process');
        }

        // (5) Her durumda 202.
        return response()->noContent(202);
    }
}
