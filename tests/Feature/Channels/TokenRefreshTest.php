<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Channels\Support\TokenRefresher;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Channels\FakeAdapter;
use Tests\Support\Channels\ProgrammableTokenRefreshAdapter;
use Tests\TestCase;

/**
 * Token yenileme taraması — V3.0'ın üçüncü çekirdek Delta'sı.
 *
 * V3.0 · §03 · Delta 3 · §20 · P0-5 · T-V3-15.
 *
 * NEDEN VAR: Etsy'nin access token'ı 1 SAAT, eBay'inki 2 saat yaşar. Yenileme
 * olmadan Etsy'de HER İKİNCİ mutabakat turu 401 alır ve `AUTHENTICATION`
 * KALICI sayılır — listing'ler "anahtarın yanlış" damgasıyla TOPLU ölür.
 * Oysa anahtar doğrudur, yalnızca süresi dolmuştur.
 *
 * ⚠️ EN KRİTİK İDDİA (P0-5): YENİLEME PARALEL KOŞAMAZ. Aynı bağlantı için iki
 * tur aynı anda yenilerse ikisi de yeni token alır ve KANAL İLKİNİ İPTAL EDER
 * — Etsy ve eBay'de refresh token TEK KULLANIMLIKTIR. Sonuç: her iki turun
 * elindeki token da geçersizdir ve bağlantı ÖLÜR.
 *
 * `expires_at` v2.2 §4'te ZATEN TANIMLI ve `INDEX(expires_at) WHERE
 * revoked_at IS NULL` da var — bugüne kadar hiçbir kod onu okumadı. Bu faz
 * onu kullanan ilk fazdır (§16 · DB Delta 2: NO SCHEMA CHANGE REQUIRED).
 */
