<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Contracts\SupportsApprovalWorkflow;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Sync\Models\Listing;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Onay durumu ekranı — "kanal ürünlerimi ne zaman yayına alacak".
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 4 (onay durumu ekranı),
 * §14 · onay süreci, §7 · SupportsApprovalWorkflow.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EKRANIN VARLIK SEBEBİ — TOPLU GÖRÜNÜM
 * ─────────────────────────────────────────────────────────────────────
 * Rozet ve red sebebi ÜRÜN-KANAL ekranında (`/products/{id}/channels`)
 * ZATEN VAR ve orada kalır — burası onun kopyası DEĞİLDİR. O ekran TEK
 * ÜRÜN için "hangi kanallarda ne durumda" sorusunu cevaplar; bu ekran
 * TERSİNİ sorar: "kaç ürünüm onay bekliyor, hangileri reddedildi".
 *
 * Fark satıcının iş akışıdır. Yüz ürün gönderen bir satıcı, reddedilen
 * üçünü bulmak için yüz ürünün kanal sekmesini TEK TEK açmak zorundaydı;
 * red sebebi kayıtlıydı ve pratikte GÖRÜNMEZDİ.
 *
 * ─────────────────────────────────────────────────────────────────────
 * REDDEDİLEN EN ÜSTTE — SIRALAMA BİR EYLEM SIRASIDIR
 * ─────────────────────────────────────────────────────────────────────
 * `rejected` satır KULLANICI MÜDAHALESİ bekler ve kendiliğinden
 * düzelmez: kanal onu reddetti, biz yeniden göndermedikçe hiçbir şey
 * olmaz. `pending_approval` ise BEKLEME durumudur ve satıcının yapacağı
 * bir şey yoktur — kanal sırası gelince onaylar.
 *
 * İkisi aynı kefeye konsaydı satıcı "sistem hallediyor" sanır ve tam
 * olarak müdahale bekleyen satırı hiç görmezdi. Mutabakat ekranındaki
 * `MANUAL_REVIEW` kuralının aynısı.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ONAY SÜRECİ OLMAYAN KANAL BU EKRANDA HİÇ GÖRÜNMEZ
 * ─────────────────────────────────────────────────────────────────────
 * Woo `SupportsApprovalWorkflow` uygulamaz: orada ürün gönderilir
 * gönderilmez yayına girer ve "onay bekliyor" diye bir hâl YOKTUR.
 * Yetenek `instanceof` ile okunur; `if ($code === '...')` YAZILMAZ (§7).
 *
 * Kanal listesi boşsa ekran bunu AÇIKÇA söyler — boş tablo göstermek
 * satıcıya "onay bekleyen ürün yok" dedirtirdi, oysa doğru cevap "bu
 * kanalda onay süreci yok"tur ve ikisi tamamen farklı şeylerdir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EKRAN SALT OKUNURDUR
 * ─────────────────────────────────────────────────────────────────────
 * Onay durumunu KANAL belirler; biz yalnızca okuruz (`approval:track`,
 * saatlik). Panelden "onayla" düğmesi koymak, kanalın kararını bizim
 * verebileceğimiz izlenimi yaratırdı.
 *
 * Reddedilen satırın DÜZELTME yolu ürün ekranıdır: satıcı veriyi
 * düzeltir, yeniden gönderir ve sonraki tur durumu günceller. Ekran o
 * yola BAĞLANTI verir.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen alanlar.
 */
final class ApprovalController extends Controller
{
    private const PER_PAGE = 200;

    /**
     * Sıralama ağırlığı — küçük sayı ÖNCE gelir.
     *
     * `rejected` müdahale ister, `pending_approval` beklemektir. Gerekçe
     * sınıf başlığında.
     */
    private const STATUS_WEIGHT = [
        'rejected' => 0,
        'pending_approval' => 1,
    ];

    public function index(Request $request): InertiaResponse
    {
        $connections = $this->approvalConnections();

        // Kanal yoksa sorgu HİÇ ÇALIŞTIRILMAZ: `whereIn` boş dizi ile
        // her zaman boş döner ama ekranın söyleyeceği şey "onay bekleyen
        // ürün yok" DEĞİL, "onay süreci olan kanalın yok"tur.
        $rows = $connections === []
            ? []
            : $this->present($this->listingQuery($connections, $request)->get());

        return Inertia::render('Approvals/Index', [
            'rows' => $rows,
            'summary' => $this->summary($connections),
            'connections' => $this->connectionOptions($connections),
            'hasApprovalChannels' => $connections !== [],
            'filters' => [
                'connection' => $this->connectionFilter($request, $connections),
                'status' => $this->statusFilter($request),
            ],
            'lastCheckedAt' => $this->lastCheckedAt($connections),
        ]);
    }

