<?php

declare(strict_types=1);

namespace App\Domain\Returns\Jobs;

use App\Domain\Channels\Contracts\SupportsReturnDecisions;
use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelRateLimiter;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Channels\Support\OutboundWriteGate;
use App\Domain\Returns\Actions\StoreRemoteReturn;
use App\Domain\Returns\Enums\ReturnReason;
use App\Domain\Returns\Enums\ReturnStatus;
use App\Domain\Returns\Exceptions\ReturnActionRejected;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnRequestLine;
use App\Domain\Sync\Support\AdapterReportedFailure;
use App\Domain\Sync\Support\ChannelErrorText;
use App\Domain\Sync\Support\RetryPolicy;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Satıcının iade kararını kanala gönderir — S4. İskelet `PrepareShippingLabel`.
 *
 * SIRA: izin (TAZE, ADR-010) → bağlantı → devre kesici → hız sınırı →
 * kanalın GÜNCEL talebi → hâlâ "karar bekliyor" olan kalemlere BİR istek.
 *
 * BİR KEZ: kalem istekten HEMEN ÖNCE `pending → remote_state_unknown`
 * koşullu güncellemesiyle "gitmiş olabilir" işaretlenir. Kuyruk tekrarı bu
 * kalemi `pending` bulmaz ve göndermez. Başarı → `submitted`; kesin ret →
 * `failed` (istek iz bırakmadı, yeniden denenebilir); 429 → istek
 * işlenmedi, `pending`'e döner ve iş bekler; ağ hatası/5xx → sonuç
 * BELİRSİZ: kanal yeniden okunur, kalem karar bekliyordan çıktıysa
 * `submitted`, çıkmadıysa `remote_state_unknown` kalır ve satıcıya
 * söylenir — kör tekrar YOK.
 *
 * Kuyrukta kişisel veri yok — yalnız talep ve kiracı kimliği.
 */
