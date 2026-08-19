<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Messaging\Models\OutboxEvent;
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

                // FİYAT GERÇEKTEN DEĞİŞTİ Mİ? Her kaydetme fiyat turu
                // açsaydı kanal kotası boşa harcanır ve mutabakat gerçek
                // sürüklenmeyi gürültü içinde kaybederdi. Karşılaştırma
                // KURUŞ ölçeğinde tam sayı üzerinden yapılır: decimal(12,2)
                // kolonu string olarak geri döner ve float karşılaştırması
                // (`100.00 != 100.0`) yanlış pozitif üretir.
                $changed = $this->priceChanged($variant->price, $price);

                $variant->forceFill([
                    'price' => $price,
                    'content_version' => $variant->content_version + 1,
                ])->save();

                // TETİKLEYİCİ — AYNI TRANSACTION İÇİNDE.
                //
                // Bu olay olmadan panelden yapılan fiyat düzeltmesi kanala
                // HİÇ gitmez ve satır panelde "senkron" görünür: en pahalı
                // sessiz hata biçimi. Stokta tetik `ApplyMovement`'ın ledger
                // transaction'ında yaşar; fiyatın ledger'ı yoktur ve tetik
                // bu yüzden buraya, kolonu yazan yere ait.
                //
                // Ayrı transaction'da yazılsaydı araya düşen hata fiyatı
                // değişmiş ama olayı yazılmamış bir varyant bırakırdı ve
                // hiçbir tarama onu görmezdi (dual write'ın tek çözümü
                // outbox'tır, §6).
                if ($changed) {
                    $this->recordPriceEvent($variant);
                }
            }

            // STOK KASITEN DOKUNULMADI. Bakiye değişimi ayrı bir eylemdir.

            return $product;
        });
    }

    /**
     * Fiyat gerçekten değişti mi — KURUŞ ölçeğinde tam sayı karşılaştırması.
     *
     * `decimal(12,2)` kolonu PHP'ye STRING olarak döner ("100.00"). Float
     * karşılaştırması iki yönden de yanıltır: `(float) "100.00" != 100.0`
     * kayan nokta gösteriminde doğru olabilir, ve gerçek bir kuruş değişimi
     * (`100.00 → 100.001`) yuvarlanarak kaybolabilir. Kuruşa çevirip tam
     * sayı karşılaştırmak ikisini de keser.
     */
    private function priceChanged(mixed $current, float $new): bool
    {
        return (int) round(((float) $current) * 100) !== (int) round($new * 100);
    }

    /**
     * Fiyat değişimini outbox'a yazar — çağıranın transaction'ı içinde.
     *
     * Yayınlama YAPILMAZ: o relay sürecinin işidir ve `published_at` boş
     * kalır.
     *
     * FİYAT STRING TAŞINIR: para float taşınmaz, yuvarlama kuruş kayması
     * üretir (§7). Yük varyantın kanonik `content_version`'ını taşır ve
     * sürüm kapısı ondan beslenir.
     *
     * `origin_connection_id` YOKTUR ve olmamalı: fiyat değişimi PANELDEN
     * geldi, bir kanaldan gelmedi. Yankı bastırılacak bir kaynak kanal yok
     * ve alan yazılsaydı o kanal gereksizce elenirdi.
     */
    private function recordPriceEvent(Variant $variant): void
    {
        OutboxEvent::record(
            aggregateType: 'variant',
            aggregateId: $variant->id,
            eventType: 'VariantPriceChanged',
            payload: [
                'variant_id' => $variant->id,
                'price' => (string) $variant->price,
                'compare_at_price' => $variant->compare_at_price !== null
                    ? (string) $variant->compare_at_price
                    : null,
                'version' => $variant->content_version,
            ],
            tenantId: $variant->tenant_id,
        );
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
