<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Shopify;

use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\HealthResult;
use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Sync\Enums\ErrorClass;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Shopify kanal adapter'ı — GraphQL Admin API.
 *
 * V3.0 · §06 · Faz 1.
 *
 * ─────────────────────────────────────────────────────────────────────
 * MİMARİ KARAR — REMIX DEĞİL, LARAVEL ADAPTER
 * ─────────────────────────────────────────────────────────────────────
 * v2.2 §2 ve §11 Shopify'ı ayrı bir Node/Remix servisi olarak öngörüyor
 * ve o mimari App Store'a çıkmak için gereklidir (doküman Ay 8+ diyor).
 *
 * V3.0 bunu YAPMIYOR (§06.1 · onaylanmış proje kararı): satıcının kendi
 * **custom app** Admin API anahtarıyla bağlandığı, Woo/Trendyol ile AYNI
 * kalıpta bir adapter. OAuth YOK, Remix YOK, projeye ikinci teknoloji
 * yığını (Node) SOKULMAZ.
 *
 * §11'İN SERVİS TOKEN'I DEĞİŞMEZİ İPTAL EDİLMEDİ, ERTELENDİ. App Store
 * kararı verilirse o değişmez OLDUĞU GİBİ uygulanır ve şema hazırdır:
 * `UNIQUE(channel_type_code, external_account_id)` kısıtı shop domain'den
 * kiracı çözümünün tekil olmasını BUGÜNDEN garanti ediyor. O gün geldiğinde
 * **bu adapter atılmaz** — Remix yalnızca OAuth ve App Bridge yüzeyi olur,
 * ürün/stok/sipariş işi yine buradan geçer.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ EN TEHLİKELİ FARK — GRAPHQL 200 DÖNER AMA BAŞARISIZ OLABİLİR
 * ─────────────────────────────────────────────────────────────────────
 * REST'te hata HTTP kodudur; GraphQL'de HER ŞEY 200'DÜR ve hata gövdede
 * `errors` / `userErrors` altında yaşar. `$response->throw()` bunu GÖRMEZ.
 *
 * Kontrol edilmezse `SyncResultRecorder` başarı yazar, `synced_version`
 * ilerler ve kanalda hiçbir şey değişmemişken satır "senkron" görünür —
 * projenin en pahalı hata biçimi (P0-1). HER GraphQL yanıtı
 * `assertNoGraphqlErrors()` üzerinden geçer; İSTİSNASIZ.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KİMLİK — `X-Shopify-Access-Token`, BASIC AUTH DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 * Başlık desteği `ChannelHttpClient`'a GENEL olarak eklenmişti
 * (`356a662`, Hepsiburada'nın `User-Agent`'ı için). Başlığı ADAPTER verir,
 * istemci taşır ve `if ($channel === '...')` YAZILMAZ.
 *
 * ⚠️ İstemcinin `access_token` → `withToken()` (Bearer) yolu Shopify için
 * YANLIŞTIR ve bu adapter kendi başlığını verir. Bearer gönderilseydi
 * Shopify 401 döner, `AUTHENTICATION` KALICI sayılır ve listing "anahtarın
 * yanlış" diyerek ölürdü — oysa anahtar doğrudur.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KAPSAM — BU SLICE: BAĞLANTI · KİMLİK · SAĞLIK · GRAPHQL SARMALAYICI
 * ─────────────────────────────────────────────────────────────────────
 * Yazılan: kimlik/başlık katmanı, sağlık kontrolü (konum kontrolü dahil),
 * hata sınıflandırma, hız sınırı profili, webhook imza doğrulaması,
 * GraphQL sarmalayıcı + `userErrors` kontrolü.
 *
 * YAZILMAYAN: katalog, stok, fiyat, sipariş, içe aktarma (§27 · slice
 * 1.3–1.9). Yetenek arayüzleri o slice'larda İLAN EDİLİR — ilan edilen
 * ama çalışmayan yetenek panelde çalışmayan sekme demektir (§05).
 */
final class ShopifyAdapter implements ChannelAdapter
{
    /** Kimlik başlığı — Bearer DEĞİL (sınıf başlığı). */
    private const AUTH_HEADER = 'X-Shopify-Access-Token';

    /** Webhook HMAC başlığı — base64, ham gövde üzerinden. */
    private const SIGNATURE_HEADER = 'x-shopify-hmac-sha256';

