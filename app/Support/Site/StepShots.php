<?php

declare(strict_types=1);

namespace App\Support\Site;

/**
 * "Nasıl çalışır" adımlarının panel görselleri (`resources/content/step-shots.php`).
 *
 * İngilizce sayfada `-en` görseli varsa o (İngilizce panel), yoksa Türkçesi.
 * Bir adım satırında görseller YA HEP YA HİÇ: bir adımın gerçek ekranı
 * yoksa satırın hiçbir adımına görsel konmaz (yarım satır dengesiz durur,
 * olmayan ekran da çizilmez).
 */
final class StepShots
{
    /** @var array<string, array{w: int, h: int, mx: float, my: float}>|null */
    private static ?array $cache = null;

    /** @return array{src: string, w: int, h: int, mx: float, my: float}|null */
    public static function get(?string $name, string $lang = 'tr'): ?array
    {
        if ($name === null) {
            return null;
        }

        $all = self::$cache ??= require resource_path('content/step-shots.php');
        $key = $lang === 'en' && isset($all["{$name}-en"]) ? "{$name}-en" : $name;

        return isset($all[$key]) ? ['src' => "images/site/adim/{$key}.webp", ...$all[$key]] : null;
    }

    /**
     * @param  list<string|null>  $names
     * @return list<array{src: string, w: int, h: int, mx: float, my: float}>|null
     */
    public static function row(array $names, int $steps, string $lang = 'tr'): ?array
    {
        $shots = array_map(fn (?string $n): ?array => self::get($n, $lang), $names);

        return count($shots) === $steps && ! in_array(null, $shots, true) ? $shots : null;
    }
}
