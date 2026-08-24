<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Actions\ResolvePriceConflict;
use App\Domain\Reconciliation\Enums\ItemStatus;
use App\Domain\Reconciliation\Models\ReconciliationItem;
use App\Domain\Reconciliation\Models\ReconciliationRun;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Mutabakat ekranı — sürüklenmenin kullanıcıya görünen TEK yeri.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 4 panel maddesi, §10 ·
 * Reconciliation Engine, §17 · "Panelde senkron geçmişi ve hata
 * görünürlüğü — destek yükünü belirleyen tek ekran".
 *
 * EKRANIN VARLIK SEBEBİ:
 *   Üç mutabakat katmanı da `reconciliation_items` yazıyordu ve hiçbiri
 *   gösterilmiyordu. Sürüklenme tespiti ürünün TEMEL İDDİASIDIR (§17) ama
 *   kullanıcı onu göremiyorsa iddia kanıtlanamaz: satıcı kanalda yanlış
 *   stok olduğunu ancak müşteri şikâyet edince öğrenir.
 *
 * DEĞİŞMEZ KURAL — `MANUAL_REVIEW` EN ÜSTTE VE AYRI SAYILIR:
 *   O satırlarda otomatik onarım DURMUŞTUR (§10 · 3 tur kuralı) ve
 *   kendiliğinden düzelmeyecektir. `DRIFT_DETECTED` ile aynı kefeye
 *   konsaydı satıcı "sistem hallediyor" sanır ve tam olarak müdahale
 *   bekleyen satırı hiç görmezdi.
 *
 * DEĞİŞMEZ KURAL — YEREL VE UZAK DEĞER BİRLİKTE GÖSTERİLİR:
 *   "Sürüklenme var" demek yetmez. `local_value` HEM ham kanonik bakiyeyi
 *   HEM karşılaştırma tabanını taşır ve ikisi FAZLA SATIŞTA AYRIŞIR:
 *   kanoniği −3 olan varyantta kanaldaki 0 DOĞRUDUR (§10 · karşılaştırma
 *   giden değerle yapılır). Yalnızca ham değer gösterilseydi satıcı olmayan
 *   bir sürüklenme arardı; yalnızca kırpılmış değer gösterilseydi fazla
 *   satışı hiç göremezdi.
 *
 * DEĞİŞMEZ KURAL — `REMOTE_UNREACHABLE` SÜRÜKLENME SAYILMAZ:
 *   Altyapı sorunudur ve fark KANITLANMAMIŞTIR. Ama AYRI gösterilir —
 *   sessizce yutulsaydı satıcı kanalının okunamadığını hiç bilmezdi.
 *
 * DEĞİŞMEZ KURAL — `PRICE_CONFLICT` EN ÜSTTE VE KARAR BEKLER (§9 · PRICE):
 *   Fiyat çakışması SÜRÜKLENME DEĞİLDİR ve onarımı AÇILMAZ; kanaldaki fiyat
 *   satıcının kendi kampanyası olabilir ve §9 onu sessizce ezmeyi açıkça
 *   yasaklar ("EN SIK ŞİKAYET"). O yüzden bu satırlar `MANUAL_REVIEW` ile
 *   AYNI ağırlıkta değil, ONDAN DA ÖNCE gösterilir: `MANUAL_REVIEW`'da
 *   sistem denemiş ve başaramamıştır, burada sistem BİLEREK BEKLİYOR ve
 *   satıcı karar verene kadar HİÇBİR ŞEY olmaz.
 *
 * DEĞİŞMEZ KURAL — EKRAN SALT OKUNURDUR, TEK İSTİSNASI FİYAT KARARIDIR:
 *   Sürüklenme tespiti ve onarımı zamanlanmış turların işidir. Fiyat kararı
 *   ise §9'un AÇIKÇA kullanıcıya bıraktığı adımdır ve panelden gelmezse
 *   hiçbir yerden gelemez.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen alanlar.
 */
final class ReconciliationController extends Controller
{
    private const PER_PAGE = 100;

    /**
     * Varsayılan listede EYLEM GEREKTİREN durumlar.
     *
     * `MATCHED` ve `REPAIRED` burada YOKTUR: varsayılan listeyi
     * doldursalardı gerçek sürüklenmeler binlerce "her şey yolunda"
     * satırının arasında kaybolurdu — ekranın varlık sebebinin tam tersi.
     * Geçmiş `?filter=all` ile açılır.
     */
    private const ACTIONABLE = [
        ItemStatus::PRICE_CONFLICT->value,
        ItemStatus::MANUAL_REVIEW->value,
        ItemStatus::DRIFT_DETECTED->value,
        ItemStatus::REPAIR_QUEUED->value,
        ItemStatus::REMOTE_MISSING->value,
        ItemStatus::REMOTE_UNREACHABLE->value,
    ];

