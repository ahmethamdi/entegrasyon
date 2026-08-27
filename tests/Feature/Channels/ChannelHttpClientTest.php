<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Enums\ErrorClass;
use App\Support\Logging\PayloadRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

/**
 * ChannelHttpClient — istek yürütme, api_calls yazımı, maskeleme.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · api_calls, §7 · Sorumluluk dağılımı,
 * §11 · maskeleme, §13 · faz 1.4.
 *
 * DEĞİŞMEZ KURAL — HER ÇAĞRI KAYDEDİLİR:
 *   Başarılı da hatalı da olsa api_calls satırı yazılır. Bu tablo şikayet ve
 *   destek soruşturmasının dayanağıdır; "o istek gitti mi" sorusunun tek
 *   cevabı burasıdır.
 *
 * DEĞİŞMEZ KURAL — SIR LOGLARA SIZMAZ:
 *   Maskeleme İKİ katmanlıdır. Anahtar adına göre maskeleme yetmez: kimlik
 *   bilgisi bir hata mesajının İÇİNDE düz metin geçebilir
 *   ("Invalid API key: ck_abc..."). İkinci katman bilinen sır değerlerini
 *   gövdede arayıp değiştirir.
 *
 * SAKLAMA expires_at İLE: 2xx +7 gün, 4xx/5xx +90 gün. Hata kayıtları uzun
 * yaşar çünkü soruşturma onlara dayanır; başarılı gövdeler büyüktür ve
 * kısa tutulur.
 */
final class ChannelHttpClientTest extends TestCase
{
    use RefreshDatabase;

    /** Başarılı çağrı api_calls satırı yazar ve 2xx için 7 gün saklanır. */
    #[Test]
    public function successful_call_is_recorded_with_seven_day_retention(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        Http::fake([
            '*' => Http::response(['id' => 42, 'stock_quantity' => 9], 200),
        ]);

        $response = $this->asTenant($tenant, fn () => $this->clientFor($connection)->request(
            method: 'POST',
            endpoint: 'products/42',
            body: ['stock_quantity' => 9],
        ));

        $this->assertTrue($response->successful());
        $this->assertSame(200, $response->status());
        $this->assertSame(42, $response->json('id'));

        $call = $this->lastApiCall();

        $this->assertSame('POST', $call->method);
        $this->assertStringContainsString('products/42', $call->endpoint);
        $this->assertSame(200, $call->status_code);
        $this->assertSame($tenant->id, $call->tenant_id);
        $this->assertSame($connection->id, $call->channel_connection_id);
        $this->assertNotNull($call->duration_ms);
        $this->assertNull($call->error_class);

        // 2xx → +7 gün.
        $this->assertEqualsWithDelta(
            now()->addDays(7)->timestamp,
            CarbonImmutable::parse($call->expires_at)->timestamp,
            60,
            '2xx kaydı 7 gün saklanmalı.',
        );
    }

    /** Hatalı çağrı da KAYDEDİLİR ve 90 gün saklanır. */
    #[Test]
    public function failed_call_is_recorded_with_ninety_day_retention(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        Http::fake([
            '*' => Http::response(['message' => 'Invalid parameter'], 400),
        ]);

        $response = $this->asTenant($tenant, fn () => $this->clientFor($connection)->request(
            method: 'PUT',
            endpoint: 'products/7',
            body: ['stock_quantity' => -1],
        ));

        $this->assertTrue($response->failed());

        $call = $this->lastApiCall();

        $this->assertSame(400, $call->status_code);

        // 4xx/5xx → +90 gün. Şikayet ve destek soruşturması bunlara dayanır.
        $this->assertEqualsWithDelta(
            now()->addDays(90)->timestamp,
            CarbonImmutable::parse($call->expires_at)->timestamp,
            60,
            'Hata kaydı 90 gün saklanmalı.',
        );
    }

