<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Ürün içeriğini günceller.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.2 · "Panelde ürün düzenleme",
 * §4 · content_version.
 *
 * DEĞİŞMEZ KURAL — İÇERİK DÜZENLEMESİ STOĞA DOKUNMAZ:
 *   `inventory_levels` ve `inventory_movements` DEĞİŞMEZ. İçerik, fiyat ve
 *   stok ayrı senkron alanlarıdır (`listing_sync_states.domain`); başlık
 *   düzeltmesinin stok hareketi yaratması ledger'ı kirletir, hareket sayısını
 *   şişirir ve gerçek fazla satışı gürültü içinde gizlerdi.
 *
 *   Stok değişimi ayrı bir eylemdir: `AdjustStock` (panelden düzeltme) veya
 *   sipariş/iade yolları.
 *
 * `content_version` ARTAR: senkron kapısı bu sürümden beslenir
 * (`desired_version > synced_version`). Artırılmazsa değişiklik kanala hiç
 * gitmez ve panelde "senkron" görünürken mağazada eski başlık durur.
 *
 * FİYAT VARYANTA YAZILIR: fiyat varyant seviyesinde tutulur (§4 · variants).
 * Tek varyantlı üründe kullanıcı tek fiyat girer; action onu varyanta taşır.
 */
final class UpdateProduct
{
    public function run(
        Product $product,
        string $title,
        ?float $price = null,
        ?string $description = null,
        ?string $brand = null,
        ?string $status = null,
        ?string $internalCategoryId = null,
    ): Product {
        return DB::transaction(function () use (
            $product, $title, $price, $description, $brand, $status, $internalCategoryId,
        ): Product {
            $product->forceFill([
                'title' => $title,
                'description' => $description,
                'brand' => $brand,
                'status' => $status ?? $product->status,
                // İç kategori: kanal eşleştirmesinin (§13 · Faz 2) çıpası.
                // Boş dize NULL'a çevrilir — "" bir kategori adı değildir ve
                // eşleştirme ekranında adsız bir satır olarak görünürdü.
                'internal_category_id' => $this->normalizeCategory($internalCategoryId),
                // Senkron kapısı bundan beslenir; artmazsa değişiklik
                // kanala hiç gitmez.
                'content_version' => $product->content_version + 1,
            ])->save();

            // Fiyat varyant seviyesindedir. Tek varyantlı üründe kullanıcı tek
            // fiyat girer; çok varyantlı üründe varyant başına düzenleme ayrı
            // bir ekranın işidir ve burada dokunulmaz.
            if ($price !== null && $product->variants()->count() === 1) {
                $variant = $product->variants()->firstOrFail();

                $variant->forceFill([
                    'price' => $price,
                    'content_version' => $variant->content_version + 1,
                ])->save();
            }

            // STOK KASITEN DOKUNULMADI. Bakiye değişimi ayrı bir eylemdir.

            return $product;
        });
    }

    /**
     * Boş dize NULL'a çevrilir.
     *
     * "" bir kategori adı DEĞİLDİR; olduğu gibi yazılsaydı eşleştirme
     * ekranında adsız bir satır belirir ve satıcı onu ne eşleştirebilir
     * ne de silebilirdi.
     */
    private function normalizeCategory(?string $internalCategoryId): ?string
    {
        if ($internalCategoryId === null) {
            return null;
        }

        $trimmed = trim($internalCategoryId);

        return $trimmed === '' ? null : $trimmed;
    }
}
