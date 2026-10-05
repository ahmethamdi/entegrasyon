<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Actions\SetImageChannelExclusion;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Channels\Contracts\DeclaresImageLimit;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Sync\Actions\PublishListing;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Support\PrerequisiteGate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

/**
 * Ürünü kanala gönderme ekranı — §13 · faz 1.5 · "Panelde kanal seçimi,
 * gönderme akışı, senkron durumu rozeti".
 *
 * DEĞİŞMEZ KURAL — YETENEK TİP SİSTEMİNDEN OKUNUR:
 *   Yalnızca `SupportsCatalog` uygulayan bağlantılar seçenek olarak sunulur.
 *   Panelde `if type === 'woocommerce'` bloğu yazılmaz; yeni kanal
 *   eklendiğinde bu dosya değişmez.
 *
 * DEĞİŞMEZ KURAL — SAĞLIKSIZ KANALA GÖNDERİLMEZ:
 *   `active` olmayan bağlantı ne listelenir ne kabul edilir. Aktif ama
 *   çalışmayan bağlantıya iş atmak, kullanıcıya "gönderildi" deyip arkada
 *   kalıcı hataya düşen operasyon bırakmaktır (§13 · faz 1.4 gerekçesi).
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen alanlar.
 *   Bağlantı modelini paylaşmak `settings` jsonb'sini ve ilişkili kimlik
 *   bilgisi kaydını HTTP yanıtına koyardı.
 *
 * Bağlantı kimliği İSTEKTEN gelir ve kiracı scope'u altında aranır: form
 * kurcalayan biri aksi halde başka kiracının mağazasına ürün gönderirdi.
 */
