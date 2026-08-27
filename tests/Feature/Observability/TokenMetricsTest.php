<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Observability\AlertAudience;
use App\Support\Observability\CaptureMetrics;
use App\Support\Observability\Metric;
use App\Support\Observability\MetricScope;
use App\Support\Observability\MetricScopeKind;
use App\Support\Observability\MetricUnit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §25'in ÜÇ YENİ METRİĞİ — token ömrü, yenileme hatası, günlük kota.
 *
 * V3.0 · §25 · P1-6.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN BU ÜÇÜ — v2.2'nin 13 metriği bunları GÖREMEZ
 * ─────────────────────────────────────────────────────────────────────
 * Mevcut metrikler bir şeyin YAVAŞ veya BOZUK olduğunu ölçer. Token
 * ölümü BAŞKA bir arıza biçimidir: hiçbir şey yavaşlamaz, hata oranı
 * yükselmez — bağlantı bir gün SESSİZCE çalışmayı bırakır ve satıcı
 * bunu ancak siparişler kesilince fark eder. §25 bu yüzden yazıldı.
 *
 * Etsy panelden bağlanabildiği için (A maddesi · `fef3e62`) bu artık
 * teorik değil: refresh token 90 günde ölür.
 */
final class TokenMetricsTest extends TestCase
{
    use RefreshDatabase;

    // ══════════════════════════════════════════════ sözleşme · enum

    /**
     * ⚠️ ÜÇ METRİK DE BAĞLANTI KAPSAMLIDIR (§25 tablosu).
     *
     * Sistem geneli toplansaydı tek bir ölmek üzere olan token yüz
     * bağlantılık kurulumda gürültüde kaybolur ve "hangi bağlantı"
     * sorusu cevapsız kalırdı — oysa eylem tam olarak o bağlantıya
     * özgüdür (yeniden yetkilendirme).
     */
    #[Test]
    public function the_three_new_metrics_are_scoped_per_connection(): void
    {
        foreach ($this->newMetrics() as $metric) {
            $this->assertSame(
                MetricScopeKind::CONNECTION,
                $metric->scopeKind(),
                "{$metric->value} bağlantı kapsamlı olmalı (§25).",
            );
        }
    }

    /**
     * ⚠️ EŞİKLER §25 TABLOSUNUN BİREBİR AYNISIDIR.
     *
     * Eşik `Metric::threshold()` içinde TEK KAYNAKTIR; panelde veya
     * uyarı turunda yeniden tanımlansaydı biri değiştiğinde rozet
     * sessizce yanlış renk gösterirdi.
     */
    #[Test]
    public function the_thresholds_match_the_document(): void
    {
        $this->assertSame(0.0, Metric::TOKEN_EXPIRING_SOON->threshold());
        $this->assertSame(3.0, Metric::TOKEN_REFRESH_FAILURES->threshold());
        $this->assertSame(80.0, Metric::CHANNEL_DAILY_QUOTA_USED->threshold());
    }

    /**
     * ⚠️ KOTA YÜZDEDİR, SAYIM DEĞİL — birim panelde okunur.
     *
     * Ham istek sayısı gösterilseydi "7.842" satıcıya hiçbir şey
     * söylemezdi; tavanı bilmeden o sayı iyi mi kötü mü belli olmaz.
     */
    #[Test]
    public function the_quota_metric_is_a_percentage(): void
    {
        $this->assertSame(
            MetricUnit::PERCENT,
            Metric::CHANNEL_DAILY_QUOTA_USED->unit(),
        );
    }

    // ══════════════════════════════════════ P0 · uyarı KİME gider

