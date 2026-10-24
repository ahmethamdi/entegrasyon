<?php

declare(strict_types=1);

namespace Tests\Support\Backup;

use App\Support\Backup\ScratchDatabase;

/** Geri yükleme provasının sahte geçici veritabanı: aç/sil kaydedilir, sayımlar verilir. */
final class FakeScratchDatabase extends ScratchDatabase
{
    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $dropped = [];

    /** @param array<string, int|null> $counts */
    public function __construct(private readonly array $counts) {}

    public function create(string $name): void
    {
        $this->created[] = $name;
    }

    public function drop(string $name): void
    {
        $this->dropped[] = $name;
    }

    public function countRows(string $name, array $tables): array
    {
        return array_intersect_key($this->counts, array_flip($tables)) + array_fill_keys($tables, null);
    }
}