    /** Olay kimliği başlığı — v2.2 §6 tablosunda ZATEN kayıtlı. */
    private const EVENT_ID_HEADER = 'x-shopify-event-id';

    /** Konum kimliğinin `settings` içindeki yeri (§06.4). */
    public const LOCATION_KEY = 'location_gid';

    /**
     * GraphQL maliyet kovası — varsayılan profil.
     *
     * Shopify'da sınır istek sayısı DEĞİL SORGU MALİYETİDİR: 1.000 puanlık
     * kova, saniyede 50 puan yenilenir. `ChannelRateLimiter` DEĞİŞMEZ —
     * bir "jeton" bir puan olarak yorumlanır (§06.8).
     *
     * Shopify Plus'ta kova 2.000 puandır ve gerçek değer yanıt gövdesinden
     * ÖĞRENİLİR (Trendyol kararının aynısı); bu sabit yalnızca ilk istek
     * için geçerlidir.
     */
    private const DEFAULT_RESTORE_RATE = 50;

    private const DEFAULT_BUCKET_SIZE = 1000;

    public function __construct(
        private readonly ChannelConnection $connection,
        private readonly ChannelHttpClient $client,
    ) {}

    public function connection(): ChannelConnection
    {
        return $this->connection;
    }

    // ---------------------------------------------------------------- sağlık

    /**
     * Mağaza bilgisi okunur, gecikme ölçülür ve KONUM varlığı doğrulanır.
     *
     * Sağlık kontrolü geçmeden bağlantı `active` OLMAZ (v2.2 · §13 · faz
     * 1.4): aktif ama çalışmayan bağlantı en pahalı hata biçimidir.
     *
     * ⚠️ KONUM SEÇİLMEMİŞSE BAĞLANTI SAĞLIKSIZDIR (P1-5 · §06.4).
     * Shopify bir mağazada birden çok konum destekler ve stok yazma
     * `location_gid` ister. Varsayılan konumu SESSİZCE seçmek, iki depolu
     * bir satıcının stoğunu YANLIŞ DEPOYA yazardı — geri alınamaz ve
     * satıcı bunu ancak siparişler yanlış depodan çıkınca fark eder.
     */
    public function healthCheck(): HealthResult
    {
        $startedAt = hrtime(true);

        try {
            $data = $this->gql(
                <<<'GQL'
                query ShopHealth {
                  shop { id name myshopifyDomain }
                }
                GQL,
                operation: 'ShopHealth',
            );

            $latency = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            if (! isset($data['shop']['id'])) {
                return HealthResult::unhealthy(
                    'Shopify yanıtı mağaza bilgisi taşımıyor.'
                );
            }

            if ($this->locationGid() === null) {
                return HealthResult::unhealthy(
                    'Stok konumu (location) seçilmedi. Konum seçilmeden stok '.
                    'gönderilemez; varsayılanı sessizce seçmek çok depolu '.
                    'mağazada stoğu yanlış depoya yazardı.'
                );
            }

            return HealthResult::healthy(latencyMs: $latency);
        } catch (Throwable $e) {
            return HealthResult::unhealthy($e->getMessage());
        }
    }

    // ------------------------------------------------------------ hız sınırı

    /**
     * Maliyet tabanlı profil — projede ilk.
     *
     * Sınır yanıt GÖVDESİNDEN öğrenilir (`extensions.cost.throttleStatus`)
     * ve bağlantıya yazılır; sabit profil Plus müşterisini yavaşlatır,
     * standart mağazayı 429'a sokardı (§06.8 · Trendyol kararının aynısı).
     *
     * Öğrenilen değer `channel_types.rate_limit_profile` üzerinden değil
     * BAĞLANTI üzerinden gelir — kova bağlantı başınadır.
     */
    public function rateLimitProfile(): RateLimitProfile
    {
        $profile = $this->connection->channelType?->rate_limit_profile;

        return is_array($profile) && $profile !== []
            ? RateLimitProfile::fromArray($profile)
            : new RateLimitProfile(
                requestsPerSecond: self::DEFAULT_RESTORE_RATE,
                burstCapacity: self::DEFAULT_BUCKET_SIZE,
            );
    }

    // -------------------------------------------------------- sınıflandırma