    /**
     * ⚠️ TOKEN UYARISI SATICIYA GİDER — §25'İN AÇIK İSTİSNASI.
     *
     * v2.2 kuralı "bağlantı kapsamlı uyarı YÖNETİCİYE gider" der ve
     * gerekçesi doğrudur: api gecikmesi ve 429 satıcının
     * düzeltebileceği şeyler değildir, altyapı sorunudur.
     *
     * Token BAŞKADIR: yeniden yetkilendirmeyi **yalnızca satıcı**
     * yapabilir (kendi Etsy hesabına girip izin verecek). Yöneticiye
     * gitseydi uyarı hiçbir işe yaramaz, yönetici satıcıyı aramak
     * zorunda kalır ve bu arada bağlantı ölü kalırdı.
     */
    #[Test]
    public function token_alerts_go_to_the_seller_not_the_admin(): void
    {
        $this->assertSame(
            AlertAudience::TENANT,
            Metric::TOKEN_EXPIRING_SOON->alertAudience(),
        );
        $this->assertSame(
            AlertAudience::TENANT,
            Metric::TOKEN_REFRESH_FAILURES->alertAudience(),
        );
    }

    /**
     * ⚠️ KOTA UYARISI YÖNETİCİDE KALIR — token'ın AKSİNE.
     *
     * Kota aşımında yapılacak şey stok itme sıklığını düşürmek ve
     * gruplamayı gözden geçirmektir (§21 · P2); satıcının elinde bir
     * düğme YOKTUR. İkisi aynı kefeye konsaydı ya satıcı yapamayacağı
     * bir iş için uyarılır ya da token uyarısı yanlış kişiye giderdi.
     */
    #[Test]
    public function quota_alerts_stay_with_the_admin(): void
    {
        $this->assertSame(
            AlertAudience::ADMIN,
            Metric::CHANNEL_DAILY_QUOTA_USED->alertAudience(),
        );
    }

    /**
     * ⚠️ ESKİ METRİKLERİN ALICISI DEĞİŞMEDİ — bu bir GENİŞLETMEDİR.
     *
     * `alertAudience()` eklenirken varsayılan yanlış seçilseydi on üç
     * metriğin uyarıları sessizce başka kişiye giderdi: fazla satış
     * yöneticiye, api gecikmesi satıcıya. İkisi de yanlış.
     */
    #[Test]
    public function the_existing_metrics_keep_their_audience(): void
    {
        // Kiracı kapsamlı → satıcı (v2.2)
        $this->assertSame(AlertAudience::TENANT, Metric::OVERSOLD_UNITS->alertAudience());
        $this->assertSame(AlertAudience::TENANT, Metric::DEAD_OPERATIONS->alertAudience());

        // Bağlantı kapsamlı ama ALTYAPI sorunu → yönetici (v2.2)
        $this->assertSame(AlertAudience::ADMIN, Metric::API_LATENCY_P95->alertAudience());
        $this->assertSame(AlertAudience::ADMIN, Metric::RATE_LIMIT_HITS->alertAudience());

        // Sistem geneli → yönetici
        $this->assertSame(AlertAudience::ADMIN, Metric::SYNC_ERROR_RATE->alertAudience());
    }

    // ══════════════════════════════ token_expiring_soon · ölçüm

    /**
     * 14 gün içinde dolacak token bir SATIR ÜRETİR ve eşiği aşar.
     *
     * Eşik `> 0`: tek bir tane bile fazladır, çünkü yenilenemeyen
     * token bağlantıyı SESSİZCE öldürür.
     */
    #[Test]
    public function a_token_expiring_within_the_window_is_measured(): void
    {
        $connection = $this->connectionWithCredential(
            'etsy',
            expiresAt: now()->addDays(10),
        );

        $this->capture();

        $value = $this->snapshot(
            Metric::TOKEN_EXPIRING_SOON,
            MetricScope::connection($connection),
        );

        $this->assertSame(1.0, $value);
        $this->assertTrue(Metric::TOKEN_EXPIRING_SOON->breaches($value));
    }

