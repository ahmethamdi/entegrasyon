<?php

declare(strict_types=1);

namespace App\Support\Observability;

/**
 * Metrik değerinin birimi — panel biçimlendirmesi buradan okur.
 *
 * Mimari Karar Dokümanı v2.2 · §11.
 *
 * Birim değerin YANINDA taşınmazsa panel `1247.5` yazar ve satıcı bunun
 * milisaniye mi, saniye mi, adet mi olduğunu bilemez. Aynı grafik hem
 * `%5.3` hem `1247.5 ms` hem `3 adet` göstereceği için tek bir
 * biçimlendirme kuralı yeterli değildir.
 */
enum MetricUnit: string
{
    case MILLISECONDS = 'ms';
    case SECONDS = 's';
    case PERCENT = '%';
    case COUNT = 'count';
}
