<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Variant;
use App\Support\Tenancy\TenantContext;

/**
 * Bir varyantın bir kanala gidecek görsel adresleri (A15) — TEK KAYNAK.
 *
 * Sıra: varyanta bağlı görseller ÖNCE (kırmızı tişörtün ilk görseli
 * kırmızı olmalı), sonra ürünün ortak görselleri. Başka varyanta ait
 * görsel, satıcının bu kanaldan HARİÇ tuttuğu görsel ve HTTPS olmayan
 * adres düşer.
 *
 * ⚠️ KAYNAĞI BU BAĞLANTI OLAN GÖRSEL ONA GERİ GÖNDERİLMEZ. Shopify'dan
 * içe aktarılan görsel aynı mağazaya yeniden gönderilseydi kanal onu
 * yeni bir medya olarak indirir ve ürün İKİ KOPYA görsel taşırdı — her
 * gönderimde bir kopya daha.
 *
 * Kanallar ayrı ayrı yazsaydı biri hariç tutmayı, öteki kaynak kuralını
 * unuturdu; kural burada bir kez yaşar.
 */
final class ChannelImages
{
    /**
     * @return list<string> Sınırsız; çağıran kanalın sınırıyla keser
     */
    public static function urlsFor(
        Variant $variant,
        string $channelTypeCode,
        ?string $skipSourceConnectionId = null,
    ): array {
        // ⚠️ AÇIKÇA SİSTEM BAĞLAMINDA, KİRACI VARYANTTAN SÜZÜLEREK okunur.
        // Adapter kiracı bağlamı olmadan da çağrılır (taramalar,
        // `runAsSystem`); kapsamlı sorgu orada istisna fırlatır ve ürün
        // görselsiz giderdi — `ChannelHttpClient::secrets()` kuralının aynısı.
        $images = TenantContext::runAsSystem(fn () => ProductImage::query()
            ->where('tenant_id', $variant->tenant_id)
            ->where('product_id', $variant->product_id)
            ->where(fn ($q) => $q->whereNull('variant_id')->orWhere('variant_id', $variant->id))
            ->forChannel($channelTypeCode)
            ->when(
                $skipSourceConnectionId !== null,
                fn ($q) => $q->where(fn ($inner) => $inner
                    ->whereNull('source_connection_id')
                    ->orWhere('source_connection_id', '!=', $skipSourceConnectionId)),
            )
            ->orderByRaw('CASE WHEN variant_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->get(['id', 'storage_path']));

        $urls = [];

        foreach ($images as $image) {
            $url = $image->publicUrl();

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }
}
