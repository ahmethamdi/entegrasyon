<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\DuplicateSkuException;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Models\VariantOption;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * Var olan ürüne yeni bir varyant ekler — açılış stoğu ve seçenekleriyle.
 *
 * ADR-003: ürün = grup, varyant = satılabilir birim. Kanaldan gelen
 * "Kırmızı / M" ile "Mavi / L" aynı ürünün iki varyantıdır; ayrı ürün
 * açılsaydı Trendyol'a gönderimde her beden ayrı ürün grubu olur, panelde
 * aynı tişört on satır görünürdü.
 *
 * `CreateProduct` ile AYNI ÜÇ KURAL:
 *   - AÇILIŞ STOĞU LEDGER ÜZERİNDEN GİRER (IMPORT hareketi), `inventory_levels`
 *     doğrudan yazılmaz;
 *   - stok satırı 0 stokta da açılır (Stok ekranında görünsün);
 *   - varyant + seçenek + açılış hareketi TEK transaction.
 *
 * AYNI SEÇENEK BİRLEŞİMİ İKİ KEZ OLAMAZ: ürünün "Kırmızı / M"si zaten varsa
 * ikinci bir "Kırmızı / M" reddedilir. Kabul edilseydi kanal tarafında iki
 * varyant ayırt edilemez, sipariş hangi stoktan düşeceğini bilemezdi.
 * Kontrol ürün satırı KİLİTLİYKEN yapılır — eşzamanlı iki ekleme aynı
 * birleşimi geçiremesin.
 */
final class AddVariantToProduct
{
    public function __construct(
        private readonly LockInventoryRows $lockRows,
        private readonly ApplyMovement $applyMovement,
        private readonly AssignVariantOptions $assignOptions,
    ) {}

    /**
     * @param  array<string, string>  $options  ad => değer
     *
     * @throws DuplicateSkuException Aynı kiracıda varyant SKU'su zaten varsa
     * @throws InvalidArgumentException Negatif stok ya da aynı seçenek birleşimi
     */
    public function run(
        Product $product,
        string $sku,
        float $price,
        int $openingStock,
        string $warehouseId,
        array $options = [],
        string $currency = 'TRY',
        ?string $barcode = null,
        ?string $originConnectionId = null,
    ): Variant {
        if ($openingStock < 0) {
            throw new InvalidArgumentException("Açılış stoğu negatif olamaz, {$openingStock} verildi.");
        }

        $options = AssignVariantOptions::normalize($options);

        try {
            return DB::transaction(function () use (
                $product, $sku, $price, $openingStock, $warehouseId, $options, $currency, $barcode, $originConnectionId,
            ): Variant {
                // Ürün satırı kilitlenir: birleşim kontrolü ile varyant
                // yazımı arasına eşzamanlı bir ekleme giremez.
                Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

                if ($options !== [] && $this->combinationExists($product, $options)) {
                    throw new InvalidArgumentException(__(
                        ':combo seçeneği bu üründe zaten var.',
                        ['combo' => implode(' / ', $options)],
                    ));
                }

                $variant = $product->variants()->create([
                    'tenant_id' => $product->tenant_id,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'price' => $price,
                    'currency' => $currency,
                    'status' => 'active',
                    'content_version' => 1,
                ]);

                $this->assignOptions->run($variant, $options);

                $this->lockRows->run($warehouseId, [$variant->id]);

                if ($openingStock > 0) {
                    $this->applyMovement->run(
                        warehouseId: $warehouseId,
                        variantId: $variant->id,
                        type: MovementType::IMPORT,
                        quantity: $openingStock,
                        idempotencyKey: MovementKey::import((string) new UuidV7),
                        sourceType: 'variant_creation',
                        sourceId: $variant->id,
                        // Yankı bastırma: açılış stoğu geldiği kanala geri
                        // yazılmaz (CreateProduct'taki 7 Eki Etsy dersi).
                        channelConnectionId: $originConnectionId,
                        note: 'Açılış stoğu',
                    );
                }

                return $variant;
            });
        } catch (UniqueConstraintViolationException) {
            // UNIQUE(tenant_id, sku) ya da barkod — kullanıcıya anlatılabilir.
            throw DuplicateSkuException::for($sku);
        }
    }

    /**
     * Ürünün herhangi bir varyantı bu ad => değer birleşiminin TAMAMINI
     * taşıyor mu?
     *
     * @param  array<string, string>  $options
     */
    private function combinationExists(Product $product, array $options): bool
    {
        $rows = VariantOption::query()
            ->join('variants', 'variants.id', '=', 'variant_options.variant_id')
            ->join('option_definitions', 'option_definitions.id', '=', 'variant_options.option_definition_id')
            ->join('option_values', 'option_values.id', '=', 'variant_options.option_value_id')
            ->where('variants.product_id', $product->id)
            ->get(['variant_options.variant_id', 'option_definitions.name', 'option_values.value']);

        foreach ($rows->groupBy('variant_id') as $variantRows) {
            $existing = $variantRows->pluck('value', 'name')->all();

            if (count($existing) === count($options) && array_diff_assoc($options, $existing) === []) {
                return true;
            }
        }

        return false;
    }
}
