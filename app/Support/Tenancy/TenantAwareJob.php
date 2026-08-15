<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Kiracı bağlamını yükünden kuran iş temel sınıfı.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · P0 güvenlik değişmezi.
 *
 * Bağlam iş yükünde taşınır ve handle() başında kurulur; bitişte finally ile
 * temizlenir. QueueServiceProvider kancaları ikinci savunma hattıdır —
 * istisna finally'yi atlarsa bile sonraki iş temiz bağlamla başlar.
 */
abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $tenantId) {}

    final public function handle(): void
    {
        TenantContext::set($this->tenantId);

        try {
            $this->handleForTenant();
        } finally {
            TenantContext::clear();
        }
    }

    abstract protected function handleForTenant(): void;
}
