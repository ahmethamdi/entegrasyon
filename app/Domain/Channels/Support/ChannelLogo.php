<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

/**
 * Kanal logosunun yerel adresi — `public/images/channels/<code>.svg|png`.
 *
 * Logo yoksa null: kart yalnız adı gösterir (logo uydurulmaz, yeniden
 * çizilmez). Kaynaklar `public/images/channels/KAYNAKLAR.md`.
 */
final class ChannelLogo
{
    public static function url(string $code): ?string
    {
        if (preg_match('/^[a-z0-9_]+$/', $code) !== 1) {
            return null;
        }

        foreach (['svg', 'png'] as $ext) {
            if (is_file(public_path("images/channels/{$code}.{$ext}"))) {
                return asset("images/channels/{$code}.{$ext}");
            }
        }

        return null;
    }

    /**
     * KARE ikon — kartlar ve menü için (`images/channels/icon/`). Yoksa null;
     * görünüm harf rozeti gösterir (logo uydurulmaz).
     */
    public static function icon(string $code): ?string
    {
        if (preg_match('/^[a-z0-9_]+$/', $code) !== 1) {
            return null;
        }

        foreach (['svg', 'png'] as $ext) {
            if (is_file(public_path("images/channels/icon/{$code}.{$ext}"))) {
                return asset("images/channels/icon/{$code}.{$ext}");
            }
        }

        return null;
    }

    /**
     * Logo yazı-logosu mu (en/boy ≥ 2)? Öyleyse markanın adını zaten
     * taşır; kart adı ikinci kez yazmaz (dar ekranda ad sıkışıp taşıyordu).
     */
    public static function isWordmark(string $code): bool
    {
        foreach (['svg', 'png'] as $ext) {
            $path = public_path("images/channels/{$code}.{$ext}");

            if (! is_file($path)) {
                continue;
            }

            if ($ext === 'png') {
                $size = @getimagesize($path);

                return is_array($size) && $size[1] > 0 && $size[0] / $size[1] >= 2;
            }

            $head = (string) file_get_contents($path, length: 2048);

            if (preg_match('/viewBox="\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/', $head, $m) === 1 && (float) $m[2] > 0) {
                return (float) $m[1] / (float) $m[2] >= 2;
            }

            return false;
        }

        return false;
    }
}