    /**
     * Shopify hatasını çekirdeğin anladığı sınıfa çevirir.
     *
     * SINIFLANDIRMA BURADA, KARAR ÇEKİRDEKTE (`RetryPolicy`).
     *
     * ⚠️ `userErrors` KALICIDIR (`VALIDATION`): iş kuralı ihlalidir ve
     * yeniden denemek AYNI sonucu verir — yalnızca kotayı harcar. Taşıma
     * hatası (`errors`) ise şema/sorgu sorunudur ve o da kalıcıdır;
     * ikisi de düzeltme ister, yeniden deneme değil.
     */
    public function classifyError(Throwable $e): ErrorClass
    {
        if ($e instanceof ShopifyGraphqlException) {
            return ErrorClass::VALIDATION;
        }

        if ($e instanceof ConnectionException) {
            return ErrorClass::NETWORK;
        }

        if (! $e instanceof RequestException) {
            return ErrorClass::NETWORK;
        }

        $status = $e->response->status();

        return match (true) {
            $status === 429 => ErrorClass::RATE_LIMITED,
            $status === 401, $status === 403 => ErrorClass::AUTHENTICATION,
            $status === 404 => ErrorClass::NOT_FOUND,
            $status === 409 => ErrorClass::CONFLICT,
            $status === 408 => ErrorClass::TIMEOUT,
            $status >= 500 => ErrorClass::SERVER_ERROR,
            $status >= 400 => ErrorClass::VALIDATION,
            default => ErrorClass::SERVER_ERROR,
        };
    }

    // ------------------------------------------------------------- webhook

    /**
     * HMAC — HAM GÖVDE üzerinden, JSON AYRIŞTIRMADAN ÖNCE (P0-6).
     *
     * Shopify imzayı base64 olarak gönderir:
     * `base64(hmac_sha256(raw, secret))`.
     *
     * SABİT ZAMANLI KARŞILAŞTIRMA (`hash_equals`): `===` ilk farklı baytta
     * döner ve süre doğru ön ek uzunluğunu SIZDIRIR.
     *
     * WEBHOOK SIRRI YOKSA DOĞRULAMA "GEÇTİ" DEMEZ — güvenli taraf
     * REDDETMEKTİR; kabul etmek imzasız sipariş enjeksiyonuna kapı açardı.
     *
     * KİRACI BAĞLAMI BEKLENMEZ: webhook anonim gelir ve kiracı ancak
     * bağlantı bulunduktan sonra bilinir; sır `runAsSystem()` ile okunur.
     * Bağlam beklenirse MEŞRU HER WEBHOOK sessizce reddedilir ve kanal
     * sonsuza kadar yeniden gönderir.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function verifyWebhookSignature(string $raw, array $headers): bool
    {
        $provided = $this->header($headers, self::SIGNATURE_HEADER);

        if ($provided === null || $provided === '') {
            return false;
        }

        $secret = $this->webhookSecret();

        if ($secret === null || $secret === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        return hash_equals($expected, $provided);
    }

    /**
     * Olay kimliği — tekilleştirmenin BİRİNCİL çıpası.
     *
     * `X-Shopify-Event-Id` v2.2 §6 tablosunda ZATEN kayıtlıdır; türetilmiş
     * kimliğe (`{id}:{status}`) gerek yoktur çünkü kanal gerçek bir olay
     * kimliği veriyor.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function extractEventId(array $headers): ?string
    {
        return $this->header($headers, self::EVENT_ID_HEADER);
    }

    /**
     * Olay tipi — Shopify'da "topic" denir (`orders/create`).
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function extractEventType(array $headers): string
    {
        return $this->header($headers, 'x-shopify-topic') ?? 'unknown';
    }

    /**
     * Webhook'u gönderen mağazanın alan adı — bağlantıyı bulan anahtar.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function extractShopDomain(array $headers): ?string
    {
        return $this->header($headers, 'x-shopify-shop-domain');
    }

    // ------------------------------------------------------------- GraphQL

    /**
     * GraphQL sorgusu çalıştırır ve `data` bloğunu döner.
     *
     * TEK POST İSTEĞİDİR — `ChannelHttpClient` DEĞİŞMEZ (§06.3): GraphQL
     * gövdede taşınan bir sorgudur, yeni bir taşıma katmanı gerektirmez.
     *
     * ⚠️ HER YANIT `assertNoGraphqlErrors()` ÜZERİNDEN GEÇER — İSTİSNASIZ.
     * Bu metodu atlayıp `$response->json()` okuyan her yol, 200 dönen bir
     * başarısızlığı BAŞARI sayar (P0-1).
     *
     * @param  array<string, mixed>  $variables
     * @param  string|null  $userErrorPath  Mutation'ın `userErrors` alanının
     *                                      yolu (`productUpdate`). Query'lerde
     *                                      null — query `userErrors` taşımaz.
     * @return array<string, mixed> `data` bloğu
     */
    public function gql(
        string $query,
        array $variables = [],
        string $operation = 'graphql',
        ?string $userErrorPath = null,
        ?string $attemptId = null,
    ): array {
        $response = $this->client->post(
            endpoint: ShopifyEndpoints::graphql($this->shopDomain()),
            body: array_filter([
                'query' => $query,
                'variables' => $variables === [] ? null : $variables,
            ], static fn ($v): bool => $v !== null),
            attemptId: $attemptId,
            headers: $this->defaultHeaders(),
        );

        // HTTP katmanı yine de kontrol edilir: 401/429/5xx GraphQL'e hiç
        // ulaşmaz ve gövdesi `errors` taşımaz.
        $response->throw();

        return $this->assertNoGraphqlErrors($response, $operation, $userErrorPath);
    }