final class TokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ProgrammableTokenRefreshAdapter::reset();
    }

    /**
     * Süresi dolmak üzere olan token YENİLENİR ve kasaya YAZILIR.
     *
     * Yazmayı ÇEKİRDEK yapar: adapter yalnızca sonuç döner (v2.2 · "adapter
     * yan etkisizdir"). Adapter yazsaydı `channel_credentials`'ın tek yazma
     * kapısı olan kasa devre dışı kalır ve anahtar sürümü ile maskeleme
     * yüzeyi ikiye bölünürdü.
     */
    #[Test]
    public function an_expiring_token_is_refreshed_and_stored_by_the_core(): void
    {
        $connection = $this->connectionWithCredential(expiresInSeconds: 300);

        ProgrammableTokenRefreshAdapter::returnSecrets(['access_token' => 'TAZE-TOKEN']);

        $result = app(TokenRefresher::class)->run();

        $this->assertSame(1, $result['refreshed']);
        $this->assertSame(1, ProgrammableTokenRefreshAdapter::refreshCalls());

        $secrets = $this->asSystem(
            fn (): array => app(CredentialVault::class)->read($connection->fresh())
        );

        $this->assertSame('TAZE-TOKEN', $secrets['access_token']);
    }

    /**
     * Yenilenen kayıt YENİ `expires_at` taşır.
     *
     * Yazılmasaydı satır her turda yeniden aday olur, token sonsuza kadar
     * yenilenir ve kanalın kotası boşa giderdi — üstelik her yenileme eskisini
     * iptal ettiği için gerçek bir zarar da verirdi.
     */
    #[Test]
    public function refreshing_advances_the_expiry(): void
    {
        $connection = $this->connectionWithCredential(expiresInSeconds: 300);

        $before = $this->credentialRow($connection)->expires_at;

        app(TokenRefresher::class)->run();

        $after = $this->credentialRow($connection)->expires_at;

        $this->assertNotSame(
            $before,
            $after,
            'expires_at ilerlemedi — satır her turda yeniden aday olur.',
        );
        $this->assertGreaterThan(strtotime((string) $before), strtotime((string) $after));
    }

    /**
     * ⚠️ P0-5 · T-V3-15 — SÜRESİ HENÜZ DOLMAYAN TOKEN'A DOKUNULMAZ.
     *
     * Pay (`refreshLeadSeconds`) adapter'ındır. Tarama her turda her token'ı
     * yenileseydi refresh token'lar boşuna tüketilir ve tek kullanımlık
     * oldukları için her tur bir öncekini geçersiz kılardı.
     */
    #[Test]
    public function a_token_that_is_not_due_yet_is_left_alone(): void
    {
        // Pay 15 dakika; token 50 dakika sonra ölüyor — vakti GELMEDİ.
        ProgrammableTokenRefreshAdapter::useLeadSeconds(900);
        $this->connectionWithCredential(expiresInSeconds: 3000);

        $result = app(TokenRefresher::class)->run();

        $this->assertSame(0, ProgrammableTokenRefreshAdapter::refreshCalls());
        $this->assertSame(0, $result['refreshed']);
    }

    /**
     * `SupportsTokenRefresh` UYGULAMAYAN kanal SESSİZCE atlanır.
     *
     * Woo, Trendyol ve Hepsiburada kalıcı anahtar taşır. İstisnaya
     * bırakılsaydı her tur o bağlantılar için bir uyarı satırı yazar ve
     * gerçek arızalar gürültüde kaybolurdu (`ReconcileActiveConnections`
     * kuralının aynısı).
     */
    #[Test]
    public function a_channel_without_the_capability_is_skipped_silently(): void
    {
        $this->connectionWithCredential(
            expiresInSeconds: 300,
            adapterClass: FakeAdapter::class,
        );

        $result = app(TokenRefresher::class)->run();

        $this->assertSame(0, $result['refreshed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, $result['skipped']);
    }

    /**
     * `expires_at` NULL olan kayıt ADAY DEĞİLDİR.
     *
     * Kalıcı anahtar taşıyan üç kanal süre bilgisi yazmaz. Aday sayılsaydı
     * her tur onları da gezer, kilit alır ve yeteneksiz diye atlardı —
     * boşuna sorgu, boşuna kilit.
     */
    #[Test]
    public function credentials_without_an_expiry_are_never_candidates(): void
    {
        $this->connectionWithCredential(expiresInSeconds: null);

        $result = app(TokenRefresher::class)->run();

        $this->assertSame(0, ProgrammableTokenRefreshAdapter::refreshCalls());
        $this->assertSame(0, $result['skipped'], 'NULL expires_at satırı sorgudan HİÇ dönmemeliydi.');
    }

    /**
     * İPTAL EDİLMİŞ kimlik bilgisi yenilenmez.
     *
     * `revoked_at` "bu token geçersiz" demektir; yenilemeye çalışmak kanaldan
     * kesin bir hata alır ve her turda tekrarlanırdı.
     */
    #[Test]
    public function revoked_credentials_are_never_refreshed(): void
    {
        $connection = $this->connectionWithCredential(expiresInSeconds: 300);

        DB::table('channel_credentials')
            ->where('channel_connection_id', $connection->id)
            ->update(['revoked_at' => now()]);

        app(TokenRefresher::class)->run();

        $this->assertSame(0, ProgrammableTokenRefreshAdapter::refreshCalls());
    }

    /**
     * BAŞARISIZ YENİLEME BAĞLANTIYI ÖLDÜRMEZ, İŞARETLER (§20).
     *
     * `revoked_at` YAZILMAZ: tarama sonraki turda yeniden dener. Yazsaydı
     * geçici bir ağ hatası bağlantıyı KALICI olarak öldürür ve satıcı yeniden
     * yetkilendirmek zorunda kalırdı.
     *
     * HATA METNİ MASKELENİR: kanalın 401 gövdesi refresh token'ı yansıtabilir
     * ve `last_error` panele Inertia prop'u olarak gider (v2.2 ·
     * `ChannelErrorText` kuralı — sır tarayıcıya kadar sızardı).
     */
    #[Test]
    public function a_failed_refresh_marks_the_connection_without_killing_it(): void
    {
        $connection = $this->connectionWithCredential(expiresInSeconds: 300);

        ProgrammableTokenRefreshAdapter::failNextRefresh();

        $result = app(TokenRefresher::class)->run();

        $this->assertSame(1, $result['failed']);

        $row = $this->credentialRow($connection);
        $this->assertNull($row->revoked_at, 'Başarısız yenileme bağlantıyı ÖLDÜRDÜ.');

        $fresh = $connection->fresh();
        $this->assertNotNull($fresh->last_error);
        $this->assertStringNotContainsString(
            'ESKI-REFRESH',
            (string) $fresh->last_error,
            'Refresh token last_error içinde MASKELENMEDEN yazıldı — panele sızar.',
        );
    }

    /**
     * Tarama `runAsSystem()` ile TÜM kiracıları görür.
     *
     * Bağlam altında koşsaydı yalnızca tek kiracının token'ları yenilenir ve
     * geri kalan her kiracının bağlantısı sessizce ölürdü.
     */
    #[Test]
    public function the_scan_sees_every_tenant(): void
    {
        $this->connectionWithCredential(expiresInSeconds: 300);
        $this->connectionWithCredential(expiresInSeconds: 300);

        $result = app(TokenRefresher::class)->run();

        $this->assertSame(2, $result['refreshed']);
    }

    private function connectionWithCredential(
        ?int $expiresInSeconds,
        string $adapterClass = ProgrammableTokenRefreshAdapter::class,
    ): ChannelConnection {
        $tenant = Tenant::factory()->create();
        $code = 'etsy_test_'.substr((string) $tenant->id, -8);

        $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => 'Etsy (test)',
                'kind' => 'marketplace',
                'adapter_class' => $adapterClass,
                'is_active' => true,
            ],
        ));

        return $this->asTenant($tenant, function () use ($code, $expiresInSeconds): ChannelConnection {
            $connection = ChannelConnection::factory()->create(['channel_type_code' => $code]);

            app(CredentialVault::class)->store(
                $connection,
                ['access_token' => 'ESKI-TOKEN', 'refresh_token' => 'ESKI-REFRESH'],
                'listings_r listings_w',
                $expiresInSeconds === null
                    ? null
                    : new \DateTimeImmutable('+'.$expiresInSeconds.' seconds'),
            );

            return $connection;
        });
    }

    private function credentialRow(ChannelConnection $connection): object
    {
        $row = DB::table('channel_credentials')
            ->where('channel_connection_id', $connection->id)
            ->first();

        $this->assertNotNull($row);

        return $row;
    }
}
