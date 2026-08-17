<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Sync\Enums\ErrorClass;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Devre kesici — ölü kanala binlerce istek atılmasını engeller.
 *
 * Mimari Karar Dokümanı v2.2 · §12 · Devre kesici, §17 · P0 listesi.
 *
 * DEĞİŞMEZ KURAL — ARDIŞIK 10 HATA → 5 DAKİKA DURAKLATMA:
 *   Kanal çöktüğünde her iş kendi zaman aşımını bekler; yüzlerce iş ölü bir
 *   uca paralel yüklenir, hem kota hem worker havuzu boşa gider. Devre
 *   açıkken işler hızlıca ertelenir ve havuz boşalır.
 *
 * DEĞİŞMEZ KURAL — AUTHENTICATION DEVREYİ SÜRESİZ AÇAR:
 *   Token geçersizse beklemekle düzelmez; kullanıcı müdahalesi gerekir.
 *   Süre koymak, her beş dakikada kesin başarısız olacak bir isteği tekrar
 *   denemek demektir. Kullanıcı kimlik bilgisini yenileyince reset() çağrılır.
 *
 * SAYAÇ BAŞARIDA SIFIRLANIR: "ardışık" hata sayılır. Toplam sayılsaydı
 * günler içinde birikmiş dağınık hatalar sağlıklı bir kanalı keserdi.
 *
 * HALF_OPEN'DA TEK SONDA: duraklatma bitince devre doğrudan kapanmaz. Tüm
 * yükü bir anda geri salmak, hâlâ toparlanmakta olan kanalı tekrar çökertir.
 * Tek istek gönderilir; başarılıysa devre kapanır, değilse yeniden açılır.
 */
final class CircuitBreaker
{
    /** Bu kadar ardışık hata devreyi açar. */
    public const FAILURE_THRESHOLD = 10;

    /** Geçici hatada duraklatma süresi. */
    public const PAUSE_SECONDS = 300;

    /** Sayaç bu süre boyunca hareketsiz kalırsa düşer. */
    private const COUNTER_TTL_SECONDS = 3600;

    public function __construct(
        private readonly ?string $connectionName = null,
    ) {}

    /** Devre durumu anahtarı — dokümandaki biçim. */
    public static function keyFor(string $channelConnectionId): string
    {
        return "channel:{$channelConnectionId}:circuit";
    }

    private static function counterKey(string $channelConnectionId): string
    {
        return "channel:{$channelConnectionId}:circuit:failures";
    }

    private static function probeKey(string $channelConnectionId): string
    {
        return "channel:{$channelConnectionId}:circuit:probe";
    }

    /**
     * İstek geçebilir mi?
     *
     * YAN ETKİLİDİR: half_open durumunda sonda hakkını TÜKETİR. İki worker
     * aynı anda sorarsa yalnızca biri geçer — `SET NX` bunu garanti eder.
     *
     * DEĞİŞMEZ KURAL — REDIS ÇÖKERSE SENKRON DURMAZ: koruma katmanının
     * erişilemezliği, korumaya çalıştığı sorundan büyük zarar vermemeli.
     */
    public function allows(string $channelConnectionId): bool
    {
        try {
            $state = $this->state($channelConnectionId);

            if ($state === 'closed') {
                return true;
            }

            if ($state === 'open') {
                return false;
            }

            // half_open: sonda hakkı TEK. Kazanan tek worker geçer.
            return (bool) $this->redis()->set(
                self::probeKey($channelConnectionId),
                '1',
                'EX',
                self::PAUSE_SECONDS,
                'NX',
            );
        } catch (Throwable $e) {
            $this->warn('circuit.unavailable', $channelConnectionId, $e);

            return true;
        }
    }

    /**
     * Devre durumu: closed | open | half_open.
     *
     * half_open ayrı bir anahtar DEĞİLDİR: süreli devre anahtarı TTL ile
     * kendiliğinden düşer ve o an devre yarı açık sayılır. Ayrı anahtar
     * tutulsaydı TTL biterken iki durum arasında yarış oluşurdu.
     */
    public function state(string $channelConnectionId): string
    {
        try {
            $value = $this->redis()->get(self::keyFor($channelConnectionId));

            if ($value === null || $value === false) {
                // Devre anahtarı yok. Daha önce açılıp süresi dolduysa
                // sonda beklenir; hiç açılmadıysa kapalıdır.
                return $this->awaitingProbe($channelConnectionId) ? 'half_open' : 'closed';
            }

            return (string) $value === 'open' ? 'open' : 'closed';
        } catch (Throwable $e) {
            $this->warn('circuit.state_unavailable', $channelConnectionId, $e);

            return 'closed';
        }
    }

