<?php

declare(strict_types=1);

namespace App\Support\Backup;

use DateTimeImmutable;

/**
 * Off-site yedek saklama: 7 günlük + 4 haftalık + 6 aylık (dede–baba–oğul).
 *
 * KOVA, TAKVİM PENCERESİ DEĞİL: "son 7 gün" yerine "yedeği olan en yeni 7
 * gün" sayılır. Takvim penceresi seçilseydi yedek işi 10 gün aksarsa (anahtar
 * süresi doldu, kimse fark etmedi) pencere boşalır ve eski yedeklerin hepsi
 * silinirdi — tam da yedeğe en çok ihtiyaç duyulan anda.
 *
 * Her kovada o kovanın EN YENİ yedeği tutulur. En yeni yedek her durumda
 * tutulur. Saf fonksiyon: silme kararını verir, silmeyi yapmaz.
 */
final class RetentionPolicy
{
    public function __construct(
        private readonly int $daily = 7,
        private readonly int $weekly = 4,
        private readonly int $monthly = 6,
    ) {}

    /**
     * @param  array<string, DateTimeImmutable>  $backups  kimlik => alınma zamanı (UTC)
     * @return list<string> tutulacak kimlikler
     */
    public function keep(array $backups): array
    {
        if ($backups === []) {
            return [];
        }

        // En yeni önce; eşit zamanda kimlik sırası (deterministik).
        uksort($backups, static function (string $a, string $b) use ($backups): int {
            return [$backups[$b], $b] <=> [$backups[$a], $a];
        });

        $keep = [array_key_first($backups) => true];

        foreach ([
            [$this->daily, 'Y-m-d'],
            [$this->weekly, 'o-\WW'],
            [$this->monthly, 'Y-m'],
        ] as [$limit, $format]) {
            $buckets = [];
            foreach ($backups as $id => $at) {
                $bucket = $at->format($format);
                if (isset($buckets[$bucket])) {
                    continue;
                }
                if (count($buckets) >= $limit) {
                    break;
                }
                $buckets[$bucket] = true;
                $keep[$id] = true;
            }
        }

        return array_values(array_map('strval', array_keys($keep)));
    }

    /**
     * @param  array<string, DateTimeImmutable>  $backups
     * @return list<string> silinecek kimlikler
     */
    public function prune(array $backups): array
    {
        $keep = array_flip($this->keep($backups));

        return array_values(array_filter(
            array_map('strval', array_keys($backups)),
            static fn (string $id): bool => ! isset($keep[$id]),
        ));
    }
}
