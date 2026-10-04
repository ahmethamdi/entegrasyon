<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Support\Facades\DB;

/**
 * Kanaldan içe aktarılan görselleri ürüne yazar (A15).
 *
 * Görsel İNDİRİLMEZ; kanalın herkese açık adresi saklanır ve öteki
 * kanallar onu doğrudan indirir. Kendi depomuza kopyalamak (S3) panelden
 * yükleme ile birlikte gelir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * YALNIZCA BU KAYNAĞIN GÖRSELLERİ DEĞİŞİR
 * ─────────────────────────────────────────────────────────────────────
 * Kaynakta silinen görsel bizde de silinir; yoksa kaldırılmış (belki
 * hatalı) bir fotoğraf her kanala gitmeye devam ederdi. Ama elle eklenen
 * ya da başka kanaldan gelen görsele DOKUNULMAZ.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SATICININ KANAL SEÇİMİ KORUNUR
 * ─────────────────────────────────────────────────────────────────────
 * Aynı adres yeniden gelirse satır GÜNCELLENİR, silinip yeniden
 * yaratılmaz: silinseydi satıcının "bu görsel Trendyol'a gitmesin"
 * seçimi her içe aktarma turunda sessizce kaybolurdu.
 *
 * BOŞ LİSTE HİÇBİR ŞEY SİLMEZ: kanal görsel alanını hiç döndürmediyse
 * (sorgu hatası, alan izni yok) bu "bütün görseller silindi" demek
 * değildir. Gerçekten görselsiz kalan ürün nadirdir; yanlışlıkla bütün
 * kataloğun görsellerini silmek ise geri alınamaz.
 */
final class SyncImportedImages
{
    /**
     * @param  list<string>  $urls  Sıralı adresler; ilki ana görsel
     */
    public function run(Product $product, string $sourceConnectionId, array $urls): void
    {
        $urls = array_values(array_unique(array_filter(
            array_map(static fn (mixed $url): string => trim((string) $url), $urls),
            static fn (string $url): bool => preg_match('#^https?://#i', $url) === 1,
        )));

        if ($urls === []) {
            return;
        }

        DB::transaction(function () use ($product, $sourceConnectionId, $urls): void {
            $existing = ProductImage::query()
                ->where('product_id', $product->id)
                ->where('source_connection_id', $sourceConnectionId)
                ->get()
                ->keyBy('storage_path');

            foreach ($urls as $position => $url) {
                $image = $existing->get($url);

                if ($image !== null) {
                    // Sıra güncellenir; `excluded_channels` KORUNUR.
                    if ($image->position !== $position) {
                        $image->forceFill(['position' => $position])->save();
                    }

                    continue;
                }

                ProductImage::query()->create([
                    'tenant_id' => $product->tenant_id,
                    'product_id' => $product->id,
                    'source_connection_id' => $sourceConnectionId,
                    'storage_path' => $url,
                    'position' => $position,
                ]);
            }

            ProductImage::query()
                ->where('product_id', $product->id)
                ->where('source_connection_id', $sourceConnectionId)
                ->whereNotIn('storage_path', $urls)
                ->delete();
        });
    }
}
