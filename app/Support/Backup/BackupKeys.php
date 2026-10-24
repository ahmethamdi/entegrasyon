<?php

declare(strict_types=1);

namespace App\Support\Backup;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Off-site anahtar adları: `{önek}34pazar-20261025T020000Z.dump.age` ve
 * yanında `.manifest.json`. Zaman anahtarın içindedir; saklama ve "en yeni
 * yedek" kararı S3'ün LastModified'ına değil adına bakar (sağlayıcıdan
 * bağımsız, testte deterministik).
 *
 * Yalnız bu desene uyan anahtarlar silinebilir: aynı bucket'a elle konmuş
 * başka bir dosya saklama turunda asla silinmez.
 */
final class BackupKeys
{
    private const PATTERN = '/^34pazar-(\d{8}T\d{6}Z)\.(dump\.age|manifest\.json)$/';

    public function __construct(private readonly string $prefix) {}

    public function stamp(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    public function dump(string $stamp): string
    {
        return "{$this->prefix}34pazar-{$stamp}.dump.age";
    }

    public function manifest(string $stamp): string
    {
        return "{$this->prefix}34pazar-{$stamp}.manifest.json";
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{at: DateTimeImmutable, dump: bool, manifest: bool}> damga => durum
     */
    public function group(array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            if (! str_starts_with($key, $this->prefix)) {
                continue;
            }
            if (preg_match(self::PATTERN, substr($key, strlen($this->prefix)), $m) !== 1) {
                continue;
            }

            $at = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $m[1], new DateTimeZone('UTC'));
            if ($at === false) {
                continue;
            }

            $out[$m[1]] ??= ['at' => $at, 'dump' => false, 'manifest' => false];
            $out[$m[1]][$m[2] === 'dump.age' ? 'dump' : 'manifest'] = true;
        }

        return $out;
    }
}
