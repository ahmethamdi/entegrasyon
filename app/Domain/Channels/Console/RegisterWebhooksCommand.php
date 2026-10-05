<?php

declare(strict_types=1);

namespace App\Domain\Channels\Console;

use App\Domain\Channels\Actions\RegisterChannelWebhooks;
use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Aktif bağlantıların sipariş webhook'larını kanalda (yeniden) kurar.
 *
 * Bağlama akışı bunu zaten yapar (`ConnectChannel`); bu komut iki durum
 * içindir: kurulum o an başarısız olduysa (`settings.webhooks.registered =
 * false`) ve otomatik kurulumdan ÖNCE bağlanmış bağlantılar. İdempotent —
 * kanalda kopya açmaz (`SupportsWebhookRegistration`).
 */
final class RegisterWebhooksCommand extends Command
{
    protected $signature = 'channels:register-webhooks {connection? : Tek bir bağlantı kimliği}';

    protected $description = 'Aktif bağlantıların sipariş webhook\'larını kanalda kurar';

    public function handle(RegisterChannelWebhooks $register): int
    {
        $connections = TenantContext::runAsSystem(
            fn () => ChannelConnection::query()
                ->where('status', 'active')
                ->when($this->argument('connection'), fn ($q, $id) => $q->whereKey($id))
                ->get(),
        );

        $failed = 0;

        foreach ($connections as $connection) {
            $result = TenantContext::runAsSystem(fn (): ?bool => $register->run($connection));

            if ($result === null) {
                continue;
            }

            $this->line(sprintf(
                '%s %s (%s)',
                $result ? '✓' : '✗',
                $connection->label,
                $connection->channel_type_code,
            ));

            $failed += $result ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
