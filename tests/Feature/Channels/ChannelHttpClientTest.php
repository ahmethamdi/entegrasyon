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
