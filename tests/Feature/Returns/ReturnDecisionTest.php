<?php

declare(strict_types=1);

namespace Tests\Feature\Returns;

use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\OutboundWriteGate;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Returns\Actions\RequestReturnDecision;
use App\Domain\Returns\Enums\ReturnReason;
use App\Domain\Returns\Jobs\SubmitReturnDecision;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnRequestLine;
use App\Domain\Sync\Support\ChannelErrorText;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S4 · iade kararı (onay / red) — dışarı yazma.
 *
 * KİMLİK DOĞRULANDI ≠ KARAR YAZMAYA YETKİLİ · bayat ekran körlemesine
 * karar gönderemez · sonucu belirsiz istek körlemesine tekrarlanmaz.
 */
final class ReturnDecisionTest extends TestCase
{
    use BuildsReturnScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReturns();
    }

    /** @return array{0: Tenant, 1: ChannelConnection, 2: ReturnRequest} */
    private function awaiting(bool $allowed = true, array $items = [['ci-1', '71', 'TS-1', 'WaitingInAction']]): array
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->soldOrder($tenant, $connection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 3]]);
        $this->claim('c-1', '5001', $items);
        $this->syncReturns($connection);
        $this->allow($connection, OutboundWrite::RETURN_DECISION, $allowed);

        return [$tenant, $connection, TenantContext::runFor($tenant->id, fn () => ReturnRequest::query()->sole())];
    }

    private function decide(Tenant $tenant, ReturnRequest $request, string $decision, ?string $reason = null, ?string $text = null): ?ReturnReason
    {
        return TenantContext::runFor($tenant->id, fn () => app(RequestReturnDecision::class)->run($request->fresh(['connection']), $decision, $reason, $text, null));
    }

    private function runJob(Tenant $tenant, ReturnRequest $request): SubmitReturnDecision
    {
        Redis::connection()->flushdb();
        $job = (new SubmitReturnDecision($request->id, $tenant->id))->withFakeQueueInteractions();
        $job->handle(app(AdapterRegistry::class), app(OutboundWriteGate::class), app(ChannelErrorText::class));

        return $job;
    }

    private function line(Tenant $tenant, string $item = 'ci-1'): ReturnRequestLine
    {
        return TenantContext::runFor($tenant->id, fn () => ReturnRequestLine::query()->where('external_item_id', $item)->sole());
    }

    #[Test]
    public function approval_is_sent_once_and_the_accepted_return_then_restocks_through_the_ledger(): void
    {
        [$tenant, $connection, $request] = $this->awaiting();

        $this->assertNull($this->decide($tenant, $request, 'approve'));

        $this->assertSame(['PUT /integration/order/sellers/'.self::SELLER.'/claims/c-1/items/approve'], $this->sent);
        $line = $this->line($tenant);
        $this->assertSame(ReturnRequestLine::DECISION_SUBMITTED, $line->decision_status);
        $this->assertSame('accepted', $line->status, 'Karar sonrası kanal yeniden okundu.');

        $this->syncReturns($connection);
        $this->assertSame(ReturnRequestLine::RESTOCK_RESTORED, $this->line($tenant)->restock_status);
        $this->assertSame(1, $this->returnMovements($tenant));
    }

    #[Test]
    public function rejection_requires_a_supported_reason_and_is_sent_once_per_item(): void
    {
        [$tenant, , $request] = $this->awaiting(items: [['ci-1', '71', 'TS-1', 'WaitingInAction'], ['ci-2', '71', 'TS-1', 'WaitingInAction']]);

        $this->assertSame(ReturnReason::RETURN_REASON_REQUIRED, $this->decide($tenant, $request, 'reject', '2101', 'değişim'));
        $this->assertSame(ReturnReason::RETURN_REASON_REQUIRED, $this->decide($tenant, $request, 'reject', '451', '   '));
        $this->assertSame([], $this->sent);

        $this->assertNull($this->decide($tenant, $request, 'reject', '451', 'Ürünü analize gönderiyoruz'));

        $this->assertCount(2, $this->sent);
        $this->assertSame(ReturnRequestLine::DECISION_SUBMITTED, $this->line($tenant, 'ci-1')->decision_status);
        $this->assertSame('disputed', $this->line($tenant, 'ci-2')->status);
        $this->assertSame(0, $this->returnMovements($tenant), 'Red stok getirmez.');
    }

    #[Test]
    public function credentials_alone_do_not_authorize_a_decision(): void
    {
        [$tenant, , $request] = $this->awaiting(allowed: false);

        $this->assertSame(ReturnReason::RETURN_ACTION_DISABLED, $this->decide($tenant, $request, 'approve'));
        $this->assertSame([], $this->sent);
        $this->assertNull($this->line($tenant)->decision_status);
    }

    #[Test]
    public function permission_revoked_after_dispatch_stops_the_queued_decision(): void
    {
        Queue::fake();
        [$tenant, $connection, $request] = $this->awaiting();

        $this->assertNull($this->decide($tenant, $request, 'approve'));
        $this->allow($connection, OutboundWrite::RETURN_DECISION, false);
        $this->runJob($tenant, $request);

        $this->assertSame([], $this->sent);
        $line = $this->line($tenant);
        $this->assertSame(ReturnRequestLine::DECISION_FAILED, $line->decision_status);
        $this->assertSame(ReturnReason::RETURN_ACTION_DISABLED->value, $line->decision_reason_code);
    }

    #[Test]
    public function stale_screen_does_not_send_when_the_claim_already_changed_on_the_channel(): void
    {
        Queue::fake();
        [$tenant, , $request] = $this->awaiting();

        $this->assertNull($this->decide($tenant, $request, 'approve'));
        // Kanal kendiliğinden kabul etti (48 saat kuralı) — ekran bayat.
        $this->setClaimItems('c-1', ['ci-1'], 'Accepted');
        $this->runJob($tenant, $request);

        $this->assertSame([], $this->sent);
        $this->assertSame(ReturnReason::RETURN_STATE_CHANGED->value, $this->line($tenant)->decision_reason_code);
    }

    #[Test]
    public function decision_on_a_non_actionable_claim_is_refused(): void
    {
        [$tenant, , $request] = $this->awaiting(items: [['ci-1', '71', 'TS-1', 'Created']]);

        $this->assertSame(ReturnReason::RETURN_ACTION_NOT_ALLOWED, $this->decide($tenant, $request, 'approve'));
        $this->assertSame(ReturnReason::RETURN_ACTION_NOT_ALLOWED, $this->decide($tenant, $request, 'refund-everything'));
        $this->assertSame([], $this->sent);
    }

    #[Test]
    public function double_click_and_duplicate_jobs_send_a_single_request(): void
    {
        Queue::fake();
        [$tenant, , $request] = $this->awaiting();

        $this->assertNull($this->decide($tenant, $request, 'approve'));
        $this->assertSame(ReturnReason::RETURN_ALREADY_PROCESSED, $this->decide($tenant, $request, 'approve'));

        $this->runJob($tenant, $request);
        $this->runJob($tenant, $request);

        $this->assertCount(1, $this->sent);
    }

    #[Test]
    public function timeout_after_possible_acceptance_is_not_blindly_retried(): void
    {
        Queue::fake();
        [$tenant, $connection, $request] = $this->awaiting();
        $this->onApprove = $this->timeout();

        $this->assertNull($this->decide($tenant, $request, 'approve'));
        $this->runJob($tenant, $request);
        $this->runJob($tenant, $request);

        $this->assertCount(1, $this->sent, 'Belirsiz istek kendiliğinden tekrarlanmaz.');
        $line = $this->line($tenant);
        $this->assertSame(ReturnRequestLine::DECISION_UNKNOWN, $line->decision_status);
        $this->assertSame(ReturnReason::RETURN_REMOTE_STATE_UNKNOWN->value, $line->decision_reason_code);

        // İstek aslında ulaşmıştı: yoklama kalemi kabul edilmiş görür ve çözer.
        $this->setClaimItems('c-1', ['ci-1'], 'Accepted');
        $this->syncReturns($connection);
        $this->assertSame(ReturnRequestLine::DECISION_SUBMITTED, $this->line($tenant)->decision_status);
    }

    #[Test]
    public function rate_limited_request_is_requeued_without_being_counted_as_sent(): void
    {
        Queue::fake();
        [$tenant, , $request] = $this->awaiting();
        $calls = 0;
        $this->onApprove = function () use (&$calls) {
            return ++$calls === 1 ? Http::response(['errors' => 'slow'], 429, ['Retry-After' => '5']) : null;
        };

        $this->assertNull($this->decide($tenant, $request, 'approve'));
        $job = $this->runJob($tenant, $request);
        $job->assertReleased();
        $this->assertSame(ReturnRequestLine::DECISION_PENDING, $this->line($tenant)->decision_status);

        $this->runJob($tenant, $request);
        $this->assertSame(ReturnRequestLine::DECISION_SUBMITTED, $this->line($tenant)->decision_status);
        $this->assertCount(2, $this->sent);
    }

    #[Test]
    public function definitive_rejection_by_the_channel_frees_the_line_for_a_new_decision(): void
    {
        Queue::fake();
        [$tenant, , $request] = $this->awaiting();
        $this->onApprove = fn () => Http::response(['errors' => [['message' => 'invalid claim item']]], 400);

        $this->assertNull($this->decide($tenant, $request, 'approve'));
        $this->runJob($tenant, $request);

        $line = $this->line($tenant);
        $this->assertSame(ReturnRequestLine::DECISION_FAILED, $line->decision_status);
        $this->assertSame(ReturnReason::RETURN_ACTION_REJECTED->value, $line->decision_reason_code);
        $this->assertNull($line->decision_requested_at);

        $this->onApprove = null;
        $this->assertNull($this->decide($tenant, $request, 'approve'));
    }

    #[Test]
    public function server_error_without_state_change_is_reported_as_unknown(): void
    {
        Queue::fake();
        [$tenant, , $request] = $this->awaiting();
        $this->onApprove = fn () => Http::response(['errors' => 'boom'], 502);

        $this->assertNull($this->decide($tenant, $request, 'approve'));
        $this->runJob($tenant, $request);

        $this->assertSame(ReturnRequestLine::DECISION_UNKNOWN, $this->line($tenant)->decision_status);
        $this->assertCount(1, $this->sent);
    }
}
