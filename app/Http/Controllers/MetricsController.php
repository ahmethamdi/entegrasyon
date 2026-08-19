<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Observability\Metric;
use App\Support\Observability\MetricScope;
use App\Support\Observability\MetricScopeKind;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Metrik ekranı — §11'in ölçümlerinin kullanıcıya görünen yeri.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · Ölçülecek metrikler,
 * §13 · Faz 3 · madde 2 ("panel grafikleri"), §17 · P0 · "On bir metrik
 * ve uyarı — ölçülmeyen güvenilirlik iddia edilemez", §4 ·
 * metric_snapshots.
 *
 * EKRANIN VARLIK SEBEBİ: `CaptureMetrics` saatlik ölçüyor ama gösteren
 * bir şey yoksa ölçüm bir veritabanı tablosunda ölür. Ölçülüp
 * GÖSTERİLMEYEN de ölçülmeyenle aynı kapıya çıkar.
 *
 * DEĞİŞMEZ KURAL — `metric_snapshots` GLOBAL SCOPE'A TABİ DEĞİLDİR.
 *   Tabloda `tenant_id` kolonu YOKTUR (§4) ve `BelongsToTenant` burada
 *   çalışmaz. Filtre `scope` kolonu üzerinden ELLE yazılmak zorundadır;
 *   yazılmazsa rakip satıcının fazla satış miktarı, ölü iş sayısı ve
 *   kanal performansı bu ekranda görünür. Bu projede aynı boşluk beş
 *   ayrı turda bulundu.
 *
 * DEĞİŞMEZ KURAL — SİSTEM METRİKLERİ HERKESE GÖSTERİLİR.
 *   Outbox birikmesi, inbox gecikmesi ve senkron hata oranı ALTYAPININ
 *   sağlığıdır ve hiçbir kiracının verisini ifşa etmez. Gizlenselerdi
 *   satıcı senkronun neden yavaşladığını hiçbir yerde göremez ve her
 *   yavaşlamada destek arardı — §17 bu ekranı tam olarak destek yükünü
 *   düşürmek için istiyor.
 *
 * DEĞİŞMEZ KURAL — GEÇMİŞ GÖNDERİLİR, YALNIZCA SON DEĞER DEĞİL.
 *   Ekranın tüm amacı "artıyor mu" sorusudur; tek sayı o soruyu ASLA
 *   cevaplayamaz ve zaten canlı sorguyla da alınabilirdi. Anlık görüntü
 *   tablosunun bütün gerekçesi geçmiştir.
 *
 * DEĞİŞMEZ KURAL — EŞİK `Metric::threshold()` İÇİNDE TEK KAYNAKTIR.
 *   Panelde yeniden tanımlansaydı iki gerçek kaynağı doğar ve biri
 *   değiştiğinde rozet sessizce yanlış renk gösterirdi.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen
 *   alanlar.
 */
final class MetricsController extends Controller
{
    /**
     * Grafikte gösterilen nokta sayısı.
     *
     * Saatlik ölçümde 48 nokta iki gün eder — "dün bu saatte nasıldı"
     * sorusunu cevaplamaya yeter. Sınırsız gönderilseydi bir yıllık
     * geçmiş 8760 nokta demektir: Inertia yükü şişer ve grafik okunamaz
     * hâle gelir.
     */
    private const HISTORY_POINTS = 48;

