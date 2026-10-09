<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\Support\StripePaymentGateway;
use App\Domain\Catalog\Support\ActiveCampaigns;
use App\Domain\Catalog\Support\ChannelPriceRules;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\SuperAdmin;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        // scoped: her iş/istekte sıfırlanır — kural değişince eski fiyat gitmesin.
        $this->app->scoped(ChannelPriceRules::class);
        $this->app->scoped(ActiveCampaigns::class);

        $this->app->bind(PaymentGateway::class, StripePaymentGateway::class);
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

        // SÜPER ADMIN (/admin) — platformu işleten. Liste sunucu ayarında
        // (`SuperAdmin`), rol kolonu yok: yetki panelden kazanılamaz.
        Gate::define('superAdmin', static fn (?User $user = null): bool => SuperAdmin::is($user));

        // KİMLİK UÇLARI HIZ SINIRI (B4) — IP başına.
        //
        // Kayıt sınırsızken tek betik binlerce kiracı (ve her biri için
        // varsayılan depo, ücretsiz plan kotası) açabiliyordu. Parola
        // sıfırlama sınırsızken aynı adrese posta bombası atılabilirdi;
        // broker'ın kendi 60 sn'lik e-posta başına sınırı FARKLI adresleri
        // denemeyi durdurmaz.
        RateLimiter::for('register', static fn (Request $request): Limit => Limit::perHour(5)->by($request->ip()));
        RateLimiter::for('password-reset', static fn (Request $request): Limit => Limit::perMinutes(15, 5)->by($request->ip()));

        // Üretimde beklenmeyen lazy loading sessiz N+1 üretir; geliştirmede
        // erken yakalanır.
        Model::preventLazyLoading(! $this->app->isProduction());

        // HTTPS ZORUNLU — §11 · "Minimum production kontrol listesi".
        //
        // Düz HTTP'de iki şey birden açığa çıkar: satıcının OTURUM ÇEREZİ
        // (yani hesabın tamamı) ve kanal bağlama formuna yazılan API
        // ANAHTARI. `StoreUrl` zaten `https` dayatıyor ama o kural KANALA
        // GİDEN yönü korur; bu satır satıcının TARAYICISINDAN gelen yönü
        // korur.
        //
        // ÜRETİMLE SINIRLIDIR: yerel geliştirme `http://localhost:8080`
        // üzerinden çalışır ve koşulsuz zorlama tüm paneli yerelde kırardı.
        //
        // HSTS BAŞLIĞI BURADA DEĞİL NGINX'TE: başlığı uygulama katmanından
        // göndermek, uygulamanın HİÇ cevap veremediği durumlarda (500,
        // bakım modu, PHP-FPM ölü) başlığın da gitmemesi demektir. HSTS'in
        // tüm değeri KESİNTİSİZLİĞİNDEDİR — bir kez eksik gönderilen
        // başlık tarayıcının kaydını süresinden önce eskitir.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
