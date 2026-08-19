<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\CsvProductParser;
use App\Domain\Catalog\Support\ImportResult;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CSV'den toplu ürün içe aktarır.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · "Toplu içe aktarma
 * (Excel/CSV)", §17 · öncelik tablosu ("TEMEL").
 *
 * MADDENİN VARLIK SEBEBİ: satıcı 500 ürününü panelden tek tek giremez.
 * Ödeme mekanizması olsa bile ürünlerini sisteme sokamayan satıcı sistemi
 * kullanamaz.
 *
 * DEĞİŞMEZ KURAL — ACTION ÇAĞRILIR, MODELE YAZILMAZ:
 *   Ürün `CreateProduct`, güncelleme `UpdateProduct` yolundan geçer.
 *   Doğrudan `Product::create()` yazmak açılış stoğunun ledger'dan
 *   geçmesini atlar ve `on_hand = Σ on_hand_delta` eşitliğini bozar; 500
 *   satırlık bir dosyada bu 500 bozuk bakiye ve mutabakatın o günden sonra
 *   bulacağı 500 sahte sürüklenme demektir.
 *
 * DEĞİŞMEZ KURAL — TEK BOZUK SATIR DOSYAYI DÜŞÜRMEZ:
 *   Taksonomi turundaki "tek bozuk bağlantı turu durdurmaz" kuralının
 *   aynısı. 500 satırlık dosyanın 437. satırında hata varsa önceki 436
 *   ürün YAZILMIŞ olmalı; yoksa kullanıcı her denemede baştan başlar ve
 *   yazılanları elle temizlemek zorunda kalır.
 *
 * DEĞİŞMEZ KURAL — TUR TEK TRANSACTION'A SARILMAZ:
 *   Yukarıdaki kuralın doğrudan sonucu. Sarılsaydı son satırdaki bir hata
 *   önceki 499 ürünü geri alırdı. Her satır KENDİ transaction'ında
 *   atomiktir (`CreateProduct` kendi içinde sarar) ve bu yeterlidir:
 *   yarım ürün (varyantsız veya stoksuz) hiçbir koşulda oluşmaz.
 *
 * DEĞİŞMEZ KURAL — GÜNCELLEMEDE STOK SATIRDAN YAZILMAZ:
 *   Stok yalnızca ledger yollarından değişir (§4). CSV'deki stok kolonu
 *   YENİ ürünün açılış stoğudur; var olan üründe uygulanırsa satıcının
 *   SATTIĞI mallar bir dosya yüklemesiyle geri gelir. Bu maddenin en
 *   tehlikeli hatasıdır: sessizdir, geri alınamaz ve fazla satışa yol
 *   açar. Stok düzeltmesi `AdjustStock` ekranının işidir.
 */
final class ImportProducts
{
    public function __construct(
        private readonly CsvProductParser $parser,
        private readonly CreateProduct $createProduct,
        private readonly UpdateProduct $updateProduct,
    ) {}

    public function run(string $csv, string $warehouseId): ImportResult
    {
        $tenantId = TenantContext::idOrFail();

        $parsed = $this->parser->parse($csv);

        if (! $parsed->headerValid) {
            return ImportResult::rejected($parsed->missingColumns);
        }

        $created = 0;
        $updated = 0;
        // Ayrıştırma hataları rapora OLDUĞU GİBİ girer: kullanıcı için
        // "3. satırda SKU yok" ile "3. satır kaydedilemedi" aynı şeydir.
        $errors = $parsed->invalid;

        foreach ($parsed->valid as $row) {
            try {
                $existing = $this->findBySku($tenantId, (string) $row['sku']);

                if ($existing !== null) {
                    $this->applyUpdate($existing, $row);
                    $updated++;

                    continue;
                }

                $this->applyCreate($row, $warehouseId);
                $created++;
            } catch (Throwable $e) {
                // SESSİZCE YUTULMAZ: satır rapora girer ve tur devam eder.
                // Yutulsaydı içe aktarma "başarılı" görünürken satıcının
                // ürünlerinin bir kısmı hiç yazılmamış olurdu.
                $errors[] = [
                    'line' => (int) ($row['line'] ?? 0),
                    'message' => $e->getMessage(),
                ];

                Log::warning('catalog.import_row_failed', [
                    'tenant' => $tenantId,
                    'sku' => $row['sku'] ?? null,
                    'line' => $row['line'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new ImportResult(
            created: $created,
            updated: $updated,
            errors: $this->sortByLine($errors),
        );
    }

    // ---------------------------------------------------------------- iç

    /**
     * AYNI DOSYADA AYNI SKU İKİ KEZ GEÇERSE İKİNCİSİ GÜNCELLEMEDİR.
     *
     * Arama her satırda YENİDEN yapılır ve önbelleğe alınmaz: ilk satır
     * ürünü yaratmışsa ikinci satır onu bulmalıdır. Başta bir kez toplu
     * okunsaydı dosya içi tekrar `UNIQUE(tenant_id, sku)` ihlaline düşer
     * ve kullanıcı kendi dosyasındaki zararsız bir tekrarı hata sanırdı.
     */
    private function findBySku(string $tenantId, string $sku): ?Product
    {
        return Product::query()
            ->where('tenant_id', $tenantId)
            ->where('sku', $sku)
            ->first();
    }

    /** @param  array<string, mixed>  $row */
    private function applyCreate(array $row, string $warehouseId): void
    {
        $this->createProduct->run(
            sku: (string) $row['sku'],
            title: (string) $row['title'],
            price: (float) $row['price'],
            openingStock: (int) $row['opening_stock'],
            warehouseId: $warehouseId,
            description: $row['description'],
            brand: $row['brand'],
            barcode: $row['barcode'],
            internalCategoryId: $row['internal_category_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     *
     * STOK PARAMETRESİ YOKTUR ve olmamalı — `UpdateProduct` zaten stok
     * almaz. Bu, kuralın koda gömülü hâli: bir gün buraya stok eklemek
     * isteyen biri önce `UpdateProduct`'ı değiştirmek zorunda kalır ve
     * orada "içerik düzenlemesi stoğa DOKUNMAZ" kuralıyla karşılaşır.
     */
    private function applyUpdate(Product $product, array $row): void
    {
        $this->updateProduct->run(
            product: $product,
            title: (string) $row['title'],
            price: (float) $row['price'],
            description: $row['description'],
            brand: $row['brand'],
            internalCategoryId: $row['internal_category_id'],
        );
    }

    /**
     * Hatalar SATIR SIRASINA göre gösterilir.
     *
     * Ayrıştırma hataları ile yazma hataları iki ayrı kaynaktan geliyor;
     * sıralanmasaydı kullanıcı raporda kendi dosyasında ileri geri
     * atlamak zorunda kalırdı.
     *
     * @param  list<array{line: int, message: string}>  $errors
     * @return list<array{line: int, message: string}>
     */
    private function sortByLine(array $errors): array
    {
        usort($errors, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return $errors;
    }
}
