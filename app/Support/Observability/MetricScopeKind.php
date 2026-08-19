<?php

declare(strict_types=1);

namespace App\Support\Observability;

/**
 * Metriğin hangi kapsamda ölçüldüğü.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · uyarı eşikleri.
 *
 * Eşik ancak kapsam bilinirse uygulanabilir: §11 "kiracı başına > 5
 * adet" ve "kanal başına > 5 sn" diyor. Sistem geneli toplanan bir fazla
 * satış sayısında "kiracı başına 5" kuralının hiçbir anlamı yoktur ve
 * yüz kiracılı bir kurulumda tek kiracının ciddi sorunu gürültüde
 * kaybolurdu.
 */
enum MetricScopeKind
{
    case SYSTEM;
    case TENANT;
    case CONNECTION;
}
