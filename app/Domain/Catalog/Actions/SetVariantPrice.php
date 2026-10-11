<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Messaging\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;

/**
 * Tek bir varyantın fiyatını yazar ve değiştiyse fiyat olayını üretir.
 *
 * ADR-003: fiyat VARYANT düzeyindedir. Çok varyantlı üründe (beden × renk)
 * her varyantın kendi fiyatı vardır; `UpdateProduct` ürün düzeyinde tek
 * fiyat aldığı için çok varyantlı ürüne dokunmaz. Kanaldan içe aktarma
 * fiyatı bu action ile DOĞRU varyanta yazar.
 *
 * `UpdateProduct`'ın tek varyantlı yolu da buradan geçer — iki yolun
 * davranışı ayrışmasın diye kural tek yerde durur.
 */
final class SetVariantPrice
{
    /**
     * @return bool Fiyat gerçekten değişti mi
     */
    public function run(Variant $variant, float $price): bool
    {
        return DB::transaction(function () use ($variant, $price): bool {
            // FİYAT GERÇEKTEN DEĞİŞTİ Mİ? Her kaydetme fiyat turu açsaydı
            // kanal kotası boşa harcanır ve mutabakat gerçek sürüklenmeyi
            // gürültü içinde kaybederdi. Karşılaştırma KURUŞ ölçeğinde tam
            // sayı üzerinden yapılır: decimal(12,2) kolonu string olarak geri
            // döner ve float karşılaştırması (`100.00 != 100.0`) yanlış
            // pozitif üretir.
            $changed = $this->priceChanged($variant->price, $price);

            $variant->forceFill([
                'price' => $price,
                'content_version' => $variant->content_version + 1,
            ])->save();

            // TETİKLEYİCİ — AYNI TRANSACTION İÇİNDE.
            //
            // Bu olay olmadan fiyat düzeltmesi kanala HİÇ gitmez ve satır
            // panelde "senkron" görünür: en pahalı sessiz hata biçimi.
            // Ayrı transaction'da yazılsaydı araya düşen hata fiyatı
            // değişmiş ama olayı yazılmamış bir varyant bırakırdı (dual
            // write'ın tek çözümü outbox'tır, §6).
            if ($changed) {
                $this->recordPriceEvent($variant);
            }

            return $changed;
        });
    }

    /**
     * Fiyat gerçekten değişti mi — KURUŞ ölçeğinde tam sayı karşılaştırması.
     *
     * `decimal(12,2)` kolonu PHP'ye STRING olarak döner ("100.00"). Float
     * karşılaştırması iki yönden de yanıltır; kuruşa çevirip tam sayı
     * karşılaştırmak ikisini de keser.
     */
    private function priceChanged(mixed $current, float $new): bool
    {
        return (int) round(((float) $current) * 100) !== (int) round($new * 100);
    }

    /**
     * Fiyat değişimini outbox'a yazar — çağıranın transaction'ı içinde.
     *
     * FİYAT STRING TAŞINIR: para float taşınmaz (§7). Yük varyantın kanonik
     * `content_version`'ını taşır ve sürüm kapısı ondan beslenir.
     *
     * `origin_connection_id` YOKTUR: yankı bastırma fiyat yolunda
     * uygulanmaz; alan yazılsaydı o kanal gereksizce elenirdi.
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
}
