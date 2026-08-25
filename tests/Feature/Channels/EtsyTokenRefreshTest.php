<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Channels\Support\TokenRefresher;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Etsy token yenileme ENTEGRASYONU — slice 3.2.
 *
 * V3.0 · §11.2 · §20 · P0-5 · T-V3-15 · v2.2 §7.
 *
 * ─────────────────────────────────────────────────────────────────────
 * BU SLICE'TA ÇEKİRDEK KODU YOK — SORU "ÇALIŞIYOR MU"
 * ─────────────────────────────────────────────────────────────────────
 * `TokenRefresher` Faz 0'da yazıldı ve KANAL BİLMEZ: adayları
 * `channel_credentials.expires_at` üzerinden seçer ve yeteneği
 * `instanceof SupportsTokenRefresh` ile okur. `EtsyAdapter` o arayüzü
 * slice 3.1'de uyguladı.
 *
 * Ama "arayüz uygulandı" ile "tarama gerçekten yeniliyor" AYNI ŞEY
 * DEĞİLDİR: `expires_at` kasaya yazılmıyorsa aday hiç seçilmez, tur her
 * seferinde sıfır bağlantı işler ve **1 saatlik token bir saat sonra
 * sessizce ölür**. Hiçbir birim testi bunu göstermez.
 *
 * `EtsyAdapterTest` gövdeyi sınar; BU test ZİNCİRİ sınar.
 */