    /**
     * ⚠️ P0-1 · T-V3-11 — 200 GÖVDE KODU BAŞARI SAYILMAZ.
     *
     * İKİ AYRI HATA KANALI VARDIR ve ikisi de kontrol edilir:
     *
     *   `errors`      — taşıma/şema hatası (sorgu bozuk, alan yok, yetki yok)
     *   `userErrors`  — İŞ KURALI hatası, mutation'a ÖZGÜ alan altında
     *                   (`productUpdate.userErrors`)
     *
     * `userErrors` YOLU ADAPTER TARAFINDAN VERİLİR çünkü her mutation onu
     * kendi adının altında döndürür; tek bir sabit yol yazılamaz. Yol
     * verilmezse (query çağrıları) o kontrol atlanır — query `userErrors`
     * taşımaz ve uydurma bir yol aramak her query'yi kırardı.
     *
     * @return array<string, mixed>
     */
    private function assertNoGraphqlErrors(
        Response $response,
        string $operation,
        ?string $userErrorPath,
    ): array {
        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        // 1 · TAŞIMA / ŞEMA HATASI
        $errors = $body['errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            /** @var list<array<string, mixed>> $errors */
            throw new ShopifyGraphqlException($operation, array_values($errors));
        }

        /** @var array<string, mixed> $data */
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        // 2 · İŞ KURALI HATASI — mutation başına ayrı alan
        if ($userErrorPath !== null) {
            $userErrors = $data[$userErrorPath]['userErrors'] ?? null;

            if (is_array($userErrors) && $userErrors !== []) {
                /** @var list<array<string, mixed>> $userErrors */
                throw new ShopifyGraphqlException(
                    $operation,
                    array_values($userErrors),
                    isUserError: true,
                );
            }
        }

        return $data;
    }

    /**
     * Yanıt gövdesinden ÖĞRENİLEN hız sınırı — yoksa null.
     *
     * `extensions.cost.throttleStatus` gövdededir (§06.8). Shopify Plus'ta
     * kova 2.000 puandır; sabit profil Plus'ı yavaşlatır, standardı 429'a
     * sokar.
     *
     * ⚠️ SAYI OLMAYAN DEĞER YOK SAYILIR. Trendyol'da vekil sunucunun iki
     * başlığı birleştirmesiyle yaşandı (`600, 300` → `(int)` sessizce 600'e
     * iner ve DÜŞÜK sınır yok sayılırdı). Burada değerler float gelir,
     * bu yüzden `is_numeric` süzgeci kullanılır.
     *
     * @return array{bucket: int, restoreRate: int}|null
     */
    public function learnedRateLimit(Response $response): ?array
    {
        $throttle = $response->json('extensions.cost.throttleStatus');

        if (! is_array($throttle)) {
            return null;
        }

        $bucket = $throttle['maximumAvailable'] ?? null;
        $restore = $throttle['restoreRate'] ?? null;

        if (! is_numeric($bucket) || ! is_numeric($restore)) {
            return null;
        }

        $bucket = (int) $bucket;
        $restore = (int) $restore;

        // Sıfır veya negatif sınır anlamsızdır ve kovayı kilitlerdi.
        if ($bucket <= 0 || $restore <= 0) {
            return null;
        }

        return ['bucket' => $bucket, 'restoreRate' => $restore];
    }

