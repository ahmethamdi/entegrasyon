<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Jobs;

use App\Domain\Suppliers\Actions\RunSupplierSync;
use App\Domain\Suppliers\Enums\RunStatus;
use App\Domain\Suppliers\Models\SupplierSyncRun;
use App\Support\Tenancy\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Throwable;

/**
 * Tedarikçi turu — `background` havuzu (`listing:bulk`).
 *
 * TEK deneme: tur kendi içinde idempotenttir ama yarıda ölen bir turu
 * kuyruk kendi başına tekrar etmesin; bir sonraki saatlik tur (ya da elle
 * tur) baştan ve güvenle geçer. Zaman aşımı havuzunkinden (600 sn) kısa:
 * havuz işi öldürmeden önce `failed()` turu FAILED yapıp kilidi bıraksın.
 */
final class RunSupplierSyncJob extends TenantAwareJob
{
    public int $tries = 1;

    public int $timeout = 540;

    public function __construct(
        string $tenantId,
        public readonly string $runId,
    ) {
        parent::__construct($tenantId);

        $this->onQueue('listing:bulk');
    }

    protected function handleForTenant(): void
    {
        $run = SupplierSyncRun::query()->find($this->runId);

        if ($run === null || $run->status !== RunStatus::RUNNING) {
            return;
        }

        app(RunSupplierSync::class)->run($run);
    }

    public function failed(?Throwable $e): void
    {
        TenantContext::runFor($this->tenantId, function () use ($e): void {
            $run = SupplierSyncRun::query()->find($this->runId);

            if ($run !== null) {
                app(RunSupplierSync::class)->abandon($run, $e?->getMessage());
            }
        });
    }
}