    /**
     * Sıralama ağırlığı — küçük sayı ÖNCE gelir.
     *
     * `MANUAL_REVIEW` en üsttedir: otomatik onarım orada durdu ve satır
     * elle müdahale bekliyor. `REMOTE_UNREACHABLE` en alttadır: altyapı
     * sorunudur ve genellikle kendiliğinden düzelir.
     */
    private const STATUS_WEIGHT = [
        // `MANUAL_REVIEW`'DAN DA ÖNCE: orada sistem denedi ve başaramadı,
        // burada sistem BİLEREK BEKLİYOR ve satıcı karar verene kadar
        // hiçbir şey olmaz (§9 · PRICE).
        ItemStatus::PRICE_CONFLICT->value => 0,
        ItemStatus::MANUAL_REVIEW->value => 1,
        ItemStatus::DRIFT_DETECTED->value => 2,
        ItemStatus::REPAIR_QUEUED->value => 3,
        ItemStatus::REMOTE_MISSING->value => 4,
        ItemStatus::REMOTE_UNREACHABLE->value => 5,
    ];

    public function index(Request $request): InertiaResponse
    {
        $filter = $request->string('filter')->toString() === 'all' ? 'all' : 'open';

        // EAGER-LOAD'DA OKUNACAK HER ALAN AÇIKÇA SEÇİLİR ve `variant`
        // ilişkisi de yüklenir: lazy loading KAPALI ve `variant.sku`
        // sunumda okunuyor. Alan listesinden düşerse ekran SKU'yu sessizce
        // boş gösterir (bu projede `adapter_class` ve `supports_webhooks`
        // aynı biçimde iki kez ısırdı).
        $items = $this->itemQuery($filter)
            ->with([
                'listing:id,variant_id,channel_connection_id,external_id',
                'listing.variant:id,sku',
            ])
            ->get();

        return Inertia::render('Reconciliation/Index', [
            'rows' => $this->present($items),
            'summary' => $this->summary(),
            'last_run' => $this->lastRun(),
            'filters' => ['filter' => $filter],
        ]);
    }

    /**
     * §9 · fiyat çakışması kararı — ekranın TEK yazma yolu.
     *
     * KALEM KİRACI SCOPE'U ALTINDA ARANIR ve rota model bağlaması
     * KULLANILMAZ: `SubstituteBindings` `web` grubundadır ve rota
     * seviyesindeki `tenant` ara katmanından ÖNCE çalışır; bağlama
     * kullanılsaydı sorgu bağlam kurulmadan atılır ve izolasyon istisnası
     * fırlatırdı. Yetkilendirme kimliğin tahmin edilemezliğine
     * DAYANDIRILMAZ — `findOrFail` global scope altında koşar ve başka
     * kiracının kalemi 404 verir.
     */
    public function resolvePriceConflict(Request $request, ResolvePriceConflict $resolve): RedirectResponse
    {
        $validated = $request->validate([
            'item' => ['required', 'string'],
            'decision' => [
                'required',
                'string',
                Rule::in([ResolvePriceConflict::ACCEPT_CHANNEL, ResolvePriceConflict::PUSH_OURS]),
            ],
        ]);

        $item = ReconciliationItem::query()
            ->with('listing')
            ->findOrFail($validated['item']);

        $resolve->run($item, $validated['decision'], $request->user()?->id);

        // FLASH ANAHTARI `success` — panelin PAYLAŞTIĞI ad budur
        // (`HandleInertiaRequests::share()`). Uydurma bir anahtar Inertia'ya
        // HİÇ ULAŞMAZ: karar yazılır ama kullanıcı hiçbir geri bildirim
        // görmez ve düğmenin çalışıp çalışmadığını bilemez.
        return redirect('/reconciliation')->with(
            'success',
            $validated['decision'] === ResolvePriceConflict::ACCEPT_CHANNEL
                ? 'Kanaldaki fiyat kabul edildi — bu ürüne fiyat gönderilmeyecek.'
                : 'Sizin fiyatınız kanala gönderilmek üzere sıraya alındı.',
        );
    }

    // ─────────────────────────────────────────────────── sorgular