    /**
     * ⚠️ UZAK BİR TARİH SIFIR YAZMAZ — SATIR HİÇ YAZILMAZ.
     *
     * v2.2'nin "ölçülemeyen metrik sıfır yazmaz" kuralının kardeşi ama
     * gerekçesi başka: burada ölçüm YAPILABİLİR, sonuç SIFIRDIR.
     * Sağlıklı her bağlantı her saat bir "0" satırı yazsaydı tablo
     * bağlantı sayısı × 24 satırla dolar ve gerçek sinyal binlerce
     * sıfır arasında kaybolurdu (`RATE_LIMIT_HITS`'in kuralının
     * aynısı).
     */
    #[Test]
    public function a_token_far_from_expiry_writes_no_row(): void
    {
        $connection = $this->connectionWithCredential(
            'etsy',
            expiresAt: now()->addDays(60),
        );

        $this->capture();

        $this->assertNull(
            $this->snapshot(
                Metric::TOKEN_EXPIRING_SOON,
                MetricScope::connection($connection),
            ),
            'Sağlıklı token için satır yazıldı — tablo sıfırlarla dolar.',
        );
    }

    /**
     * ⚠️ SÜRESİZ TOKEN (`expires_at IS NULL`) ÖLÇÜLMEZ.
     *
     * Woo/Trendyol/Shopify kalıcı anahtar taşır ve Shopify'ın offline
     * token'ı SÜRESİZDİR. NULL "hemen doluyor" sayılsaydı o bağlantılar
     * her turda kırmızı yanar ve satıcı hiç bitmeyen bir uyarıyı
     * kapatmaya çalışırdı — uyarıya olan güven biter (§12'nin "aynı
     * uyarı iki kez gitmez" kuralının gerekçesi).
     */
    #[Test]
    public function a_credential_without_an_expiry_is_never_measured(): void
    {
        $connection = $this->connectionWithCredential('woocommerce', expiresAt: null);

        $this->capture();

        $this->assertNull($this->snapshot(
            Metric::TOKEN_EXPIRING_SOON,
            MetricScope::connection($connection),
        ));
    }

    /**
     * ⚠️ SÜRESİ ZATEN DOLMUŞ TOKEN DA SAYILIR — "geçti" diye atlanmaz.
     *
     * Atlansaydı metrik tam da EN KÖTÜ anda susardı: token öldükten
     * sonra bağlantı çalışmıyordur ve uyarı asıl O ZAMAN gerekir.
     * Pencere "14 gün içinde" değil "14 günden daha yakın"dır ve
     * geçmiş de ona dahildir.
     */
    #[Test]
    public function an_already_expired_token_is_still_measured(): void
    {
        $connection = $this->connectionWithCredential(
            'etsy',
            expiresAt: now()->subDays(2),
        );

        $this->capture();

        $this->assertSame(1.0, $this->snapshot(
            Metric::TOKEN_EXPIRING_SOON,
            MetricScope::connection($connection),
        ));
    }

    /**
     * ⚠️ İPTAL EDİLMİŞ KİMLİK BİLGİSİ ÖLÇÜLMEZ.
     *
     * `revoked_at` dolu satır artık kullanılmıyor (Shopify'ın
     * `app/uninstalled` yolu bunu yazar). Ölçülseydi kaldırılmış bir
     * uygulamanın ölü token'ı sonsuza kadar uyarı üretirdi.
     */
    #[Test]
    public function a_revoked_credential_is_not_measured(): void
    {
        $connection = $this->connectionWithCredential(
            'etsy',
            expiresAt: now()->addDays(3),
            revokedAt: now(),
        );

        $this->capture();

        $this->assertNull($this->snapshot(
            Metric::TOKEN_EXPIRING_SOON,
            MetricScope::connection($connection),
        ));
    }

    // ═════════════════════════ token_refresh_failures · ölçüm

