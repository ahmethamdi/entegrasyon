<?php

declare(strict_types=1);

namespace App\Domain\Returns\Actions;

use App\Domain\Channels\Contracts\SupportsReturnDecisions;
use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\OutboundWriteGate;
use App\Domain\Returns\Enums\ReturnReason;
use App\Domain\Returns\Enums\ReturnStatus;
use App\Domain\Returns\Jobs\SubmitReturnDecision;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnRequestLine;
use App\Support\Tenancy\TenantContext;

/**
 * Panelden gelen iade kararını (onay / red) DOĞRULAR ve kuyruğa alır — S4.
 *
 * Ağ isteği YAPMAZ. Kanala yazma kuyruk işindedir ve iş, göndermeden önce
 * izni (taze), kanalın GÜNCEL durumunu ve geçişi yeniden doğrular — bayat
 * ekran körlemesine karar gönderemez.
 *
 * TALEP KİRACI SCOPE'U ALTINDA bulunur (çağıran controller): başka kiracının
 * ya da var olmayan talebin cevabı AYNI 404'tür.
 *
 * ÇİFT TIKLAMA: kalemler `decision_requested_at IS NULL` koşullu
 * güncellemeyle talep edilir; ikinci istek bu yarışı kaybeder.
 */
final class RequestReturnDecision
{
    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly OutboundWriteGate $writes,
    ) {}

    /** @return ReturnReason|null null = kuyruğa alındı */
    public function run(ReturnRequest $request, string $decision, ?string $issueReasonId, ?string $description, ?string $userId): ?ReturnReason
    {
        $connection = $request->connection;

        if ($connection === null || $connection->status !== 'active') {
            return ReturnReason::RETURN_CHANNEL_UNAVAILABLE;
        }

        $adapter = $this->adapters->for($connection);

        if (! $adapter instanceof SupportsReturnDecisions) {
            return ReturnReason::RETURN_ACTION_UNSUPPORTED;
        }

        // Erken ve dostça ret; asıl kapı işin içinde yeniden sorulur.
        if (! $this->writes->allows($connection->id, $request->tenant_id, OutboundWrite::RETURN_DECISION)) {
            return ReturnReason::RETURN_ACTION_DISABLED;
        }

        if ($decision === ReturnRequestLine::DECISION_REJECT) {
            $allowed = array_column($adapter->returnRejectionReasons(), 'id');
            $description = trim((string) $description);

            if ($issueReasonId === null || ! in_array($issueReasonId, $allowed, true) || $description === '' || mb_strlen($description) > 500) {
                return ReturnReason::RETURN_REASON_REQUIRED;
            }
        } elseif ($decision !== ReturnRequestLine::DECISION_APPROVE) {
            return ReturnReason::RETURN_ACTION_NOT_ALLOWED;
        }

        $actionable = $request->lines()
            ->where('status', ReturnStatus::AWAITING_ACTION->value)
            ->get();

        if ($actionable->isEmpty()) {
            return ReturnReason::RETURN_ACTION_NOT_ALLOWED;
        }

        $now = now();

        // Sonucu belirsiz önceki istek satıcının AÇIK tekrarıyla yeniden
        // gönderilebilir: iş göndermeden önce kanalın güncel durumunu okur
        // ve kalem artık "karar bekliyor" değilse hiçbir şey göndermez.
        $claimed = ReturnRequestLine::query()
            ->whereIn('id', $actionable->pluck('id'))
            ->where('status', ReturnStatus::AWAITING_ACTION->value)
            ->where(fn ($q) => $q->whereNull('decision_status')
                ->orWhereIn('decision_status', [ReturnRequestLine::DECISION_FAILED, ReturnRequestLine::DECISION_UNKNOWN]))
            ->update([
                'decision' => $decision,
                'decision_status' => ReturnRequestLine::DECISION_PENDING,
                'decision_reason_code' => null,
                'decision_error' => null,
                'decision_issue_reason_id' => $decision === ReturnRequestLine::DECISION_REJECT ? $issueReasonId : null,
                'decision_description' => $decision === ReturnRequestLine::DECISION_REJECT ? $description : null,
                'decision_requested_at' => $now,
                'decided_by' => $userId,
                'updated_at' => $now,
            ]);

        if ($claimed === 0) {
            return ReturnReason::RETURN_ALREADY_PROCESSED;
        }

        SubmitReturnDecision::dispatch($request->id, TenantContext::idOrFail());

        return null;
    }
}