    // ─────────────────────────────────────────────────── sorgular

    /**
     * Onay süreci OLAN aktif bağlantılar.
     *
     * `adapter_class` EAGER-LOAD'DA AÇIKÇA SEÇİLİR: seçilmezse
     * `AdapterRegistry` sınıfı bulamaz ve yetenek sessizce BOŞ çıkar —
     * ekran hiçbir kanal görmez ve "onay süreci olan kanalın yok" der.
     * Bu tuzak projede `supports_webhooks` ile de yaşandı.
     *
     * @return list<ChannelConnection>
     */
    private function approvalConnections(): array
    {
        $connections = ChannelConnection::query()
            ->where('status', 'active')
            ->with('channelType:code,name,adapter_class')
            ->get()
            ->all();

        return array_values(array_filter(
            $connections,
            fn (ChannelConnection $c): bool => app(AdapterRegistry::class)->for($c) instanceof SupportsApprovalWorkflow,
        ));
    }

    /**
     * Onay bekleyen ve reddedilen listing'ler.
     *
     * `listings_tenant_id_lifecycle_status_index` tam bu sorgu içindir.
     *
     * Kiracı kapsaması `BelongsToTenant` global scope'undan gelir — bu bir
     * Eloquent sorgusudur, ham `DB::table()` DEĞİLDİR.
     *
     * @param  list<ChannelConnection>  $connections
     * @return Builder<Listing>
     */
    private function listingQuery(array $connections, Request $request): Builder
    {
        $query = Listing::query()
            ->whereIn('lifecycle_status', array_keys(self::STATUS_WEIGHT))
            ->whereIn('channel_connection_id', array_column($connections, 'id'))
            // EAGER-LOAD'DA OKUNACAK HER ALAN AÇIKÇA SEÇİLİR: `variant.sku`
            // ve ürün başlığı sunumda okunuyor ve alan listesinden düşerse
            // ekran onları SESSİZCE boş gösterir.
            ->with([
                'variant:id,sku,product_id',
                'variant.product:id,title',
            ]);

        $connectionFilter = $this->connectionFilter($request, $connections);

        if ($connectionFilter !== null) {
            $query->where('channel_connection_id', $connectionFilter);
        }

        $statusFilter = $this->statusFilter($request);

        if ($statusFilter !== null) {
            $query->where('lifecycle_status', $statusFilter);
        }

        return $query->limit(self::PER_PAGE);
    }

    /**
     * Üst özet — İKİ SAYI, İKİ FARKLI EYLEM.
     *
     * `rejected` müdahale ister, `pending_approval` yalnızca beklemektir.
     * Tek sayıda birleştirilselerdi satıcı hangi eylemin gerektiğini
     * bilemezdi (mutabakat ekranındaki üç-sayı kuralının aynısı).
     *
     * SAYIM FİLTREDEN BAĞIMSIZDIR: kullanıcı "yalnızca reddedilenler"
     * filtresini açtığında bekleyen sayısının sıfıra düşmesi, o ürünlerin
     * kaybolduğu izlenimini verirdi.
     *
     * @param  list<ChannelConnection>  $connections
     * @return array<string, int>
     */
    private function summary(array $connections): array
    {
        if ($connections === []) {
            return ['rejected' => 0, 'pending' => 0];
        }

        $row = Listing::query()
            ->whereIn('channel_connection_id', array_column($connections, 'id'))
            ->selectRaw("count(*) FILTER (WHERE lifecycle_status = 'rejected') AS rejected")
            ->selectRaw("count(*) FILTER (WHERE lifecycle_status = 'pending_approval') AS pending")
            ->first();

        return [
            'rejected' => (int) ($row?->rejected ?? 0),
            'pending' => (int) ($row?->pending ?? 0),
        ];
    }

