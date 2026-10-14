<?php

declare(strict_types=1);

namespace App\Domain\BulkEdit\Jobs;

use App\Domain\BulkEdit\Actions\ApplyBulkEdit;
use App\Domain\BulkEdit\Models\BulkEditImport;
use App\Support\Tenancy\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Throwable;

/**
 * Excel uygulaması — `background` havuzu. Yeniden denenebilir: uygulanmış
 * satır atlanır, defter anahtarı satır başına sabittir.
 */
final class ApplyBulkEditJob extends TenantAwareJob
{
    public int $tries = 3;

    public int $timeout = 540;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        string $tenantId,
        public readonly string $importId,
    ) {
        parent::__construct($tenantId);

        $this->onQueue('listing:bulk');
    }

    protected function handleForTenant(): void
    {
        $import = BulkEditImport::query()->find($this->importId);

        if ($import === null || $import->status !== BulkEditImport::APPLYING) {
            return;
        }

        app(ApplyBulkEdit::class)->run($import);
    }

    public function failed(?Throwable $e): void
    {
        TenantContext::runFor($this->tenantId, function (): void {
            BulkEditImport::query()
                ->whereKey($this->importId)
                ->where('status', BulkEditImport::APPLYING)
                ->update(['status' => BulkEditImport::FAILED, 'updated_at' => now()]);
        });
    }
}