    // ------------------------------------------------------------------- iç

    /**
     * Her isteğe eklenen başlıklar.
     *
     * `X-Shopify-Access-Token` — Bearer DEĞİL. İstemcinin `access_token`
     * yolu Bearer üretir ve Shopify onu kabul etmez (sınıf başlığı).
     *
     * TOKEN YOKSA İSTEK HİÇ ATILMAZ: boş başlıkla giden istek 401 alır,
     * `AUTHENTICATION` KALICI sayılır ve listing "anahtarın yanlış" diyerek
     * ölür — oysa anahtar yoktur, yanlış değildir. Hepsiburada'daki
     * "satıcı kimliği yoksa istek atılmaz" kuralının aynısı.
     *
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        $token = $this->accessToken();

        if ($token === null || $token === '') {
            throw new RuntimeException(
                'Shopify Admin API access token tanımsız — istek kimliksiz '.
                'gider, kanal 401 döner ve listing "anahtarın yanlış" '.
                'damgasıyla ölür.'
            );
        }

        return [self::AUTH_HEADER => $token];
    }

    /**
     * Mağaza alan adı — `magaza.myshopify.com`.
     *
     * `external_account_id` BİRİNCİL kaynaktır (§06.2). Shopify'da tek API
     * ana bilgisayarı YOKTUR; her mağaza kendi alt alan adına sahiptir ve o
     * ad kalıcı kimliktir. Mağaza özel alan adı kullansa bile
     * `.myshopify.com` adresi DEĞİŞMEZ.
     */
    private function shopDomain(): string
    {
        $domain = $this->connection->external_account_id;

        if (! is_string($domain) || $domain === '') {
            throw new RuntimeException(
                'Shopify mağaza alan adı (external_account_id) tanımsız — '.
                'istek atılacak adres bilinmiyor.'
            );
        }

        return $domain;
    }

    /**
     * Stok konumu — bağlantı başına TEK (§06.4).
     *
     * LISTING'DE DEĞİL BAĞLANTIDA saklanır: bir mağazanın stok konumu tüm
     * ürünler için aynıdır ve listing başına saklamak aynı değeri 5.000
     * satıra kopyalardı.
     */
    public function locationGid(): ?string
    {
        $gid = $this->connection->settings[self::LOCATION_KEY] ?? null;

        return is_string($gid) && $gid !== '' ? $gid : null;
    }

    private function accessToken(): ?string
    {
        $token = $this->secrets()['access_token'] ?? null;

        return is_string($token) ? $token : null;
    }

    /** Webhook sırrı kasadan okunur; bağlam beklenmez. */
    private function webhookSecret(): ?string
    {
        $secret = $this->secrets()['webhook_secret'] ?? null;

        return is_string($secret) ? $secret : null;
    }

    /**
     * Kasadaki sırlar — AÇIKÇA SİSTEM BAĞLAMINDA.
     *
     * `channel_credentials` kiracıya göre kapsanır ama bu adapter kuyruk
     * işinden, `runAsSystem()` taramasından ve webhook doğrulamasından da
     * çağrılır; oralarda bağlam YOKTUR. Kapsanmış sorgu istisna fırlatır ve
     * istek SESSİZCE KİMLİKSİZ giderdi (`97a7eb7`'de yaşanmış hata).
     *
     * @return array<string, mixed>
     */
    private function secrets(): array
    {
        return TenantContext::runAsSystem(function (): array {
            try {
                return app(CredentialVault::class)->read($this->connection);
            } catch (Throwable) {
                return [];
            }
        });
    }

    /**
     * Başlık okuma — ad BÜYÜK/KÜÇÜK HARFTEN bağımsızdır.
     *
     * HTTP başlık adları büyük/küçük harf duyarsızdır ve vekil sunucular
     * onları yeniden yazar. Tam eşleşme aransaydı `X-Shopify-Hmac-Sha256`
     * gönderen kanal `x-shopify-hmac-sha256` aranırken bulunamaz ve MEŞRU
     * webhook reddedilirdi; kanal sonsuza kadar yeniden gönderirdi.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $values) {
            if (mb_strtolower((string) $key) !== $name) {
                continue;
            }

            $value = is_array($values) ? ($values[0] ?? null) : $values;

            return is_string($value) ? $value : null;
        }

        return null;
    }
}
