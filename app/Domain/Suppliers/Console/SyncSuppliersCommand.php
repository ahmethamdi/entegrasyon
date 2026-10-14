<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Console;

use App\Domain\Suppliers\Actions\RunSupplierSync;
use App\Domain\Suppliers\Actions\StartSupplierSync;
use App\Domain\Suppliers\Enums\RunStatus;
use App\Domain\Suppliers\Models\SupplierSource;
use App\Domain\Suppliers\Models\SupplierSyncRun;
use App\Domain\Suppliers\Support\SupplierFeedException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Saatlik tedarikçi güncellemesi (S5, ADR-012).
 *
 * Her çağrıda:
 *   1. YARIDA KALAN turlar (STALE_MINUTES'tan eski `running`) FAILED
 *      yapılır, kilit bırakılır — süreç öldürüldüyse `failed()` hiç
 *      çalışmamış olabilir.
 *   2. Zamanı gelen etkin kaynaklar için tur AÇILIR (kuyruğa). Akış bu
 *      komutun içinde indirilmez. Kilitli kaynak atlanır.
 */
final class SyncSuppliersCommand extends Command
{
    /** İş zaman aşımı 540 sn; bundan eski `running` tur ölüdür. */
    public const STALE_MINUTES = 30;

    protected $signature = 'suppliers:sync';

    protected $description = 'Zamanı gelen tedarikçi XML kaynaklarını kuyruğa alır, yarıda kalan turları kapatır';

    public function handle(StartSupplierSync $start, RunSupplierSync $runner): int
    {
        $abandoned = 0;
        $started = 0;
        $skipped = 0;

        $stale = TenantContext::runAsSystem(fn () => SupplierSyncRun::query()
            ->where('status', RunStatus::RUNNING->value)
            ->where('created_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->get(['id', 'tenant_id']));

        foreach ($stale as $row) {
            TenantContext::runFor($row->tenant_id, function () use ($row, $runner, &$abandoned): void {
                $run = SupplierSyncRun::query()->find($row->id);

                if ($run !== null) {
                    $runner->abandon($run, 'Tur zaman aşımına uğradı.');
                    $abandoned++;
                }
            });
        }

        $due = TenantContext::runAsSystem(fn () => SupplierSource::query()
            ->where('is_active', true)
            ->where('sync_state', SupplierSource::STATE_IDLE)
            ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->get(['id', 'tenant_id']));

        foreach ($due as $row) {
            TenantContext::runFor($row->tenant_id, function () use ($row, $start, &$started, &$skipped): void {
                $source = SupplierSource::query()->find($row->id);

                if ($source === null) {
                    return;
                }

                try {
                    $start->run($source, SupplierSyncRun::MODE_APPLY, SupplierSyncRun::TRIGGER_SCHEDULE);
                    $started++;
                } catch (SupplierFeedException $e) {
                    $skipped++;
                    Log::info('supplier.schedule_skipped', ['source_id' => $source->id, 'reason' => $e->reason->value]);
                }
            });
        }

        $this->line("started={$started} skipped={$skipped} abandoned={$abandoned}");

        return self::SUCCESS;
    }
}
