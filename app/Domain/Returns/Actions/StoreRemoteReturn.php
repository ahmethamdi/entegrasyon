<?php

declare(strict_types=1);

namespace App\Domain\Returns\Actions;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderLine;
use App\Domain\Returns\Enums\ReturnReason;
use App\Domain\Returns\Enums\ReturnStatus;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnRequestLine;
use App\Domain\Returns\Support\RemoteReturn;
use App\Domain\Returns\Support\RemoteReturnItem;
use App\Domain\Returns\Support\ReturnInterpretation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kanalın iade talebini kanonik kayda yazar — idempotent (S4).
 *
 * KİMLİK: talep `(bağlantı, external_claim_id)`, kalem `(bağlantı,
 * external_item_id)`. Aynı talep yoklamada, örtüşen pencerede, tekil
 * tazelemede ya da karar öncesi okumada gelse de AYNI satırları günceller.
 *
 * HAM ≠ YORUM: `channel_status` kanalın kelimesidir, `status` yorumdur.
 * Tanınmayan durum UNKNOWN olur ve stok hareketi üretmez.
 *
 * FİZİKSEL VARIŞ: kalem kanalın "depoya ulaştı" durumunda görülürse
 * `receipt_proven_at` yazılır ve bir daha silinmez — sonraki `Accepted`
 * stok iadesinin kanıtıdır.
 *
 * EŞLEŞME TAHMİN ETMEZ: sipariş paket kimliğiyle, yoksa numarasıyla; kalem
 * önce kanal satır kimliğiyle, yoksa SKU/barkodla ve YALNIZ TEK aday varsa.
 * Birden çok aday `ambiguous`, aday yoksa `line_missing` / `order_missing`.
 * Stoğu dönmüş kalemin eşleşmesi bir daha değiştirilmez.
 */
final class StoreRemoteReturn
{
    public function run(ChannelConnection $connection, RemoteReturn $remote): ReturnRequest
    {
        return DB::transaction(function () use ($connection, $remote): ReturnRequest {
            $now = now();

            $request = ReturnRequest::query()->firstOrNew([
                'channel_connection_id' => $connection->id,
                'external_claim_id' => $remote->externalClaimId,
            ]);

            $request->forceFill([
                'tenant_id' => $connection->tenant_id,
                'channel_type_code' => $connection->channel_type_code,
                'external_order_number' => $remote->orderNumber ?? $request->external_order_number,
                'external_package_id' => $remote->packageId ?? $request->external_package_id,
                'claim_type' => $remote->claimType ?? $request->claim_type,
                'claimed_at' => $remote->claimedAt ?? $request->claimed_at,
                'remote_modified_at' => $remote->modifiedAt ?? $request->remote_modified_at,
                'last_seen_at' => $now,
                'match_status' => $request->match_status ?? 'order_missing',
                'status' => $request->status ?? ReturnStatus::UNKNOWN->value,
            ])->save();

            foreach ($remote->items as $item) {
                $this->storeItem($connection, $request, $item);
            }

            $this->match($connection, $request);

            return $request->refresh();
        }, attempts: 3);
    }

    private function storeItem(ChannelConnection $connection, ReturnRequest $request, RemoteReturnItem $item): void
    {
        $line = ReturnRequestLine::query()
            ->where('channel_connection_id', $connection->id)
            ->where('external_item_id', $item->externalItemId)
            ->lockForUpdate()
            ->first() ?? new ReturnRequestLine([
                'channel_connection_id' => $connection->id,
                'external_item_id' => $item->externalItemId,
                'match_status' => 'order_missing',
                'restock_status' => ReturnRequestLine::RESTOCK_NOT_APPLICABLE,
            ]);

        $status = ReturnInterpretation::status($connection->channel_type_code, $item->channelStatus);

        $line->forceFill([
            'tenant_id' => $connection->tenant_id,
            'return_request_id' => $request->id,
            'external_order_line_id' => $item->externalOrderLineId ?? $line->external_order_line_id,
            'sku' => $item->sku ?? $line->sku,
            'barcode' => $item->barcode ?? $line->barcode,
            'product_name' => $item->productName ?? $line->product_name,
            'channel_status' => $item->channelStatus,
            'status' => $status->value,
            'reason_code' => $item->reasonCode,
            'reason_text' => $item->reasonText,
            'customer_note' => $item->customerNote,
            'accept_detail' => $item->acceptDetail,
        ]);

        // ADET: stoğu dönmüş kalemin adedi DEĞİŞTİRİLMEZ — kanal sonradan
        // farklı adet bildirirse çakışma işaretlenir, defter yeniden yazılmaz.
        if ($line->restock_status === ReturnRequestLine::RESTOCK_RESTORED && $line->exists && $line->quantity !== $item->quantity) {
            $line->restock_reason_code = ReturnReason::RETURN_QUANTITY_CONFLICT->value;
        } else {
            $line->quantity = $item->quantity;
        }

        if ($line->receipt_proven_at === null && ReturnInterpretation::provesReceipt($connection->channel_type_code, $item->channelStatus)) {
            $line->receipt_proven_at = now();
        }

        $this->settleDecision($line, $status);

        $line->save();
    }