    /**
     * Listing başına YALNIZCA SON kalem gösterilir.
     *
     * Her tur yeni bir kalem yazar; hepsi listelenseydi üç turdur
     * sürüklenen tek bir listing ekranı üç satırla doldurur ve satıcı kaç
     * ayrı sorunu olduğunu sayamazdı. Ekran "şu an ne durumdayım"
     * sorusunu cevaplar, "geçmişte neler oldu"yu değil.
     *
     * SON KALEM `id` ÜZERİNDEN SEÇİLİR, `checked_at` ÜZERİNDEN DEĞİL:
     * `checked_at` saniye hassasiyetlidir ve arka arkaya koşan turlar aynı
     * damgayı taşıyabilir; sıra belirsiz kalır. `id` UUIDv7'dir — zaman
     * sıralı ve saniye içinde de ayırt edici.
     */
    private function itemQuery(string $filter): Builder
    {
        $query = ReconciliationItem::query()
            ->whereIn('id', $this->latestItemIds());

        if ($filter === 'open') {
            $query->whereIn('status', self::ACTIONABLE)
                // ÇÖZÜLMÜŞ KALEM AÇIK LİSTEDE DURMAZ.
                //
                // Fiyat çakışmasında durum `PRICE_CONFLICT` KALIR ve yalnızca
                // `resolved_at` damgalanır (§9 kararı `ResolvePriceConflict`
                // içinde gerekçelendirildi: kalem o turda gerçekten çakışma
                // BULMUŞTU ve `MATCHED` yazmak "zaten doğruydu" demek olurdu).
                // Bedeli burada ödenir: yalnızca duruma bakan bir filtre,
                // satıcının kararını verdiği satırı EKRANDA BIRAKIRDI ve
                // satıcı aynı kararı tekrar tekrar verirdi.
                ->whereNull('resolved_at');
        }

        return $query->limit(self::PER_PAGE);
    }

    /**
     * Listing başına EN SON kalemin kimlikleri.
     *
     * `MAX(id)` KULLANILAMAZ: PostgreSQL'de uuid için `max()` toplam
     * fonksiyonu YOKTUR ve sorgu doğrudan patlar. `DISTINCT ON` hem
     * çalışır hem de `recon_items_listing_time_idx` sıralamasını kullanır.
     *
     * Kiracı filtresi AÇIKÇA yazılır: ham sorgu global scope'a TABİ
     * DEĞİLDİR ve yazılmazsa başka kiracının kalemleri bu listeye girer.
     *
     * @return list<string>
     */
    private function latestItemIds(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT DISTINCT ON (listing_id) id
              FROM reconciliation_items
             WHERE tenant_id = ?
             ORDER BY listing_id, id DESC
        SQL, [TenantContext::idOrFail()]);