    /**
     * Hata kaydeder; eşiğe ulaşınca devreyi açar.
     *
     * AUTHENTICATION eşiği BEKLEMEZ: ilk hatada ve süresiz açar.
     */
    public function recordFailure(string $channelConnectionId, ErrorClass $class): void
    {
        try {
            if ($class === ErrorClass::AUTHENTICATION) {
                $this->openIndefinitely($channelConnectionId);

                return;
            }

            // Yarı açıkken gelen hata: kanal hâlâ ölü, yeniden aç.
            if ($this->awaitingProbe($channelConnectionId)) {
                $this->openFor($channelConnectionId, self::PAUSE_SECONDS);

                return;
            }

            $failures = (int) $this->redis()->incr(self::counterKey($channelConnectionId));

            if ($failures === 1) {
                $this->redis()->expire(self::counterKey($channelConnectionId), self::COUNTER_TTL_SECONDS);
            }

            if ($failures >= self::FAILURE_THRESHOLD) {
                $this->openFor($channelConnectionId, self::PAUSE_SECONDS);
            }
        } catch (Throwable $e) {
            $this->warn('circuit.record_failure_failed', $channelConnectionId, $e);
        }
    }

    /**
     * Başarı — sayaç sıfırlanır ve devre TAMAMEN kapanır.
     *
     * "Daha önce açıldı" izi de silinir: kalsaydı devre anahtarı yokken
     * durum sonsuza kadar half_open görünür ve her istek sonda hakkı
     * beklerdi — sağlıklı kanal tek istekle sınırlanmış kalırdı.
     */
    public function recordSuccess(string $channelConnectionId): void
    {
        try {
            $this->redis()->del(
                self::counterKey($channelConnectionId),
                self::keyFor($channelConnectionId),
                self::probeKey($channelConnectionId),
                self::openedKey($channelConnectionId),
            );
        } catch (Throwable $e) {
            $this->warn('circuit.record_success_failed', $channelConnectionId, $e);
        }
    }

    /**
     * Devreyi belirli süreyle açar.
     *
     * Sonda anahtarı silinir: yeni duraklatma yeni bir sonda hakkı doğurur.
     */
    public function openFor(string $channelConnectionId, int $seconds): void
    {
        try {
            $this->redis()->setex(self::keyFor($channelConnectionId), max($seconds, 1), 'open');
            $this->redis()->del(self::probeKey($channelConnectionId));

            // "Daha önce açıldı" izi: TTL bitince yarı açık olunacağını bu
            // anahtar söyler ve kendisi devre anahtarından UZUN yaşar.
            $this->redis()->setex(
                self::openedKey($channelConnectionId),
                max($seconds, 1) + self::COUNTER_TTL_SECONDS,
                '1',
            );
        } catch (Throwable $e) {
            $this->warn('circuit.open_failed', $channelConnectionId, $e);
        }
    }

    /** Kimlik hatası — süresiz açık, kullanıcı müdahalesi bekler. */
    public function openIndefinitely(string $channelConnectionId): void
    {
        try {
            // TTL YOK: kimlik bilgisi yenilenene kadar kapalı kalır.
            $this->redis()->set(self::keyFor($channelConnectionId), 'open');
            $this->redis()->del(self::probeKey($channelConnectionId));
            $this->redis()->set(self::openedKey($channelConnectionId), '1');
        } catch (Throwable $e) {
            $this->warn('circuit.open_indefinitely_failed', $channelConnectionId, $e);
        }
    }

    /** Elle sıfırlama — kullanıcı token'ı yeniledikten sonra. */
    public function reset(string $channelConnectionId): void
    {
        try {
            $this->redis()->del(
                self::keyFor($channelConnectionId),
                self::counterKey($channelConnectionId),
                self::probeKey($channelConnectionId),
                self::openedKey($channelConnectionId),
            );
        } catch (Throwable $e) {
            $this->warn('circuit.reset_failed', $channelConnectionId, $e);
        }
    }

    // ---------------------------------------------------------------- iç

    private static function openedKey(string $channelConnectionId): string
    {
        return "channel:{$channelConnectionId}:circuit:opened";
    }

    /**
     * Devre açılmış ama süresi dolmuş mu — yani sonda bekleniyor mu?
     *
     * Sonda zaten alınmışsa yarı açık DEĞİLDİR: o tur bitene kadar diğer
     * işler beklemeye devam eder.
     */
    private function awaitingProbe(string $channelConnectionId): bool
    {
        $opened = $this->redis()->exists(self::openedKey($channelConnectionId));
        $circuit = $this->redis()->exists(self::keyFor($channelConnectionId));

        return (int) $opened === 1 && (int) $circuit === 0;
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
