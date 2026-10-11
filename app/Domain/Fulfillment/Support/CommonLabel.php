<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Support;

/**
 * Kanalın döndürdüğü etiket(ler) — DOKUNULMADAN. Kutu başına bir öğe.
 *
 * `label` biçime göre ZPL metni ya da kanalın verdiği PDF adresidir.
 */
final readonly class CommonLabel
{
    /** @param list<array{format: string, label: string}> $labels */
    public function __construct(public array $labels) {}

    public function format(): ?string
    {
        $formats = array_values(array_unique(array_map(static fn (array $l): string => $l['format'], $this->labels)));

        return count($formats) === 1 ? $formats[0] : null;
    }

    public function toJson(): string
    {
        return (string) json_encode($this->labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
