<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Channels\Support\TokenRefresher;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Channels\ProgrammableTokenRefreshAdapter;
use Tests\TestCase;

/**
 * ⚠️ P0-5 · T-V3-15 — TOKEN YENİLEME PARALEL KOŞAMAZ.
 *
 * V3.0 · §03 · Delta 3 · §20.
 *
 * BU TESTİN VARLIK SEBEBİ: Etsy ve eBay'de refresh token TEK KULLANIMLIKTIR.
 * Aynı bağlantı için iki tur aynı anda yenilerse ikisi de yeni token alır ve
 * KANAL İLKİNİ İPTAL EDER — sonuçta her iki turun elindeki token da geçersiz
 * olur ve bağlantı ÖLÜR. Satıcı yeniden yetkilendirmek zorunda kalır ve
 * sebebini hiçbir yerde göremez.
 *
 * `SKIP LOCKED` yerine düz `FOR UPDATE` kullanılsaydı ikinci tur birincinin
 * bitmesini BEKLER ve ardından AYNI satırı İKİNCİ KEZ yenilerdi — koruma
 * gecikme üretir ama çift yenilemeyi ENGELLEMEZDİ.
 *
 * NEDEN `RefreshDatabase` DEĞİL:
 *   RefreshDatabase her testi tek transaction'a sarar ve geri alır. Bu testin
 *   amacı İKİ AYRI transaction'ın kilit üzerinde karşılaşmasını görmektir;
 *   tek transaction içinde kilit çekişmesi HİÇ oluşmaz ve test yanlış yeşile
 *   döner (v2.2 · eşzamanlılık testi kuralı, `ConcurrentSaleTest` ile aynı).
 */
final class ConcurrentTokenRefreshTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        ProgrammableTokenRefreshAdapter::reset();
    }

    /**
     * DatabaseTruncation KENDİ setUp'ında boşaltır, tearDown'da değil.
     * Commit edilen artık sonraki testlere sızar.
     */
    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    /**
     * Başka bir worker'ın kilitlediği satır ATLANIR — beklenmez, yenilenmez.
     */
    #[Test]
    public function a_row_locked_by_another_worker_is_skipped_not_refreshed(): void
    {
        $connection = $this->connectionWithExpiringCredential();

        // İkinci "worker": satırı kilitler ve transaction'ı AÇIK tutar.
        $other = $this->secondConnection();
        $other->beginTransaction();
        $other->table('channel_credentials')
            ->where('channel_connection_id', $connection->id)
            ->lockForUpdate()
            ->get();

        try {
            $result = app(TokenRefresher::class)->run();
        } finally {
            $other->rollBack();
        }

        $this->assertSame(
            0,
            ProgrammableTokenRefreshAdapter::refreshCalls(),
            'Kilitli satır YENİLENDİ — SKIP LOCKED çalışmıyor. Paralel yenileme '.
            'kanalın ilk token\'ı iptal etmesine yol açar (P0-5).',
        );

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['refreshed']);
    }

    /**
     * Kilit KALKINCA satır normal şekilde yenilenir.
     *
     * Atlama KALICI bir dışlama DEĞİLDİR: bu olmadan bir kez kilitlenen satır
     * sonsuza kadar atlanır ve token sessizce ölürdü. Tarama 15 dakikada bir
     * koşar; atlanan satır sonraki turda yeniden adaydır.
     */
    #[Test]
    public function the_row_is_refreshed_on_the_next_pass_once_the_lock_is_gone(): void
    {
        $connection = $this->connectionWithExpiringCredential();

        $other = $this->secondConnection();
        $other->beginTransaction();
        $other->table('channel_credentials')
            ->where('channel_connection_id', $connection->id)
            ->lockForUpdate()
            ->get();

        app(TokenRefresher::class)->run();      // tur 1 — atlar
        $other->rollBack();                      // kilit kalktı

        $result = app(TokenRefresher::class)->run();  // tur 2 — yeniler

        $this->assertSame(1, $result['refreshed']);
        $this->assertSame(1, ProgrammableTokenRefreshAdapter::refreshCalls());
    }

    /**
     * A'dan bağımsız ikinci bir PostgreSQL bağlantısı.
     *
     * Laravel aynı isimli bağlantıyı önbelleğe alır; ayrı bir isim altında
     * yapılandırma kopyalanarak gerçekten ikinci bir PDO açılır.
     */
    private function secondConnection(): Connection
    {
        $name = 'pgsql_token_refresh';

        config(['database.connections.'.$name => config('database.connections.pgsql')]);

        DB::purge($name);

        return DB::connection($name);
    }

    private function connectionWithExpiringCredential(): ChannelConnection
    {
        $tenant = Tenant::factory()->create();
        $code = 'etsy_lock_'.substr((string) $tenant->id, -8);

        $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => 'Etsy (kilit testi)',
                'kind' => 'marketplace',
                'adapter_class' => ProgrammableTokenRefreshAdapter::class,
                'is_active' => true,
            ],
        ));

        return $this->asTenant($tenant, function () use ($code): ChannelConnection {
            $connection = ChannelConnection::factory()->create(['channel_type_code' => $code]);

            app(CredentialVault::class)->store(
                $connection,
                ['access_token' => 'ESKI-TOKEN', 'refresh_token' => 'ESKI-REFRESH'],
                'listings_r listings_w',
                new \DateTimeImmutable('+300 seconds'),
            );

            return $connection;
        });
    }
}