    /**
     * Sonucu belirsiz kararın kanalda sonuçlandığı görülürse çözülür.
     *
     * Kanal kalemi "karar bekliyor"dan çıkardıysa istek ulaşmıştır (ya da
     * karar panelden verilmiştir); her iki durumda kör tekrar gereksizdir.
     */
    /** Kayıttaki talebi yeniden eşler (sipariş sonradan geldiyse) — kanala gitmez. */
    public function rematch(ChannelConnection $connection, string $requestId): void
    {
        DB::transaction(function () use ($connection, $requestId): void {
            $request = ReturnRequest::query()->lockForUpdate()->find($requestId);

            if ($request !== null) {
                $this->match($connection, $request);
            }
        }, attempts: 3);
    }

    private function settleDecision(ReturnRequestLine $line, ReturnStatus $status): void
    {
        if (! in_array($line->decision_status, [ReturnRequestLine::DECISION_UNKNOWN, ReturnRequestLine::DECISION_SUBMITTED], true)) {
            return;
        }

        if ($status === ReturnStatus::AWAITING_ACTION) {
            return;
        }

        $line->forceFill([
            'decision_status' => ReturnRequestLine::DECISION_SUBMITTED,
            'decision_reason_code' => null,
            'decided_at' => $line->decided_at ?? now(),
        ]);
    }

    private function match(ChannelConnection $connection, ReturnRequest $request): void
    {
        $orders = $this->candidateOrders($connection, $request);
        $lines = ReturnRequestLine::query()->where('return_request_id', $request->id)->lockForUpdate()->get();

        $candidateLines = $orders->isEmpty()
            ? collect()
            : OrderLine::query()->whereIn('order_id', $orders->pluck('id'))->get();

        foreach ($lines as $line) {
            if ($line->restock_status === ReturnRequestLine::RESTOCK_RESTORED) {
                continue;
            }

            if ($orders->isEmpty()) {
                $line->forceFill(['match_status' => 'order_missing', 'order_line_id' => null, 'variant_id' => null])->save();

                continue;
            }

            $found = $this->candidatesFor($line, $candidateLines);

            if ($found->count() === 1) {
                $orderLine = $found->first();
                $line->forceFill(['match_status' => 'matched', 'order_line_id' => $orderLine->id, 'variant_id' => $orderLine->variant_id])->save();
            } else {
                $line->forceFill([
                    'match_status' => $found->isEmpty() ? 'line_missing' : 'ambiguous',
                    'order_line_id' => null,
                    'variant_id' => null,
                ])->save();
            }
        }

        $lines = $lines->fresh();
        $matchedOrders = OrderLine::query()->whereIn('id', $lines->pluck('order_line_id')->filter())->pluck('order_id')->unique();
        $statuses = $lines->map(fn (ReturnRequestLine $l) => $l->statusEnum())->all();

        $request->forceFill([
            'order_id' => $matchedOrders->count() === 1 ? $matchedOrders->first() : ($orders->count() === 1 ? $orders->first()->id : null),
            'match_status' => match (true) {
                $orders->isEmpty() => 'order_missing',
                $lines->every(fn ($l) => $l->match_status === 'matched') => 'matched',
                $lines->contains(fn ($l) => $l->match_status === 'ambiguous') => 'ambiguous',
                default => 'partial',
            },
            'status' => ReturnStatus::aggregate($statuses)->value,
            'channel_status' => mb_substr($lines->pluck('channel_status')->filter()->unique()->implode(','), 0, 64) ?: null,
        ])->save();
    }

    /** @return Collection<int, Order> */
    private function candidateOrders(ChannelConnection $connection, ReturnRequest $request): Collection
    {
        if ($request->external_package_id !== null) {
            $byPackage = Order::query()
                ->where('channel_connection_id', $connection->id)
                ->where('external_id', $request->external_package_id)
                ->get();

            if ($byPackage->isNotEmpty()) {
                return $byPackage;
            }
        }

        if ($request->external_order_number === null) {
            return collect();
        }

        // Numara birden çok pakete bölünmüş olabilir: hepsi aday, kalem
        // düzeyinde yalnız TEK aday kabul edilir.
        $byNumber = Order::query()
            ->where('channel_connection_id', $connection->id)
            ->where('external_number', $request->external_order_number)
            ->get();

        if ($byNumber->isNotEmpty()) {
            return $byNumber;
        }

        // HB siparişi numarayla tutar (external_id = sipariş numarası).
        return Order::query()
            ->where('channel_connection_id', $connection->id)
            ->where('external_id', $request->external_order_number)
            ->get();
    }

    /**
     * @param  Collection<int, OrderLine>  $orderLines
     * @return Collection<int, OrderLine>
     */
    private function candidatesFor(ReturnRequestLine $line, Collection $orderLines): Collection
    {
        if ($line->external_order_line_id !== null) {
            $byId = $orderLines->filter(fn (OrderLine $o) => (string) $o->external_line_id === $line->external_order_line_id);

            if ($byId->isNotEmpty()) {
                return $byId->values();
            }
        }

        $keys = array_values(array_filter([
            $line->sku === null ? null : mb_strtoupper($line->sku),
            $line->barcode === null ? null : mb_strtoupper($line->barcode),
        ]));

        if ($keys === []) {
            return collect();
        }

        return $orderLines
            ->filter(fn (OrderLine $o) => $o->sku !== null && in_array(mb_strtoupper((string) $o->sku), $keys, true))
            ->values();
    }
}