    /**
     * Onay durumu EN SON ne zaman soruldu.
     *
     * Bilinmezse satıcı boş bir listeye bakıp "onay bekleyen yok" mu yoksa
     * "tur hiç koşmadı" mı olduğunu ayırt edemez — mutabakat ekranındaki
     * `last_run` kuralının aynısı.
     *
     * `DB::table()` GLOBAL SCOPE'A TABİ DEĞİLDİR: kiracı filtresi AÇIKÇA
     * yazılır, yoksa başka kiracının turu bu ekranda tarih olarak görünür.
     *
     * DEĞER ISO-8601 OLARAK GÖNDERİLİR, HAM KOLON METNİ OLARAK DEĞİL.
     * `DB::max()` `"2026-08-24 14:31:09"` döndürür; tarayıcı o metni YEREL
     * saat sanar ve UTC'ye çevirmez. Satırlar `toIso8601String()`
     * kullandığı için AYNI AN iki farklı saat olarak görünürdü — gerçek
     * tarayıcı çalıştırmasında iki saatlik fark ölçüldü (üstte 14:31,
     * satırda 16:31).
     *
     * @param  list<ChannelConnection>  $connections
     */
    private function lastCheckedAt(array $connections): ?string
    {
        if ($connections === []) {
            return null;
        }

        $value = DB::table('listings')
            ->where('tenant_id', TenantContext::idOrFail())
            ->whereIn('channel_connection_id', array_column($connections, 'id'))
            ->max('approval_checked_at');

        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }

    // ─────────────────────────────────────────────────── filtreler

    /**
     * Bağlantı filtresi — YALNIZCA onay süreci olan bağlantılar kabul edilir.
     *
     * Doğrulanmasaydı kullanıcı adres çubuğuna başka bir bağlantı kimliği
     * yazarak sorguyu o bağlantıya çevirebilirdi; kiracı kapsaması sızıntıyı
     * zaten engeller ama sonuç HER ZAMAN boş olur ve ekran sebebini
     * söyleyemezdi.
     *
     * @param  list<ChannelConnection>  $connections
     */
    private function connectionFilter(Request $request, array $connections): ?string
    {
        $value = $request->string('connection')->toString();

        return in_array($value, array_column($connections, 'id'), true) ? $value : null;
    }

    private function statusFilter(Request $request): ?string
    {
        $value = $request->string('status')->toString();

        return array_key_exists($value, self::STATUS_WEIGHT) ? $value : null;
    }

    /**
     * @param  list<ChannelConnection>  $connections
     * @return list<array<string, string>>
     */
    private function connectionOptions(array $connections): array
    {
        return array_map(static fn (ChannelConnection $c): array => [
            'id' => $c->id,
            'label' => $c->label,
            'channel' => $c->channelType?->name ?? $c->channel_type_code,
        ], $connections);
    }

    // ─────────────────────────────────────────────────── sunum

    /**
     * @param  iterable<Listing>  $listings
     * @return list<array<string, mixed>>
     */
    private function present(iterable $listings): array
    {
        $rows = [];

        foreach ($listings as $listing) {
            $rows[] = [
                'id' => $listing->id,
                'productId' => $listing->variant?->product_id,
                'title' => $listing->variant?->product?->title,
                'sku' => $listing->variant?->sku,
                'externalId' => $listing->external_id,
                'externalUrl' => $listing->external_url,
                'connectionId' => $listing->channel_connection_id,
                'status' => $listing->lifecycle_status,
                // RED SEBEBİ ADIYLA GÖSTERİLİR: "reddedildi" demek satıcıya
                // ne yapacağını söylemez ve sebep zaten kayıtlıdır (§14).
                'reason' => $listing->approval_rejection_reason,
                'checkedAt' => $listing->approval_checked_at?->toIso8601String(),
                'weight' => self::STATUS_WEIGHT[$listing->lifecycle_status] ?? 9,
            ];
        }

        // Sıralama UYGULAMA katmanında: `rejected` en üstte, sonra en uzun
        // süredir bekleyen. SQL tarafında CASE ile yazılabilirdi ama sayfa
        // başına 200 satırda fark ölçülemez ve ağırlık tablosu burada
        // okunabilir kalıyor (mutabakat ekranındaki kararın aynısı).
        usort($rows, static function (array $a, array $b): int {
            $weight = $a['weight'] <=> $b['weight'];

            if ($weight !== 0) {
                return $weight;
            }

            // HİÇ SORULMAMIŞ SATIR ÖNCE GELİR (`NULLS FIRST`): onay
            // durumu bilinmeyen satır, bilinenden daha çok ilgi ister.
            return ($a['checkedAt'] ?? '') <=> ($b['checkedAt'] ?? '');
        });

        return $rows;
    }
}