final class SubmitReturnDecision implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $requestId,
        public readonly string $tenantId,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(
        AdapterRegistry $registry,
        OutboundWriteGate $writes,
        ChannelErrorText $errorText,
        ?CircuitBreaker $breaker = null,
        ?ChannelRateLimiter $limiter = null,
    ): void {
        TenantContext::set($this->tenantId);

        try {
            $this->submit($registry, $writes, $errorText, $breaker ?? app(CircuitBreaker::class), $limiter ?? app(ChannelRateLimiter::class));
        } finally {
            TenantContext::clear();
        }
    }

    public function failed(?Throwable $e): void
    {
        TenantContext::set($this->tenantId);

        try {
            // Gönderilmemiş (pending) kalem başarısız; gitmiş olabilecek
            // (remote_state_unknown) kalem öyle KALIR — yalan söylenmez.
            ReturnRequestLine::query()
                ->where('return_request_id', $this->requestId)
                ->where('decision_status', ReturnRequestLine::DECISION_PENDING)
                ->update([
                    'decision_status' => ReturnRequestLine::DECISION_FAILED,
                    'decision_reason_code' => ReturnReason::RETURN_ACTION_FAILED->value,
                    'decision_error' => mb_substr($e?->getMessage() ?? '', 0, 500),
                    'decision_requested_at' => null,
                    'updated_at' => now(),
                ]);
        } finally {
            TenantContext::clear();
        }
    }

    private function submit(AdapterRegistry $registry, OutboundWriteGate $writes, ChannelErrorText $errorText, CircuitBreaker $breaker, ChannelRateLimiter $limiter): void
    {
        $request = ReturnRequest::query()->with('connection')->find($this->requestId);

        if ($request === null) {
            return;
        }

        $pending = $this->pendingLines();

        if ($pending->isEmpty()) {
            return;
        }

        // İZİN TAZE OKUNUR: iş kuyruktayken izin kapatıldıysa kanala hiçbir şey gitmez.
        if (! $writes->allows($request->channel_connection_id, $this->tenantId, OutboundWrite::RETURN_DECISION)) {
            $this->fail($pending, ReturnReason::RETURN_ACTION_DISABLED);

            return;
        }

        $connection = $request->connection;

        if ($connection === null || $connection->status !== 'active') {
            $this->fail($pending, ReturnReason::RETURN_CHANNEL_UNAVAILABLE);

            return;
        }

        if (! $breaker->allows($connection->id)) {
            $this->release(CircuitBreaker::PAUSE_SECONDS);

            return;
        }

        $adapter = $registry->for($connection);

        if (! $adapter instanceof SupportsReturnDecisions) {
            $this->fail($pending, ReturnReason::RETURN_ACTION_UNSUPPORTED);

            return;
        }

        if (! $limiter->attempt($connection->id, $adapter->rateLimitProfile())) {
            $this->release(max($limiter->secondsUntilAvailable($connection->id, $adapter->rateLimitProfile()), 1));

            return;
        }

        // KANALIN GÜNCEL DURUMU: ekran bayat olabilir (müşteri iptal etti,
        // satıcı panelden karar verdi, kanal otomatik kabul etti).
        try {
            $remote = $adapter->fetchReturn($request->external_claim_id);
        } catch (Throwable $e) {
            $this->retryOrFail($e, $adapter, $breaker, $connection->id, $pending, $errorText->redact($connection, $e->getMessage()));

            return;
        }

        if ($remote === null) {
            $this->fail($pending, ReturnReason::RETURN_STATE_CHANGED);

            return;
        }

        app(StoreRemoteReturn::class)->run($connection, $remote);

        $stale = $this->pendingLines()->filter(fn (ReturnRequestLine $l) => $l->statusEnum() !== ReturnStatus::AWAITING_ACTION);
        $this->fail($stale, ReturnReason::RETURN_STATE_CHANGED);

        $ready = $this->pendingLines();

        if ($ready->isEmpty()) {
            return;
        }

        $decision = $ready->first()->decision;

        foreach ($decision === ReturnRequestLine::DECISION_REJECT ? $ready->map(fn ($l) => new Collection([$l])) : [$ready] as $batch) {
            // "Gitmiş olabilir" işareti İSTEKTEN ÖNCE.
            $claimed = ReturnRequestLine::query()
                ->whereIn('id', $batch->pluck('id'))
                ->where('decision_status', ReturnRequestLine::DECISION_PENDING)
                ->update(['decision_status' => ReturnRequestLine::DECISION_UNKNOWN, 'updated_at' => now()]);

            if ($claimed !== $batch->count()) {
                continue;
            }

            try {
                if ($decision === ReturnRequestLine::DECISION_REJECT) {
                    $line = $batch->first();
                    $adapter->rejectReturnItem($request->external_claim_id, $line->external_item_id, (string) $line->decision_issue_reason_id, (string) $line->decision_description);
                } else {
                    $adapter->approveReturnItems($request->external_claim_id, $batch->pluck('external_item_id')->values()->all());
                }
            } catch (ReturnActionRejected $e) {
                // Kesin ret: istek kanalda iz bırakmadı.
                $this->setLines($batch, [
                    'decision_status' => ReturnRequestLine::DECISION_FAILED,
                    'decision_reason_code' => ReturnReason::RETURN_ACTION_REJECTED->value,
                    'decision_error' => mb_substr((string) $errorText->redact($connection, $e->getMessage()), 0, 500),
                    'decision_requested_at' => null,
                ]);

                continue;
            } catch (RequestException $e) {
                if ($e->response->status() === 429) {
                    // Kanal isteği İŞLEMEDİ: güvenle yeniden kuyruğa.
                    $this->setLines($batch, ['decision_status' => ReturnRequestLine::DECISION_PENDING]);
                    $this->release(max((int) $e->response->header('Retry-After'), 30));

                    return;
                }

                $this->settleAmbiguous($adapter, $connection, $request, $batch, $errorText->redact($connection, $e->getMessage()));

                continue;
            } catch (Throwable $e) {
                $breaker->recordFailure($connection->id, AdapterReportedFailure::classify($e, $adapter));
                $this->settleAmbiguous($adapter, $connection, $request, $batch, $errorText->redact($connection, $e->getMessage()));

                continue;
            }

            $breaker->recordSuccess($connection->id);
            $this->setLines($batch, [
                'decision_status' => ReturnRequestLine::DECISION_SUBMITTED,
                'decision_reason_code' => null,
                'decision_error' => null,
                'decided_at' => now(),
            ]);
        }

        // Sonucu hemen tazele (en iyi çaba): kabul/ret kanalda görünür.
        try {
            if (($fresh = $adapter->fetchReturn($request->external_claim_id)) !== null) {
                app(StoreRemoteReturn::class)->run($connection, $fresh);
            }
        } catch (Throwable) {
            // Yoklama sonraki turda görür.
        }
    }

    /**
     * Sonucu belirsiz istek: kanalı yeniden oku; kalem karar bekliyordan
     * çıktıysa istek ulaşmıştır, çıkmadıysa BİLİNMİYOR kalır.
     *
     * @param  Collection<int, ReturnRequestLine>  $batch
     */
    private function settleAmbiguous(SupportsReturnDecisions $adapter, $connection, ReturnRequest $request, Collection $batch, ?string $detail): void
    {
        try {
            if (($remote = $adapter->fetchReturn($request->external_claim_id)) !== null) {
                app(StoreRemoteReturn::class)->run($connection, $remote);
            }
        } catch (Throwable) {
            // Okunamadı: belirsiz kalır.
        }

        foreach ($batch as $line) {
            $line->refresh();

            if ($line->decision_status === ReturnRequestLine::DECISION_UNKNOWN) {
                $line->forceFill([
                    'decision_reason_code' => ReturnReason::RETURN_REMOTE_STATE_UNKNOWN->value,
                    'decision_error' => $detail === null ? null : mb_substr($detail, 0, 500),
                ])->save();
            }
        }
    }

    /** @param  Collection<int, ReturnRequestLine>  $pending */
    private function retryOrFail(Throwable $e, SupportsReturnDecisions $adapter, CircuitBreaker $breaker, string $connectionId, Collection $pending, ?string $detail): void
    {
        $class = AdapterReportedFailure::classify($e, $adapter);
        $breaker->recordFailure($connectionId, $class);

        $delay = RetryPolicy::delayFor($class, $this->attempts(), AdapterReportedFailure::retryAfterOf($e));

        if ($delay !== null) {
            $this->release($delay);

            return;
        }

        $this->setLines($pending, [
            'decision_status' => ReturnRequestLine::DECISION_FAILED,
            'decision_reason_code' => ReturnReason::RETURN_ACTION_FAILED->value,
            'decision_error' => $detail === null ? null : mb_substr($detail, 0, 500),
            'decision_requested_at' => null,
        ]);
    }

    /** @return Collection<int, ReturnRequestLine> */
    private function pendingLines(): Collection
    {
        return ReturnRequestLine::query()
            ->where('return_request_id', $this->requestId)
            ->where('decision_status', ReturnRequestLine::DECISION_PENDING)
            ->orderBy('id')
            ->get();
    }

    /** @param  Collection<int, ReturnRequestLine>  $lines */
    private function fail(Collection $lines, ReturnReason $reason): void
    {
        $this->setLines($lines, [
            'decision_status' => ReturnRequestLine::DECISION_FAILED,
            'decision_reason_code' => $reason->value,
            'decision_requested_at' => null,
        ]);
    }

    /**
     * @param  Collection<int, ReturnRequestLine>  $lines
     * @param  array<string, mixed>  $values
     */
    private function setLines(Collection $lines, array $values): void
    {
        if ($lines->isEmpty()) {
            return;
        }

        ReturnRequestLine::query()->whereIn('id', $lines->pluck('id'))->update($values + ['updated_at' => now()]);
    }
}