        return array_map(static fn (object $row): string => $row->id, $rows);
    }

    /**
     * Üst özet — üç sayı, üç FARKLI eylem.
     *
     * `manual_review` elle müdahale ister, `drift` kendiliğinden onarılır,
     * `unreachable` kanal sağlığına bakmayı gerektirir. Tek sayıda
     * birleştirilselerdi satıcı hangi eylemin gerektiğini bilemezdi.
     *
     * Sayım da listing başına SON kalem üzerinden yapılır: eski turların
     * kalemleri sayılsaydı tek bir sürüklenme her turda sayacı bir daha
     * artırırdı.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $row = ReconciliationItem::query()
            ->whereIn('id', $this->latestItemIds())
            ->selectRaw('count(*) FILTER (WHERE status = ?) AS manual_review', [ItemStatus::MANUAL_REVIEW->value])
            ->selectRaw('count(*) FILTER (WHERE status IN (?, ?)) AS drift', [
                ItemStatus::DRIFT_DETECTED->value,
                ItemStatus::REPAIR_QUEUED->value,
            ])
            ->selectRaw('count(*) FILTER (WHERE status = ?) AS unreachable', [ItemStatus::REMOTE_UNREACHABLE->value])
            ->selectRaw('count(*) FILTER (WHERE status = ?) AS missing', [ItemStatus::REMOTE_MISSING->value])
            ->selectRaw('count(*) FILTER (WHERE status = ?) AS repaired', [ItemStatus::REPAIRED->value])
            // ÇÖZÜLMÜŞ ÇAKIŞMA SAYILMAZ — durum `PRICE_CONFLICT` KALDIĞI için
            // `resolved_at` kapısı burada da ZORUNLUDUR. Yazılmasaydı özet,
            // satıcının çoktan karar verdiği satırları saymaya devam eder ve
            // "kaç karar bekliyor" sorusuna sürekli yanlış cevap verirdi.
            ->selectRaw('count(*) FILTER (WHERE status = ? AND resolved_at IS NULL) AS price_conflict', [
                ItemStatus::PRICE_CONFLICT->value,
            ])
            ->first();

        return [
            'manual_review' => (int) ($row?->manual_review ?? 0),
            'drift' => (int) ($row?->drift ?? 0),
            'unreachable' => (int) ($row?->unreachable ?? 0),
            'missing' => (int) ($row?->missing ?? 0),
            'repaired' => (int) ($row?->repaired ?? 0),
            // AYRI SAYI, AYRI EYLEM: sürüklenme kendiliğinden onarılır,
            // çakışma KARAR bekler. Aynı kutuda toplansalardı satıcı
            // "sistem hallediyor" sanır ve kampanyası askıda kalırdı.
            'price_conflict' => (int) ($row?->price_conflict ?? 0),
        ];
    }

    /**
     * Son mutabakat turu — listenin ne kadar taze olduğunu söyler.
     *
     * Bilinmezse kullanıcı boş bir listeye bakıp "sürüklenme yok" mu yoksa
     * "tur hiç koşmadı" mı olduğunu ayırt edemez. Yeni kiracıda hiç tur
     * yoktur ve NULL döner — ekran boş durumu gösterir.
     *
     * @return array<string, mixed>|null
     */
    private function lastRun(): ?array
    {
        $run = ReconciliationRun::query()
            ->orderByDesc('id')
            ->first(['id', 'scope', 'status', 'started_at', 'finished_at', 'checked_count', 'drift_count']);

        if ($run === null) {
            return null;
        }

        return [
            'scope' => $run->scope,
            'status' => $run->status,
            'startedAt' => $run->started_at?->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
            'checked' => (int) $run->checked_count,
            'drift' => (int) $run->drift_count,
        ];
    }

    // ─────────────────────────────────────────────────── sunum

    /**
     * @param  iterable<ReconciliationItem>  $items
     * @return list<array<string, mixed>>
     */
    private function present(iterable $items): array
    {
        $rows = [];

        foreach ($items as $item) {
            $rows[] = $this->presentRow($item);
        }

        // Sıralama UYGULAMA katmanında: `MANUAL_REVIEW` en üstte, sonra
        // sürüklenme büyüklüğüne göre. SQL tarafında CASE ile yazılabilirdi
        // ama sayfa başına 100 satırda fark ölçülemez ve ağırlık tablosu
        // burada okunabilir kalıyor.
        usort($rows, static function (array $a, array $b): int {
            $weight = ($a['weight'] <=> $b['weight']);

            return $weight !== 0
                ? $weight
                : ($b['drift_magnitude'] ?? 0) <=> ($a['drift_magnitude'] ?? 0);
        });

        return $rows;
    }

    /** @return array<string, mixed> */
    private function presentRow(ReconciliationItem $item): array
    {
        $local = $item->local_value ?? [];
        $remote = $item->remote_value ?? [];

        // HAM kanonik bakiye KIRPILMADAN gider (§17 · P0). Karşılaştırma
        // tabanı ayrıca taşınır; ikisi fazla satışta AYRIŞIR ve satıcının
        // "kanaldaki 0 doğru mu" sorusuna ancak ikisi birden cevap verir.
        $available = isset($local['available']) ? (int) $local['available'] : null;

        return [
            'id' => $item->id,
            'listingId' => $item->listing_id,
            'sku' => $item->listing?->variant?->sku,
            'externalId' => $item->listing?->external_id,

            // DOMAIN GÖRÜNÜR OLMALI: stok kalemi ADET, fiyat kalemi PARA
            // taşır ve ekran ikisini aynı sütunda gösteremez. Alan
            // yazılmasaydı satıcı "17 → 99" satırının stok mu fiyat mı
            // olduğunu ayırt edemezdi.
            'domain' => $item->domain,

            // Fiyat alanları YALNIZCA fiyat kaleminde dolar; stok
            // kaleminde `local_value` bir `price` anahtarı taşımaz.
            'our_price' => $local['price'] ?? null,
            'channel_price' => $remote['price'] ?? null,

            'status' => $item->status,
            'reason' => $item->priority_reason,
            'weight' => self::STATUS_WEIGHT[$item->status] ?? 9,

            'available' => $available,
            'expected_remote' => isset($local['expected_remote']) ? (int) $local['expected_remote'] : null,
            'observed_remote' => isset($remote['quantity']) && $remote['quantity'] !== null
                ? (int) $remote['quantity']
                : null,
            'drift_magnitude' => $item->drift_magnitude,

            // Fazla satış AYRI işaretlenir: negatif kanonik bakiyede
            // kanaldaki 0 DOĞRUDUR ve satıcı bunu bilmeli.
            'oversold' => $available !== null && $available < 0,

            'checkedAt' => $item->checked_at?->toIso8601String(),
            'resolvedAt' => $item->resolved_at?->toIso8601String(),
        ];
    }
}
