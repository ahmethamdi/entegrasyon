<?php

declare(strict_types=1);

namespace App\Support\Observability;

/**
 * Metrik kapsamının metin gösterimi.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · metric_snapshots.scope.
 *
 * `metric_snapshots` KİRACIYA AİT DEĞİLDİR (§4: tabloda `tenant_id`
 * kolonu yoktur) çünkü metriklerin çoğu sistem genelidir. Kapsam bu
 * yüzden tek bir metin kolonunda yaşar ve BİÇİMİ BURADA TEK KAYNAKTAN
 * üretilir: iki yerde elle kurulsaydı biri `tenant:` diğeri `tenant_`
 * yazar, panel sorgusu hiçbir şey bulamaz ve grafik sessizce boş
 * kalırdı.
 */
final class MetricScope
{
    /** Sistem geneli — kapsamsız metriklerin kapsamı. */
    public const SYSTEM = 'system';

    public static function tenant(string $tenantId): string
    {
        return 'tenant:'.$tenantId;
    }

    public static function connection(string $connectionId): string
    {
        return 'connection:'.$connectionId;
    }

    /** Kapsam metninden kimliği çıkarır — panel satırı bunu gösterir. */
    public static function idOf(string $scope): ?string
    {
        $position = strpos($scope, ':');

        return $position === false ? null : substr($scope, $position + 1);
    }
}
