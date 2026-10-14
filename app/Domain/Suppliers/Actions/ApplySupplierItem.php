<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Actions;

use App\Domain\Catalog\Actions\AddVariantToProduct;
use App\Domain\Catalog\Actions\CreateProduct;
use App\Domain\Catalog\Actions\SetVariantCost;
use App\Domain\Catalog\Actions\SetVariantPrice;
use App\Domain\Catalog\Exceptions\DuplicateSkuException;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Support\PriceRuleCalculator;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Suppliers\Enums\FieldState;
use App\Domain\Suppliers\Enums\SupplierReason;
use App\Domain\Suppliers\Models\SupplierRecord;
use App\Domain\Suppliers\Models\SupplierSource;
use App\Domain\Suppliers\Support\FieldValue;
use App\Domain\Suppliers\Support\SupplierItem;
use App\Domain\Suppliers\Support\SyncContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * TEK tedarikçi öğesini kanonik kataloğa yansıtır — ya da önizlemede
 * yansıtsaydı ne olacağını sayar. İki kip AYNI karar yolundan geçer:
 * önizleme uygulamanın birebir ne yapacağını gösterir.
 *
 * SIRA (ADR-012):
 *   1. Kayıt (kaynak, kimlik) — ham alanlar ve durumlar yazılır.
 *   2. Bağ: mevcut bağ → varyant SKU → barkod. SKU ve barkod farklı
 *      varyantı gösterirse BELİRSİZ; başka kayda bağlı varyant ÇAKIŞMA.
 *      Belirsizlikte stok/fiyat/ürün YAZILMAZ. Bulanık eşleme yok.
 *   3. Bağ yoksa ve kaynakta izin varsa yeni ürün/varyant (kota, kanonik
 *      eylemler — içe aktarma bypass'ı yok).
 *   4. Bağlıysa: stok (yalnız `authoritative`), maliyet, fiyat — her biri
 *      "tedarikçi değeri DEĞİŞTİYSE" (`last_applied_*`).
 *
 * Uygulama kipinde 1–4 TEK transaction'dır: defter hareketi ile
 * `last_applied_stock` birlikte yazılır ya da hiçbiri yazılmaz → çökme
 * sonrası yeniden deneme ikinci hareket üretmez. Hareket anahtarı
 * `supplier:{kayıt}:{tur}` aynı turun tekrarını da defterde yakalar.
 */
final class ApplySupplierItem
{
    public function __construct(
        private readonly CreateProduct $createProduct,
        private readonly AddVariantToProduct $addVariant,
        private readonly SetVariantPrice $setPrice,
        private readonly SetVariantCost $setCost,
        private readonly LockInventoryRows $lockRows,
        private readonly ApplyMovement $applyMovement,
    ) {}

    public function run(SyncContext $ctx, SupplierItem $item): void
    {
        if ($ctx->preview()) {
            $this->process($ctx, $item);

            return;
        }

        DB::transaction(fn () => $this->process($ctx, $item));
    }

    /**
     * Kimliği tekrarlanan öğe: kayıt (varsa) işaretlenir, hiçbir şey uygulanmaz.
     */
    public function markDuplicate(SyncContext $ctx, string $key): void
    {
        $ctx->bump('duplicate');
        $ctx->change('duplicate', $key, reason: SupplierReason::SUPPLIER_DUPLICATE_IDENTITY);

        if ($ctx->preview()) {
            return;
        }

        SupplierRecord::query()
            ->where('supplier_source_id', $ctx->source->id)
            ->where('source_key', $key)
            ->update([
                'match_status' => SupplierRecord::DUPLICATE,
                'reason_code' => SupplierReason::SUPPLIER_DUPLICATE_IDENTITY->value,
                'presence' => SupplierRecord::PRESENT,
                'last_seen_run_id' => $ctx->run->id,
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function process(SyncContext $ctx, SupplierItem $item): void
    {
        $key = (string) $item->key;
        $record = $this->record($ctx, $item);

        [$variant, $status, $reason] = $this->resolve($ctx, $record, $item);

        if ($variant === null && $status === SupplierRecord::UNMATCHED) {
            $variant = $this->create($ctx, $record, $item);

            if ($variant === null) {
                $this->save($ctx, $record);

                return;
            }
        } elseif ($variant === null) {
            $record->match_status = $status;
            $record->reason_code = $reason?->value;
            $ctx->bump($status);
            $ctx->change($status, $key, $record->id, reason: $reason);
            $this->save($ctx, $record);

            return;
        } else {
            $isNewBinding = $record->variant_id !== $variant->id;
            $record->variant_id = $variant->id;
            $record->match_status = SupplierRecord::BOUND;
            $record->reason_code = null;

            if ($isNewBinding) {
                $ctx->bump('matched');
                $ctx->change('matched', $key, $record->id, $variant->id, after: $variant->sku);
                $ctx->claimedVariants[$variant->id] = true;
            }

            $changed = $this->applyStock($ctx, $record, $item, $variant)
                | $this->applyCost($ctx, $record, $item, $variant)
                | $this->applyPrice($ctx, $record, $item, $variant);

            if (! $changed && ! $isNewBinding) {
                $ctx->bump('unchanged');
            }
        }

        $this->save($ctx, $record);
    }

    private function record(SyncContext $ctx, SupplierItem $item): SupplierRecord
    {
        $query = SupplierRecord::query()
            ->where('supplier_source_id', $ctx->source->id)
            ->where('source_key', $item->key);

        $record = ($ctx->preview() ? $query : $query->lockForUpdate())->first()
            ?? new SupplierRecord([
                'tenant_id' => $ctx->source->tenant_id,
                'supplier_source_id' => $ctx->source->id,
                'source_key' => $item->key,
                'first_seen_run_id' => $ctx->run->id,
            ]);

        // Yeni kaydın kimliği defter anahtarına girer → kaydetmeden önce.
        if (! $record->exists && ! $ctx->preview()) {
            $record->id = SupplierRecord::generateUuidV7();
        }

        $record->fill([
            'group_key' => $item->groupKey,
            'sku' => $item->sku,
            'barcode' => $item->barcode,
            'name' => $item->name,
            'raw' => $this->boundedRaw($item->raw),
            'stock_state' => $item->stock->state->value,
            'stock_value' => $item->stock->hasValue() ? $item->stock->value : null,
            'cost_state' => $item->cost->state->value,
            'cost_value' => $item->cost->hasValue() ? $item->cost->value : null,
            'price_state' => $item->price->state->value,
            'price_value' => $item->price->hasValue() ? $item->price->value : null,
            'currency' => $item->currency,
            'presence' => SupplierRecord::PRESENT,
            'last_seen_run_id' => $ctx->run->id,
            'last_seen_at' => now(),
        ]);

        return $record;
    }

    /**
     * @return array{0: Variant|null, 1: string, 2: SupplierReason|null}
     */
    private function resolve(SyncContext $ctx, SupplierRecord $record, SupplierItem $item): array
    {
        // 1. Mevcut bağ — yeniden eşleme YOK.
        if ($record->variant_id !== null) {
            $bound = Variant::query()->find($record->variant_id);

            if ($bound !== null) {
                return [$bound, SupplierRecord::BOUND, null];
            }
        }

        // 2. SKU ve barkod — ikisi de TAM eşleşme.
        $bySku = $item->sku !== null ? Variant::query()->where('sku', $item->sku)->first() : null;
        $byBarcode = $item->barcode !== null ? Variant::query()->where('barcode', $item->barcode)->first() : null;

        if ($bySku !== null && $byBarcode !== null && $bySku->id !== $byBarcode->id) {
            return [null, SupplierRecord::AMBIGUOUS, SupplierReason::SUPPLIER_MATCH_AMBIGUOUS];
        }

        $variant = $bySku ?? $byBarcode;

        if ($variant === null) {
            return [null, SupplierRecord::UNMATCHED, null];
        }

        // 3. Varyant başka bir tedarikçi kaydına bağlı mı?
        $taken = SupplierRecord::query()
            ->where('variant_id', $variant->id)
            ->when($record->exists, fn ($q) => $q->where('id', '!=', $record->id))
            ->exists();

        if ($taken || ($ctx->preview() && isset($ctx->claimedVariants[$variant->id]))) {
            return [null, SupplierRecord::CONFLICT, SupplierReason::SUPPLIER_VARIANT_ALREADY_BOUND];
        }

        return [$variant, SupplierRecord::BOUND, null];
    }

    private function create(SyncContext $ctx, SupplierRecord $record, SupplierItem $item): ?Variant
    {
        $key = (string) $item->key;
        $block = function (string $status, SupplierReason $reason, string $counter) use ($ctx, $record, $key): null {
            $record->match_status = $status;
            $record->reason_code = $reason->value;
            $ctx->bump($counter);
            $ctx->change($counter, $key, $record->id, reason: $reason);

            return null;
        };

        if (! $ctx->source->create_products) {
            return $block(SupplierRecord::UNMATCHED, SupplierReason::SUPPLIER_NOT_MATCHED, 'unmatched');
        }

        if ($item->sku === null) {
            return $block(SupplierRecord::UNMATCHED, SupplierReason::SUPPLIER_SKU_MISSING, 'unmatched');
        }

        if ($item->name === null) {
            return $block(SupplierRecord::UNMATCHED, SupplierReason::SUPPLIER_NAME_MISSING, 'unmatched');
        }

        [$price, $priceReason] = $this->targetPrice($ctx->source, $item);

        if ($price === null) {
            return $block(SupplierRecord::UNMATCHED, $priceReason ?? SupplierReason::SUPPLIER_PRICE_MISSING, 'unmatched');
        }

        // Ürün SKU'su ayrı ad alanıdır: varyantta yok ama bir ürünün
        // SKU'su olabilir — tahmin değil, açık çakışma.
        if (Product::query()->where('sku', $item->sku)->exists()) {
            return $block(SupplierRecord::CONFLICT, SupplierReason::SUPPLIER_SKU_CONFLICT, 'conflict');
        }

        $product = $this->groupProduct($ctx, $record, $item);
        $joinsGroup = $product !== null || ($item->groupKey !== null && isset($ctx->previewGroups[$item->groupKey]));

        if (! $joinsGroup) {
            if ($ctx->quotaRemaining !== null && $ctx->quotaRemaining <= 0) {
                $ctx->quotaHit = true;

                return $block(SupplierRecord::UNMATCHED, SupplierReason::SUPPLIER_QUOTA_REACHED, 'quota');
            }
        }

        $opening = $ctx->source->isAuthoritativeForStock() && $item->stock->hasValue() ? (int) $item->stock->value : 0;
        $cost = $item->cost->isPositive() ? (string) $item->cost->value : null;

        if ($ctx->preview()) {
            if (! $joinsGroup) {
                $ctx->quotaRemaining = $ctx->quotaRemaining !== null ? $ctx->quotaRemaining - 1 : null;

                if ($item->groupKey !== null) {
                    $ctx->previewGroups[$item->groupKey] = true;
                }
            }

            $counter = $joinsGroup ? 'created_variants' : 'created_products';
            $ctx->bump($counter);
            $ctx->change(rtrim($counter, 's'), $key, after: $item->sku);

            return null;
        }

        try {
            if ($joinsGroup && $product !== null) {
                $variant = $this->addVariant->run(
                    product: $product,
                    sku: $item->sku,
                    price: (float) $price,
                    openingStock: $opening,
                    warehouseId: $ctx->warehouseId,
                    options: $item->options,
                    currency: (string) $item->currency,
                    barcode: $item->barcode,
                );
                $counter = 'created_variants';
            } else {
                $product = $this->createProduct->run(
                    sku: $item->sku,
                    title: $item->name,
                    price: (float) $price,
                    openingStock: $opening,
                    warehouseId: $ctx->warehouseId,
                    description: $item->description,
                    brand: $item->brand,
                    currency: (string) $item->currency,
                    barcode: $item->barcode,
                    internalCategoryId: $item->category,
                    options: $item->options,
                );
                $variant = $product->variants()->firstOrFail();
                $this->attachImages($product, $item->images);
                $ctx->quotaRemaining = $ctx->quotaRemaining !== null ? $ctx->quotaRemaining - 1 : null;
                $counter = 'created_products';
            }
        } catch (DuplicateSkuException) {
            // SKU ya da barkod tekilliği — hangisi olduğu ayırt edilir.
            $reason = $item->barcode !== null && Variant::query()->where('barcode', $item->barcode)->exists()
                ? SupplierReason::SUPPLIER_BARCODE_CONFLICT
                : SupplierReason::SUPPLIER_SKU_CONFLICT;

            return $block(SupplierRecord::CONFLICT, $reason, 'conflict');
        } catch (InvalidArgumentException) {
            // Aynı seçenek birleşimi grupta zaten var.
            return $block(SupplierRecord::CONFLICT, SupplierReason::SUPPLIER_SKU_CONFLICT, 'conflict');
        }

        if ($cost !== null) {
            $this->setCost->run($variant, $cost);
        }

        $record->variant_id = $variant->id;
        $record->match_status = SupplierRecord::BOUND;
        $record->reason_code = null;
        $record->last_applied_stock = $ctx->source->isAuthoritativeForStock() && $item->stock->hasValue() ? $opening : null;
        $record->last_applied_cost = $cost;
        $record->last_applied_price = $price;

        $ctx->bump($counter);
        $ctx->change(rtrim($counter, 's'), $key, $record->id, $variant->id, after: $variant->sku);

        // Stoğu bilinmeyen yeni ürün 0 ile açılır (fazla satış olmasın) —
        // ama bu SESSİZ geçmez: neden satırda görünür.
        if ($ctx->source->isAuthoritativeForStock() && ! $item->stock->hasValue()) {
            $this->skip($ctx, $record, $item->stock->state === FieldState::INVALID
                ? SupplierReason::SUPPLIER_STOCK_INVALID
                : SupplierReason::SUPPLIER_STOCK_MISSING, $item->stock->rawText);
        }

        return $variant;
    }

    private function groupProduct(SyncContext $ctx, SupplierRecord $record, SupplierItem $item): ?Product
    {
        if ($item->groupKey === null) {
            return null;
        }

        $variantId = SupplierRecord::query()
            ->where('supplier_source_id', $ctx->source->id)
            ->where('group_key', $item->groupKey)
            ->whereNotNull('variant_id')
            ->when($record->exists, fn ($q) => $q->where('id', '!=', $record->id))
            ->value('variant_id');

        if ($variantId === null) {
            return null;
        }

        return Variant::query()->find($variantId)?->product;
    }

    /**
     * @param  list<string>  $urls
     */
    private function attachImages(Product $product, array $urls): void
    {
        foreach ($urls as $position => $url) {
            ProductImage::query()->create([
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'storage_path' => $url,
                'position' => $position,
            ]);
        }
    }

    private function applyStock(SyncContext $ctx, SupplierRecord $record, SupplierItem $item, Variant $variant): bool
    {
        if (! $ctx->source->isAuthoritativeForStock()) {
            return false;
        }

        if (! $item->stock->hasValue()) {
            $reason = $item->stock->state === FieldState::INVALID
                ? SupplierReason::SUPPLIER_STOCK_INVALID
                : SupplierReason::SUPPLIER_STOCK_MISSING;
            $this->skip($ctx, $record, $reason, $item->stock->rawText);

            return false;
        }

        $target = (int) $item->stock->value;

        // DEĞİŞİNCE AYARLA: tedarikçi aynı değeri tekrarlıyorsa bizim
        // satışımız geri eklenmez; tekrar eden tur hareket üretmez.
        if ($record->last_applied_stock === $target) {
            return false;
        }

        if ($ctx->preview()) {
            $onHand = (int) InventoryLevel::query()
                ->where('warehouse_id', $ctx->warehouseId)
                ->where('variant_id', $variant->id)
                ->value('on_hand');

            if ($onHand !== $target) {
                $ctx->bump('stock_changed');
                $ctx->change('stock', $record->source_key, $record->id, $variant->id, $onHand, $target);

                return true;
            }

            return false;
        }

        $this->lockRows->run($ctx->warehouseId, [$variant->id]);

        $onHand = (int) InventoryLevel::query()
            ->where('warehouse_id', $ctx->warehouseId)
            ->where('variant_id', $variant->id)
            ->value('on_hand');

        $record->last_applied_stock = $target;
        $delta = $target - $onHand;

        if ($delta === 0) {
            return false;
        }

        $this->applyMovement->run(
            warehouseId: $ctx->warehouseId,
            variantId: $variant->id,
            type: $delta > 0 ? MovementType::MANUAL_ADJUSTMENT : MovementType::MANUAL_REDUCTION,
            quantity: abs($delta),
            idempotencyKey: MovementKey::supplier((string) $record->id, $ctx->run->id),
            sourceType: 'supplier_sync',
            sourceId: $record->id,
            note: __('Tedarikçi stoğu: :name', ['name' => $ctx->source->name]),
        );

        $ctx->bump('stock_changed');
        $ctx->change('stock', $record->source_key, $record->id, $variant->id, $onHand, $target);

        return true;
    }

    private function applyCost(SyncContext $ctx, SupplierRecord $record, SupplierItem $item, Variant $variant): bool
    {
        if (! isset($ctx->source->mapping['fields']['cost']) || trim((string) $ctx->source->mapping['fields']['cost']) === '') {
            return false;
        }

        if (! $item->cost->isPositive()) {
            $this->skip($ctx, $record, match ($item->cost->state) {
                FieldState::ZERO => SupplierReason::SUPPLIER_COST_ZERO,
                FieldState::INVALID => SupplierReason::SUPPLIER_COST_INVALID,
                default => SupplierReason::SUPPLIER_COST_MISSING,
            }, $item->cost->rawText);

            return false;
        }

        if ($item->currency !== $variant->currency) {
            $this->skip($ctx, $record, SupplierReason::SUPPLIER_CURRENCY_MISMATCH, $item->currency);

            return false;
        }

        $cost = (string) $item->cost->value;

        if ($record->last_applied_cost !== null && bccomp((string) $record->last_applied_cost, $cost, 2) === 0) {
            return false;
        }

        $current = $variant->cost_price === null ? null : (string) $variant->cost_price;
        $record->last_applied_cost = $cost;

        if ($current !== null && bccomp($current, $cost, 2) === 0) {
            return false;
        }

        if (! $ctx->preview()) {
            $this->setCost->run($variant, $cost);
        }

        $ctx->bump('cost_changed');
        $ctx->change('cost', $record->source_key, $record->id, $variant->id, $current, $cost);

        return true;
    }

    private function applyPrice(SyncContext $ctx, SupplierRecord $record, SupplierItem $item, Variant $variant): bool
    {
        if ($ctx->source->price_policy === SupplierSource::PRICE_NONE) {
            return false;
        }

        [$price, $reason] = $this->targetPrice($ctx->source, $item);

        if ($price === null) {
            $this->skip($ctx, $record, $reason ?? SupplierReason::SUPPLIER_PRICE_MISSING, null);

            return false;
        }

        if ($item->currency !== $variant->currency) {
            $this->skip($ctx, $record, SupplierReason::SUPPLIER_CURRENCY_MISMATCH, $item->currency);

            return false;
        }

        // Tedarikçi girdisi (ya da kural) değişmediyse dokunma: satıcının
        // panelden yaptığı fiyat değişikliği her saat ezilmez.
        if ($record->last_applied_price !== null && bccomp((string) $record->last_applied_price, $price, 2) === 0) {
            return false;
        }

        $current = (string) $variant->price;
        $record->last_applied_price = $price;

        if (bccomp($current, $price, 2) === 0) {
            return false;
        }

        if (! $ctx->preview()) {
            $this->setPrice->run($variant, (float) $price);
        }

        $ctx->bump('price_changed');
        $ctx->change('price', $record->source_key, $record->id, $variant->id, $current, $price);

        return true;
    }

    /**
     * Politikaya göre hedef satış fiyatı; uydurulmaz.
     *
     * @return array{0: string|null, 1: SupplierReason|null}
     */
    public function targetPrice(SupplierSource $source, SupplierItem $item): array
    {
        $field = match ($source->price_policy) {
            SupplierSource::PRICE_FEED => $item->price,
            SupplierSource::PRICE_COST_MARKUP => $item->cost,
            default => null,
        };

        if ($field === null) {
            return [null, SupplierReason::SUPPLIER_PRICE_MISSING];
        }

        $isCost = $source->price_policy === SupplierSource::PRICE_COST_MARKUP;

        if (! $field->isPositive()) {
            return [null, $this->priceReason($field, $isCost)];
        }

        if (! $isCost) {
            return [(string) $field->value, null];
        }

        return [PriceRuleCalculator::costMarkup(
            (string) $field->value,
            (string) $source->markup_percent,
            (string) $source->markup_amount,
            $source->rounding,
        ), null];
    }

    private function priceReason(FieldValue $field, bool $isCost): SupplierReason
    {
        return match ($field->state) {
            FieldState::ZERO => $isCost ? SupplierReason::SUPPLIER_COST_ZERO : SupplierReason::SUPPLIER_PRICE_ZERO,
            FieldState::INVALID => $isCost ? SupplierReason::SUPPLIER_COST_INVALID : SupplierReason::SUPPLIER_PRICE_INVALID,
            default => $isCost ? SupplierReason::SUPPLIER_COST_MISSING : SupplierReason::SUPPLIER_PRICE_MISSING,
        };
    }

    private function skip(SyncContext $ctx, SupplierRecord $record, SupplierReason $reason, ?string $raw): void
    {
        $record->reason_code ??= $reason->value;
        $ctx->bump('skipped');
        $ctx->change('skipped', $record->source_key, $record->id, $record->variant_id, after: $raw, reason: $reason);
    }

    private function save(SyncContext $ctx, SupplierRecord $record): void
    {
        if (! $ctx->preview()) {
            $record->save();
        }
    }

    /**
     * @param  array<string, list<string>>  $raw
     * @return array<string, list<string>>
     */
    private function boundedRaw(array $raw): array
    {
        $encoded = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encoded !== false && strlen($encoded) <= 32_768) {
            return $raw;
        }

        // Çok büyük kayıt: her alanın yalnız ilk değeri, kısaltılmış.
        $out = [];

        foreach ($raw as $path => $values) {
            $out[$path] = [mb_substr((string) ($values[0] ?? ''), 0, 200)];
        }

        return $out;
    }
}
