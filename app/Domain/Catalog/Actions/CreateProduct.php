<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\DuplicateSkuException;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Ürün yaratır — varyant ve açılış stoğuyla birlikte.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.2 · "Panelde ürün oluşturma",
 * §19 · "Panelde ürün oluştur (SKU, fiyat, stok 10)".
 *
 * DEĞİŞMEZ KURAL — AÇILIŞ STOĞU LEDGER ÜZERİNDEN GİRER:
 *   `InventoryLevel` satırı DOĞRUDAN yazılmaz. Açılış stoğu bir IMPORT
 *   hareketidir ve projeksiyon ondan türer. Doğrudan yazmak
 *   `on_hand = Σ on_hand_delta` eşitliğini ürün yaratılırken bozar; mutabakat
 *   o günden sonra sahte sürüklenme bulmaya başlar.
 *
 * DEĞİŞMEZ KURAL — TEK TRANSACTION:
 *   Ürün, varyant ve açılış hareketi aynı commit'te yazılır. Araya düşen bir
 *   hata stoksuz varyant veya varyantsız ürün bırakırdı.
 *
 * MODÜL SINIRI: bu action `inventory_levels` satırına DOKUNMAZ. Kilidi
 * `LockInventoryRows` ile alır, hareketi `ApplyMovement`'a yaptırır — Catalog
 * domain'i Inventory'nin modeline yazmaz, action'ını çağırır.
 *
 * TEK VARYANTLI BAŞLANGIÇ: varsayılan varyant ürünün SKU'sunu taşır. Çok
 * varyantlı ürün (renk × beden) `variant_options` ile kurulur ve bu action'ın
 * kapsamı dışındadır; kullanıcıya ilk adımda ikinci bir SKU sormak gereksiz
 * karar yüklüyordu.
 */
final class CreateProduct
{
    public function __construct(
        private readonly LockInventoryRows $lockRows,
        private readonly ApplyMovement $applyMovement,
    ) {}

    /**
     * @throws DuplicateSkuException Aynı kiracıda SKU zaten varsa
     */
    public function run(
        string $sku,
        string $title,
        float $price,
        int $openingStock,
        string $warehouseId,
        ?string $description = null,
        ?string $brand = null,
        string $currency = 'TRY',
        ?string $barcode = null,
        ?string $internalCategoryId = null,
    ): Product {
        $tenantId = TenantContext::idOrFail();

        if ($openingStock < 0) {
            throw new \InvalidArgumentException(
                "Açılış stoğu negatif olamaz, {$openingStock} verildi."
            );
        }

        try {
            return DB::transaction(function () use (
                $tenantId, $sku, $title, $price, $openingStock, $warehouseId,
                $description, $brand, $currency, $barcode, $internalCategoryId,
            ): Product {
                $normalizedCategory = $internalCategoryId === null
                    ? null
                    : (trim($internalCategoryId) === '' ? null : trim($internalCategoryId));

                $product = Product::query()->create([
                    'tenant_id' => $tenantId,
                    'sku' => $sku,
                    'title' => $title,
                    'description' => $description,
                    'brand' => $brand,
                    // İç kategori kanal eşleştirmesinin (§13 · Faz 2) çıpası;
                    // boş dize NULL'a çevrilir, adsız kategori satırı olmaz.
                    'internal_category_id' => $normalizedCategory,
                    'status' => 'active',
                    'content_version' => 1,
                ]);

                $variant = $product->variants()->create([
                    'tenant_id' => $tenantId,
                    // Varsayılan varyant ürünün SKU'sunu taşır.
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'price' => $price,
                    'currency' => $currency,
                    'status' => 'active',
                    'content_version' => 1,
                ]);

                // AÇILIŞ STOĞU HAREKET OLARAK GİRER — projeksiyona doğrudan
                // yazılmaz. Sıfır stokta hareket açılmaz: ApplyMovement pozitif
                // miktar bekler ve sıfırlık bir ledger satırı anlamsızdır.
                if ($openingStock > 0) {
                    $this->lockRows->run($warehouseId, [$variant->id]);

                    $this->applyMovement->run(
                        warehouseId: $warehouseId,
                        variantId: $variant->id,
                        type: MovementType::IMPORT,
                        quantity: $openingStock,
                        idempotencyKey: MovementKey::import((string) new UuidV7),
                        sourceType: 'product_creation',
                        sourceId: $product->id,
                        note: 'Açılış stoğu',
                    );
                }

                return $product;
            });
        } catch (UniqueConstraintViolationException $e) {
            // UNIQUE(tenant_id, sku) ihlali — kullanıcıya anlatılabilir hale getir.
            throw DuplicateSkuException::for($sku);
        }
    }
}
