<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Channels\Support\CredentialVault;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Maskeleme ve kimlik bilgisi kasası paylaşılabilir; durum taşımazlar.
        // NOT: Adapter örnekleri ASLA singleton bağlanmaz (§7, Karar 20) —
        // onlar AdapterRegistry tarafından her çağrıda yeniden yaratılır.
        $this->app->singleton(PayloadRedactor::class);
        $this->app->singleton(CredentialVault::class);
    }

    public function boot(): void
    {
        // Modüler monolit: modeller app/Domain/* altında, factory'ler ise
        // database/factories altında düz namespace kullanır. Varsayılan
        // çözümleyici App\Models\* varsayar; eşlemeyi burada kuruyoruz.
        Factory::guessFactoryNamesUsing(static function (string $modelName): string {
            $base = class_basename($modelName);

            return 'Database\\Factories\\'.$base.'Factory';
        });

        Factory::guessModelNamesUsing(static function (Factory $factory): string {
            $base = str_replace('Factory', '', class_basename($factory));

            return match ($base) {
                'Tenant', 'User', 'TenantUser' => 'App\\Domain\\Identity\\Models\\'.$base,
                'Warehouse', 'InventoryLevel', 'InventoryMovement' => 'App\\Domain\\Inventory\\Models\\'.$base,
                'ChannelType', 'ChannelConnection', 'ChannelCredential' => 'App\\Domain\\Channels\\Models\\'.$base,
                'OutboxEvent' => 'App\\Domain\\Messaging\\Models\\'.$base,
                default => 'App\\Domain\\Catalog\\Models\\'.$base,
            };
        });

        // Üretimde beklenmeyen lazy loading sessiz N+1 üretir; geliştirmede
        // erken yakalanır.
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
