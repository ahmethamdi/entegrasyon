<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Support\ChannelImportResult;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\RemoteProduct;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Kanaldaki kataloğu okuyup kanonik ürünlere çevirir.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · madde 5 ("kanaldan ürün
 * çekme"), §7 · SupportsCatalogImport.
 *
 * MADDENİN VARLIK SEBEBİ: satıcının ürünleri ZATEN bir kanalda duruyor.
 * CSV'ye döküp yeniden yüklemesini istemek, sistemin bağlandığı kanaldan
 * okuyabildiği veriyi elle taşıtmak demektir; yeni müşteri kurulumunun en
 * büyük sürtünmesi budur.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DEĞİŞMEZ KURAL — YAZMA YOLU `ImportProducts` İLE AYNIDIR
 * ─────────────────────────────────────────────────────────────────────
 * Ürün `CreateProduct`, güncelleme `UpdateProduct` yolundan geçer.
 * `Product::create()` yazmak açılış stoğunun ledger'dan geçmesini atlar ve
 * `on_hand = Σ on_hand_delta` eşitliğini bozar (§4). Kanaldan çekilen 500
 * ürün bu kuralı atlarsa 500 bozuk bakiye ve mutabakatın o günden sonra
 * bulacağı 500 SAHTE sürüklenme demektir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DEĞİŞMEZ KURAL — VAR OLAN SKU'DA STOK YAZILMAZ
 * ─────────────────────────────────────────────────────────────────────
 * CSV içe aktarmasındaki kuralın AYNISI ve burada DAHA TEHLİKELİDİR:
 * kanaldaki stok değeri BAYAT olabilir (biz henüz göndermemişizdir, ya da
 * kanal bizim gönderdiğimizi uygulamamıştır). Uygulansaydı satıcının
 * SATILMIŞ malları bir içe aktarma turuyla geri gelir, bakiye sessizce
 * bozulur ve fazla satışa yol açardı. Stok yalnızca ledger yollarından
 * değişir; sürüklenme MUTABAKATIN işidir, içe aktarmanın değil.
 *
 * Kanaldaki stok YALNIZCA yeni üründe ve YALNIZCA açılış hareketi olarak
 * yazılır — o an kanonik bakiye YOKTUR, dolayısıyla ezilecek bir gerçek de
 * yoktur.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DEĞİŞMEZ KURAL — TEK BOZUK ÜRÜN TURU DÜŞÜRMEZ
 * ─────────────────────────────────────────────────────────────────────
 * Taksonomideki "tek bozuk bağlantı turu durdurmaz" ve CSV'deki "tek bozuk
 * satır dosyayı düşürmez" kurallarının aynısı. Tur TEK TRANSACTION'A
 * SARILMAZ; her ürün kendi transaction'ında atomiktir (`CreateProduct`
 * kendi içinde sarar).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ADR-003 — KANAL GRUBU TEK ÜRÜNDÜR
 * ─────────────────────────────────────────────────────────────────────
 * Kanal varyant başına satır döndürür; aynı `external_parent_id`'yi
 * (Shopify ürün gid'i, Trendyol `contentId`, Etsy `listing_id`) taşıyan
 * satırlar TEK ürünün varyantlarıdır. İlk gelen ürünü açar, kardeşleri
 * `AddVariantToProduct` ile o ürüne eklenir; seçenek değerleri yazılır,
 * fiyat ve kanal bağı DOĞRU VARYANTA gider.
 *
 * ESKİ BÖLÜNMÜŞ VERİ BİRLEŞTİRİLMEZ: ADR-003 öncesi içe aktarmada aynı
 * grubun varyantları ayrı ürün açtı. Grup birden çok ürüne dağılmışsa yeni
 * kardeş hangisine ekleneceği belirsizdir — eski davranışla ayrı ürün açılır.
 * Birleştirme kanal ilanlarına dokunduğu için ayrı, kiracı onaylı bir iştir.
 *
 * SAYFA HATASI İSE TURU DURDURUR ve bu ayrım bilinçlidir: tek ürünün
 * bozukluğu o ürüne özgüdür, ama sayfa çekilemiyorsa kanal konuşmuyor
 * demektir ve kalan sayfaları denemek yalnızca kotayı yakar. O ana kadar
 * yazılanlar KORUNUR ve rapor nerede durulduğunu söyler.
 */
final class ImportProductsFromChannel
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly CreateProduct $createProduct,
        private readonly UpdateProduct $updateProduct,
        private readonly SyncImportedImages $syncImages,
        private readonly EnforceQuota $quota,
        private readonly AddVariantToProduct $addVariant,
        private readonly SetVariantPrice $setPrice,
        private readonly AssignVariantOptions $assignOptions,
    ) {}

    /**
     * Turda görülen görseller, ürün başına BİRLEŞİK ve sıralı.
     *
     * Görsel senkronu kaynak başına "listede olmayanı sil" yapar. Gruplu
     * üründe her varyant yalnız kendi görselini taşır; varyant başına
     * senkron kardeşin görselini silerdi. Tur sonunda ürün başına BİR KEZ
     * yazılır.
     *
     * @var array<string, array{product: Product, urls: list<string>}>
     */
    private array $images = [];

    public function run(ChannelConnection $connection, string $warehouseId): ChannelImportResult
    {
        $tenantId = TenantContext::idOrFail();

        $adapter = $this->registry->for($connection);

        // YETENEK `instanceof` İLE OKUNUR, kanal adı KONTROL EDİLMEZ (§7).
        // Desteklemeyen kanal SESSİZCE BOŞ DÖNMEZ: "0 ürün bulundu" ile
        // "bu kanal içe aktarmayı desteklemiyor" farklı şeylerdir ve
        // birincisi satıcıya kataloğunun boş olduğunu düşündürürdü.
        if (! $adapter instanceof SupportsCatalogImport) {
            return ChannelImportResult::unsupported(
                $connection->channelType?->name ?? $connection->channel_type_code,
            );
        }

        $created = 0;
        $variantsAdded = 0;
        $updated = 0;
        $this->images = [];
        $skipped = 0;
        $errors = [];

        // ⚠️ KOTA BURADA DA GEÇERLİ (B2) — ImportProducts ile aynı kural:
        // yalnız YENİ ürün sayılır, güncelleme serbest.
        $remaining = $this->quota->remaining(QuotaMetric::PRODUCTS);
        $quotaBlocked = 0;
        $archived = 0;

        $cursor = null;
        $pagesRead = 0;
        $maxPages = $adapter->maxImportPages();

        do {
            try {
                $page = $adapter->fetchProductPage($cursor);
            } catch (Throwable $e) {
                // TUR DURUR ama o ana kadar yazılanlar KORUNUR — gerekçe
                // sınıf başlığında.
                Log::warning('catalog.channel_import_page_failed', [
                    'tenant' => $tenantId,
                    'connection' => $connection->id,
                    'cursor' => $cursor,
                    'error' => $e->getMessage(),
                ]);

                $errors = $this->flushImages($connection, $errors);

                return new ChannelImportResult(
                    created: $created,
                    updated: $updated,
                    skipped: $skipped,
                    errors: $this->withQuotaNote($errors, $quotaBlocked),
                    stoppedEarly: true,
                    stopReason: $e->getMessage(),
                    variantsAdded: $variantsAdded,
                );
            }

            $pagesRead++;
            $archived += $page->archived;

            foreach ($page->products as $product) {
                // SKU'SUZ VE ADRESSİZ ÜRÜN ATLANIR ama SAYILIR ve SEBEBİYLE
                // raporlanır. Sessizce düşseydi satıcı "50 ürünüm vardı,
                // 47'si geldi" der ve eksiğin nedenini hiçbir yerde bulamazdı.
                if (! $product->isImportable()) {
                    $skipped++;
                    $errors[] = [
                        'line' => 0,
                        'message' => __(
                            ':title: kanalda SKU tanımlı değil, içe aktarılamadı.',
                            ['title' => $product->title ?? "#{$product->externalId}"],
                        ),
                    ];

                    continue;
                }

                // SKU'suz ürüne SKU ÜRETİLİR — yalnız kanal adresi biliniyorsa
                // (`isImportable` bunu garanti eder). Eşleşme bundan sonra bu
                // SKU'ya değil, aşağıda kurulan `Listing` bağına dayanır.
                $sku = $product->hasSku()
                    ? trim((string) $product->sku)
                    : (string) $product->autoSku(substr($connection->channel_type_code, 0, 3));

                try {
                    // ÖNCE BAĞ, SONRA SKU: satıcı kanalda SKU'yu değiştirdiyse
                    // (ya da sonradan girdiyse) SKU araması tutmaz ve ürün
                    // ikinci kez açılırdı. Bağ kanal kimliğidir, değişmez.
                    // Arama VARYANT döndürür: çok varyantlı üründe fiyat ve
                    // bağ doğru bedene gitmeli (ADR-003).
                    $existing = $this->findByListing($connection, $product)
                        ?? $this->findBySku($tenantId, $sku);

                    if ($existing !== null) {
                        $this->applyUpdate($connection, $existing, $product);
                        $this->collectImages($existing->product, $product->images);
                        $this->linkListing($connection, $existing, $product);
                        $updated++;

                        continue;
                    }

                    // KARDEŞ VARYANT: grubun ürünü bu turda ya da önceki bir
                    // turda açılmışsa yeni ürün DEĞİL, o ürüne varyanttır.
                    // Kota ürün sayar; varyant kotadan düşmez.
                    [$group, $ambiguous] = $this->findGroup($connection, $product);

                    if ($group !== null) {
                        $variant = $this->addVariant->run(
                            product: $group,
                            sku: $sku,
                            price: (float) ($product->price ?? 0),
                            openingStock: max(0, $product->quantity ?? 0),
                            warehouseId: $warehouseId,
                            options: $product->options,
                            currency: $product->currency ?? 'TRY',
                            barcode: $product->barcode,
                            originConnectionId: $connection->id,
                        );
                        $created++;
                        $variantsAdded++;

                        $this->collectImages($group, $product->images);
                        $this->linkListing($connection, $variant, $product);

                        continue;
                    }

                    if ($remaining !== null && $remaining <= 0) {
                        $quotaBlocked++;

                        continue;
                    }

                    $new = $this->applyCreate($product, $sku, $warehouseId, $connection->id, $ambiguous);
                    $created++;

                    if ($remaining !== null) {
                        $remaining--;
                    }

                    // Görsel hatası ürünü geri almaz: ürün yazıldı ve
                    // sayıldı, görsel hatası raporda ayrıca görünür.
                    $this->collectImages($new, $product->images);
                    $this->linkListing($connection, $new->variants()->firstOrFail(), $product);
                } catch (Throwable $e) {
                    // SESSİZCE YUTULMAZ — tur devam eder, ürün rapora girer.
                    $errors[] = [
                        'line' => 0,
                        'message' => sprintf('%s: %s', $sku, $e->getMessage()),
                    ];

                    Log::warning('catalog.channel_import_product_failed', [
                        'tenant' => $tenantId,
                        'connection' => $connection->id,
                        'sku' => $sku,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $cursor = $page->nextCursor;
        } while ($page->hasMore && $cursor !== null && $pagesRead < $maxPages);

        // ÜST SINIRA TAKILDIYSA KULLANICI BİLİR. Sessizce durulsaydı rapor
        // "içe aktarma tamamlandı" der, oysa katalogun kalanı hiç
        // görülmemiştir (§13 · "no silent caps").
        $hitPageCap = $page->hasMore && $pagesRead >= $maxPages;

        $errors = $this->flushImages($connection, $errors);

        // Arşivli varyantlar ATLANDI sayılır ve TEK satırla açıklanır.
        if ($archived > 0) {
            $skipped += $archived;
            $errors[] = [
                'line' => 0,
                'message' => __(
                    ':count ürün kanalda arşivde olduğu için içe aktarılmadı. Satışa açmak için ürünü kanalda arşivden çıkarıp yeniden çekin.',
                    ['count' => $archived],
                ),
            ];
        }

        return new ChannelImportResult(
            created: $created,
            updated: $updated,
            skipped: $skipped,
            errors: $this->withQuotaNote($errors, $quotaBlocked),
            stoppedEarly: $hitPageCap,
            stopReason: $hitPageCap
                ? __(
                    'Tur başına en fazla :max sayfa okunur; kanalda daha fazla ürün var. Yeniden çalıştırın.',
                    ['max' => $maxPages],
                )
                : null,
            variantsAdded: $variantsAdded,
        );
    }

    // ---------------------------------------------------------------- iç

    /**
     * @param  list<string>  $urls
     */
    private function collectImages(Product $product, array $urls): void
    {
        $entry = $this->images[$product->id] ?? ['product' => $product, 'urls' => []];
        $entry['urls'] = array_values(array_unique([...$entry['urls'], ...$urls]));
        $this->images[$product->id] = $entry;
    }

    /**
     * Toplanan görselleri ürün başına BİR KEZ yazar.
     *
     * Görsel hatası ürünü geri almaz: ürün yazıldı ve sayıldı, hata
     * raporda ayrıca görünür.
     *
     * @param  list<array{line: int, message: string}>  $errors
     * @return list<array{line: int, message: string}>
     */
    private function flushImages(ChannelConnection $connection, array $errors): array
    {
        foreach ($this->images as $entry) {
            try {
                $this->syncImages->run($entry['product'], $connection->id, $entry['urls']);
            } catch (Throwable $e) {
                $errors[] = [
                    'line' => 0,
                    'message' => sprintf('%s: %s', $entry['product']->sku, $e->getMessage()),
                ];

                Log::warning('catalog.channel_import_images_failed', [
                    'connection' => $connection->id,
                    'product' => $entry['product']->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->images = [];

        return $errors;
    }

    /**
     * Kota yüzünden atlananlar TEK satırla raporlanır — sessizce düşseydi
     * satıcı "kanalda 300 ürün var, 50'si geldi" der ve sebebi bulamazdı.
     *
     * @param  list<array{line: int, message: string}>  $errors
     * @return list<array{line: int, message: string}>
     */
    private function withQuotaNote(array $errors, int $blocked): array
    {
        if ($blocked === 0) {
            return $errors;
        }

        $limit = $this->quota->planForCurrentTenant()?->limitFor(QuotaMetric::PRODUCTS);

        $errors[] = [
            'line' => 0,
            'message' => __(
                'Plan ürün sınırına ulaşıldı (:limit ürün): kanaldaki :count yeni ürün içe aktarılmadı. Mevcut ürünlerin güncellemesi uygulandı. Daha fazla ürün için planınızı yükseltin.',
                ['limit' => (int) $limit, 'count' => $blocked],
            ),
        ];

        return $errors;
    }

    /**
     * AYNI TURDA AYNI SKU İKİ KEZ GELİRSE İKİNCİSİ GÜNCELLEMEDİR.
     *
     * Arama her üründe YENİDEN yapılır ve önbelleğe alınmaz — `ImportProducts`
     * ile aynı gerekçe: ilk ürün kaydı yaratmışsa ikincisi onu BULMALIDIR,
     * yoksa `UNIQUE(tenant_id, sku)` ihlaline düşer.
     *
     * ÖNCE VARYANT SKU'SU: çok varyantlı üründe satılabilir birimin SKU'su
     * varyantta yaşar. Ürün SKU'su (grup kodu) yalnız TEK varyantlı üründe
     * eşleşme sayılır — çok varyantlıda hangi bedene denk geldiği belirsizdir.
     */
    private function findBySku(string $tenantId, string $sku): ?Variant
    {
        $variant = Variant::query()
            ->where('tenant_id', $tenantId)
            ->where('sku', $sku)
            ->first();

        if ($variant !== null) {
            return $variant;
        }

        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('sku', $sku)
            ->first();

        if ($product === null) {
            return null;
        }

        $variants = $product->variants()->limit(2)->get();

        return $variants->count() === 1
            ? $variants->first()->setRelation('product', $product)
            : null;
    }

    /**
     * Bu bağlantıda bu kanal kimliğine bağlı VARYANT — yeniden içe aktarmada.
     */
    private function findByListing(ChannelConnection $connection, RemoteProduct $product): ?Variant
    {
        $externalId = $product->listingIdentity['external_id'] ?? null;

        if (! is_string($externalId) || $externalId === '') {
            return null;
        }

        return Listing::query()
            ->where('channel_connection_id', $connection->id)
            ->where('external_id', $externalId)
            ->first()?->variant;
    }

    /**
     * Kanal grubunun (`external_parent_id`) bizdeki ürünü.
     *
     * Dönüş: [ürün, belirsiz mi]. Grup TEK ürüne bağlıysa o ürün döner.
     * Birden çok ürüne dağılmışsa (ADR-003 öncesi bölünmüş veri) ürün
     * DÖNMEZ ve `belirsiz = true`: hangisine eklendiği tahmin olurdu ve
     * yanlış tahmin stoğu başka ürünün altına yazardı.
     *
     * @return array{0: Product|null, 1: bool}
     */
    private function findGroup(ChannelConnection $connection, RemoteProduct $product): array
    {
        $groupId = $product->groupId();

        if ($groupId === null) {
            return [null, false];
        }

        $productIds = Listing::query()
            ->join('variants', 'variants.id', '=', 'listings.variant_id')
            ->where('listings.channel_connection_id', $connection->id)
            ->where('listings.external_parent_id', $groupId)
            ->distinct()
            ->limit(2)
            ->pluck('variants.product_id');

        if ($productIds->count() !== 1) {
            return [null, $productIds->count() > 1];
        }

        return [Product::query()->find($productIds->first()), false];
    }

    /**
     * Grup birden çok ürüne dağılmış mı (eski bölünmüş veri)?
     *
     * O durumda tek varyantlı ürünün başlığı kanaldaki GRUP başlığıyla
     * EZİLMEZ: Etsy'de eski içe aktarma "Kolye — Altın" yazdı; grup başlığı
     * ("Kolye") yazılsaydı kardeş ürünler panelde ayırt edilemez olurdu.
     */
    private function isSplitGroup(ChannelConnection $connection, Variant $variant, RemoteProduct $remote): bool
    {
        $groupId = $remote->groupId();

        if ($groupId === null) {
            return false;
        }

        return Listing::query()
            ->join('variants', 'variants.id', '=', 'listings.variant_id')
            ->where('listings.channel_connection_id', $connection->id)
            ->where('listings.external_parent_id', $groupId)
            ->where('variants.product_id', '!=', $variant->product_id)
            ->exists();
    }

    /**
     * Ürünü kanaldaki karşılığına BAĞLAR: canlı `Listing`, kanal adresiyle.
     *
     * Ürün o kanaldan GELDİ — yani orada zaten satışta. Bağ kurulmasaydı:
     * - stok değişince bu kanala hiç gitmezdi (fan-out yalnız canlı satıra),
     * - satıcı ürünü kanala "eklediğinde" SKU araması tutmazsa (SKU'suz
     *   ürün) kanalda KOPYA ürün yaratılırdı,
     * - SKU'suz siparişin satırı hiçbir varyanta eşlenemezdi.
     *
     * VAR OLAN ADRES EZİLMEZ: satır başka bir kanal kaydına bağlıysa o bağ
     * satıcının (ya da önceki gönderimin) kararıdır; sessizce çevirmek
     * stoğu başka ürüne yazdırırdı. Raporlanır, dokunulmaz.
     */
    private function linkListing(ChannelConnection $connection, Variant $variant, RemoteProduct $remote): void
    {
        $identity = $remote->listingIdentity;
        $externalId = $identity['external_id'] ?? null;

        if (! is_string($externalId) || $externalId === '') {
            return;
        }

        $listing = Listing::query()->firstOrNew([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
        ]);

        if ($listing->external_id !== null && $listing->external_id !== $externalId) {
            throw new RuntimeException(__(
                'ürün bu kanalda başka bir kayda bağlı (:id); bağ değiştirilmedi.',
                ['id' => $listing->external_id],
            ));
        }

        $metadata = is_array($identity['channel_metadata'] ?? null) ? $identity['channel_metadata'] : [];

        $listing->forceFill(array_filter([
            'tenant_id' => $variant->tenant_id,
            'external_id' => $externalId,
            'external_parent_id' => $identity['external_parent_id'] ?? $listing->external_parent_id,
            'external_url' => $identity['external_url'] ?? $listing->external_url,
            'channel_metadata' => [...($listing->channel_metadata ?? []), ...$metadata] ?: null,
            'lifecycle_status' => 'live',
            'listed_at' => $listing->listed_at ?? now(),
        ], static fn (mixed $value): bool => $value !== null))->save();
    }

    /**
     * FİYATI OLMAYAN ÜRÜN 0 İLE AÇILIR.
     *
     * Reddetmek satıcının kanalda fiyatsız duran (taslak) ürününü
     * kataloğun DIŞINDA bırakırdı; 0 ise panelde görünür ve düzeltilebilir.
     * Kanala giden yol ayrıca `lifecycle_status = 'live'` kapısından geçer,
     * yani 0 fiyat kazara kanala gitmez.
     */
    private function applyCreate(RemoteProduct $product, string $sku, string $warehouseId, string $originConnectionId, bool $splitGroup): Product
    {
        $title = $product->title ?? $sku;

        // Bölünmüş eski grupta yeni kardeş ayrı ürün olur; ayırt edilsin
        // diye seçenek değeri başlığa eklenir (eski Etsy davranışı).
        if ($splitGroup && $product->optionLabel() !== null) {
            $title .= ' — '.$product->optionLabel();
        }

        return $this->createProduct->run(
            sku: $sku,
            title: $title,
            price: (float) ($product->price ?? 0),
            // Kanaldaki stok YALNIZCA burada kullanılır: yeni üründe
            // ezilecek kanonik bakiye YOKTUR. Negatif gelirse 0'a çekilir —
            // açılış hareketi negatif olamaz.
            openingStock: max(0, $product->quantity ?? 0),
            warehouseId: $warehouseId,
            description: $product->description,
            brand: $product->brand,
            currency: $product->currency ?? 'TRY',
            barcode: $product->barcode,
            internalCategoryId: null,
            originConnectionId: $originConnectionId,
            options: $product->options,
        );
    }

    /**
     * STOK PARAMETRESİ YOKTUR ve olmamalı — gerekçe sınıf başlığında.
     *
     * `UpdateProduct` zaten stok almaz; bu imza o kuralın koda gömülü
     * hâlidir. Buraya stok eklemek isteyen biri önce `UpdateProduct`'ı
     * değiştirmek zorunda kalır ve orada "içerik düzenlemesi stoğa
     * DOKUNMAZ" kuralıyla karşılaşır.
     *
     * İÇ KATEGORİ EZİLMEZ (`internalCategoryId: $product->internal_category_id`):
     * o alan SATICININ eşleştirme kararının çıpasıdır ve kanaldan gelen
     * veride karşılığı yoktur. NULL geçilseydi her içe aktarma turu
     * satıcının kurduğu eşleştirmeleri sessizce koparırdı.
     */
    private function applyUpdate(ChannelConnection $connection, Variant $variant, RemoteProduct $remote): void
    {
        $product = $variant->product;

        $title = $this->isSplitGroup($connection, $variant, $remote)
            ? $product->title
            : ($remote->title ?? $product->title);

        $this->updateProduct->run(
            product: $product,
            title: $title,
            // Fiyat ÜRÜNE değil VARYANTA yazılır (aşağıda): çok varyantlı
            // üründe `UpdateProduct` fiyata dokunmaz ve kanal fiyatı
            // sessizce kaybolurdu.
            price: null,
            description: $remote->description ?? $product->description,
            brand: $remote->brand ?? $product->brand,
            internalCategoryId: $product->internal_category_id,
        );

        // NULL "DEĞİŞMEDİ" DEMEKTİR, "SIFIRLA" DEĞİL. `(float)` dönüşümü
        // koşulsuz yapılsaydı fiyat göndermeyen kanal varyantı 0.00'a
        // düşürür ve o fiyat sonraki senkronda TÜM kanallara yayılırdı.
        if ($remote->price !== null) {
            $this->setPrice->run($variant, (float) $remote->price);
        }

        // Seçenekler yalnız EKSİKSE yazılır: eski içe aktarmanın seçeneksiz
        // bıraktığı varyant dolar, satıcının panelde düzelttiği ezilmez.
        if ($remote->options !== []) {
            $this->assignOptions->run($variant, $remote->options);
        }
    }
}
