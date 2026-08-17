<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Support\ChannelRateLimiter;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ChannelRateLimiter — ortak Redis kovası.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · Sorumluluk dağılımı, §12, §13 · faz 1.4.
 *
 * DEĞİŞMEZ KURAL — PROFİLİ ADAPTER BİLDİRİR, UYGULAMAYI ÇEKİRDEK YAPAR:
 *   Sınır kanala ve hatta hesaba özgüdür (Trendyol'da satıcı seviyesine göre
 *   değişir ve yanıt başlığından öğrenilir), ama kova mantığı ORTAKTIR.
 *   Her adapter kendi kovasını yazsaydı bir kanalın hatası diğerlerinin
 *   sınırını da bozardı ve "kaç istek attık" sorusu kanal başına farklı
 *   cevaplanırdı.
 *
 * KOVA BAĞLANTI BAŞINADIR, KİRACI BAŞINA DEĞİL:
 *   Sınırı koyan kanaldır ve kanal bağlantıyı (mağaza hesabını) tanır.
 *   Aynı kiracının iki Woo mağazası ayrı kotalara sahiptir; tek kovada
 *   toplansalardı biri diğerini aç bırakırdı.
 *
 * ZAMAN REDIS'TEN OKUNUR:
 *   Kova doldurma PHP'nin saatine değil Redis'in saatine dayanır. Birden çok
 *   worker sürecinde PHP saatleri milisaniyeler kadar kayabilir ve o kayma
 *   kovayı olduğundan hızlı doldurur.
 */
final class ChannelRateLimiterTest extends TestCase
{
    private string $connectionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionId = 'conn-'.uniqid();

