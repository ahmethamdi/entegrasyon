<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\DuplicateSkuException;
use App\Domain\Catalog\Exceptions\InvalidBundle;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Models\VariantComponent;
use App\Domain\Inventory\Support\SellableStock;
use App\Domain\Sync\Actions\RequestResync;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Set / paket ürün yaratır ya da setin bileşenlerini değiştirir.
 *
 * Set TEK VARYANTLI bir üründür (`variants.kind = 'bundle'`), kendi fiyatıyla
 * kanallarda ayrı ürün olarak listelenir. Kendi stok satırı AÇILMAZ — stoğu
 * bileşenlerden türetilir (`SellableStock`); açılış stoğu da yoktur.
 *
 * KURALLAR (sözleşme testleriyle):
 *   · en az 1, en çok `MAX_COMPONENTS` bileşen; adet 1–1000
 *   · bileşen bu kiracının STANDART varyantıdır — SET İÇİNDE SET YOK
 *     (iç içe set, türetilmiş stoğun türetilmiş stoktan hesaplanması ve
 *     sipariş açılımında döngü riski demekti)
 *   · aynı bileşen iki kez yazılmaz (adet toplanır)
 *
 * TANIM DEĞİŞİNCE `bundle_revision` ARTAR: setin stok sürümü bileşen
 * sürümleri toplamı + bu değerdir. Bileşen çıkarılınca toplam düşebilir;
 * revizyon, yeni toplam ESKİSİNİ geçecek kadar artırılır — geçmeseydi sürüm
 * kapısı yeni değeri "eski" sayıp elerdi. Ardından setin canlı ilanları
 * yeniden senkronlanır (`RequestResync`): bileşen değişmeden de setin
 * stoğu değişmiş olabilir.
 */
final class SaveBundle
{
    public const MAX_COMPONENTS = 20;

    public const MAX_QUANTITY = 1000;

    public function __construct(private readonly RequestResync $resync) {}

    /**
     * @param  array<array-key, array{variant_id: string, quantity: int}>  $components
     *
     * @throws DuplicateSkuException
     * @throws InvalidBundle
     */
    public function create(string $sku, string $title, float $price, array $components, string $currency = 'TRY', ?string $description = null): Product
    {
        $tenantId = TenantContext::idOrFail();
        $components = $this->validated($tenantId, $components, null);

        try {
            return DB::transaction(function () use ($tenantId, $sku, $title, $price, $components, $currency, $description): Product {
                $product = Product::query()->create([
                    'tenant_id' => $tenantId,
                    'sku' => $sku,
                    'title' => $title,
                    'description' => $description,
                    'status' => 'active',
                    'content_version' => 1,
                ]);

                $variant = $product->variants()->create([
                    'tenant_id' => $tenantId,
                    'sku' => $sku,
                    'price' => $price,
                    'currency' => $currency,
                    'status' => 'active',
                    'content_version' => 1,
                    'kind' => Variant::KIND_BUNDLE,
                ]);

                $this->writeComponents($tenantId, $variant, $components);

                return $product;
            });
        } catch (UniqueConstraintViolationException) {
            throw DuplicateSkuException::for($sku);
        }
    }

    /**
     * @param  array<array-key, array{variant_id: string, quantity: int}>  $components
     *
     * @throws InvalidBundle
     */
    public function update(Variant $bundle, array $components): void
    {
        if (! $bundle->isBundle()) {
            throw new InvalidBundle(__('Bu ürün bir set değil.'));
        }

        $tenantId = TenantContext::idOrFail();
        $components = $this->validated($tenantId, $components, $bundle->id);

        DB::transaction(function () use ($tenantId, $bundle, $components): void {
            // Eşzamanlı iki kayıt revizyonu aynı tabandan hesaplamasın.
            $locked = Variant::query()->whereKey($bundle->id)->lockForUpdate()->firstOrFail();
            $before = SellableStock::forVariant($tenantId, $locked->id)->version ?? 0;

            VariantComponent::query()->where('bundle_variant_id', $locked->id)->delete();
            $this->writeComponents($tenantId, $locked, $components);

            $componentSum = SellableStock::forVariant($tenantId, $locked->id)->version - $locked->bundle_revision;
            $locked->forceFill(['bundle_revision' => max($locked->bundle_revision + 1, $before + 1 - $componentSum)])->save();

            Listing::query()
                ->where('variant_id', $locked->id)
                ->live()
                ->get()
                ->each(fn (Listing $listing) => $this->resync->run($listing, SyncDomain::INVENTORY, 'Set bileşenleri değişti'));
        });
    }

    /**
     * @param  array<array-key, mixed>  $components
     * @return list<array{variant_id: string, quantity: int}>
     *
     * @throws InvalidBundle
     */
    private function validated(string $tenantId, array $components, ?string $bundleId): array
    {
        $merged = [];

        foreach ($components as $component) {
            $id = is_array($component) ? (string) ($component['variant_id'] ?? '') : '';
            $quantity = is_array($component) ? (int) ($component['quantity'] ?? 0) : 0;

            if ($id === '' || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
                throw new InvalidBundle(__('Her bileşenin adedi 1 ile :max arasında olmalı.', ['max' => self::MAX_QUANTITY]));
            }

            $merged[$id] = ($merged[$id] ?? 0) + $quantity;
        }

        if ($merged === []) {
            throw new InvalidBundle(__('Sete en az bir ürün ekleyin.'));
        }

        if (count($merged) > self::MAX_COMPONENTS) {
            throw new InvalidBundle(__('Bir sette en çok :max farklı ürün olabilir.', ['max' => self::MAX_COMPONENTS]));
        }

        if ($bundleId !== null && isset($merged[$bundleId])) {
            throw new InvalidBundle(__('Set kendisini içeremez.'));
        }

        $found = Variant::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_keys($merged))
            ->get(['id', 'kind']);

        if ($found->count() !== count($merged)) {
            throw new InvalidBundle(__('Seçilen ürünlerden biri bulunamadı.'));
        }

        if ($found->contains(static fn (Variant $v): bool => $v->isBundle())) {
            throw new InvalidBundle(__('Bir set başka bir sete eklenemez.'));
        }

        ksort($merged);

        return array_map(static fn (string $id, int $quantity): array => ['variant_id' => $id, 'quantity' => $quantity], array_keys($merged), array_values($merged));
    }

    /** @param list<array{variant_id: string, quantity: int}> $components */
    private function writeComponents(string $tenantId, Variant $bundle, array $components): void
    {
        foreach ($components as $component) {
            VariantComponent::query()->create([
                'tenant_id' => $tenantId,
                'bundle_variant_id' => $bundle->id,
                'component_variant_id' => $component['variant_id'],
                'quantity' => $component['quantity'],
            ]);
        }
    }
}