    /**
     * Başarısız yenileme çağrıları GÜN İÇİNDE sayılır.
     *
     * ⚠️ SAYI `api_calls`'TAN TÜRETİLİR, AYRI SAYAÇ KOLONU TUTULMAZ.
     * Yenileme çağrıları zaten `ChannelHttpClient` üzerinden gidiyor ve
     * her çağrı bir satır yazıyor. Ayrı bir sayaç kolonu, yazan HER
     * yolun onu da güncellemesini zorunlu kılar ve biri unutulunca iki
     * gerçek kaynağı SESSİZCE ayrışır (§10'un `DriftHistory` kararının
     * aynısı: sayaç ayrı kolonda tutulmaz, türetilir).
     */
    #[Test]
    public function failed_refresh_calls_are_counted_per_day(): void
    {
        $connection = $this->connectionWithCredential('etsy', expiresAt: now()->addDays(30));

        $this->recordTokenCall($connection, status: 401);
        $this->recordTokenCall($connection, status: 400);
        $this->recordTokenCall($connection, status: 500);
        $this->recordTokenCall($connection, status: 401);

        $this->capture();

        $value = $this->snapshot(
            Metric::TOKEN_REFRESH_FAILURES,
            MetricScope::connection($connection),
        );

        $this->assertSame(4.0, $value);
        $this->assertTrue(
            Metric::TOKEN_REFRESH_FAILURES->breaches($value),
            'Günde 4 hata eşiği (>3) aşmalı.',
        );
    }

    /**
     * ⚠️ BAŞARILI YENİLEME SAYILMAZ ve satır ÜRETMEZ.
     *
     * Sayılsaydı sağlıklı bir bağlantı — her 15 dakikada bir yenilenen
     * — günde 96 "hata" raporlar ve metrik tamamen anlamsızlaşırdı.
     */
    #[Test]
    public function successful_refresh_calls_are_not_counted(): void
    {
        $connection = $this->connectionWithCredential('etsy', expiresAt: now()->addDays(30));

        $this->recordTokenCall($connection, status: 200);
        $this->recordTokenCall($connection, status: 200);

        $this->capture();

        $this->assertNull($this->snapshot(
            Metric::TOKEN_REFRESH_FAILURES,
            MetricScope::connection($connection),
        ));
    }

    /**
     * ⚠️ YENİLEME DIŞI ÇAĞRILAR SAYILMAZ — uç nokta AYIRT EDİCİDİR.
     *
     * Bu metrik `api_calls`'tan türetiliyor ve o tablo KANALA GİDEN HER
     * çağrıyı taşır. Süzülmeseydi başarısız bir stok itmesi "token
     * yenilenemedi" sayılır, satıcıya yeniden yetkilendirme yaptırılır
     * ve gerçek sorun (yanlış SKU, kapalı ürün) hiç görünmezdi.
     */
    #[Test]
    public function unrelated_failed_calls_are_not_counted_as_refresh_failures(): void
    {
        $connection = $this->connectionWithCredential('etsy', expiresAt: now()->addDays(30));

        // Aynı bağlantıda BAŞKA bir uç nokta patlıyor.
        $this->recordApiCall(
            $connection,
            endpoint: 'https://openapi.etsy.com/v3/application/listings/1/inventory',
            status: 500,
        );
        $this->recordApiCall(
            $connection,
            endpoint: 'https://openapi.etsy.com/v3/application/listings/2/inventory',
            status: 500,
        );

        $this->capture();

        $this->assertNull(
            $this->snapshot(
                Metric::TOKEN_REFRESH_FAILURES,
                MetricScope::connection($connection),
            ),
            'Stok itme hatası token yenileme hatası sayıldı.',
        );
    }

