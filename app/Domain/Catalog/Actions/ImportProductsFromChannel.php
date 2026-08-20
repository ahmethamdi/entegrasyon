<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\ChannelImportResult;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Sync\Support\RemoteProduct;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
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
                    errors: $errors,
                    stoppedEarly: true,
                    stopReason: $e->getMessage(),
                );
            }

            $pagesRead++;

            foreach ($page->products as $product) {
                // SKU'SUZ ÜRÜN ATLANIR ama SAYILIR ve SEBEBİYLE raporlanır.
                // Sessizce düşseydi satıcı "50 ürünüm vardı, 47'si geldi"
                // der ve eksiğin nedenini hiçbir yerde bulamazdı.
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

                try {
                    $existing = $this->findBySku($tenantId, (string) $product->sku);

                    if ($existing !== null) {
                        $this->applyUpdate($existing, $product);
                        $updated++;

                        continue;
                    }

                    $this->applyCreate($product, $warehouseId);
                    $created++;
                } catch (Throwable $e) {
                    // SESSİZCE YUTULMAZ — tur devam eder, ürün rapora girer.
                    $errors[] = [
                        'line' => 0,
                        'message' => sprintf('%s: %s', $product->sku, $e->getMessage()),
                    ];

                    Log::warning('catalog.channel_import_product_failed', [
                        'tenant' => $tenantId,
                        'connection' => $connection->id,
                        'sku' => $product->sku,
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
            errors: $errors,
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
     * FİYATI OLMAYAN ÜRÜN 0 İLE AÇILIR.
     *
     * Reddetmek satıcının kanalda fiyatsız duran (taslak) ürününü
     * kataloğun DIŞINDA bırakırdı; 0 ise panelde görünür ve düzeltilebilir.
     * Kanala giden yol ayrıca `lifecycle_status = 'live'` kapısından geçer,
     * yani 0 fiyat kazara kanala gitmez.
     */
    private function applyCreate(RemoteProduct $product, string $warehouseId): void
    {
        $this->createProduct->run(
            sku: (string) $product->sku,
            title: $product->title ?? (string) $product->sku,
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
