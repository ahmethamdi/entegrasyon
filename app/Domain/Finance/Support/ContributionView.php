<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

/**
 * Bir siparişin katkısı (KDV dahil) — tek bir taban için (beklenen ya da
 * gerçekleşen), izlenebilir.
 *
 * Tutarlar decimal string, İŞARETLİ (kesinti negatif). NULL = bilinmiyor —
 * 0 DEĞİL. `contribution` yalnız `status = complete` iken dolu; eksikken
 * bilinen bileşenlerin toplamı `partial` olarak durur ve ekranda "eksik"
 * diye gösterilir, son rakam gibi sunulmaz.
 */
final readonly class ContributionView
{
    public const COMPLETE = 'complete';

    public const INCOMPLETE = 'incomplete';

    /** Gerçekleşen için: hakediş kaydı henüz yok. */
    public const NOT_AVAILABLE = 'not_available';

    /**
     * @param  list<string>  $reasons  eksikliğin insan okunur sebepleri
     * @param  list<array{label: string, amount: ?string, included: bool, source: string, ref: ?string, note: ?string}>  $trace
     */
    public function __construct(
        public string $basis,
        public string $status,
        public string $currency,
        public ?string $sale,
        public ?string $refund,
        public ?string $commission,
        public ?string $shipping,
        public ?string $other,
        public ?string $cost,
        public ?string $contribution,
        public string $partial,
        public array $reasons,
        public array $trace,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
