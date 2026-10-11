<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderRefund;
use App\Domain\Orders\Models\OrderRefundLine;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * İade olayının PARA tarafını kaydeder — stoktan AYRI (ADR-002).
 *
 * Kanal adapter'ı iade kayıtlarını kanonik `refund_records` bloğunda verir
 * (Shopify `refunds/create`, Woo `orders/{id}/refunds`). Blok yoksa kanal
 * tutar bildirmiyordur: adet bazlı tek bir `return` kaydı yazılır, tutar NULL.
 *
 * İDEMPOTENT: anahtar kanal iade kimliğinden (`refund:{id}`), yoksa olay
 * kimliğinden. Shopify'ın tekrar gönderdiği webhook ya da Woo'nun her olayda
 * TÜM iadeleri taşıyan kümülatif gövdesi ikinci kayıt açmaz.
 *
 * Bozuk tutar kaydı düşürmez: tutar NULL (bilinmiyor) yazılır, iade yine iz bırakır.
 */
final class RecordOrderRefunds
{
    /**
     * @param  list<array{line_id: string, quantity: int}>  $affectedLines  stok yolunun eşlediği kalemler
     * @return int yeni yazılan kayıt sayısı
     */
    public function run(Order $order, NormalizedOrderEvent $event, ?string $inboxMessageId, array $affectedLines): int
    {
        $records = is_array($event->payload['refund_records'] ?? null) ? $event->payload['refund_records'] : [];
        $lineIds = $order->lines()->pluck('id', 'external_line_id')->all();
        $fallbackRef = $event->externalRef ?? $inboxMessageId ?? $event->externalOrderId;

        if ($records === []) {
            $records = [[
                'kind' => OrderRefund::KIND_RETURN,
                'lines' => array_map(static fn (array $pair): array => [
                    'order_line_id' => $pair['line_id'],
                    'quantity' => $pair['quantity'],
                    'restock' => true,
                ], $affectedLines),
            ]];
        }

        $written = 0;

        foreach (array_values($records) as $index => $record) {
            if (! is_array($record)) {
                continue;
            }

            $externalId = isset($record['external_refund_id']) && (string) $record['external_refund_id'] !== ''
                ? (string) $record['external_refund_id']
                : null;

            $key = $externalId !== null ? "refund:{$externalId}" : "event:{$fallbackRef}:{$index}";

            $written += $this->write($order, $record, $key, $externalId, $event, $inboxMessageId, $lineIds) ? 1 : 0;
        }

        return $written;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $lineIds  external_line_id → order_line_id
     */
    private function write(Order $order, array $record, string $key, ?string $externalId, NormalizedOrderEvent $event, ?string $inboxMessageId, array $lineIds): bool
    {
        return DB::transaction(function () use ($order, $record, $key, $externalId, $event, $inboxMessageId, $lineIds): bool {
            $tenantId = TenantContext::idOrFail();
            $id = OrderRefund::generateUuidV7();
            $now = now();
            $amount = self::amount($record['amount'] ?? null);

            $inserted = DB::table('order_refunds')->insertOrIgnore([
                'id' => $id,
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'channel_connection_id' => $order->channel_connection_id,
                'idempotency_key' => mb_substr($key, 0, 255),
                'external_refund_id' => $externalId,
                // Tutar bilinmiyorsa adet bazlı iade; biliniyorsa para iadesi.
                'kind' => $record['kind'] ?? ($amount === null ? OrderRefund::KIND_RETURN : OrderRefund::KIND_REFUND),
                'amount' => $amount,
                'shipping_amount' => self::amount($record['shipping_amount'] ?? null),
                'currency' => strtoupper((string) ($record['currency'] ?? $order->currency ?? 'TRY')) ?: 'TRY',
                'status' => isset($record['status']) ? mb_substr((string) $record['status'], 0, 64) : null,
                'reason' => isset($record['reason']) && $record['reason'] !== '' ? mb_substr((string) $record['reason'], 0, 500) : null,
                'raw' => is_array($record['raw'] ?? null) ? json_encode($record['raw'], JSON_THROW_ON_ERROR) : null,
                'occurred_at' => self::date($record['occurred_at'] ?? null) ?? $event->occurredAt ?? $now,
                'inbox_message_id' => $inboxMessageId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 0) {
                return false;
            }

            foreach ((array) ($record['lines'] ?? []) as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $external = isset($line['external_line_id']) ? (string) $line['external_line_id'] : null;

                OrderRefundLine::query()->create([
                    'tenant_id' => $tenantId,
                    'order_refund_id' => $id,
                    'order_line_id' => $line['order_line_id'] ?? ($external !== null ? ($lineIds[$external] ?? null) : null),
                    'external_line_id' => $external,
                    'quantity' => abs((int) ($line['quantity'] ?? 0)),
                    'amount' => self::amount($line['amount'] ?? null),
                    'restock' => isset($line['restock']) ? (bool) $line['restock'] : null,
                ]);
            }

            return true;
        });
    }

    private static function amount(mixed $value): ?string
    {
        try {
            $normalized = Money::normalizeOrNull($value);

            return $normalized === null ? null : ltrim($normalized, '-');
        } catch (Throwable) {
            return null;
        }
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
