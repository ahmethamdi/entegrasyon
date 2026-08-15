<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Channels\Registry\AdapterRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Adapter altyapısı bağlamaları.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · Registry, §1 · Karar 20, §17 · P0.
 *
 * DİKKAT — `bind`, `singleton` DEĞİL.
 *
 * AdapterRegistry durumsuzdur ve singleton bağlanması teknik olarak zararsız
 * görünür. Yine de `bind` kullanılıyor: singleton olarak bağlanan bir sınıfa
 * ileride önbellek eklemek doğal görünür ve "her çağrıda yeni adapter" kuralı
 * o gün sessizce delinir. Bağlamanın kendisi kuralı hatırlatan bir işaret.
 *
 * Bu dosyaya adapter sınıfları için singleton bağlama EKLENMEZ.
 */
final class AdapterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AdapterRegistry::class, static fn (): AdapterRegistry => new AdapterRegistry);
    }
}