    /**
     * ⚠️ DÜNKÜ HATALAR BUGÜNÜN SAYIMINA GİRMEZ — eşik GÜNLÜKTÜR.
     *
     * Pencere uygulanmasaydı sayı yalnızca büyür ve bir kez eşiği aşan
     * bağlantı `api_calls` budanana kadar (4xx/5xx için 90 GÜN) her
     * turda uyarı üretirdi.
     */
    #[Test]
    public function failures_older_than_a_day_are_excluded(): void
    {
        $connection = $this->connectionWithCredential('etsy', expiresAt: now()->addDays(30));

        foreach (range(1, 5) as $i) {
            $this->recordTokenCall($connection, status: 401, calledAt: now()->subDays(2));
        }

        $this->capture();

        $this->assertNull($this->snapshot(
            Metric::TOKEN_REFRESH_FAILURES,
            MetricScope::connection($connection),
        ));
    }

    // ═══════════════════════ channel_daily_quota_used · ölçüm

    /**
     * Günlük kotanın kullanılan yüzdesi ölçülür.
     *
     * Etsy'nin tavanı 10.000 istek/gün (§21). 8.500 çağrı = %85 ve bu
     * eşiği (>%80) aşar.
     */
    #[Test]
    public function the_daily_quota_percentage_is_measured(): void
    {
        $connection = $this->connectionWithCredential('etsy', expiresAt: now()->addDays(30));

        $this->seedApiCalls($connection, count: 8_500);

        $this->capture();

        $value = $this->snapshot(
            Metric::CHANNEL_DAILY_QUOTA_USED,
            MetricScope::connection($connection),
        );

        $this->assertSame(85.0, $value);
        $this->assertTrue(Metric::CHANNEL_DAILY_QUOTA_USED->breaches($value));
    }

    /**
     * ⚠️ GÜNLÜK KOTASI OLMAYAN KANAL ÖLÇÜLMEZ — SIFIR DA YAZILMAZ.
     *
     * §25'in açık kuralı: "Kanal kota bilgisi vermiyorsa satır HİÇ
     * yazılmaz; sıfır yazılsaydı grafik 'her şey mükemmel' derdi."
     * Woo satıcının KENDİ sunucusudur ve günlük bir tavanı yoktur;
     * uydurma bir tavana bölünseydi anlamsız bir yüzde çıkardı.
     */
    #[Test]
    public function a_channel_without_a_daily_quota_is_not_measured(): void
    {
        $connection = $this->connectionWithCredential('woocommerce', expiresAt: null);

        $this->seedApiCalls($connection, count: 50);

        $this->capture();

        $this->assertNull(
            $this->snapshot(
                Metric::CHANNEL_DAILY_QUOTA_USED,
                MetricScope::connection($connection),
            ),
            'Günlük kotası olmayan kanal için yüzde uyduruldu.',
        );
    }

    /**
     * ⚠️ TAVAN ADAPTER'DAN OKUNUR, ÇEKİRDEĞE GÖMÜLMEZ.
     *
     * `if ($channel === 'etsy') return 10000;` yazılsaydı yeni kanalın
     * tavanı çekirdekte bir satır uzatırdı ve biri eklemeyi unutunca
     * kanal sessizce ölçülmez olurdu — yeteneklerin `instanceof` ile
     * okunması kuralının kota karşılığı.
     */
    #[Test]
    public function the_ceiling_comes_from_the_adapter(): void
    {
        $etsy = $this->adapterFor('etsy');
        $woo = $this->adapterFor('woocommerce');

        $this->assertSame(10_000, $etsy->dailyRequestQuota());
        $this->assertNull(
            $woo->dailyRequestQuota(),
            'Woo satıcının kendi sunucusudur; günlük tavanı YOKTUR.',
        );
    }

    // ──────────────────────────────────────────────────── yardımcılar

    /** @return list<Metric> */
    private function newMetrics(): array
    {
        return [
            Metric::TOKEN_EXPIRING_SOON,
            Metric::TOKEN_REFRESH_FAILURES,
            Metric::CHANNEL_DAILY_QUOTA_USED,
        ];
    }