final class EtsyTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ SÜRESİ YAKLAŞAN ETSY BAĞLANTISI TARAMADA GERÇEKTEN YENİLENİR.
     *
     * Zincirin tamamı: kasadaki `expires_at` → aday sorgusu → kilit →
     * `instanceof` kapısı → adapter çağrısı → kasaya yazma.
     * Herhangi bir halka kopuksa tur `refreshed = 0` döner.
     */
    #[Test]
    public function an_expiring_etsy_connection_is_refreshed_by_the_scan(): void
    {
        [$tenant, $connection] = $this->etsyConnection(expiresInSeconds: 300);

        Http::fake(['*' => Http::response([
            'access_token' => '12345.taze-access',
            'refresh_token' => '12345.taze-refresh',
            'expires_in' => 3600,
        ], 200)]);

        $result = app(TokenRefresher::class)->run();

        $this->assertSame(
            1,
            $result['refreshed'],
            'Etsy bağlantısı taramada HİÇ yenilenmedi — zincirin bir halkası '
            .'kopuk ve 1 saatlik token bir saat sonra SESSİZCE ölür.',
        );

        $stored = TenantContext::runAsSystem(
            fn (): array => app(CredentialVault::class)->read($connection)
        );

        $this->assertSame('12345.taze-access', $stored['access_token']);
        $this->assertSame('12345.taze-refresh', $stored['refresh_token']);
    }

    /**
     * ⚠️ YENİ `expires_at` KASAYA YAZILIR — yoksa tur SONSUZA KADAR
     * aynı satırı yeniler.
     *
     * Yazılmasaydı `expires_at` eski değerde kalır, satır her 15
     * dakikada bir yeniden aday olur ve her turda gereksiz bir yenileme
     * yapılırdı. Etsy'de bedeli AĞIRDIR: refresh token TEK
     * KULLANIMLIKTIR ve her tur onu tüketir.
     */
    #[Test]
    public function the_new_expiry_is_persisted(): void
    {
        [, $connection] = $this->etsyConnection(expiresInSeconds: 300);

        Http::fake(['*' => Http::response([
            'access_token' => '12345.taze',
            'refresh_token' => '12345.taze-refresh',
            'expires_in' => 3600,
        ], 200)]);

        app(TokenRefresher::class)->run();

        $expiresAt = TenantContext::runAsSystem(
            fn () => DB::table('channel_credentials')
                ->where('channel_connection_id', $connection->id)
                ->whereNull('revoked_at')
                ->value('expires_at')
        );

        $this->assertNotNull($expiresAt);
        $this->assertGreaterThan(
            time() + 3000,
            strtotime((string) $expiresAt),
            'Yeni süre kasaya yazılmadı — satır her turda yeniden aday olur '
            .'ve TEK KULLANIMLIK refresh token her turda tüketilir.',
        );
    }

    /**
     * ⚠️ SÜRESİ UZAK OLAN SATIRA DOKUNULMAZ.
     *
     * Adapter'ın payı 900 sn'dir. Bir saatlik pay varmış gibi
     * davranılsaydı her tur her bağlantıyı yeniler ve Etsy'nin tek
     * kullanımlık refresh token'ı boşuna tüketilirdi.
     */
    #[Test]
    public function a_token_with_plenty_of_life_is_left_alone(): void
    {
        $this->etsyConnection(expiresInSeconds: 3000);

        Http::fake();

        $result = app(TokenRefresher::class)->run();

        $this->assertSame(0, $result['refreshed']);
        Http::assertNothingSent();
    }

    /**
     * ⚠️ BAŞARISIZ YENİLEME BAĞLANTIYI ÖLDÜRMEZ, İŞARETLER (§20).
     *
     * `revoked_at` YAZILMAZ: tarama sonraki turda yeniden dener.
     * Yazılsaydı geçici bir ağ hatası satıcının bağlantısını KALICI
     * olarak öldürür ve yeniden yetkilendirme gerektirirdi.
     */
    #[Test]
    public function a_failed_refresh_marks_but_does_not_kill(): void
    {
        [, $connection] = $this->etsyConnection(expiresInSeconds: 300);

        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $result = app(TokenRefresher::class)->run();

        $this->assertSame(1, $result['failed']);

        $row = TenantContext::runAsSystem(
            fn () => DB::table('channel_credentials')
                ->where('channel_connection_id', $connection->id)
                ->first()
        );

        $this->assertNull(
            $row->revoked_at,
            'Başarısız yenileme bağlantıyı ÖLDÜRDÜ — geçici bir ağ hatası '
            .'satıcıyı yeniden yetkilendirmeye zorlardı.',
        );

        $connectionRow = TenantContext::runAsSystem(
            fn () => ChannelConnection::query()->find($connection->id)
        );

        $this->assertNotNull($connectionRow->last_error);
    }

    /**
     * ⚠️ HATA METNİ MASKELENİR — kasadaki sır `last_error`'a SIZMAZ.
     *
     * Kanal 401 gövdesinde token'ı yansıtabilir ve `last_error` panele
     * Inertia prop'u olarak gider; maskelenmezse sır TARAYICIDA görünür
     * ve kasa şifrelemesinin tüm anlamı kaybolur (§11 · katman 2).
     */
    #[Test]
    public function the_failure_message_never_leaks_the_token(): void
    {
        [, $connection] = $this->etsyConnection(expiresInSeconds: 300);

        // Kanal, gövdesinde REFRESH TOKEN'I yansıtıyor — gerçek bir vaka.
        Http::fake(['*' => Http::response(
            ['error' => 'invalid_grant', 'token' => '12345.eski-refresh-cok-gizli'],
            400,
        )]);

        app(TokenRefresher::class)->run();

        $lastError = (string) TenantContext::runAsSystem(
            fn () => ChannelConnection::query()->find($connection->id)->last_error
        );

        $this->assertStringNotContainsString(
            '12345.eski-refresh-cok-gizli',
            $lastError,
            'Refresh token `last_error`\'a sızdı — o kolon panele Inertia '
            .'prop\'u olarak gider ve sır TARAYICIDA görünür.',
        );
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function etsyConnection(int $expiresInSeconds): array
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'etsy'],
            [
                'name' => 'Etsy',
                'kind' => 'marketplace',
                'adapter_class' => EtsyAdapter::class,
                'supports_webhooks' => false,
                'is_active' => false,
            ],
        ));

        $tenant = (new CreateTenant)->run(
            name: 'Etsy Yenileme '.uniqid(),
            owner: User::factory()->create(),
        );

        $connection = $this->asTenant($tenant, function () use ($expiresInSeconds): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'etsy',
                'external_account_id' => 'etsy-'.uniqid(),
                'status' => 'active',
                'settings' => [
                    EtsyAdapter::KEYSTRING_KEY => 'key-abc',
                    EtsyAdapter::SHOP_ID_KEY => '777',
                ],
            ]);

            app(CredentialVault::class)->store(
                $connection,
                [
                    'access_token' => '12345.eski-access',
                    'refresh_token' => '12345.eski-refresh-cok-gizli',
                ],
                null,
                // Süre KASAYA yazılır — aday sorgusu TAM OLARAK bunu okur.
                new \DateTimeImmutable('@'.(time() + $expiresInSeconds)),
            );

            return $connection;
        });

        return [$tenant, $connection];
    }
}
