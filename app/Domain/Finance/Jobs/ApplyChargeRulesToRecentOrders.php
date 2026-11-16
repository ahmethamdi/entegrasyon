<?php

declare(strict_types=1);

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Actions\ApplyChargeRules;
use App\Domain\Orders\Models\Order;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Kesinti varsayılanı kaydedilince bağlantının SON 90 GÜN siparişlerine
 * kuralı uygular. İdempotent: kuralla kesintisi yazılmış sipariş atlanır
 * (sabit external_ref), kanal kesintisi olan tür ezilmez.
 */
final class ApplyChargeRulesToRecentOrders implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const DAYS = 90;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $connectionId,
    ) {
        $this->onQueue('maintenance');
    }

    public function handle(ApplyChargeRules $rules): void
    {
        TenantContext::runFor($this->tenantId, function () use ($rules): void {
            Order::query()
                ->where('channel_connection_id', $this->connectionId)
                ->whereRaw('coalesce(placed_at, created_at) >= ?', [now()->subDays(self::DAYS)])
                ->orderBy('id')
                ->chunkById(200, function ($orders) use ($rules): void {
                    foreach ($orders as $order) {
                        $rules->forOrder($order);
                    }
                });
        });
    }
}
