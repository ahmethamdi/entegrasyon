<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Catalog\Models\Product;
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
    ) {}

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
        $updated = 0;
        $skipped = 0;
        $errors = [];

        // ⚠️ KOTA BURADA DA GEÇERLİ (B2) — ImportProducts ile aynı kural:
        // yalnız YENİ ürün sayılır, güncelleme serbest.
        $remaining = $this->quota->remaining(QuotaMetric::PRODUCTS);
        $quotaBlocked = 0;

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

                return new ChannelImportResult(
                    created: $created,
                    updated: $updated,
                    skipped: $skipped,
                    errors: $this->withQuotaNote($errors, $quotaBlocked),
                    stoppedEarly: true,
                    stopReason: $e->getMessage(),
                );
            }

            $pagesRead++;

            foreach ($page->products as $product) {
                // SKU'SUZ VE ADRESSİZ ÜRÜN ATLANIR ama SAYILIR ve SEBEBİYLE
                // raporlanır. Sessizce düşseydi satıcı "50 ürünüm vardı,
                // 47'si geldi" der ve eksiğin nedenini hiçbir yerde bulamazdı.
                if (! $product->isImportable()) {
                    $skipped++;
                    $errors[] = [
                        'line' => 0,
                        'message' => sprintf(
                            '%s: kanalda SKU tanımlı değil, içe aktarılamadı.',
                            $product->title ?? "#{$product->externalId}",
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
                    $existing = $this->findByListing($connection, $product)
                        ?? $this->findBySku($tenantId, $sku);

                    if ($existing !== null) {
                        $this->applyUpdate($existing, $product);
                        $this->syncImages->run($existing, $connection->id, $product->images);
                        $this->linkListing($connection, $existing, $product);
                        $updated++;

                        continue;
                    }

                    if ($remaining !== null && $remaining <= 0) {
                        $quotaBlocked++;

                        continue;
                    }

                    $new = $this->applyCreate($product, $sku, $warehouseId);
                    $created++;

                    if ($remaining !== null) {
                        $remaining--;
                    }

                    // Görsel hatası ürünü geri almaz: ürün yazıldı ve
                    // sayıldı, görsel hatası raporda ayrıca görünür.
                    $this->syncImages->run($new, $connection->id, $product->images);
                    $this->linkListing($connection, $new, $product);
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

        return new ChannelImportResult(
            created: $created,
            updated: $updated,
            skipped: $skipped,
            errors: $this->withQuotaNote($errors, $quotaBlocked),
            stoppedEarly: $hitPageCap,
            stopReason: $hitPageCap
                ? sprintf(
                    'Tur başına en fazla %d sayfa okunur; kanalda daha fazla ürün var. Yeniden çalıştırın.',
                    $maxPages,
                )
                : null,
        );
    }

    // ---------------------------------------------------------------- iç

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
            'message' => sprintf(
                'Plan ürün sınırına ulaşıldı (%d ürün): kanaldaki %d yeni ürün içe aktarılmadı. Mevcut ürünlerin güncellemesi uygulandı. Daha fazla ürün için planınızı yükseltin.',
                (int) $limit,
                $blocked,
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
     */
    private function findBySku(string $tenantId, string $sku): ?Product
    {
        return Product::query()
            ->where('tenant_id', $tenantId)
            ->where('sku', $sku)
            ->first();
    }

    /**
     * Bu bağlantıda bu kanal kimliğine bağlı ürün — yeniden içe aktarmada.
     */
    private function findByListing(ChannelConnection $connection, RemoteProduct $product): ?Product
    {
        $externalId = $product->listingIdentity['external_id'] ?? null;

        if (! is_string($externalId) || $externalId === '') {
            return null;
        }

        return Listing::query()
            ->where('channel_connection_id', $connection->id)
            ->where('external_id', $externalId)
            ->first()?->variant?->product;
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
    private function linkListing(ChannelConnection $connection, Product $product, RemoteProduct $remote): void
    {
        $identity = $remote->listingIdentity;
        $externalId = $identity['external_id'] ?? null;
        $variant = $product->variants()->first();

        if (! is_string($externalId) || $externalId === '' || $variant === null) {
            return;
        }

        $listing = Listing::query()->firstOrNew([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
        ]);

        if ($listing->external_id !== null && $listing->external_id !== $externalId) {
            throw new RuntimeException(sprintf(
                'ürün bu kanalda başka bir kayda bağlı (%s); bağ değiştirilmedi.',
                $listing->external_id,
            ));
        }

        $metadata = is_array($identity['channel_metadata'] ?? null) ? $identity['channel_metadata'] : [];

        $listing->forceFill(array_filter([
            'tenant_id' => $product->tenant_id,
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
    private function applyCreate(RemoteProduct $product, string $sku, string $warehouseId): Product
    {
        return $this->createProduct->run(
            sku: $sku,
            title: $product->title ?? $sku,
            price: (float) ($product->price ?? 0),
            // Kanaldaki stok YALNIZCA burada kullanılır: yeni üründe
            // ezilecek kanonik bakiye YOKTUR. Negatif gelirse 0'a çekilir —
            // açılış hareketi negatif olamaz.
            openingStock: max(0, $product->quantity ?? 0),
            warehouseId: $warehouseId,
            description: $product->description,
            brand: $product->brand,
            barcode: $product->barcode,
            internalCategoryId: null,
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
    private function applyUpdate(Product $product, RemoteProduct $remote): void
    {
        $this->updateProduct->run(
            product: $product,
            title: $remote->title ?? $product->title,
            // NULL "DEĞİŞMEDİ" DEMEKTİR, "SIFIRLA" DEĞİL — `UpdateProduct`
            // null fiyata DOKUNMAZ. `(float)` dönüşümü yapılsaydı fiyat
            // göndermeyen kanal ürünü 0.00'a düşürür ve o fiyat sonraki
            // senkronda TÜM kanallara yayılırdı.
            price: $remote->price !== null ? (float) $remote->price : null,
            description: $remote->description ?? $product->description,
            brand: $remote->brand ?? $product->brand,
            internalCategoryId: $product->internal_category_id,
        );
    }
}
