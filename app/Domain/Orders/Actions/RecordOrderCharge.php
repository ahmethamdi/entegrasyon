<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderCharge;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Kanal kesintisini İDEMPOTENT kaydeder (ADR-002).
 *
 * Aynı `(kaynak, external_ref)` ikinci kez gelirse hiçbir şey yazılmaz —
 * hakediş yeniden çekildiğinde komisyon iki kez sayılmaz. Tutar ondalık
 * metin olarak gelir; float kabul edilmez.
 */
final class RecordOrderCharge
{
    /**
     * @param  array<string, mixed>  $raw
     * @return bool yeni yazıldı mı
     */
    public function run(
        Order $order,
        string $kind,
        string $basis,
        string $amount,
        string $currency,
        string $source,
        ?string $externalRef,
        DateTimeInterface $occurredAt,
        ?string $orderLineId = null,
        ?string $rate = null,
        array $raw = [],
    ): bool {
        if (! in_array($kind, OrderCharge::KINDS, true)) {
            throw new InvalidArgumentException("Bilinmeyen kesinti türü: {$kind}");
        }

        if (! in_array($basis, [OrderCharge::BASIS_EXPECTED, OrderCharge::BASIS_ACTUAL], true)) {
            throw new InvalidArgumentException("Bilinmeyen taban: {$basis}");
        }

        $now = now();

        return DB::table('order_charges')->insertOrIgnore([
            'id' => OrderCharge::generateUuidV7(),
            'tenant_id' => TenantContext::idOrFail(),
            'order_id' => $order->id,
            'order_line_id' => $orderLineId,
            'channel_connection_id' => $order->channel_connection_id,
            'kind' => $kind,
            'basis' => $basis,
            'amount' => Money::normalize($amount),
            'currency' => strtoupper($currency),
            'rate' => $rate === null ? null : Money::normalize($rate, 4),
            'source' => $source,
            'external_ref' => $externalRef,
            'raw' => $raw === [] ? null : json_encode($raw, JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }
}
