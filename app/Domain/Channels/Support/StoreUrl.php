<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use InvalidArgumentException;

/**
 * Mağaza adresi normalleştirme.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · channel_connections · external_account_id.
 *
 * NORMALLEŞTİRME BİR GÜVENLİK ADIMIDIR, kolaylık değil. `external_account_id`
 * global tekillik kısıtının parçasıdır: bir mağaza yalnızca tek kiracıya
 * bağlanabilir. Kullanıcı adresi çok biçimde girer —
 * `https://Magaza.example.com/`, `magaza.example.com`, `HTTPS://MAGAZA...` —
 * ve normalleştirilmezse aynı mağaza farklı kimliklerle iki kez bağlanır.
 * O noktada kısıt hâlâ "geçerlidir" ama hiçbir şey korumaz.
 *
 * HTTPS VARSAYILIR: WooCommerce anahtar çiftini Basic auth ile taşır ve
 * düz HTTP üzerinde anahtar her istekte ağda açık gider.
 */
final readonly class StoreUrl
{
    private function __construct(
        /** Tekillik anahtarı — yalnızca ana makine adı, küçük harf. */
        public string $host,
        /** İstemcinin kullanacağı taban adres. */
        public string $baseUrl,
    ) {}

    /**
     * @param  string  $wooApiPath  Woo REST kökü; kanal başına değişir
     *
     * @throws InvalidArgumentException Adres ayrıştırılamazsa
     */
    public static function parse(string $input, string $wooApiPath = '/wp-json/wc/v3'): self
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Mağaza adresi boş olamaz.');
        }

        // Şemasız girdiyi parse_url ana makine değil YOL sayar; https ekle.
        if (! preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $trimmed)) {
            $trimmed = 'https://'.$trimmed;
        }

        $parts = parse_url($trimmed);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidArgumentException("Mağaza adresi ayrıştırılamadı: {$input}");
        }

        $host = strtolower($parts['host']);

        // Woo alt dizinde kurulu olabilir: example.com/magaza. Yol korunur
        // ama sondaki eğik çizgi ve API kökü tekrarları atılır.
        $path = rtrim($parts['path'] ?? '', '/');

        if ($path !== '' && str_ends_with($path, $wooApiPath)) {
            $path = substr($path, 0, -strlen($wooApiPath));
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return new self(
            host: $host.$port.$path,
            baseUrl: 'https://'.$host.$port.$path.$wooApiPath,
        );
    }
}