    public function index(): InertiaResponse
    {
        $scopes = $this->visibleScopes();
        $cards = $this->cards($scopes);

        return Inertia::render('Metrics/Index', [
            'cards' => $cards,
            'summary' => [
                'breaching' => count(array_filter($cards, static fn (array $c): bool => $c['breaching'])),
                'measured' => count($cards),
                'capturedAt' => $cards === [] ? null : $cards[0]['capturedAt'],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────── kapsam

    /**
     * Bu kiracının GÖREBİLECEĞİ kapsamlar.
     *
     * Üç tür: her zaman `system`, kendi `tenant:{id}`'si ve KENDİ
     * bağlantılarının `connection:{id}`'leri. Bağlantı listesi
     * `ChannelConnection` üzerinden alınır ve o model `BelongsToTenant`
     * kullanır — yani kiracı filtresi orada global scope'tan gelir.
     * Kapsam metinlerini ham olarak eşleştirmek yerine ÖNCE sahip
     * olunan kimlikler toplanır: "connection: ile başlayan her satır
     * benimdir" varsayımı doğrudan bir sızıntı olurdu.
     *
     * @return list<string>
     */
    private function visibleScopes(): array
    {
        $tenantId = TenantContext::idOrFail();

        $scopes = [
            MetricScope::SYSTEM,
            MetricScope::tenant($tenantId),
        ];

        foreach (ChannelConnection::query()->pluck('id') as $connectionId) {
            $scopes[] = MetricScope::connection((string) $connectionId);
        }

        return $scopes;
    }

    // ─────────────────────────────────────────────────────────── kartlar

    /**
     * Metrik başına bir kart: son değer + geçmiş + eşik durumu.
     *
     * @param  list<string>  $scopes
     * @return list<array<string, mixed>>
     */
    private function cards(array $scopes): array
    {
        $history = $this->history($scopes);
        $cards = [];

        foreach (Metric::cases() as $metric) {
            // HİÇ ÖLÇÜLMEMİŞ METRİK KART ÜRETMEZ. Sıfır gösterilseydi
            // satıcı "her şey mükemmel" sanırdı; oysa ölçüm YAPILMADI.
            // `CaptureMetrics`'in "sıfır yazma" kuralının panel karşılığı.
            $points = $history[$metric->value] ?? [];

            if ($points === []) {
                continue;
            }

            $latest = end($points);
            $value = (float) $latest['value'];

            $cards[] = [
                'metric' => $metric->value,
                'label' => $metric->label(),
                'unit' => $metric->unit()->value,
                'scopeKind' => $this->scopeKindLabel($metric->scopeKind()),

                'value' => $value,
                'threshold' => $metric->threshold(),
                'breaching' => $metric->breaches($value),

                // EŞİĞE DAYANMIŞ AMA AŞMAMIŞ DEĞER AYRI İŞARETLENİR.
                // "5 / eşik 5" aşım DEĞİLDİR (§11 "büyüktür" diyor) ve
                // kırmızı gösterilmesi yanlış olurdu — ama sessizce yeşil
                // göstermek de satıcıyı bir adım ötede olduğundan habersiz
                // bırakır. Gerçek çalıştırmada tam bu durum görüldü: fazla
                // satış eşiğe dayanmıştı ve kart sıradan görünüyordu.
                'nearThreshold' => ! $metric->breaches($value) && $metric->nearThreshold($value),

                'history' => $points,
                'capturedAt' => $latest['capturedAt'],
            ];
        }

        // Aşan metrikler ÜSTE alınır: on üç kart arasında tek bir kırmızı
        // gözden kaçar ve ekranın amacı tam olarak onu göstermektir.
        usort($cards, static fn (array $a, array $b): int => ($b['breaching'] <=> $a['breaching']));

        return $cards;
    }

    /**
     * Metrik başına son N ölçüm, ESKİDEN YENİYE.
     *
     * Sıralama `id` ÜZERİNDENDİR, `captured_at` üzerinden DEĞİL:
     * `captured_at` SANİYE hassasiyetlidir ve arka arkaya koşan iki tur
     * aynı damgayı taşıyabilir — sıra belirsiz kalır ve panel bazen eski
     * değeri "son" diye gösterir. Bu projenin tekrar eden tuzağı
     * (`latest('<timestamp>')`).
     *
     * Kiracı filtresi `whereIn('scope', ...)` ile AÇIKÇA yazılır:
     * `metric_snapshots` global scope'a TABİ DEĞİLDİR.
     *
     * @param  list<string>  $scopes
     * @return array<string, list<array<string, mixed>>>
     */
    private function history(array $scopes): array
    {
        // Pencere metrik BAŞINA uygulanır: tek bir `LIMIT` konsaydı çok
        // ölçülen bir metrik pencereyi doldurur ve seyrek ölçülenler hiç
        // görünmezdi.
        $rows = DB::table('metric_snapshots')
            ->select('metric', 'scope', 'value', 'captured_at', 'id')
            ->whereIn('scope', $scopes)
            ->orderBy('metric')
            ->orderByDesc('id')
            ->get();

        $history = [];

        foreach ($rows as $row) {
            $bucket = &$history[$row->metric];
            $bucket ??= [];

            if (count($bucket) >= self::HISTORY_POINTS) {
                continue;
            }

            // Sorgu YENİDEN ESKİYE geliyor; grafik soldan sağa okunur ve
            // ters çevirme aşağıda tek seferde yapılır.
            $bucket[] = [
                'value' => (float) $row->value,
                'capturedAt' => $row->captured_at,
            ];
        }

        unset($bucket);

        return array_map(
            static fn (array $points): array => array_reverse($points),
            $history,
        );
    }

    private function scopeKindLabel(MetricScopeKind $kind): string
    {
        return match ($kind) {
            MetricScopeKind::SYSTEM => 'Sistem',
            MetricScopeKind::TENANT => 'Hesabınız',
            MetricScopeKind::CONNECTION => 'Kanal',
        };
    }
}
