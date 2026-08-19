<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sync\Actions\RequestResync;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ölü mektup ekranı — "Başarısız işlemler" + tek tıkla yeniden deneme.
 *
 * Mimari Karar Dokümanı v2.2 · §12 · Dead letter (adım 4 ve 5),
 * §13 · Faz 3 · madde 3+4, §9 · error_permanent durumundan çıkış,
 * §1 · Karar 18, §17 · "Panelde senkron geçmişi ve hata görünürlüğü —
 * destek yükünü belirleyen tek ekran".
 *
 * EKRANIN VARLIK SEBEBİ — §12'nin beş adımının İLK ÜÇÜ zaten çalışıyordu:
 * operasyon `dead` işaretleniyor, sync state `error_*` oluyor ve yük
 * `failed_jobs`'ta duruyordu. Dördüncü ve beşinci adım (panel + buton)
 * yoktu; onlar olmadan ölü satır SONSUZA KADAR ölü kalır çünkü
 * `error_permanent` mutabakatta ASLA aday değildir (§10) ve o satıra
 * başka hiçbir mekanizma dokunmaz.
 *
 * DEĞİŞMEZ KURAL — DURUM YAZMAK YETMEZ (§9 · Karar 18):
 *   `sync_operations.status = 'pending'` yazmak YANLIŞ olurdu. Kanonik
 *   veri o arada DEĞİŞMEDİ ve değişmeyen veriden yeni domain olayı
 *   doğmaz: hiç kimse o operasyonu yeniden dispatch etmez ve satır
 *   sonsuza kadar "bekliyor" görünür. Buton bu yüzden `RequestResync`'i
 *   çağırır — o, aynı transaction'da `ListingResyncRequested` yazar ve
 *   ASIL İŞ odur.
 *
 * DEĞİŞMEZ KURAL — ESKİ ÖLÜ OPERASYON `dead` KALIR:
 *   Yeniden deneme YENİ bir operasyon açar (REPAIR niyetiyle, tüketici
 *   tarafında). Eskisini `pending`'e çevirmek "bu satır beş kez denendi
 *   ve öldü" denetim izini siler ve destek bir daha neyin yaşandığını
 *   göremez.
 *
 * DEĞİŞMEZ KURAL — DOMAIN OPERASYON TÜRÜNDEN OKUNUR:
 *   `sync_operations`'ta `listing_id` ve `domain` KOLONU YOKTUR. Listing
 *   kimliği `entity_id`'de (`entity_type = 'listing'`), alan ise
 *   `operation_type`'ta yaşar. Domain sabit yazılsaydı ölü bir
 *   `PRICE_PUSH` için stok senkronu açılır ve fiyat HİÇ gitmezdi.
 *
 * DEĞİŞMEZ KURAL — HATA SINIFI GÖSTERİLİR:
 *   `AUTHENTICATION` (anahtarı yenile) ile `VALIDATION` (ürün verisini
 *   düzelt) kullanıcıya TAMAMEN FARKLI iş yaptırır. Gizlenseydi "yeniden
 *   dene" tek çare gibi görünür ve kullanıcı aynı hatayı sonsuza kadar
 *   yeniden üretirdi.
 *
 * DEĞİŞMEZ KURAL — ROTA MODEL BAĞLAMASI KULLANILMAZ:
 *   `SubstituteBindings` `web` grubundadır ve rota seviyesindeki `tenant`
 *   ara katmanından ÖNCE çalışır; bağlama kullanılsaydı sorgu kiracı
 *   bağlamı kurulmadan atılır ve izolasyon istisnası fırlatırdı. Kimlik
 *   `string` alınır ve burada, kiracı scope'unun ALTINDA aranır —
 *   yetkilendirme kimliğin tahmin edilemezliğine dayandırılmaz.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen alanlar.
 */
final class SyncFailureController extends Controller
{
    private const PER_PAGE = 200;