    /**
     * Ağ hatasında da satır yazılır — istek gitti ama yanıt gelmedi.
     *
     * Kayıt olmazsa "bu çağrı hiç yapılmadı" sanılır; TIMEOUT sonucu
     * belirsizdir ve tam da bu yüzden iz bırakması gerekir.
     */
    #[Test]
    public function network_failure_is_recorded_and_rethrown(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $threw = false;

        try {
            $this->asTenant($tenant, fn () => $this->clientFor($connection)->request(
                method: 'GET',
                endpoint: 'products',
            ));
        } catch (ConnectionException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Ağ hatası çağırana YÜKSELTİLMELİ — sınıflandırmayı adapter yapar.');

        $call = $this->lastApiCall();

        $this->assertNull($call->status_code, 'Yanıt gelmediyse durum kodu yok.');
        $this->assertSame(ErrorClass::NETWORK->value, $call->error_class);
        $this->assertNotNull($call->duration_ms);
    }

    /**
     * Sır ne istekte ne yanıtta düz metin kalır — İKİ katman da sınanır.
     *
     * Katman 1 anahtar adına göre maskeler (Authorization başlığı).
     * Katman 2 sır DEĞERİNİ gövdede arar: kanal hata mesajının içine
     * anahtarı basabilir ve katman 1 bunu yakalayamaz.
     */
    #[Test]
    public function secrets_never_reach_the_api_call_log(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        $secret = 'cs_super_secret_value_1234567890';

        $this->asTenant($tenant, fn () => app(CredentialVault::class)->store($connection, [
            'consumer_key' => 'ck_public_key_1234567890',
            'consumer_secret' => $secret,
        ]));

        // Kanal sırrı hata mesajının İÇİNDE geri veriyor — katman 2 senaryosu.
        Http::fake([
            '*' => Http::response(['message' => "Invalid signature for secret {$secret}"], 401),
        ]);

        $this->asTenant($tenant, fn () => $this->clientFor($connection)->request(
            method: 'POST',
            endpoint: 'products',
            body: [
                'name' => 'Test',
                'consumer_secret' => $secret,     // katman 1: anahtar adı
            ],
        ));

        $call = $this->lastApiCall();

        $encoded = json_encode([$call->request_body, $call->response_body]);

        $this->assertStringNotContainsString(
            $secret,
            (string) $encoded,
            'Sır api_calls kaydına düz metin sızmış.',
        );

        // Katman 1: anahtar adına göre maskelendi.
        $requestBody = json_decode((string) $call->request_body, true);

        $this->assertSame(PayloadRedactor::REDACTED, $requestBody['consumer_secret']);
        $this->assertSame('Test', $requestBody['name'], 'Hassas olmayan alan korunmalı.');

        // Katman 2: yanıt gövdesindeki düz metin sır değiştirildi.
        $responseBody = json_decode((string) $call->response_body, true);

        $this->assertStringContainsString(
            PayloadRedactor::REDACTED,
            $responseBody['message'],
        );
    }

    /**
     * Rate limit başlığı okunur — panelde kalan kota görünür.
     */
    #[Test]
    public function rate_limit_header_is_captured(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        Http::fake([
            '*' => Http::response(['ok' => true], 200, ['X-RateLimit-Remaining' => '37']),
        ]);

        $this->asTenant($tenant, fn () => $this->clientFor($connection)->request(
            method: 'GET',
            endpoint: 'products',
        ));

        $this->assertSame(37, $this->lastApiCall()->rate_limit_remaining);
    }

    /**
     * Deneme kimliği taşınır — hangi çağrı hangi denemeye ait, izlenebilir.
     *
     * api_calls üzerinde FK YOKTUR (en çok yazılan tablo, teknik günlük);
     * ilişki sync_attempt_id alanı üzerinden kurulur.
     */
    #[Test]
    public function attempt_id_is_carried_into_the_log(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        $attemptId = (string) new UuidV7;

        Http::fake(['*' => Http::response([], 200)]);

        $this->asTenant($tenant, fn () => $this->clientFor($connection)->request(
            method: 'GET',
            endpoint: 'products',
            attemptId: $attemptId,
        ));

        $this->assertSame($attemptId, $this->lastApiCall()->sync_attempt_id);
    }

    /**
     * Kaydetme başarısız olsa bile ÇAĞRI SONUCU kaybolmaz.
     *
     * Günlükleme yan iştir; onun hatası senkronu düşürmemelidir. Tersi
     * olsaydı dolu bir disk tüm senkronu durdururdu.
     */
    #[Test]
    public function logging_failure_does_not_break_the_call(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        Http::fake(['*' => Http::response(['id' => 5], 200)]);

        // api_calls tablosunu düşürerek yazımı imkânsız kıl.
        $this->asSystem(fn () => DB::statement('DROP TABLE api_calls'));

        $response = $this->asTenant($tenant, fn () => $this->clientFor($connection)->request(
            method: 'GET',
            endpoint: 'products/5',
        ));

        $this->assertSame(200, $response->status(), 'Günlükleme hatası çağrıyı düşürmemeli.');
        $this->assertSame(5, $response->json('id'));
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function makeConnection(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'HTTP '.uniqid(),
            owner: User::factory()->create(),
        );

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'store',
                'adapter_class' => 'App\\Domain\\Channels\\Adapters\\WooCommerce\\WooCommerceAdapter',
                'is_active' => true,
                'rate_limit_profile' => ['requests_per_second' => 5, 'burst_capacity' => 10],
            ],
        ));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'channel_type_code' => 'woocommerce',
            'external_account_id' => 'shop.example.com',
            'settings' => ['base_url' => 'https://shop.example.com/wp-json/wc/v3/'],
        ]));

        return [$tenant, $connection];
    }

    /**
     * ADAPTER KENDİ BAŞLIĞINI EKLEYEBİLİR — ama istemci KANAL BİLMEZ.
     *
     * Bazı kanallar kimlik doğrulamanın PARÇASI olarak özel başlık ister:
     * Hepsiburada `User-Agent: {merchantId} - {AppName}` bekler ve eksikse
     * **401 döner** (kimlik bilgisi doğru olsa bile). Bu, projede daha önce
     * yaşanmış "sessizce kimliksiz gitti" hatasının bir başka biçimidir —
     * anahtar doğru, istek reddediliyor ve sebep görünmüyor.
     *
     * Başlıklar `if ($channel === '...')` ile DEĞİL, çağıranın verdiği
     * dizi ile taşınır: istemci hangi kanal olduğunu bilmez ve yeni kanal
     * eklendiğinde bu sınıf DEĞİŞMEZ (basic auth çiftlerinin tek yerde
     * toplanmasıyla aynı gerekçe).
     */
    #[Test]
    public function adapter_supplied_headers_are_sent(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        Http::fake(['*' => Http::response([], 200)]);

        $this->asTenant($tenant, fn () => $this->clientFor($connection)->request(
            method: 'GET',
            endpoint: 'listings',
            headers: ['User-Agent' => '12345 - Entegrasyon'],
        ));

        Http::assertSent(static fn ($request): bool => $request->hasHeader(
            'User-Agent',
            '12345 - Entegrasyon',
        ));
    }

    // ────────────────────────────────── OAuth token isteği (V3.0 · §13.3)

    /**
     * ⚠️ ADAPTER'IN VERDİĞİ `Authorization` KASA TARAFINDAN EZİLMEZ.
     *
     * Bu, eBay'in token YENİLEME isteğinin var olma koşuludur. Kasada
     * `access_token` bulunduğu için istemci normalde `withToken()` çağırır
     * ve `Authorization: Bearer ...` yazar; ama yenileme isteği tam da o
     * ölü token'ı tazelemek için atılıyor ve OAuth uç noktası
     * `Basic {client_id:client_secret}` bekliyor.
     *
     * Kapı olmasaydı yenileme sessizce Bearer ile gider, 401 alır ve
     * bağlantı "anahtarın yanlış" damgasıyla ölürdü — satıcı anahtarı
     * defalarca yeniden girer, hiçbiri işe yaramazdı çünkü anahtar
     * DOĞRUDUR ve yalnızca yanlış BİÇİMDE gönderilmiştir.
     */
    #[Test]
    public function an_adapter_supplied_authorization_header_is_not_overwritten_by_the_vault(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        $this->asTenant($tenant, fn () => app(CredentialVault::class)->store(
            $connection,
            ['access_token' => 'olu-token'],
        ));

        Http::fake(['*' => Http::response([], 200)]);

        $this->asTenant($tenant, fn () => $this->clientFor($connection)->post(
            endpoint: 'identity/v1/oauth2/token',
            body: ['grant_type' => 'refresh_token'],
            headers: ['Authorization' => 'Basic '.base64_encode('app:secret')],
            asForm: true,
        ));

        // ⚠️ İDDİA DEĞER SAYISINA BAKAR, TEK BİR DEĞERİN VARLIĞINA DEĞİL.
        //
        // `hasHeader('Authorization', 'Basic ...')` TEK BAŞINA yetmez:
        // kasa kendi değerini AYNI anahtarın yanına eklediğinde o iddia
        // YİNE yeşil kalır ve istek iki kimlikle gider (mutasyonla
        // bulundu — ayrıntı bir alttaki testin başlığında).
        Http::assertSent(static function ($request): bool {
            $values = [];

            foreach ($request->headers() as $name => $sent) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $values = [...$values, ...$sent];
                }
            }

            return $values === ['Basic '.base64_encode('app:secret')];
        });
    }

    /**
     * ⚠️ KARŞILAŞTIRMA HARF DUYARSIZDIR (RFC 9110 · §5.1).
     *
     * Tam eşleşme aransaydı `authorization` yazan bir adapter kapıya
     * TAKILMAZ ve kasa KENDİ başlığını EKLERDİ.
     *
     * ⚠️ İDDİA "BEARER DEĞERİ GÖNDERİLMEDİ" DEĞİL, "İKİNCİ BİR BAŞLIK
     * HİÇ YOK"TUR — ve bu ayrım MUTASYONLA bulundu. İlk yazımda iddia
     * `! hasHeader('Authorization', 'Bearer ...')` idi ve `strcasecmp`'i
     * `===` yapan mutasyon HAYATTA KALDI.
     *
     * Sebep — ÖLÇÜLDÜ, varsayılmadı: Laravel başlık anahtarlarını
     * NORMALİZE ETMEZ ve `withToken()` küçük harfli `authorization`'ı
     * EZMEZ; değerini AYNI anahtarın YANINA ekler. Giden istekte
     * `authorization` tek anahtardır ama İKİ DEĞER taşır:
     * `["Basic kucuk-harf", "Bearer olu-token"]`. HTTP'de bu, virgülle
     * birleşmiş TEK bir başlık olarak gider — sunucu ya reddeder ya
     * birini seçer ve davranış öngörülemez olur.
     *
     * İlk iddia (`! hasHeader(..., 'Bearer ...')`) bunu göremedi çünkü
     * eşleşme TAM DEĞERE bakıyor ve iki değerli başlıkta o karşılaştırma
     * `false` dönüyordu. İkinci iddia (anahtar SAYISI) de göremedi çünkü
     * anahtar zaten TEKTİ. Ölçülmesi gereken şey DEĞER SAYISIDIR.
     */
    #[Test]
    public function the_authorization_guard_is_case_insensitive(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        $this->asTenant($tenant, fn () => app(CredentialVault::class)->store(
            $connection,
            ['access_token' => 'olu-token'],
        ));

        Http::fake(['*' => Http::response([], 200)]);

        $this->asTenant($tenant, fn () => $this->clientFor($connection)->post(
            endpoint: 'identity/v1/oauth2/token',
            body: ['grant_type' => 'refresh_token'],
            headers: ['authorization' => 'Basic kucuk-harf'],
        ));

        Http::assertSent(static function ($request): bool {
            $values = [];

            foreach ($request->headers() as $name => $sent) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $values = [...$values, ...$sent];
                }
            }

            return $values === ['Basic kucuk-harf'];
        });
    }

    /**
     * ⚠️ `asForm` GÖVDEYİ FORM-ENCODED GÖNDERİR.
     *
     * OAuth 2 token uç noktaları (RFC 6749 · §4.1.3) gövdeyi
     * `application/x-www-form-urlencoded` BEKLER. JSON gönderilseydi eBay
     * alanları HİÇ okumaz, `invalid_request` döner ve sebebi gövdede
     * görünmezdi.
     *
     * Etsy'nin uç noktası JSON'u da kabul ettiği için bu fark beşinci
     * kanalda hiç görünmemişti — "aynı kural iki kanalda ters sonuç
     * verebilir" kuralının taşıma katmanındaki biçimi.
     */
    #[Test]
    public function the_as_form_flag_sends_a_form_encoded_body(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        Http::fake(['*' => Http::response([], 200)]);

        $this->asTenant($tenant, fn () => $this->clientFor($connection)->post(
            endpoint: 'identity/v1/oauth2/token',
            body: ['grant_type' => 'refresh_token', 'refresh_token' => 'r-123'],
            asForm: true,
        ));

        Http::assertSent(static function ($request): bool {
            return str_contains((string) $request->header('Content-Type')[0], 'application/x-www-form-urlencoded')
                && $request->body() === 'grant_type=refresh_token&refresh_token=r-123';
        });
    }

    /**
     * VARSAYILAN JSON'DUR ve bu DEĞİŞMEDİ.
     *
     * Bayrak eklenirken varsayılan yanlışlıkla form'a kaysaydı BEŞ kanalın
     * TÜM çağrıları sessizce bozulurdu — kanal alanları okuyamaz ve her
     * senkron `VALIDATION` alırdı.
     */
    #[Test]
    public function the_default_body_format_is_still_json(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        Http::fake(['*' => Http::response([], 200)]);

        $this->asTenant($tenant, fn () => $this->clientFor($connection)->post(
            endpoint: 'products',
            body: ['stock_quantity' => 9],
        ));

        Http::assertSent(static function ($request): bool {
            return str_contains((string) $request->header('Content-Type')[0], 'application/json')
                && $request->body() === '{"stock_quantity":9}';
        });
    }

    private function clientFor(ChannelConnection $connection): ChannelHttpClient
    {
        return new ChannelHttpClient(
            connection: $connection,
            vault: app(CredentialVault::class),
            redactor: app(PayloadRedactor::class),
        );
    }

    private function lastApiCall(): object
    {
        return $this->asSystem(fn () => DB::table('api_calls')->latest('id')->first());
    }
}
