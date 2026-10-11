<?php

declare(strict_types=1);

namespace App\Domain\Orders\Console;

use App\Domain\Channels\Contracts\MapsOrderState;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Orders\Actions\TransitionOrderState;
use App\Domain\Orders\Enums\OrderState;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `orders.state` geri doldurma (ADR-001) — YALNIZ `state IS NULL` satırlar.
 *
 * Kaynak sırası, canlı yolla aynı:
 *   1. adapter eşlemesi (ham `status` / `financial_status`)
 *   2. kalem sayaçları (iptal / iade)
 *   3. kargo kaydı varsa en az `shipped`
 *
 * İDEMPOTENT: dolu satıra dokunmaz; ikinci çalıştırma bir şey yapmaz. Ham
 * kanal kelimesi DEĞİŞMEZ. Eşlenemeyen satır NULL kalır ve okuyucular eski
 * ham listeye düşer — yanlış bir durum uydurulmaz.
 */
final class BackfillOrderStateCommand extends Command
{
    protected $signature = 'orders:backfill-state {--dry-run : Yalnız say}';

    protected $description = 'Kanonik sipariş durumunu (orders.state) mevcut siparişler için doldurur';

    public function handle(AdapterRegistry $registry, TransitionOrderState $transition): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $filled = 0;
        $unmapped = 0;
        $adapters = [];

        $ids = TenantContext::runAsSystem(fn () => DB::table('orders')
            ->whereNull('state')
            ->orderBy('id')
            ->get(['id', 'tenant_id']));

        foreach ($ids as $row) {
            TenantContext::runFor((string) $row->tenant_id, function () use ($row, $registry, $transition, $dryRun, &$filled, &$unmapped, &$adapters): void {
                $order = Order::query()->with('connection.channelType')->find($row->id);

                if ($order === null || $order->state !== null) {
                    return;
                }

                $candidate = $this->mapped($order, $registry, $adapters);

                if ($dryRun) {
                    $candidate !== null ? $filled++ : $unmapped++;

                    return;
                }

                $transition->run($order, $candidate);
                $transition->syncFromLines($order);

                if ($order->fresh()?->state === null && $order->fulfillments()->exists()) {
                    $transition->run($order, OrderState::SHIPPED);
                }

                $order->fresh()?->state !== null ? $filled++ : $unmapped++;
            });
        }

        $this->line("filled={$filled} unmapped={$unmapped}".($dryRun ? ' (dry-run, yalnız adapter eşlemesi)' : ''));

        return self::SUCCESS;
    }

    /** @param  array<string, MapsOrderState|false>  $adapters */
    private function mapped(Order $order, AdapterRegistry $registry, array &$adapters): ?OrderState
    {
        $connection = $order->connection;

        if (! $connection instanceof ChannelConnection) {
            return null;
        }

        if (! array_key_exists($connection->id, $adapters)) {
            try {
                $adapter = $registry->for($connection);
                $adapters[$connection->id] = $adapter instanceof MapsOrderState ? $adapter : false;
            } catch (Throwable) {
                $adapters[$connection->id] = false;
            }
        }

        $adapter = $adapters[$connection->id];

        if ($adapter === false) {
            return null;
        }

        return $adapter->orderStateFor(new NormalizedOrderEvent(
            type: 'updated',
            externalOrderId: (string) $order->external_id,
            externalRef: null,
            payload: ['status' => $order->status, 'financial_status' => $order->financial_status],
        ));
    }
}