    public function index(): InertiaResponse
    {
        // EAGER-LOAD'DA OKUNACAK HER ALAN AÇIKÇA SEÇİLİR. Lazy loading
        // KAPALI; alan listesinden düşen bir kolon ekranda sessizce boş
        // görünür (bu projede `adapter_class` ve `supports_webhooks` aynı
        // biçimde iki kez ısırdı).
        $operations = $this->deadOperations()
            ->with([
                'listing:id,variant_id,channel_connection_id,external_id',
                'listing.variant:id,sku',
                'connection:id,label,channel_type_code',
            ])
            ->orderByDesc('id')
            ->limit(self::PER_PAGE)
            ->get();

        return Inertia::render('Failures/Index', [
            'rows' => $this->present($operations),
            'summary' => $this->summary(),
        ]);
    }

    /**
     * §12 · ADIM 5 — TEK TIKLA YENİDEN DENEME.
     *
     * Tekil ve toplu yol AYNI `RequestResync` çağrısını paylaşır. Ayrı
     * yollar yazılsaydı "durum yazmak yetmez" kuralı iki yerde yaşar,
     * biri değiştiğinde diğeri sessizce eski davranışı sürdürürdü.
     */
    public function retry(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'operation' => ['required_without:all', 'string'],
            'all' => ['sometimes', 'boolean'],
        ]);

        $operations = $request->boolean('all')
            ? $this->deadOperations()->with('listing')->get()
            : collect([$this->findDeadOrFail($validated['operation'])]);

        $requested = $this->requestResyncFor($operations);

        // FLASH ANAHTARI `success` — panelin PAYLAŞTIĞI ad budur
        // (`HandleInertiaRequests::share()`). Uydurma bir anahtar
        // (`status`) Inertia'ya HİÇ ULAŞMAZ: istek başarılı olur, olay
        // yazılır ama kullanıcı hiçbir geri bildirim görmez ve butonun
        // çalışıp çalışmadığını bilemez. Bu boşluk gerçek tarayıcı
        // çalıştırmasında bulundu — testler görmez, çünkü hiçbiri flash
        // mesajını okumuyordu.
        return redirect('/failures')->with(
            'success',
            $requested === 0
                ? 'Yeniden denenecek başarısız işlem bulunamadı.'
                : $requested.' işlem yeniden kuyruğa alındı — gönderim sıraya girdi.',
        );
    }

    // ─────────────────────────────────────────────────────────── sorgular

    /**
     * Ölü operasyonlar — YALNIZCA `dead`.
     *
     * `retrying` ve `pending` listelenseydi gerçek ölüler "sistem
     * hallediyor" satırlarının arasında kaybolurdu; ekran tam olarak
     * eylem gerektiren satırı öne çıkarmak için var.
     *
     * Kiracı kapsaması `BelongsToTenant` global scope'undan gelir — bu
     * bir Eloquent sorgusudur ve ham `DB::table()` DEĞİLDİR.
     *
     * @return Builder<SyncOperation>
     */
    private function deadOperations()
    {
        return SyncOperation::query()->where('status', SyncOperationStatus::DEAD->value);
    }

    /**
     * Ölü operasyonu kiracı scope'u ALTINDA arar.
     *
     * Bulunamazsa 404 — başka kiracının kimliği tahmin edilse bile.
     * Ölü OLMAYAN bir operasyon da 404 verir: bekleyen bir operasyon için
     * resync açmak ikinci bir gönderim üretirdi ve ekran onu zaten
     * göstermiyor.
     */
    private function findDeadOrFail(string $id): SyncOperation
    {
        $operation = $this->deadOperations()->with('listing')->find($id);

        if ($operation === null) {
            throw new NotFoundHttpException;
        }

        return $operation;
    }

    /**
     * Üst özet — kaç ölü işlem ve kaçı kullanıcı müdahalesi bekliyor.
     *
     * KALICI VE GEÇİCİ HATA AYRI SAYILIR: `AUTHENTICATION`/`VALIDATION`
     * kullanıcı müdahalesi ister; diğerleri (timeout, 5xx, hız sınırı)
     * kanal düzelince yeniden denemeyle geçebilir. Tek sayıda
     * birleştirilselerdi satıcı hangi satırların kendisini beklediğini
     * bilemezdi.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $permanent = array_map(
            static fn ($case): string => $case->value,
            array_values(array_filter(
                ErrorClass::cases(),
                static fn ($case): bool => $case->isPermanent(),
            )),
        );

        $row = $this->deadOperations()
            ->selectRaw('count(*) AS total')
            ->selectRaw(
                'count(*) FILTER (WHERE last_error_class = ANY(?)) AS needs_user',
                ['{'.implode(',', $permanent).'}'],
            )
            ->first();

        return [
            'total' => (int) ($row?->total ?? 0),
            'needs_user' => (int) ($row?->needs_user ?? 0),
        ];
    }

    /**
     * Operasyon başına son denemenin hata mesajı.
     *
     * SON DENEME OKUNUR, İLKİ DEĞİL: bir operasyon önce `TIMEOUT` alıp
     * sonra `VALIDATION` ile ölebilir; ilk deneme okunsaydı ekran "zaman
     * aşımı" der ve kullanıcı gerçek sebebi HİÇ görmezdi.
     *
     * Sıralama `attempt_number` üzerindendir, zaman damgası üzerinden
     * DEĞİL: `started_at` SANİYE hassasiyetlidir ve arka arkaya koşan
     * denemeler aynı damgayı taşıyabilir — sıra belirsiz kalırdı.
     *
     * Kiracı filtresi AÇIKÇA yazılır: `DB::table()` global scope'a TABİ
     * DEĞİLDİR ve yazılmazsa başka kiracının hata metni bu ekrana sızar.
     *
     * @param  list<string>  $operationIds
     * @return array<string, string|null>
     */
    private function latestMessages(array $operationIds): array
    {
        if ($operationIds === []) {
            return [];
        }

        $rows = DB::table('sync_attempts')
            ->select('sync_operation_id', 'error_message')
            ->where('tenant_id', TenantContext::idOrFail())
            ->whereIn('sync_operation_id', $operationIds)
            ->orderBy('sync_operation_id')
            ->orderByDesc('attempt_number')
            ->distinct('sync_operation_id')
            ->get();

        $messages = [];

        foreach ($rows as $row) {
            // `DISTINCT ON` yerine ilk görülen kazanır: sorgu zaten
            // (operasyon, deneme no DESC) sırasında geliyor.
            $messages[$row->sync_operation_id] ??= $this->readable($row->error_message);
        }

        return $messages;
    }

    /**
     * Kanal hatasını OKUNABİLİR hâle getirir.
     *
     * Ham mesaj HTTP istisnasının metnidir ve içine kanalın JSON gövdesi
     * gömülüdür: `HTTP request returned status code 400: {"code": ...,
     * "message": "ürün ..."}`. Ekranda olduğu gibi gösterilirse
     * satıcı `ürün` görür ve bu, ekranın TÜM AMACINI —
     * "kullanıcıya ne olduğunu söylemek" — boşa çıkarır. Gerçek tarayıcı
     * çalıştırmasında görüldü.
     *
     * GÖVDE ÇOĞU ZAMAN KIRPIKTIR ve bu yüzden `json_decode` TEK BAŞINA
     * YETMEZ: Guzzle istisna metnine gövdenin yalnızca ilk 120 karakterini
     * koyar ve `(truncated...)` ekler — JSON kapanmaz, çözümleme
     * başarısız olur ve satıcı yine kaçış dizisi okur. Bu yüzden önce tam
     * çözümleme denenir, olmazsa `message` alanı ham metinden ÇEKİLİR.
     *
     * Hiçbir yol tutmazsa ham metin OLDUĞU GİBİ kalır. Ayrıştırılamayan
     * bir hatayı gizlemek, teşhis için gereken tek ipucunu atmak olurdu.
     */
    private function readable(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return $raw;
        }

        $start = strpos($raw, '{');

        if ($start === false) {
            return $raw;
        }

        // (1) Gövde tamsa doğrudan çözülür.
        $decoded = json_decode(substr($raw, $start), true);

        if (is_array($decoded)) {
            $message = $decoded['message'] ?? $decoded['error'] ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        // (2) Gövde kırpıksa `message` alanı metinden çekilir. Kırpma
        // mesajın ORTASINA düşebilir; yarım kaçış dizisi (`\u00`) sona
        // eklenmiş olabilir ve `json_decode` onu reddeder — bu yüzden
        // yakalanan parça tek başına, hoşgörülü biçimde çözülür.
        if (preg_match('/"(?:message|error)"\s*:\s*"((?:[^"\\\\]|\\\\.)*)/', $raw, $m) !== 1) {
            return $raw;
        }

        // Guzzle'ın kırpma işareti mesajın PARÇASI DEĞİLDİR: kapanış
        // tırnağı olmadığı için regex onu da yutar ve gösterilirse satıcı
        // kanalın söylediği sanır.
        $fragment = preg_replace('/\s*\(truncated\.\.\.\)\s*$/', '', $m[1]) ?? $m[1];

        // Yarım kalan kaçış dizisi atılır; yoksa çözümleme yine düşer.
        $fragment = preg_replace('/\\\\u[0-9a-fA-F]{0,3}$|\\\\$/', '', $fragment) ?? $fragment;

        $decodedFragment = json_decode('"'.$fragment.'"');

        if (! is_string($decodedFragment) || $decodedFragment === '') {
            return $raw;
        }

        // Kırpıldığı belli edilir: satıcı metnin yarım olduğunu bilmeli,
        // yoksa kanalın söylediğinin tamamını okuduğunu sanar. Bu dala
        // ancak tam çözümleme DÜŞTÜĞÜNDE gelinir — yani gövde zaten
        // eksiktir.
        return $decodedFragment.'…';
    }

    // ─────────────────────────────────────────────────────────── eylem

    /**
     * Her ölü operasyon için resync talebi açar ve kaç tanesinin
     * açıldığını döner.
     *
     * DOMAIN OPERASYON TÜRÜNDEN OKUNUR. Tanınmayan bir tür (şemaya yeni
     * bir `*_PUSH` eklenip enum güncellenmezse) SESSİZCE ATLANIR: uydurma
     * bir domain seçmek yanlış alanı senkronlar ve hata teşhis edilemez
     * hâle gelirdi.
     *
     * Listing'i silinmiş operasyon da atlanır — `RequestResync` listing
     * ister ve `entity_id` bir FK DEĞİLDİR.
     *
     * @param  Collection<int, SyncOperation>  $operations
     */
    private function requestResyncFor(Collection $operations): int
    {
        $action = app(RequestResync::class);
        $requested = 0;

        foreach ($operations as $operation) {
            $domain = SyncDomain::fromOperationType($operation->operation_type);
            $listing = $operation->listing;

            if ($domain === null || ! $listing instanceof Listing) {
                continue;
            }

            $action->run($listing, $domain, RequestResync::REASON_MANUAL_RETRY);
            $requested++;
        }

        return $requested;
    }

    // ─────────────────────────────────────────────────────────── sunum

    /**
     * @param  Collection<int, SyncOperation>  $operations
     * @return list<array<string, mixed>>
     */
    private function present(Collection $operations): array
    {
        $messages = $this->latestMessages($operations->pluck('id')->all());

        $rows = [];

        foreach ($operations as $operation) {
            $errorClass = $operation->last_error_class;

            $rows[] = [
                'id' => $operation->id,
                'sku' => $operation->listing?->variant?->sku,
                'externalId' => $operation->listing?->external_id,
                'channel' => $operation->connection?->label,

                // Ekranda alan adı gösterilir (`Stok`), operasyon türü
                // değil: `INVENTORY_PUSH` iç bir kavramdır ve satıcıya
                // hiçbir şey söylemez.
                'domain' => SyncDomain::fromOperationType($operation->operation_type)?->value,

                'errorClass' => $errorClass,
                'errorMessage' => $messages[$operation->id] ?? null,

                // Kalıcı hata kullanıcı müdahalesi bekler; geçici hata
                // kanal düzelince yeniden denemeyle geçer. İkisi ekranda
                // AYRI anlatılır.
                'needsUser' => $errorClass !== null
                    && (ErrorClass::tryFrom($errorClass)?->isPermanent() ?? false),

                'attemptCount' => (int) $operation->attempt_count,
                'failedAt' => $operation->updated_at?->toIso8601String(),
            ];
        }

        return $rows;
    }
}
