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
use Illuminate\Support\Facades\RateLimiter;

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
    /**
     * §11 · Webhook güvenliği: "Rate limit — bağlantı başına dakikada 600".
     *
     * SINIR BAĞLANTI BAŞINADIR, IP BAŞINA DEĞİL: kanal webhook'ları kendi
     * altyapısından gelir ve aynı IP yüzlerce satıcıya hizmet eder. IP
     * başına sınır, yoğun bir satıcının aynı kanaldaki DİĞER satıcıların
     * siparişlerini düşürmesi demekti. Koruma katmanının "kova bağlantı
     * başınadır" kuralıyla aynı gerekçe.
     */
    public const MAX_REQUESTS_PER_MINUTE = 600;

    /**
     * Kabul edilen içerik tipleri — §11: "beklenmeyen tip → 415".
     *
     * `application/x-www-form-urlencoded` DE kabul edilir: bazı kanallar
     * (Woo dahil) webhook'u form kodlamasıyla gönderebilir ve reddetmek
     * meşru sipariş kaybettirirdi. Kapının işi bilinmeyen bir gövde
     * biçimini ayrıştırıcıya sokmamaktır, biçim sayısını azaltmak değil.
     */
    private const ACCEPTED_CONTENT_TYPES = [
        'application/json',
        'application/x-www-form-urlencoded',
        'text/json',
    ];

    public function __invoke(Request $request, string $connectionId): Response
    {
        // (0) İÇERİK TİPİ — İMZADAN ÖNCE, EN UCUZ KAPI.
        //
        // 415 dönmek "her durumda 202" kuralını İHLAL ETMEZ: o kural
        // TANIDIĞIMIZ bir mesajın işlenmesiyle ilgilidir ve kanalın
        // gereksiz yeniden göndermesini önler. Yanlış içerik tipi kanalın
        // YAPILANDIRMA hatasıdır; 2xx dönmek onu sessizce gizler ve mesaj
        // sonsuza kadar kaybolur.
        if (! $this->hasAcceptedContentType($request)) {
            Log::warning('webhook.unsupported_content_type', [
                'connection' => $connectionId,
                'content_type' => $request->header('Content-Type'),
            ]);

            return response()->noContent(415);
        }

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

        // (0b) HIZ SINIRI — bağlantı BULUNDUKTAN sonra, imzadan ÖNCE.
        //
        // Sıra bilinçlidir: kova bağlantı başınadır ve hangi bağlantı
        // olduğunu bilmeden sayaç tutulamaz. İmzadan önce gelmesi ise
        // asıl korumadır — imza doğrulaması kasadan okuma ve kriptografik
        // karşılaştırma demektir, yani sel altında en pahalı adımdır.
        //
        // 429, "her durumda 202" kuralının bilinçli istisnasıdır: kanala
        // "yavaşla ve TEKRAR GÖNDER" der. 202 dönseydi mesaj kabul edilmiş
        // sayılır ama işlenmezdi ve sipariş sessizce kaybolurdu.
        $throttleKey = 'webhook:'.$connection->id;

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_REQUESTS_PER_MINUTE)) {
            Log::warning('webhook.rate_limited', [
                'connection' => $connection->id,
                'channel' => $connection->channel_type_code,
            ]);

            return response()->noContent(429, [
                'Retry-After' => (string) RateLimiter::availableIn($throttleKey),
            ]);
        }

        RateLimiter::hit($throttleKey, decaySeconds: 60);

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

    /**
     * İçerik tipi tanıdık mı?
     *
     * Başlık `application/json; charset=utf-8` biçiminde parametre
     * taşıyabilir; karşılaştırma bu yüzden ÖN EK üzerinden yapılır. Tam
     * eşitlik arasaydı charset gönderen her kanal 415 alır ve meşru
     * webhook'lar reddedilirdi.
     *
     * BAŞLIKSIZ İSTEK KABUL EDİLİR: bazı kanallar `Content-Type`
     * göndermez ve gövde yine geçerli JSON'dur. Reddetmek meşru sipariş
     * kaybettirirdi; imza doğrulaması zaten ikinci kapıdır.
     */
    private function hasAcceptedContentType(Request $request): bool
    {
        $contentType = $request->header('Content-Type');

        if ($contentType === null || $contentType === '') {
            return true;
        }

        $normalized = mb_strtolower(trim($contentType));

        foreach (self::ACCEPTED_CONTENT_TYPES as $accepted) {
            if (str_starts_with($normalized, $accepted)) {
                return true;
            }
        }

        return false;
    }
}
