<?php

declare(strict_types=1);

namespace App\Support\Site;

use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelLogo;

/**
 * Çözüm sayfalarının içeriği (`resources/content/solutions.php`) ve
 * her çözümün çalıştığı kanallar.
 *
 * Kanal listesi adaptörün YETENEĞİNDEN hesaplanır (arayüz adı): elle
 * tutulan liste, kanal eklendiğinde ya da yetenek kazandığında eskirdi.
 */
final class Solutions
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::$cache ??= require resource_path('content/solutions.php');
    }

    /** TR adresinden ya da EN adresinden çözüm; yoksa null. */
    public static function find(string $slug, string $lang): ?array
    {
        foreach (self::all() as $trSlug => $solution) {
            if (($lang === 'tr' && $trSlug === $slug) || ($lang === 'en' && $solution['en_slug'] === $slug)) {
                return ['slug' => $trSlug, ...$solution];
            }
        }

        return null;
    }

    /**
     * Menü için kısa liste — dil başına ad, ipucu ve adres.
     *
     * @return list<array{label: string, hint: string, url: string, icon: string, slug: string}>
     */
    public static function menu(string $lang): array
    {
        $items = [];

        foreach (self::all() as $slug => $solution) {
            $items[] = [
                'label' => $solution[$lang]['menu'],
                'hint' => $solution[$lang]['menu_hint'],
                'url' => $lang === 'en' ? route('site.en.solution', $solution['en_slug']) : route('site.solution', $slug),
                'icon' => $solution['icon'],
                'slug' => $slug,
            ];
        }

        return $items;
    }

    /**
     * Çözümün çalıştığı AÇIK kanallar.
     *
     * @param  array<string, mixed>  $solution
     * @return list<array{code: string, name: string, logo: ?string, wordmark: bool}>
     */
    public static function channels(array $solution): array
    {
        $rule = $solution['channels'] ?? null;

        if ($rule === null) {
            return [];
        }

        return ChannelType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name', 'adapter_class'])
            ->filter(function (ChannelType $type) use ($rule): bool {
                if (is_array($rule)) {
                    return in_array($type->code, $rule, true);
                }

                $interface = 'App\\Domain\\Channels\\Contracts\\'.$rule;

                return is_string($type->adapter_class) && is_a($type->adapter_class, $interface, true);
            })
            ->map(fn (ChannelType $type): array => [
                'code' => $type->code,
                'name' => $type->name,
                'logo' => ChannelLogo::url($type->code),
                'wordmark' => ChannelLogo::isWordmark($type->code),
                'icon' => ChannelLogo::icon($type->code),
            ])
            ->values()
            ->all();
    }
}
