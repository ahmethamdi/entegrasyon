<?php

declare(strict_types=1);

namespace App\Support\Site;

/**
 * Hizmetler (`resources/content/services.php`) — 34Devs'in 34Pazar
 * çevresinde verdiği işler. Fiyat yok; her hizmet iletişim formuna
 * "Hizmet teklifi" konusuyla gider (`offerUrl`).
 */
final class Services
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::$cache ??= require resource_path('content/services.php');
    }

    /** @return array<string, mixed>|null */
    public static function find(string $slug): ?array
    {
        $service = self::all()[$slug] ?? null;

        return $service === null ? null : ['slug' => $slug, ...$service];
    }

    /** Ayrı sayfası olan hizmetin adresi; yoksa /hizmetler'deki bölümü. */
    public static function url(string $slug): string
    {
        return (self::all()[$slug]['detail'] ?? false)
            ? route('site.service', $slug)
            : route('site.services').'#'.$slug;
    }

    /** "Teklif al" — iletişim formu, konu ve hizmet ön seçili. */
    public static function offerUrl(string $slug): string
    {
        return route('site.contact', ['konu' => 'teklif', 'hizmet' => $slug]).'#form';
    }

    /** @return list<array{label: string, hint: string, url: string, slug: string}> */
    public static function menu(): array
    {
        $items = [];
        foreach (self::all() as $slug => $service) {
            $items[] = ['label' => $service['title'], 'hint' => $service['hint'], 'url' => self::url($slug), 'slug' => $slug];
        }

        return $items;
    }
}
