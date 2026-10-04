<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Exceptions\BlockedDestinationException;
use Closure;

/**
 * Sunucunun kanala attığı isteklerin İÇ AĞA gitmesini engeller (SSRF, B3).
 *
 * KAPATILAN AÇIK: mağaza adresini satıcı yazar ve sunucu oraya kasadaki
 * anahtarla istek atar. Adres `127.0.0.1`, `169.254.169.254` (bulut
 * metadata — sunucunun kendi kimlik bilgileri), `postgres` / `redis`
 * (Docker servis adları) olabiliyordu; yanıt gövdesi de api_calls'a yazılıp
 * panelde gösterildiği için iç servis okunabiliyordu.
 *
 * İKİ KATMAN:
 *   (1) `assertAllowedHost()` — sözdizimi, kayıt anında (StoreUrl). DNS'e
 *       gitmez: adres yazıldığı anda reddedilir, ağ gerektirmez.
 *   (2) `resolve()` — istek anında DNS çözülür ve TÜM adresler genel
 *       olmalıdır. Çağıran bağlantıyı dönen IP'ye SABİTLER (CURLOPT_RESOLVE):
 *       sabitlenmeseydi kontrol ile bağlantı arasında DNS yanıtı değişebilir
 *       ve aynı ad iç adrese döndürülebilirdi (DNS rebinding).
 *
 * Çözümleyici enjekte edilir: testler ağa çıkmaz.
 */
final class OutboundUrlGuard
{
    /** Yalnız varsayılan HTTPS portu — iç servis portlarını taramaya kapı açılmasın. */
    private const ALLOWED_PORTS = [443];

    /** Kamuya açık DNS'te var olmayan, iç ağı işaret eden son ekler. */
    private const INTERNAL_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home', '.intranet', '.corp'];

    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /** @param  (Closure(string): list<string>)|null  $resolver  ad → IP listesi */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::systemResolver();
    }

    /**
     * Ana makine kamuya açık bir hedef gibi YAZILMIŞ mı — DNS'siz.
     *
     * @throws BlockedDestinationException
     */
    public function assertAllowedHost(string $host, ?int $port = null): void
    {
        $host = strtolower(trim($host, " .[]\t\n"));

        if ($port !== null && ! in_array($port, self::ALLOWED_PORTS, true)) {
            throw new BlockedDestinationException("Yalnız standart HTTPS portu (443) desteklenir: {$port}");
        }

        if ($host === '' || $host === 'localhost') {
            throw new BlockedDestinationException('Mağaza adresi geçerli bir alan adı olmalıdır.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! self::isPublicIp($host)) {
                throw new BlockedDestinationException("İç ağ adresine bağlanılamaz: {$host}");
            }

            return;
        }

        // TEK ETİKETLİ AD (nokta yok) kamuya açık DNS'te çözülmez; yalnız
        // iç ağda anlamı vardır: `postgres`, `redis`, `app`, `metadata`.
        if (! str_contains($host, '.')) {
            throw new BlockedDestinationException("Mağaza adresi tam bir alan adı olmalıdır: {$host}");
        }

        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new BlockedDestinationException("İç ağ alan adına bağlanılamaz: {$host}");
            }
        }
    }

    /**
     * İstek anı: adı çözer, TÜM adresler genel olmalı; sabitlenecek IP'yi döner.
     *
     * @throws BlockedDestinationException
     */
    public function resolve(string $host, ?int $port = null): string
    {
        $this->assertAllowedHost($host, $port);

        $host = strtolower(trim($host, '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $addresses = ($this->resolver)($host);

        if ($addresses === []) {
            throw new BlockedDestinationException("Alan adı çözülemedi: {$host}");
        }

        // HEPSİ genel olmalı: biri bile iç adresse istemci onu seçebilir.
        foreach ($addresses as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new BlockedDestinationException("Alan adı iç ağ adresine çözülüyor: {$host}");
            }
        }

        return $addresses[0];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // filter_var'ın KAPSAMADIĞI aralıklar.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            foreach ([
                ['100.64.0.0', 10],   // taşıyıcı NAT (bulutlarda iç ağ)
                ['192.0.0.0', 24],    // IETF protokol atamaları
                ['198.18.0.0', 15],   // kıyaslama ağı
            ] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);

                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }

            return true;
        }

        // IPv4 eşlemeli IPv6 (::ffff:10.0.0.1) yukarıdaki NO_RES_RANGE ile
        // TÜMÜYLE reddedilir (::ffff:0:0/96 ayrılmış aralık) — test edildi.
        return true;
    }

    /** @return Closure(string): list<string> */
    private static function systemResolver(): Closure
    {
        return static function (string $host): array {
            $ips = gethostbynamel($host) ?: [];

            $v6 = @dns_get_record($host, DNS_AAAA) ?: [];

            foreach ($v6 as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }

            return array_values(array_unique($ips));
        };
    }
}