final class ProductChannelController extends Controller
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        // Ön koşul sonucu MESAJ için gerekir: `PublishListing`'in boş
        // dizisi "engellendi" ile "zaten güncel"i ayırt etmez.
        private readonly PrerequisiteGate $gate,
    ) {}

    /** Ürünün kanal durumları ve gönderilebilir bağlantılar. */
    public function index(string $product): InertiaResponse
    {
        // Kiracı scope'u altında aranır: başka kiracının ürünü 404.
        $model = Product::query()->with('variants')->findOrFail($product);

        return Inertia::render('Products/Channels', [
            'product' => [
                'id' => $model->id,
                'sku' => $model->sku,
                'title' => $model->title,
                'contentVersion' => $model->content_version,
            ],
            'channels' => $this->channelsFor($model),
            'images' => $this->imagesFor($model),
            'imageChannels' => $this->imageChannels(),
        ]);
    }

    /**
     * Görseli bir kanal türüne gönder / gönderme (A15).
     *
     * Kanal türü, bu kiracının GÖNDERİLEBİLİR bağlantılarından biri
     * olmalıdır: serbest metin kabul edilseydi `excluded_channels`'a
     * anlamsız değerler yazılırdı.
     */
    public function updateImageChannel(
        Request $request,
        string $product,
        string $image,
        SetImageChannelExclusion $setExclusion,
    ): RedirectResponse {
        $model = Product::query()->findOrFail($product);

        // Görsel BU ürüne ait olmalı — başka ürünün kimliği 404.
        $imageModel = ProductImage::query()
            ->where('product_id', $model->id)
            ->findOrFail($image);

        $validated = $request->validate([
            'channel_type_code' => ['required', 'string', Rule::in(array_column($this->imageChannels(), 'code'))],
            'excluded' => ['required', 'boolean'],
        ]);

        $setExclusion->run($imageModel, $validated['channel_type_code'], (bool) $validated['excluded']);

        return redirect("/products/{$model->id}/channels")->with(
            'success',
            __('Görsel seçimi kaydedildi. Kanalda görünmesi için ürünü yeniden gönder.'),
        );
    }

    /** Ürünü seçilen kanala gönderir. */
    public function store(
        Request $request,
        string $product,
        PublishListing $publish,
    ): RedirectResponse {
        $model = Product::query()->with('variants')->findOrFail($product);

        $validated = $request->validate([
            'connection_id' => ['required', 'string'],
        ]);

        $connection = $this->publishableConnection($validated['connection_id']);

        // ÖN KOŞUL KAPISI ÖNCE SORULUR — sonuç `PublishListing`'in boş
        // dizisinden AYIRT EDİLEMEZ.
        //
        // Boş dizi İKİ ayrı anlama gelir: sürüm kapısı eledi (zaten
        // gönderilmiş) veya ön koşul kapısı engelledi (hiç gönderilmedi).
        // İkisi tek mesaja indirgenirse satıcı eksik eşleştirmeyi "her şey
        // yolunda" sanır ve ürününün neden kanalda görünmediğini asla
        // anlayamaz. (Gerçek tarayıcı çalıştırmasında bulundu.)
        $prerequisite = $this->gate->check($model, $connection);

        $operationIds = $publish->run($model, $connection);

        if (! $prerequisite->satisfied()) {
            return redirect("/products/{$model->id}/channels")->with(
                'warning',
                __(
                    ':sku gönderilemedi — ön koşul eksik. :reason',
                    ['sku' => $model->sku, 'reason' => $prerequisite->reason()],
                ),
            );
        }

        // Sürüm kapısı elediyse yeni iş yoktur; kullanıcıya "gönderildi"
        // demek yanlış olurdu — zaten gönderilmiş olan budur.
        $message = $operationIds === []
            ? __(':sku bu kanalda zaten güncel.', ['sku' => $model->sku])
            : __(':sku :channel kanalına gönderiliyor.', ['sku' => $model->sku, 'channel' => $connection->label]);

        return redirect("/products/{$model->id}/channels")->with('success', $message);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /**
     * Ürünün görselleri, sırasıyla — panelde kanal seçimiyle birlikte.
     *
     * @return list<array<string, mixed>>
     */
    private function imagesFor(Product $product): array
    {
        return ProductImage::query()
            ->where('product_id', $product->id)
            ->orderByRaw('CASE WHEN variant_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->get()
            ->map(fn (ProductImage $image): array => [
                'id' => $image->id,
                'url' => $image->publicUrl(),
                // HTTPS olmayan görsel hiçbir kanala gitmez; panel bunu
                // söylemeli, yoksa satıcı neden gitmediğini bilemez.
                'path' => $image->storage_path,
                'excludedChannels' => $image->excluded_channels ?? [],
                'imported' => $image->source_connection_id !== null,
            ])
            ->values()
            ->all();
    }

    /**
     * Görsel seçiminde gösterilecek kanal TÜRLERİ — bağlantı başına değil.
     *
     * İki Trendyol mağazası aynı görsel kuralını paylaşır; bağlantı başına
     * seçim satıcıya aynı soruyu iki kez sorardı. Sınır yetenek arayüzünden
     * okunur (`DeclaresImageLimit`), kanal adı kontrol edilmez.
     *
     * @return list<array{code: string, name: string, maxImages: int|null}>
     */
    private function imageChannels(): array
    {
        $types = [];

        foreach ($this->publishableConnections() as $connection) {
            $code = $connection->channel_type_code;

            if (isset($types[$code])) {
                continue;
            }

            $maxImages = null;

            try {
                $adapter = $this->registry->for($connection);
                $maxImages = $adapter instanceof DeclaresImageLimit ? $adapter->maxImages() : null;
            } catch (Throwable) {
                // Adapter kurulamıyorsa sınır bilinmez; kanal yine listelenir.
            }

            $types[$code] = [
                'code' => $code,
                'name' => $connection->channelType?->name ?? $code,
                'maxImages' => $maxImages,
            ];
        }

        return array_values($types);
    }

    /**
     * Gönderilebilir bağlantı: kiracıya ait, aktif ve KATALOG yeteneği olan.
     *
     * Üç koşulun üçü de alan hatasına çevrilir; 404 veya 500 vermek
     * kullanıcıya ne yapacağını söylemez.
     */
    private function publishableConnection(string $connectionId): ChannelConnection
    {
        // Kiracı scope'u: başka kiracının bağlantısı bulunamaz.
        $connection = ChannelConnection::query()
            ->with('channelType:code,name,adapter_class')
            ->find($connectionId);

        if ($connection === null) {
            throw ValidationException::withMessages([
                'connection_id' => __('Kanal bulunamadı.'),
            ]);
        }

        if ($connection->status !== 'active') {
            throw ValidationException::withMessages([
                'connection_id' => __(
                    ':name bağlantısı aktif değil; önce sağlık kontrolünü geçmesi gerekiyor.',
                    ['name' => $connection->label],
                ),
            ]);
        }

        if (! $this->supportsCatalog($connection)) {
            throw ValidationException::withMessages([
                'connection_id' => __(
                    ':name kanalı ürün göndermeyi desteklemiyor.',
                    ['name' => $connection->label],
                ),
            ]);
        }

        return $connection;
    }

    /**
     * Ürünün kanal başına durumu.
     *
     * Listing satırı VARYANT başınadır; bu ekran KANAL başına özet gösterir.
     * Çok varyantlı üründe kanalın durumu, o kanaldaki varyant satırlarının
     * en kötüsüdür — rozet sırası: kalıcı hata > geçici hata > bekliyor >
     * senkron. `error_permanent` kullanıcı müdahalesi bekler; "bekliyor"
     * demek satıcıyı kendiliğinden düzelecek sanmaya iter.
     *
     * @return list<array<string, mixed>>
     */
    private function channelsFor(Product $product): array
    {
        $variantIds = $product->variants->pluck('id')->all();

        $listings = $variantIds === []
            ? new Collection
            : Listing::query()->whereIn('variant_id', $variantIds)->get();

        $states = $listings->isEmpty()
            ? new Collection
            : ListingSyncState::query()
                ->whereIn('listing_id', $listings->pluck('id')->all())
                ->where('domain', SyncDomain::CONTENT->value)
                ->get()
                ->keyBy('listing_id');

        $rows = [];

        foreach ($this->publishableConnections() as $connection) {
            $forConnection = $listings->where('channel_connection_id', $connection->id);

            $rows[] = [
                'connectionId' => $connection->id,
                'label' => $connection->label,
                'channel' => $connection->channelType?->name ?? $connection->channel_type_code,
                'account' => $connection->external_account_id,
                'published' => $forConnection->isNotEmpty(),
                'externalId' => $forConnection->first()?->external_id,
                'externalUrl' => $forConnection->first()?->external_url,
                'lifecycle' => $forConnection->first()?->lifecycle_status,
                // ONAY DURUMU LIFECYCLE'DAN AYRI GÖSTERİLİR (§14): ürün
                // bizde "gönderildi" ama kanalda "beklemede" veya
                // "reddedildi" olabilir. Red sebebi gösterilmezse satıcı
                // neyi düzelteceğini bilemez.
                'rejectionReason' => $forConnection->first()?->approval_rejection_reason,
                ...$this->syncSummary($forConnection, $states),
            ];
        }

        return $rows;
    }

    /**
     * Kanal başına senkron özeti — en kötü durum kazanır.
     *
     * @param  Collection<int, Listing>  $listings
     * @param  Collection<string, ListingSyncState>  $states
     * @return array{syncStatus: string|null, lastError: string|null, pendingWork: bool}
     */
    private function syncSummary(Collection $listings, Collection $states): array
    {
        // Rozet sırası: küçük sayı daha kötü.
        $rank = [
            'error_permanent' => 0,
            'error_transient' => 1,
            'blocked' => 2,
            'pending' => 3,
            'syncing' => 4,
            'synced' => 5,
        ];

        $worst = null;
        $lastError = null;
        $pendingWork = false;

        foreach ($listings as $listing) {
            $state = $states->get($listing->id);

            if ($state === null) {
                continue;
            }

            $pendingWork = $pendingWork || $state->is_dirty;

            $current = $rank[$state->status] ?? 3;

            if ($worst === null || $current < $rank[$worst]) {
                $worst = $state->status;
                $lastError = $state->last_error;
            }
        }

        return [
            'syncStatus' => $worst,
            'lastError' => $lastError,
            'pendingWork' => $pendingWork,
        ];
    }

    /**
     * Kiracının aktif ve katalog yeteneği olan bağlantıları.
     *
     * @return list<ChannelConnection>
     */
    private function publishableConnections(): array
    {
        return ChannelConnection::query()
            // adapter_class DA yüklenir: registry onu okuyarak yetenekleri
            // çözer. Yalnızca code,name seçilirse yetenekler sessizce boşalır
            // ve hiçbir kanal listelenmez.
            ->with('channelType:code,name,adapter_class')
            ->where('status', 'active')
            ->orderBy('label')
            ->get()
            ->filter(fn (ChannelConnection $c): bool => $this->supportsCatalog($c))
            ->values()
            ->all();
    }

    /**
     * Bağlantı ürün göndermeyi destekliyor mu — `instanceof` ile.
     *
     * ⚠️ İKİ ANAHTAR DA OKUNUR ve bu bir "veya" DEĞİL, İKİ AYRI YOLDUR.
     * `catalog` TEK çağrılık yayındır (`PushListing`), `offer_lifecycle`
     * ÜÇ ADIMLI ve ara kimlikli olandır (`PushOfferListing`, §03 · Delta
     * 1). Hangi işin atılacağına `ContentPushDispatcher` karar verir;
     * BURADAKİ soru yalnızca "bu kanala ürün gönderilebilir mi".
     *
     * ⚠️ YALNIZCA `catalog` OKUNSAYDI eBay bu ekranda HİÇ GÖRÜNMEZDİ:
     * zincir çalışır, testler yeşil olur ve satıcı çalışan özelliği hiç
     * göremezdi. Projede bu hata biçimi ÜÇ KEZ yaşandı (Etsy
     * `pricing`/`orders`, Woo `catalog_import`) ve her seferinde
     * davranış testleri yeşildi — çünkü hepsi yeteneği `instanceof` ile
     * okuyordu, ekranı süren kimse yoktu.
     *
     * Bozuk bir adapter sınıfı ekranı 500'e düşürmemeli; kanal listelenmez
     * ama sebep günlüğe yazılır (sessizce yutulmaz).
     */
    private function supportsCatalog(ChannelConnection $connection): bool
    {
        try {
            $capabilities = $this->registry->capabilitiesFor($connection);

            return ($capabilities['catalog'] ?? false)
                || ($capabilities['offer_lifecycle'] ?? false);
        } catch (Throwable $e) {
            Log::warning('products.channel_capabilities_unavailable', [
                'connection' => $connection->id,
                'channel_type' => $connection->channel_type_code,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
