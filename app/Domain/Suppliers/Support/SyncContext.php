<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Support;

use App\Domain\Suppliers\Enums\SupplierReason;
use App\Domain\Suppliers\Models\SupplierRunChange;
use App\Domain\Suppliers\Models\SupplierSource;
use App\Domain\Suppliers\Models\SupplierSyncRun;
use Illuminate\Support\Carbon;

/**
 * Bir turun çalışma durumu: sayaçlar, değişiklik satırı tamponu, kota,
 * önizlemede "yazılmış gibi" izler.
 */
final class SyncContext
{
    /** Ayrıntı satırı üst sınırı — sayaçlar sınırsız, satırlar değil. */
    public const MAX_CHANGE_ROWS = 20_000;

    private const FLUSH_EVERY = 500;

    /** @var array<string, int> */
    public array $counts;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private int $written = 0;

    public bool $quotaHit = false;

    /** Önizleme: bu turda bağlanmış sayılan varyantlar. @var array<string, true> */
    public array $claimedVariants = [];

    /** Önizleme: bu turda açılmış sayılan gruplar. @var array<string, true> */
    public array $previewGroups = [];

    public function __construct(
        public readonly SupplierSource $source,
        public readonly SupplierSyncRun $run,
        public readonly string $warehouseId,
        public ?int $quotaRemaining,
    ) {
        $this->counts = array_fill_keys(SupplierSyncRun::COUNT_KEYS, 0);
    }

    public function preview(): bool
    {
        return $this->run->isPreview();
    }

    public function bump(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    public function change(
        string $kind,
        ?string $sourceKey,
        ?string $recordId = null,
        ?string $variantId = null,
        int|string|null $before = null,
        int|string|null $after = null,
        ?SupplierReason $reason = null,
    ): void {
        if ($this->written + count($this->buffer) >= self::MAX_CHANGE_ROWS) {
            return;
        }

        $this->buffer[] = [
            'id' => SupplierRunChange::generateUuidV7(),
            'tenant_id' => $this->run->tenant_id,
            'supplier_sync_run_id' => $this->run->id,
            'supplier_record_id' => $recordId,
            'source_key' => $sourceKey !== null ? mb_substr($sourceKey, 0, 191) : null,
            'variant_id' => $variantId,
            'kind' => $kind,
            'before' => $before !== null ? mb_substr((string) $before, 0, 64) : null,
            'after' => $after !== null ? mb_substr((string) $after, 0, 64) : null,
            'reason_code' => $reason?->value,
            'created_at' => Carbon::now(),
        ];

        if (count($this->buffer) >= self::FLUSH_EVERY) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        SupplierRunChange::query()->insert($this->buffer);
        $this->written += count($this->buffer);
        $this->buffer = [];
    }
}
