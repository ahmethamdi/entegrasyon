<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Support\PruneApiCalls;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §13 · Faz 3 · api_calls saklama taraması.
 *
 * api_calls bu şemanın EN ÇOK YAZILAN tablosudur: her kanal çağrısı bir
 * satır yazar — başarı, hata, ağ kopması. `expires_at` bugüne kadar
 * DOLDURULUYORDU (2xx +7 gün, 4xx/5xx +90 gün) ama SİLEN HİÇBİR ŞEY YOKTU;
 * saklama politikası yalnızca bir niyet olarak duruyordu ve tablo sınırsız
 * büyüyordu.
 *
 * Migration'ın kendi yorumu bunu şart koşuyor: "Günlük bakım işi partileyerek
 * siler; tablo sabit boyutta kalır ve bölümlemeye gerek kalmaz."
 */
final class PruneApiCallsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SÜRESİ GEÇEN SATIR SİLİNİR, GEÇMEYEN KALIR.
     *
     * Taramanın tek işi bu; ölçüt `expires_at` ve o alan yazılırken zaten
     * 2xx/hata ayrımını taşıyor. Tarama durum kodunu YENİDEN YORUMLAMAZ —
     * yorumlasaydı saklama politikası iki yerde yaşar ve biri değiştiğinde
     * diğeri sessizce eski kuralı uygulardı.
     */
    #[Test]
    public function it_deletes_expired_rows_and_keeps_live_ones(): void
    {
        $expired = $this->apiCall(expiresInDays: -1);
        $live = $this->apiCall(expiresInDays: 7);

        $deleted = app(PruneApiCalls::class)->run();

        $this->assertSame(1, $deleted);
        $this->assertFalse($this->exists($expired), 'süresi geçen satır silinmedi — tablo sınırsız büyür.');
        $this->assertTrue($this->exists($live), 'süresi geçmeyen satır silindi — iz kaybı.');
    }

    /**
     * BİR SANİYE SONRA DOLACAK SATIR BU TURDA KALIR.
     *
     * Eşiğin yönünü değil, eşiğin GERÇEKTEN uygulandığını sınar: yüklem
     * tümden kaldırılsa (veya `expires_at > clock_timestamp()` gibi ters
     * çevrilse) bu satır da giderdi.
     *
     * DÜRÜST SINIR — `<` ile `<=` ARASINDAKİ FARK SINANAMAZ. `expires_at`
     * kolonunun hassasiyeti SIFIRDIR (`datetime_precision = 0`, saniyeye
     * yuvarlanır) ama `clock_timestamp()` mikrosaniye taşır; iki değerin
     * TAM eşit olması pratikte imkânsızdır ve operatör mutasyonu bu yüzden
     * hayatta kalır. Sahte test YAZILMADI. `<` duruyor çünkü `expires_at`
     * "bu ana kadar saklanacak" demektir; yuvarlama satırı zaten
     * yazıldığından bir saniyeye kadar ileriye taşıdığı için farkın gerçek
     * bir bedeli de yoktur (bkz. CLAUDE.md · zaman damgası tuzağı).
     */
    #[Test]
    public function a_row_that_expires_one_second_from_now_survives_this_run(): void
    {
        $future = $this->apiCallExpiringInSeconds(1);

        app(PruneApiCalls::class)->run();

        $this->assertTrue(
            $this->exists($future),
            'süresi henüz dolmamış satır silindi — yüklem hiç uygulanmıyor.',
        );
    }

    /**
     * SİLME PARTİLENİR — TEK DEV DELETE TABLOYU KİLİTLER.
     *
     * En çok yazılan tabloda aylarca birikmiş milyonlarca satırı tek DELETE
     * ile silmek tabloyu dakikalarca kilitler ve o süre boyunca HİÇBİR kanal
     * çağrısı günlüklenemez (`api_calls` yazımı çağrıyı düşürmüyor ama iz
     * kaybolur). Parti boyutu aşıldığında tarama birden çok tur atar.
     */
    #[Test]
    public function deletion_is_chunked_rather_than_one_large_statement(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->apiCall(expiresInDays: -1);
        }

        $statements = 0;
        DB::listen(function ($query) use (&$statements): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'delete')) {
                $statements++;
            }
        });

        $deleted = app(PruneApiCalls::class)->run(chunkSize: 2);

        $this->assertSame(5, $deleted, 'partileme satır kaçırdı.');
        $this->assertGreaterThan(
            1,
            $statements,
            'silme tek DELETE ile yapıldı — büyük tabloda kilit dakikalarca sürer.',
        );
    }

    /**
     * TUR BAŞINA ÜST SINIR VAR — BAKIM PENCERESİ SONSUZA KADAR TUTULMAZ.
     *
     * Aylarca hiç çalışmamış bir kurulumda birikmiş satır sayısı milyonlarca
     * olabilir. Tarama bitene kadar dönseydi günlük bakım turu saatlerce
     * sürer, `withoutOverlapping` yüzünden sonraki turlar hiç başlamaz ve
     * tarama kendi kuyruğunu kilitlerdi. Kalan satırlar YARIN silinir.
     */
    #[Test]
    public function a_single_run_stops_at_the_per_run_ceiling(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->apiCall(expiresInDays: -1);
        }

        $deleted = app(PruneApiCalls::class)->run(chunkSize: 2, maxRows: 4);

        $this->assertSame(4, $deleted, 'tur başına üst sınır uygulanmadı.');
        $this->assertSame(1, $this->remaining(), 'üst sınırın ötesindeki satır silinmemeliydi.');
    }

    /**
     * TARAMA TÜM KİRACILARI GÖRÜR.
     *
     * Saklama politikası kiracıya göre değişmez ve kiracı bağlamıyla
     * çalışan bir tarama yalnızca birinin satırlarını temizler; geri
     * kalanların günlükleri sonsuza kadar birikirdi. Erişim `runAsSystem()`
     * ile AÇIKÇA alınır.
     */
    #[Test]
    public function it_prunes_rows_of_every_tenant(): void
    {
        $a = $this->apiCall(expiresInDays: -1, tenantId: (string) Str::uuid7());
        $b = $this->apiCall(expiresInDays: -1, tenantId: (string) Str::uuid7());

        // Bağlam TEK kiracıya kurulu: tarama yine ikisini de görmeli.
        TenantContext::runFor($a['tenant_id'], function (): void {
            app(PruneApiCalls::class)->run();
        });

        $this->assertFalse($this->exists($a));
        $this->assertFalse($this->exists($b), 'başka kiracının süresi geçen satırı kaldı.');
    }

    /** Silinecek satır yokken tur sessizce sıfırla döner. */
    #[Test]
    public function an_empty_table_is_not_an_error(): void
    {
        $this->assertSame(0, app(PruneApiCalls::class)->run());
    }

    /** Komut çalışır, silinen sayısını yazar ve sıfırla çıkar. */
    #[Test]
    public function the_command_runs_and_reports_the_deleted_count(): void
    {
        $this->apiCall(expiresInDays: -1);

        $this->artisan('api-calls:prune')
            ->expectsOutput('1')
            ->assertSuccessful();

        $this->assertSame(0, $this->remaining());
    }

    // ---------------------------------------------------------------- yardımcılar

    /**
     * api_calls satırı — model YOK, tablo `DB::table()` ile yazılır.
     *
     * @return array{id: int, tenant_id: string}
     */
    private function apiCall(int $expiresInDays, ?string $tenantId = null): array
    {
        $tenantId ??= (string) Str::uuid7();

        $id = DB::table('api_calls')->insertGetId([
            'tenant_id' => $tenantId,
            'channel_connection_id' => (string) Str::uuid7(),
            'method' => 'GET',
            'endpoint' => 'https://example.test/wp-json/wc/v3/products',
            'status_code' => 200,
            'duration_ms' => 12,
            'called_at' => now()->subDays(30),
            'expires_at' => now()->addDays($expiresInDays),
        ]);

        return ['id' => $id, 'tenant_id' => $tenantId];
    }

    /**
     * Saniye ölçeğinde dolacak satır — gün ölçeği eşiği sınamak için fazla kaba.
     *
     * @return array{id: int, tenant_id: string}
     */
    private function apiCallExpiringInSeconds(int $seconds): array
    {
        $row = $this->apiCall(expiresInDays: 0);

        DB::table('api_calls')
            ->where('id', $row['id'])
            ->update(['expires_at' => now()->addSeconds($seconds)]);

        return $row;
    }

    /** @param array{id: int, tenant_id: string} $row */
    private function exists(array $row): bool
    {
        return DB::table('api_calls')->where('id', $row['id'])->exists();
    }

    private function remaining(): int
    {
        return DB::table('api_calls')->count();
    }
}
