<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Contracts\RateLimitProfile;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Kanal hız sınırı — ortak Redis jeton kovası.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · Sorumluluk dağılımı, §12, §13 · faz 1.4.
 *
 * DEĞİŞMEZ KURAL — PROFİLİ ADAPTER BİLDİRİR, UYGULAMAYI ÇEKİRDEK YAPAR:
 *   Sınır kanala ve hesaba özgüdür; kova mantığı ortaktır. Her adapter kendi
 *   kovasını yazsaydı "kaç istek attık" sorusu kanal başına farklı
 *   cevaplanırdı ve tek bir hatalı uygulama tüm hesabı bloke ettirirdi.
 *
 * KOVA BAĞLANTI BAŞINADIR: sınırı koyan kanaldır ve kanal mağaza hesabını
 * tanır. Aynı kiracının iki Woo mağazası ayrı kotaya sahiptir.
 *
 * NEDEN JETON KOVASI, SABİT PENCERE DEĞİL:
 *   Sabit pencere sınır çizgisinde iki kat isteğe izin verir — pencerenin
 *   son anında N istek, hemen ardından yeni pencerede N istek daha. Kanal
 *   bunu ani yük olarak görür ve 429 döner.
 *
 * ATOMİKLİK LUA İLE: oku-hesapla-yaz üç ayrı komut olsaydı iki worker aynı
 * jetonu birden alırdı. Redis Lua betiğini tek işlem olarak çalıştırır.
 *
 * ZAMAN REDIS'TEN OKUNUR (`TIME`): worker süreçlerinin PHP saatleri
 * birbirinden kayabilir ve kayma kovayı olduğundan hızlı doldurur.
 */
final class ChannelRateLimiter
{
    /** Kullanılmayan kova bu süre sonunda düşer — ölü bağlantı Redis'i şişirmesin. */
    private const KEY_TTL_SECONDS = 3600;

    /**
     * Jeton al; kova boşsa false.
     *
     * Redis mikro saniye döndürür; hesap milisaniye üzerinden yapılır ve
     * jeton sayısı kesirli tutulur — tam sayıya yuvarlamak düşük hızlarda
     * (1 istek/sn altı) kovayı hiç doldurmazdı.
     */
    private const CONSUME_SCRIPT = <<<'LUA'
        local key      = KEYS[1]
        local rate     = tonumber(ARGV[1])
        local capacity = tonumber(ARGV[2])
        local ttl      = tonumber(ARGV[3])

        local time     = redis.call('TIME')
        local now      = tonumber(time[1]) + (tonumber(time[2]) / 1000000)

        local bucket   = redis.call('HMGET', key, 'tokens', 'updated_at')
        local tokens   = tonumber(bucket[1])
        local updated  = tonumber(bucket[2])

        if tokens == nil then
            tokens  = capacity
            updated = now
        end

        -- Geçen süre kadar doldur, kapasiteyi aşma.
        local elapsed = math.max(0, now - updated)
        tokens = math.min(capacity, tokens + (elapsed * rate))

        local allowed = 0

        if tokens >= 1 then
            tokens = tokens - 1
            allowed = 1
        end

        redis.call('HSET', key, 'tokens', tokens, 'updated_at', now)
        redis.call('EXPIRE', key, ttl)

        return allowed
    LUA;

    /**
     * Bir sonraki jetona kalan saniye — jeton varsa 0.
     *
     * Kova durumunu DEĞİŞTİRMEZ; yalnızca okur.
     */
    private const PEEK_SCRIPT = <<<'LUA'
        local key      = KEYS[1]
        local rate     = tonumber(ARGV[1])
        local capacity = tonumber(ARGV[2])

        local time     = redis.call('TIME')
        local now      = tonumber(time[1]) + (tonumber(time[2]) / 1000000)

        local bucket   = redis.call('HMGET', key, 'tokens', 'updated_at')
        local tokens   = tonumber(bucket[1])
        local updated  = tonumber(bucket[2])

        if tokens == nil then
            return 0
        end

        local elapsed = math.max(0, now - updated)
        tokens = math.min(capacity, tokens + (elapsed * rate))

        if tokens >= 1 then
            return 0
        end

        -- Eksik jetonun dolması için gereken süre, yukarı yuvarlanır.
        return math.ceil((1 - tokens) / rate)
    LUA;

    public function __construct(
        private readonly ?string $connectionName = null,
    ) {}

    public static function keyFor(string $channelConnectionId): string
    {
        return "channel:{$channelConnectionId}:ratelimit";
    }

    /**
     * Jeton tüketmeyi dener.
     *
     * DEĞİŞMEZ KURAL — REDIS ÇÖKERSE SENKRON DURMAZ:
     *   Bu bir KORUMA katmanıdır, doğruluk kuralı değil. Redis erişilemezken
     *   tüm senkronu durdurmak, kanalın sınırını aşma riskinden büyük bir
     *   zarardır: kanal 429 döndüğünde RetryPolicy zaten devreye girer.
     */
    public function attempt(string $channelConnectionId, RateLimitProfile $profile): bool
    {
        try {
            $allowed = $this->redis()->eval(
                self::CONSUME_SCRIPT,
                1,
                self::keyFor($channelConnectionId),
                max($profile->requestsPerSecond, 1),
                max($profile->burstCapacity, 1),
                self::KEY_TTL_SECONDS,
            );

            return (int) $allowed === 1;
        } catch (Throwable $e) {
            $this->warn('ratelimit.unavailable', $channelConnectionId, $e);

            return true;
        }
    }

    /** Bir sonraki jetona kaç saniye kaldı — jeton varsa 0. */
    public function secondsUntilAvailable(string $channelConnectionId, RateLimitProfile $profile): int
    {
        try {
            return (int) $this->redis()->eval(
                self::PEEK_SCRIPT,
                1,
                self::keyFor($channelConnectionId),
                max($profile->requestsPerSecond, 1),
                max($profile->burstCapacity, 1),
            );
        } catch (Throwable $e) {
            $this->warn('ratelimit.peek_unavailable', $channelConnectionId, $e);

            return 0;
        }
    }

    /** Kovayı boşaltır — testler ve elle müdahale için. */
    public function clear(string $channelConnectionId): void
    {
        try {
            $this->redis()->del(self::keyFor($channelConnectionId));
        } catch (Throwable $e) {
            $this->warn('ratelimit.clear_failed', $channelConnectionId, $e);
        }
    }

    private function redis(): Connection
    {
        return Redis::connection($this->connectionName);
    }

    private function warn(string $event, string $connectionId, Throwable $e): void
    {
        Log::warning($event, [
            'connection_id' => $connectionId,
            'reason' => $e->getMessage(),
        ]);
    }
}
