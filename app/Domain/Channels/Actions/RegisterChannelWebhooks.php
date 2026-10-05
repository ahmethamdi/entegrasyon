<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Channels\Contracts\SupportsWebhookRegistration;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\CredentialVault;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bağlantının sipariş webhook'larını kanalda kurar (`SupportsWebhookRegistration`).
 *
 * İMZA ANAHTARINI BİZ ÜRETİRİZ: kasada `webhook_secret` yoksa rastgele bir
 * tane yazılır ve kanala o verilir. Satıcı hiçbir anahtar görmez, kopyalamaz.
 * Varsa KORUNUR — yeniden bağlamada değişseydi, kanalın kuyruğunda eski
 * anahtarla imzalanmış bekleyen teslimler 401 alıp düşerdi.
 *
 * BAĞLANTIYI BOZMAZ: kurulum başarısız olursa bağlantı yine `active` kalır
 * (stok ve ürün gönderimi webhook'a bağlı değildir). Sonuç `settings.webhooks`
 * altına yazılır — panel "siparişler gelmiyor olabilir" uyarısını oradan
 * okur, `channels:register-webhooks` aynı işi yeniden dener.
 */
final class RegisterChannelWebhooks
{
    public function __construct(
        private readonly CredentialVault $vault,
        private readonly AdapterRegistry $adapters,
    ) {}

    /**
     * @return bool|null null: kanal webhook kurmayı desteklemiyor (Trendyol yoklanır)
     */
    public function run(ChannelConnection $connection): ?bool
    {
        $adapter = $this->adapters->for($connection);

        if (! $adapter instanceof SupportsWebhookRegistration) {
            return null;
        }

        try {
            $secret = $this->ensureSecret($connection);

            $result = $adapter->registerWebhooks(
                route('webhooks.receive', ['connectionId' => $connection->id]),
                $secret,
            );

            $error = $result->failed()
                ? ($result->errorMessage ?? 'Kanal webhook kurulumunu reddetti.')
                : null;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        if ($error !== null) {
            Log::warning('channel.webhooks.register_failed', [
                'connection' => $connection->id,
                'channel' => $connection->channel_type_code,
                'error' => $error,
            ]);
        }

        $connection->forceFill([
            'settings' => [
                ...$connection->settings ?? [],
                'webhooks' => [
                    'registered' => $error === null,
                    'checked_at' => now()->toIso8601String(),
                    'error' => $error === null ? null : Str::limit($error, 300),
                ],
            ],
        ])->save();

        return $error === null;
    }

    private function ensureSecret(ChannelConnection $connection): string
    {
        $secrets = TenantContext::runAsSystem(fn (): array => $this->vault->read($connection));

        $existing = $secrets['webhook_secret'] ?? null;

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $secret = Str::random(48);

        // ⚠️ SÜRE VE KAPSAM KORUNUR. `store()` kaydın TAMAMINI yazar;
        // `expiresAt` verilmeseydi süresi dolan anahtarın (Shopify: 1 saat)
        // `expires_at`'i NULL olur, yenileme turu (`TokenRefresher` yalnız
        // `expires_at IS NOT NULL` satırları seçer) bağlantıyı bir daha
        // görmez ve anahtar bir saat sonra SESSİZCE ölürdü.
        $current = TenantContext::runAsSystem(fn () => $connection->activeCredential()->first());

        TenantContext::runAsSystem(fn () => $this->vault->store(
            $connection,
            [...$secrets, 'webhook_secret' => $secret],
            $current?->scope,
            $current?->expires_at,
        ));

        return $secret;
    }
}