    private function adapterFor(string $code): object
    {
        $connectionId = $this->connectionWithCredential($code, expiresAt: null);

        return TenantContext::runAsSystem(function () use ($connectionId): object {
            $connection = ChannelConnection::query()
                ->with('channelType:code,name,adapter_class')
                ->findOrFail($connectionId);

            return app(AdapterRegistry::class)->for($connection);
        });
    }

    private function capture(): void
    {
        app(CaptureMetrics::class)->run();
    }

    private function snapshot(Metric $metric, ?string $scope = null): ?float
    {
        $row = DB::table('metric_snapshots')
            ->where('metric', $metric->value)
            ->where('scope', $scope ?? MetricScope::SYSTEM)
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : (float) $row->value;
    }

    /** Bağlantı + aktif kimlik bilgisi; bağlantı kimliğini döner. */
    private function connectionWithCredential(
        string $code,
        ?\DateTimeInterface $expiresAt,
        ?\DateTimeInterface $revokedAt = null,
    ): string {
        $tenant = $this->makeTenant();

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => ucfirst($code),
                'kind' => $code === 'woocommerce' ? 'storefront' : 'marketplace',
                'adapter_class' => $code === 'etsy'
                    ? EtsyAdapter::class
                    : WooCommerceAdapter::class,
                'supports_webhooks' => $code !== 'etsy',
                'is_active' => true,
            ],
        ));

        return $this->asTenant($tenant, function () use ($code, $expiresAt, $revokedAt, $tenant): string {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => $code,
                'external_account_id' => $code.'-'.uniqid(),
                'status' => 'active',
            ]);

            DB::table('channel_credentials')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'channel_connection_id' => $connection->id,
                'encrypted_payload' => 'sahte',
                'key_version' => 1,
                'expires_at' => $expiresAt,
                'revoked_at' => $revokedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $connection->id;
        });
    }

    /** Token uç noktasına yapılmış bir çağrı satırı. */
    private function recordTokenCall(
        string $connectionId,
        int $status,
        ?\DateTimeInterface $calledAt = null,
    ): void {
        $this->recordApiCall(
            $connectionId,
            endpoint: 'https://openapi.etsy.com/v3/public/oauth/token',
            status: $status,
            calledAt: $calledAt,
        );
    }

    private function recordApiCall(
        string $connectionId,
        string $endpoint,
        int $status,
        ?\DateTimeInterface $calledAt = null,
    ): void {
        TenantContext::runAsSystem(function () use ($connectionId, $endpoint, $status, $calledAt): void {
            $tenantId = ChannelConnection::query()->where('id', $connectionId)->value('tenant_id');

            DB::table('api_calls')->insert([
                'tenant_id' => $tenantId,
                'channel_connection_id' => $connectionId,
                'method' => 'POST',
                'endpoint' => $endpoint,
                'status_code' => $status,
                'duration_ms' => 40,
                'called_at' => $calledAt ?? now(),
                'expires_at' => now()->addDays(90),
            ]);
        });
    }

    private function seedApiCalls(string $connectionId, int $count): void
    {
        TenantContext::runAsSystem(function () use ($connectionId, $count): void {
            $tenantId = ChannelConnection::query()->where('id', $connectionId)->value('tenant_id');

            $rows = [];

            foreach (range(1, $count) as $i) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'channel_connection_id' => $connectionId,
                    'method' => 'GET',
                    'endpoint' => 'https://openapi.etsy.com/v3/application/listings/'.$i,
                    'status_code' => 200,
                    'duration_ms' => 30,
                    'called_at' => now(),
                    'expires_at' => now()->addDays(7),
                ];
            }

            foreach (array_chunk($rows, 1_000) as $chunk) {
                DB::table('api_calls')->insert($chunk);
            }
        });
    }

    private function makeTenant(): Tenant
    {
        return (new CreateTenant)->run(
            name: 'Token '.uniqid(),
            owner: User::factory()->create(),
        );
    }
}
