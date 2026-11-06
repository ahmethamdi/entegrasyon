<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * Ziyaretçi sayacı — çerezsiz, ham IP saklamayan günlük toplam (6 Kas 2026).
 *
 * Canlıda erişim kaydı yoktu: reklam dönemi boyunca siteye kaç kişinin
 * geldiğini bilmiyorduk. Analitik aracı çerez, onay bandı ve üçüncü tarafa
 * veri aktarımı getirirdi; bu sayaç hiçbirini getirmez:
 *
 *   - Tarayıcıya hiçbir şey yazılmaz (çerez yok, localStorage yok).
 *   - IP SAKLANMAZ. Tekil ziyaretçi, IP + tarayıcı + GÜN + uygulama
 *     anahtarının özetidir; gün değişince aynı kişi yeni değer üretir,
 *     yani günler arası iz sürülemez ve anahtarsız geri çıkarılamaz.
 *   - Yalnız toplam tutulur: hangi ziyaretçinin hangi sayfaya baktığı
 *     ilişkisi YOKTUR (iki tablo birbirine bağlanmaz).
 *
 * BEDELİ: "tekil ziyaretçi" gün başınadır. Yedi günün toplamı, aynı kişi
 * üç gün geldiyse onu üç kez sayar — panel bunu açıkça yazar.
 *
 * Tablolar platform düzeyidir (kiracısız); Eloquent modeli yok, `DB::table`
 * ile okunur ve yazılır (`PlatformStats` ile aynı gerekçe).
 */
final class VisitStats
{
    public const RETENTION_DAYS = 90;

    /** Günlük tekil ziyaretçi özeti: 32 onaltılık karakter; IP satıra yazılmaz, anahtarsız eşlenemez. */
    public static function visitorId(string $day, string $ip, string $userAgent): string
    {
        return substr(hash('sha256', config('app.key').'|'.$day.'|'.$ip.'|'.$userAgent), 0, 32);
    }

    /**
     * Bir görüntülemeyi sayar. Yarışta doğru: iki eşzamanlı istek aynı
     * satırı `ON CONFLICT … views + 1` ile artırır; "oku-artır-yaz" olsaydı
     * biri ötekinin artışını ezerdi.
     */
    public function record(string $path, string $referrerHost, string $visitor, ?string $day = null): void
    {
        $day ??= now()->toDateString();

        DB::table('page_views_daily')->upsert(
            [['day' => $day, 'path' => $path, 'referrer_host' => $referrerHost, 'views' => 1]],
            ['day', 'path', 'referrer_host'],
            ['views' => DB::raw('page_views_daily.views + 1')],
        );

        DB::table('visitors_daily')->insertOrIgnore(['day' => $day, 'visitor' => $visitor]);
    }

    /** Saklama süresini aşan günleri siler; silinen satır sayısını döner. */
    public function prune(int $days = self::RETENTION_DAYS): int
    {
        $cutoff = now()->subDays($days)->toDateString();

        return DB::table('page_views_daily')->where('day', '<', $cutoff)->delete()
            + DB::table('visitors_daily')->where('day', '<', $cutoff)->delete();
    }

    /**
     * Süper admin özeti.
     *
     * @return array{
     *     views7: int, views30: int, visitors7: int, visitors30: int,
     *     daily: list<array{date: string, views: int, visitors: int}>,
     *     topPages: list<array{path: string, views: int}>,
     *     topReferrers: list<array{host: string, views: int}>,
     * }
     */
    public function summary(): array
    {
        $since7 = now()->subDays(6)->toDateString();
        $since30 = now()->subDays(29)->toDateString();

        $views = DB::table('page_views_daily')
            ->selectRaw('day, SUM(views) AS n')
            ->where('day', '>=', $since30)
            ->groupBy('day')
            ->pluck('n', 'day');

        $visitors = DB::table('visitors_daily')
            ->selectRaw('day, COUNT(*) AS n')
            ->where('day', '>=', $since30)
            ->groupBy('day')
            ->pluck('n', 'day');

        // Boş günler 0 ile doldurulur (dailySignups kalıbı): grafikte eksik
        // gün "veri yok" değil "ziyaret yok" demek.
        $daily = [];

        for ($i = 29; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $daily[] = ['date' => $day, 'views' => (int) ($views[$day] ?? 0), 'visitors' => (int) ($visitors[$day] ?? 0)];
        }

        $sum = static fn (string $key, string $since): int => (int) collect($daily)
            ->filter(fn (array $d): bool => $d['date'] >= $since)
            ->sum($key);

        return [
            'views7' => $sum('views', $since7),
            'views30' => $sum('views', $since30),
            'visitors7' => $sum('visitors', $since7),
            'visitors30' => $sum('visitors', $since30),
            'daily' => $daily,
            'topPages' => DB::table('page_views_daily')
                ->where('day', '>=', $since30)
                ->groupBy('path')
                ->orderByDesc(DB::raw('SUM(views)'))
                ->orderBy('path')
                ->limit(8)
                ->get(['path', DB::raw('SUM(views) AS views')])
                ->map(fn ($r): array => ['path' => (string) $r->path, 'views' => (int) $r->views])
                ->all(),
            'topReferrers' => DB::table('page_views_daily')
                ->where('day', '>=', $since30)
                ->where('referrer_host', '<>', '')
                ->groupBy('referrer_host')
                ->orderByDesc(DB::raw('SUM(views)'))
                ->orderBy('referrer_host')
                ->limit(8)
                ->get(['referrer_host', DB::raw('SUM(views) AS views')])
                ->map(fn ($r): array => ['host' => (string) $r->referrer_host, 'views' => (int) $r->views])
                ->all(),
        ];
    }
}
