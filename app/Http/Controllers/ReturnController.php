<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Contracts\SupportsReturnDecisions;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Returns\Actions\RequestReturnDecision;
use App\Domain\Returns\Enums\ReturnStatus;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnRequestLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * S4 · İade talepleri ekranı.
 *
 * Talepler KİRACI SCOPE'U altında okunur; model bağlaması da öyle — başka
 * kiracının ya da olmayan talebin cevabı AYNI 404'tür.
 *
 * "TALEP VAR" ≠ "STOK DÖNDÜ": ekran stok durumunu kalemin defter kaydından
 * (`restock_status = restored`) gösterir, kanal durumundan değil.
 */
final class ReturnController extends Controller
{
    public function index(Request $request, AdapterRegistry $registry): Response
    {
        $filter = $request->query('filter') === 'all' ? 'all' : 'action';

        $query = ReturnRequest::query()
            ->with(['lines' => fn ($q) => $q->orderBy('id'), 'order:id,external_number,external_id', 'connection'])
            ->orderByDesc('claimed_at')
            ->orderByDesc('id');

        if ($filter === 'action') {
            $query->where(fn ($q) => $q
                ->whereIn('status', [ReturnStatus::AWAITING_ACTION->value, ReturnStatus::UNKNOWN->value, ReturnStatus::DISPUTED->value])
                ->orWhereHas('lines', fn ($l) => $l->where('restock_status', ReturnRequestLine::RESTOCK_PENDING)
                    ->orWhereIn('decision_status', [ReturnRequestLine::DECISION_FAILED, ReturnRequestLine::DECISION_UNKNOWN])
                    ->orWhereIn('match_status', ['ambiguous', 'line_missing'])));
        }

        $page = $query->paginate(30)->withQueryString();

        $decisionInfo = [];

        foreach ($page->getCollection()->pluck('connection')->filter()->unique('id') as $connection) {
            $decisionInfo[$connection->id] = $this->decisionInfo($registry, $connection);
        }

        return Inertia::render('Returns/Index', [
            'filter' => $filter,
            'requests' => $page->through(fn (ReturnRequest $r) => $this->present($r, $decisionInfo[$r->channel_connection_id] ?? null)),
        ]);
    }

    public function approve(string $return, RequestReturnDecision $decide, Request $request): RedirectResponse
    {
        // Kiracı scope'u altında: başka kiracının ya da olmayan talep AYNI 404.
        $return = ReturnRequest::query()->with('connection')->findOrFail($return);
        $reason = $decide->run($return, ReturnRequestLine::DECISION_APPROVE, null, null, $request->user()?->id);

        return back()->with($reason === null ? 'success' : 'warning', $reason === null
            ? __('Onay kanala gönderiliyor. Sonuç birkaç dakika içinde burada görünür.')
            : $reason->label());
    }

    public function reject(string $return, RequestReturnDecision $decide, Request $request): RedirectResponse
    {
        $return = ReturnRequest::query()->with('connection')->findOrFail($return);

        $validated = $request->validate([
            'reason_id' => ['required', 'string', 'max:16'],
            'description' => ['required', 'string', 'max:500'],
        ]);

        $reason = $decide->run($return, ReturnRequestLine::DECISION_REJECT, $validated['reason_id'], $validated['description'], $request->user()?->id);

        return back()->with($reason === null ? 'success' : 'warning', $reason === null
            ? __('Red talebi kanala gönderiliyor. Sonuç birkaç dakika içinde burada görünür.')
            : $reason->label());
    }

    /** @return array{supported: bool, enabled: bool, reasons: list<array{id: string, label: string}>} */
    private function decisionInfo(AdapterRegistry $registry, ChannelConnection $connection): array
    {
        try {
            $adapter = $registry->for($connection);
        } catch (Throwable) {
            return ['supported' => false, 'enabled' => false, 'reasons' => []];
        }

        $supported = $adapter instanceof SupportsReturnDecisions;

        return [
            'supported' => $supported,
            'enabled' => $connection->return_decision_enabled === true,
            'reasons' => $supported ? array_map(fn ($r) => ['id' => $r['id'], 'label' => __($r['label'])], $adapter->returnRejectionReasons()) : [],
        ];
    }

    /** @param  array{supported: bool, enabled: bool, reasons: list<array{id: string, label: string}>}|null  $decision */
    private function present(ReturnRequest $r, ?array $decision): array
    {
        $lines = $r->lines;
        $actionable = $lines->filter(fn (ReturnRequestLine $l) => $l->statusEnum() === ReturnStatus::AWAITING_ACTION && ! in_array($l->decision_status, [ReturnRequestLine::DECISION_PENDING, ReturnRequestLine::DECISION_SUBMITTED], true));

        return [
            'id' => $r->id,
            'channel' => $r->channel_type_code,
            'connection' => $r->connection?->label,
            'claimId' => $r->external_claim_id,
            'orderNumber' => $r->external_order_number,
            'orderId' => $r->order_id,
            'matchStatus' => $r->match_status,
            'status' => $r->status,
            'statusLabel' => $r->statusEnum()->label(),
            'claimedAt' => $r->claimed_at?->toIso8601String(),
            'decision' => [
                'supported' => $decision['supported'] ?? false,
                'enabled' => $decision['enabled'] ?? false,
                'available' => ($decision['supported'] ?? false) && $actionable->isNotEmpty(),
                'reasons' => $decision['reasons'] ?? [],
            ],
            'lines' => $lines->map(fn (ReturnRequestLine $l) => [
                'id' => $l->id,
                'product' => $l->product_name ?? $l->sku ?? $l->barcode,
                'sku' => $l->sku,
                'quantity' => $l->quantity,
                'reason' => $l->reason_text,
                'customerNote' => $l->customer_note,
                'channelStatus' => $l->channel_status,
                'status' => $l->status,
                'statusLabel' => $l->statusEnum()->label(),
                'matchStatus' => $l->match_status,
                'receiptProven' => $l->receipt_proven_at !== null,
                'restockStatus' => $l->restock_status,
                'restockReason' => $l->restockReason()?->label(),
                'restockedAt' => $l->restocked_at?->toIso8601String(),
                'decision' => $l->decision,
                'decisionStatus' => $l->decision_status,
                'decisionReason' => $l->decisionReason()?->label(),
            ])->values()->all(),
        ];
    }
}
