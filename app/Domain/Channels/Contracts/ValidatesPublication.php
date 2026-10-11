<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Sync\Support\PublishIssue;

/**
 * Kanalın KENDİ yayın gereksinimleri — dış yazmadan ÖNCE, ürünün TÜM
 * varyantları için birlikte (S3).
 *
 * Gereksinimler kanaldan gelir (taksonomi + belge); global sabit DEĞİL.
 * Tek bir eksik bile varsa ürünün HİÇBİR varyantı gönderilmez: grup
 * halinde açılan kanalda (N11 `productMainId`, HB `VaryantGroupID`)
 * yarım grup kısmi bir dış yazma olurdu.
 *
 * Yan etkisizdir: ağ çağrısı yapmaz, yalnız yerel veriyi (eşleme,
 * taksonomi, ürün, ayarlar) okur.
 */
interface ValidatesPublication
{
    /** @return list<PublishIssue> boşsa ürün gönderilebilir */
    public function validateForPublication(Product $product, ChannelCategory $category): array;

    /**
     * Kanalın ürünün kendi alanlarından doldurduğu öznitelik kimlikleri
     * (başlık, marka, fiyat…) — satıcıdan değer İSTENMEZ.
     *
     * @return list<string>
     */
    public function autoFilledAttributes(): array;
}