        Redis::connection()->flushdb();
    }

    protected function tearDown(): void
    {
        Redis::connection()->flushdb();

        parent::tearDown();
    }

    /** Kova kapasitesi kadar istek hemen geçer. */
    #[Test]
    public function requests_within_burst_capacity_pass_immediately(): void
    {
        $limiter = app(ChannelRateLimiter::class);
        $profile = new RateLimitProfile(requestsPerSecond: 5, burstCapacity: 3);

        for ($i = 1; $i <= 3; $i++) {
            $this->assertTrue(
                $limiter->attempt($this->connectionId, $profile),
                "Kapasite içindeki {$i}. istek geçmeli.",
            );
        }
    }

    /** Kapasite tükendiğinde istek REDDEDİLİR — beklemez, false döner. */
    #[Test]
    public function request_beyond_capacity_is_rejected(): void
    {
        $limiter = app(ChannelRateLimiter::class);
        $profile = new RateLimitProfile(requestsPerSecond: 1, burstCapacity: 2);

        $this->assertTrue($limiter->attempt($this->connectionId, $profile));
        $this->assertTrue($limiter->attempt($this->connectionId, $profile));

        // Üçüncü istek kovayı aşar.
        $this->assertFalse(
            $limiter->attempt($this->connectionId, $profile),
            'Kapasite aşıldığında istek reddedilmeli.',
        );
    }

    /**
     * Kova ZAMANLA dolar — yeniden doldurma oranı requestsPerSecond'dır.
     *
     * Sabit pencere yerine token kovası kullanılıyor: sabit pencere sınır
     * çizgisinde iki kat isteğe izin verir (pencerenin sonunda N, hemen
     * ardından yeni pencerede N daha).
     */
    #[Test]
    public function bucket_refills_over_time(): void
    {
        $limiter = app(ChannelRateLimiter::class);
        $profile = new RateLimitProfile(requestsPerSecond: 10, burstCapacity: 1);

        $this->assertTrue($limiter->attempt($this->connectionId, $profile));
        $this->assertFalse($limiter->attempt($this->connectionId, $profile));

        // 10 istek/sn → bir jeton 100 ms'de dolar.
        usleep(150_000);

        $this->assertTrue(
            $limiter->attempt($this->connectionId, $profile),
            'Kova zamanla dolmalı.',
        );
    }

    /**
     * KOVALAR BAĞLANTI BAŞINA AYRIDIR.
     *
     * Bir bağlantının kotasını tüketmek diğerini etkilemez; aksi halde tek
     * bir yoğun mağaza tüm kiracıların senkronunu durdururdu.
     */
    #[Test]
    public function buckets_are_isolated_per_connection(): void
    {
        $limiter = app(ChannelRateLimiter::class);
        $profile = new RateLimitProfile(requestsPerSecond: 1, burstCapacity: 1);

        $other = 'conn-'.uniqid();

        $this->assertTrue($limiter->attempt($this->connectionId, $profile));
        $this->assertFalse($limiter->attempt($this->connectionId, $profile));

        // Diğer bağlantı kendi kovasına sahip.
        $this->assertTrue(
            $limiter->attempt($other, $profile),
            'Bir bağlantının tükenmesi diğerini etkilememeli.',
        );
    }

    /**
     * Bir sonraki jetona kalan süre söylenir — çağıran işi o kadar erteler.
     *
     * İş "sonra dene" diye kuyruğa geri konurken gecikme buradan gelir;
     * körlemesine sabit bekleme ya kota israf eder ya sınırı tekrar deler.
     */
    #[Test]
    public function reports_seconds_until_next_token(): void
    {
        $limiter = app(ChannelRateLimiter::class);
        $profile = new RateLimitProfile(requestsPerSecond: 2, burstCapacity: 1);

        $limiter->attempt($this->connectionId, $profile);

        $wait = $limiter->secondsUntilAvailable($this->connectionId, $profile);

        // 2 istek/sn → jeton 0.5 sn'de dolar; en az 1 sn'ye yuvarlanır.
        $this->assertGreaterThanOrEqual(1, $wait);
        $this->assertLessThanOrEqual(2, $wait);
    }

    /** Kova doluyken bekleme SIFIRDIR. */
    #[Test]
    public function no_wait_when_tokens_are_available(): void
    {
        $limiter = app(ChannelRateLimiter::class);
        $profile = new RateLimitProfile(requestsPerSecond: 5, burstCapacity: 5);

        $this->assertSame(
            0,
            $limiter->secondsUntilAvailable($this->connectionId, $profile),
            'Jeton varken beklenmemeli.',
        );
    }

    /**
     * Kova anahtarı SÜRESİ DOLAR — ölü bağlantılar Redis'i şişirmez.
     *
     * Kiracı bağlantıyı sildiğinde kova anahtarı kimse tarafından
     * temizlenmez; TTL olmadan Redis'te sonsuza kadar kalırdı.
     */
    #[Test]
    public function bucket_key_has_a_ttl(): void
    {
        $limiter = app(ChannelRateLimiter::class);
        $profile = new RateLimitProfile(requestsPerSecond: 5, burstCapacity: 5);

        $limiter->attempt($this->connectionId, $profile);

        $ttl = Redis::connection()->ttl(ChannelRateLimiter::keyFor($this->connectionId));

        $this->assertGreaterThan(0, $ttl, 'Kova anahtarına TTL konmalı.');
    }

    /**
     * Redis çökerse senkron DURMAZ — istek geçer.
     *
     * Rate limiter bir KORUMA katmanıdır, doğruluk kuralı değil. Redis
     * erişilemezken tüm senkronu durdurmak, kanalın koyduğu sınırı aşma
     * riskinden daha büyük bir zarardır: kanal 429 döndüğünde RetryPolicy
     * zaten devreye girer.
     */
    #[Test]
    public function redis_failure_does_not_block_the_call(): void
    {
        $limiter = new ChannelRateLimiter(connectionName: 'gecersiz-baglanti');

        $this->assertTrue(
            $limiter->attempt($this->connectionId, new RateLimitProfile(1, 1)),
            'Redis erişilemezken istek geçmeli — limiter koruma katmanıdır.',
        );

        $this->assertSame(
            0,
            $limiter->secondsUntilAvailable($this->connectionId, new RateLimitProfile(1, 1)),
        );
    }
}
