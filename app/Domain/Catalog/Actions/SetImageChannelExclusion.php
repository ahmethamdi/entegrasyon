<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Support\Facades\DB;

/**
 * Görseli bir kanal türüne gönder / gönderme (A15).
 *
 * ⚠️ ÜRÜNÜN İÇERİK SÜRÜMÜ ARTAR. Artmasaydı sürüm kapısı yeniden
 * gönderimi "bu kanalda zaten güncel" diye eler ve yeni görsel seti
 * kanala HİÇ gitmezdi — panel seçimi kaydetmiş görünürken.
 */
final class SetImageChannelExclusion
{
    public function run(ProductImage $image, string $channelTypeCode, bool $excluded): void
    {
        DB::transaction(function () use ($image, $channelTypeCode, $excluded): void {
            $current = $image->excluded_channels ?? [];

            $next = $excluded
                ? array_values(array_unique([...$current, $channelTypeCode]))
                : array_values(array_diff($current, [$channelTypeCode]));

            sort($next);
            $sortedCurrent = $current;
            sort($sortedCurrent);

            // Değişmediyse sürüm de artmaz: gereksiz yeniden gönderim olmaz.
            if ($next === $sortedCurrent) {
                return;
            }

            $image->forceFill(['excluded_channels' => $next === [] ? null : $next])->save();

            $image->product()->increment('content_version');
        });
    }
}
