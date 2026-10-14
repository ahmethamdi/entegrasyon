<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Actions;

use App\Domain\Suppliers\Enums\RunStatus;
use App\Domain\Suppliers\Enums\SupplierReason;
use App\Domain\Suppliers\Jobs\RunSupplierSyncJob;
use App\Domain\Suppliers\Models\SupplierSource;
use App\Domain\Suppliers\Models\SupplierSyncRun;
use App\Domain\Suppliers\Support\SupplierFeedException;
use Illuminate\Support\Facades\DB;

/**
 * Bir tur AÇAR: kaynağı koşullu güncellemeyle kilitler, tur satırını
 * yaratır, işi kuyruğa koyar. Akış isteğin içinde İNDİRİLMEZ.
 *
 * KİLİT: `sync_state idle → running` tek UPDATE ... WHERE; etkilenen satır
 * yoksa başka bir tur sürüyordur → SUPPLIER_SYNC_ALREADY_RUNNING. Aynı
 * kaynağın iki turu (saatlik + elle, ya da iki zamanlayıcı) BİRLİKTE
 * koşamaz; farklı kaynaklar birbirini beklemez.
 */
final class StartSupplierSync
{
    public function run(SupplierSource $source, string $mode, string $trigger): SupplierSyncRun
    {
        return DB::transaction(function () use ($source, $mode, $trigger): SupplierSyncRun {
            $run = SupplierSyncRun::query()->create([
                'tenant_id' => $source->tenant_id,
                'supplier_source_id' => $source->id,
                'mode' => $mode,
                'trigger' => $trigger,
                'status' => RunStatus::RUNNING,
                'counts' => [],
            ]);

            $claimed = SupplierSource::query()
                ->whereKey($source->id)
                ->where('sync_state', SupplierSource::STATE_IDLE)
                ->update([
                    'sync_state' => SupplierSource::STATE_RUNNING,
                    'running_run_id' => $run->id,
                    'running_since' => now(),
                    'last_attempted_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                throw new SupplierFeedException(SupplierReason::SUPPLIER_SYNC_ALREADY_RUNNING);
            }

            RunSupplierSyncJob::dispatch($source->tenant_id, $run->id)->afterCommit();

            return $run;
        });
    }
}
