<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CircuitBreaker — ölü kanala binlerce istek atılmasını engeller.
 *
 * Mimari Karar Dokümanı v2.2 · §12 · Devre kesici, §17 · P0 listesi.
 *
 * DEĞİŞMEZ KURAL — ARDIŞIK 10 HATA → 5 DAKİKA DURAKLATMA:
 *   Kanal çöktüğünde her iş kendi zaman aşımını bekler; yüzlerce iş
 *   paralel olarak ölü bir uca yüklenir ve hem kota hem worker havuzu
 *   boşa harcanır. Devre açıkken işler hızlıca ertelenir.
 *
 * DEĞİŞMEZ KURAL — AUTHENTICATION DEVREYİ SÜRESİZ AÇAR:
 *   Token geçersizse bekleyerek düzelmez; kullanıcı müdahalesi gerekir.
 *   Süre koymak, her beş dakikada bir kesin başarısız olacak bir isteği
 *   tekrar denemek demektir.
 *
 * SAYAÇ BAŞARIDA SIFIRLANIR: "ardışık" hata sayılır, toplam değil. Aksi
 * halde günler içinde birikmiş dağınık hatalar sağlıklı bir kanalı keserdi.
 */
final class CircuitBreakerTest extends TestCase
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

    /** Başlangıçta devre KAPALI — istek geçer. */
    #[Test]
    public function circuit_starts_closed(): void
    {
        $breaker = app(CircuitBreaker::class);

        $this->assertTrue($breaker->allows($this->connectionId));
        $this->assertSame('closed', $breaker->state($this->connectionId));
    }

    /**
     * Ardışık 10 hata devreyi AÇAR; 9 hata açmaz.
     *
     * Eşiğin altında kesmek, geçici tek tük hatalarda kanalı gereksiz yere
     * duraklatırdı.
     */
    #[Test]
    public function ten_consecutive_failures_open_the_circuit(): void
    {
        $breaker = app(CircuitBreaker::class);

        for ($i = 1; $i <= 9; $i++) {
            $breaker->recordFailure($this->connectionId, ErrorClass::SERVER_ERROR);
        }

        $this->assertTrue(
            $breaker->allows($this->connectionId),
            'Dokuz hata devreyi açmamalı.',
        );

        $breaker->recordFailure($this->connectionId, ErrorClass::SERVER_ERROR);

        $this->assertFalse(
            $breaker->allows($this->connectionId),
            'Onuncu ardışık hata devreyi AÇMALI.',
        );
        $this->assertSame('open', $breaker->state($this->connectionId));
    }

    /**
     * BAŞARI SAYACI SIFIRLAR — "ardışık" hata sayılır, toplam değil.
     */
    #[Test]
    public function success_resets_the_failure_counter(): void
    {
        $breaker = app(CircuitBreaker::class);

        for ($i = 1; $i <= 9; $i++) {
            $breaker->recordFailure($this->connectionId, ErrorClass::SERVER_ERROR);
        }

        $breaker->recordSuccess($this->connectionId);

        // Sayaç sıfırlandı; dokuz hata daha devreyi açmamalı.
        for ($i = 1; $i <= 9; $i++) {
            $breaker->recordFailure($this->connectionId, ErrorClass::SERVER_ERROR);
        }

        $this->assertTrue(
            $breaker->allows($this->connectionId),
            'Başarı sayacı sıfırlamalı; dağınık hatalar birikmemeli.',
        );
    }

    /**
     * AUTHENTICATION devreyi TEK hatada ve SÜRESİZ açar.
     *
     * Token geçersizken beklemek işe yaramaz; kimlik bilgisi yenilenene
     * kadar kapalı kalmalıdır.
     */
    #[Test]
    public function authentication_error_opens_the_circuit_immediately_and_indefinitely(): void
    {
        $breaker = app(CircuitBreaker::class);

        $breaker->recordFailure($this->connectionId, ErrorClass::AUTHENTICATION);

        $this->assertFalse(
            $breaker->allows($this->connectionId),
            'AUTHENTICATION tek hatada devreyi açmalı.',
        );

        // SÜRESİZ: anahtara TTL konmaz.
        $ttl = Redis::connection()->ttl(CircuitBreaker::keyFor($this->connectionId));

        $this->assertSame(
            -1,
            $ttl,
            'AUTHENTICATION devresine süre KONMAMALI (-1 = TTL yok).',
        );
    }

    /** Süreli devrenin anahtarı 5 dakika sonra kendiliğinden düşer. */
    #[Test]
    public function transient_open_circuit_expires_after_the_pause_window(): void
    {
        $breaker = app(CircuitBreaker::class);

        for ($i = 1; $i <= 10; $i++) {
            $breaker->recordFailure($this->connectionId, ErrorClass::SERVER_ERROR);
        }

        $ttl = Redis::connection()->ttl(CircuitBreaker::keyFor($this->connectionId));

        $this->assertGreaterThan(0, $ttl, 'Geçici devreye TTL konmalı.');
        $this->assertLessThanOrEqual(
            CircuitBreaker::PAUSE_SECONDS,
            $ttl,
            'Duraklatma 5 dakikayı aşmamalı.',
        );
    }

    /**
     * Duraklatma bitince devre HALF_OPEN olur ve TEK deneme geçer.
     *
     * Doğrudan kapatmak, hâlâ ölü olan bir kanala tüm yükü bir anda geri
     * salardı ve kanal ayağa kalkarken tekrar çökerdi.
     */
    #[Test]
    public function circuit_becomes_half_open_after_the_pause_and_allows_one_probe(): void
    {
        $breaker = app(CircuitBreaker::class);

        // Kısa duraklatmayla aç — beş dakika beklenmez.
        $breaker->openFor($this->connectionId, seconds: 1);

        $this->assertFalse($breaker->allows($this->connectionId));

        sleep(2);

        $this->assertSame(
            'half_open',
            $breaker->state($this->connectionId),
            'Duraklatma bitince yarı açık olmalı.',
        );

        // İlk deneme geçer.
        $this->assertTrue($breaker->allows($this->connectionId), 'Yarı açıkta tek deneme geçmeli.');

        // İkincisi geçmez — sonda tektir.
        $this->assertFalse(
            $breaker->allows($this->connectionId),
            'Yarı açıkta İKİNCİ deneme geçmemeli.',
        );
    }

    /** Yarı açıkta başarı devreyi KAPATIR. */
    #[Test]
    public function success_in_half_open_closes_the_circuit(): void
    {
        $breaker = app(CircuitBreaker::class);

        $breaker->openFor($this->connectionId, seconds: 1);

        sleep(2);

        $breaker->allows($this->connectionId);          // sonda
        $breaker->recordSuccess($this->connectionId);

        $this->assertSame('closed', $breaker->state($this->connectionId));
        $this->assertTrue($breaker->allows($this->connectionId));
    }

    /** Yarı açıkta hata devreyi TEKRAR açar. */
    #[Test]
    public function failure_in_half_open_reopens_the_circuit(): void
    {
        $breaker = app(CircuitBreaker::class);

        $breaker->openFor($this->connectionId, seconds: 1);

        sleep(2);

        $breaker->allows($this->connectionId);          // sonda
        $breaker->recordFailure($this->connectionId, ErrorClass::SERVER_ERROR);

        $this->assertSame('open', $breaker->state($this->connectionId));
        $this->assertFalse($breaker->allows($this->connectionId));
    }

    /**
     * DEVRELER BAĞLANTI BAŞINA AYRIDIR.
     *
     * Bir kanalın çökmesi diğerlerini durdurmaz — T4'ün izolasyon kuralının
     * devre kesici seviyesindeki karşılığı.
     */
    #[Test]
    public function circuits_are_isolated_per_connection(): void
    {
        $breaker = app(CircuitBreaker::class);

        $other = 'conn-'.uniqid();

        $breaker->recordFailure($this->connectionId, ErrorClass::AUTHENTICATION);

        $this->assertFalse($breaker->allows($this->connectionId));
        $this->assertTrue(
            $breaker->allows($other),
            'Bir bağlantının devresi diğerini etkilememeli.',
        );
    }

    /** Elle sıfırlama — kullanıcı token'ı yeniledikten sonra. */
    #[Test]
    public function reset_closes_an_indefinitely_open_circuit(): void
    {
        $breaker = app(CircuitBreaker::class);

        $breaker->recordFailure($this->connectionId, ErrorClass::AUTHENTICATION);

        $this->assertFalse($breaker->allows($this->connectionId));

        $breaker->reset($this->connectionId);

        $this->assertTrue($breaker->allows($this->connectionId));
        $this->assertSame('closed', $breaker->state($this->connectionId));
    }

    /**
     * Redis çökerse senkron DURMAZ — istek geçer.
     *
     * Devre kesici koruma katmanıdır. Redis erişilemezken tüm senkronu
     * durdurmak, korumaya çalıştığı sorundan daha büyük bir zarardır.
     */
    #[Test]
    public function redis_failure_does_not_block_the_call(): void
    {
        $breaker = new CircuitBreaker(connectionName: 'gecersiz-baglanti');

        $this->assertTrue($breaker->allows($this->connectionId));
        $this->assertSame('closed', $breaker->state($this->connectionId));

        // Yazma da patlamamalı.
        $breaker->recordFailure($this->connectionId, ErrorClass::AUTHENTICATION);
        $breaker->recordSuccess($this->connectionId);

        $this->assertTrue($breaker->allows($this->connectionId));
    }
}
