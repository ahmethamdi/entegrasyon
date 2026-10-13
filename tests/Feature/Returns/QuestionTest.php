<?php

declare(strict_types=1);

namespace Tests\Feature\Returns;

use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\OutboundWriteGate;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Questions\Actions\RequestQuestionAnswer;
use App\Domain\Questions\Enums\QuestionReason;
use App\Domain\Questions\Enums\QuestionStatus;
use App\Domain\Questions\Jobs\SubmitQuestionAnswer;
use App\Domain\Questions\Models\ChannelQuestion;
use App\Domain\Sync\Support\ChannelErrorText;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S4 · müşteri soruları: içe alma, cevapsız sayacı, cevap gönderme.
 *
 * KUYRUĞA ALINDI ≠ CEVAPLANDI · HTTP 200 ≠ yayında · belirsiz cevap
 * körlemesine yeniden gönderilmez.
 */
final class QuestionTest extends TestCase
{
    use BuildsReturnScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReturns();
    }

    /** @return array{0: Tenant, 1: ChannelConnection, 2: ChannelQuestion} */
    private function waiting(bool $allowed = true): array
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->question('100');
        $this->syncQuestions($connection);
        $this->allow($connection, OutboundWrite::QUESTION_ANSWER, $allowed);

        return [$tenant, $connection, TenantContext::runFor($tenant->id, fn () => ChannelQuestion::query()->sole())];
    }

    private function answer(Tenant $tenant, ChannelQuestion $q, string $text = 'Evet, %100 pamukludur.'): ?QuestionReason
    {
        return TenantContext::runFor($tenant->id, fn () => app(RequestQuestionAnswer::class)->run($q->fresh(['connection']), $text, null));
    }

    private function runJob(Tenant $tenant, ChannelQuestion $q): SubmitQuestionAnswer
    {
        Redis::connection()->flushdb();
        $job = (new SubmitQuestionAnswer($q->id, $tenant->id))->withFakeQueueInteractions();
        $job->handle(app(AdapterRegistry::class), app(OutboundWriteGate::class), app(ChannelErrorText::class));

        return $job;
    }

    private function unanswered(Tenant $tenant): int
    {
        return TenantContext::runFor($tenant->id, fn () => ChannelQuestion::query()->unanswered()->count());
    }

    private function fresh(Tenant $tenant, ChannelQuestion $q): ChannelQuestion
    {
        return TenantContext::runFor($tenant->id, fn () => $q->fresh());
    }

    #[Test]
    public function questions_are_imported_once_without_customer_identity(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->question('100', overrides: ['userName' => 'Ayşe Y.', 'showUserName' => true]);
        $this->question('101', 'ANSWERED', ['answer' => ['id' => 9, 'text' => 'Evet.', 'creationDate' => 1791810000000]]);
        $this->question('102', 'UNANSWERED');

        $this->syncQuestions($connection);
        $this->syncQuestions($connection);

        $rows = TenantContext::runFor($tenant->id, fn () => ChannelQuestion::query()->orderBy('external_question_id')->get());
        $this->assertCount(3, $rows);
        $this->assertSame([QuestionStatus::AWAITING_ANSWER->value, QuestionStatus::ANSWERED->value, QuestionStatus::CLOSED->value], $rows->pluck('status')->all());
        $this->assertSame('Evet.', $rows[1]->answer_body);
        $this->assertStringNotContainsString('Ayşe', json_encode($rows->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $this->unanswered($tenant), 'Süresi dolmuş soru cevapsız sayılmaz — artık cevaplanamaz.');
    }

    #[Test]
    public function unanswered_counter_is_tenant_scoped_and_reflects_answer_progress(): void
    {
        [$tenant, , $q] = $this->waiting();
        [$other, $otherConnection] = $this->trendyolTenant('999002');
        $this->syncQuestions($otherConnection);

        $this->assertSame(1, $this->unanswered($tenant));
        $this->assertSame(1, $this->unanswered($other));

        $this->actingAs($this->owner)->get('/panel')->assertOk()
            ->assertInertia(fn ($page) => $page->where('todos', fn ($todos) => collect($todos)->firstWhere('key', 'unanswered_questions')['count'] === 1));

        Queue::fake();
        $this->assertNull($this->answer($tenant, $q));
        $this->assertSame(0, $this->unanswered($tenant), 'Yoldaki cevap satıcının işi bitti demektir.');
        $this->assertSame(1, $this->unanswered($other));
    }

    #[Test]
    public function answer_is_sent_once_and_only_the_channel_marks_it_answered(): void
    {
        [$tenant, $connection, $q] = $this->waiting();

        $this->assertNull($this->answer($tenant, $q));

        $this->assertSame(['POST /integration/qna/sellers/'.self::SELLER.'/questions/100/answers'], $this->sent);
        $q = $this->fresh($tenant, $q);
        $this->assertSame(ChannelQuestion::ANSWER_SUBMITTED, $q->answer_status);
        $this->assertSame('777', $q->submitted_answer_external_id);
        $this->assertNotSame(QuestionStatus::ANSWERED->value, $q->status, 'HTTP 200 ≠ yayında.');

        $this->questions['100']['status'] = 'ANSWERED';
        $this->questions['100']['answer'] = ['id' => 777, 'text' => 'Evet, %100 pamukludur.', 'creationDate' => 1791810000000];
        $this->travel(15)->minutes();
        $this->syncQuestions($connection);

        $q = $this->fresh($tenant, $q);
        $this->assertSame(QuestionStatus::ANSWERED->value, $q->status);
        $this->assertSame(0, $this->unanswered($tenant));
    }

    #[Test]
    public function answer_rejected_by_the_channel_makes_the_question_unanswered_again(): void
    {
        [$tenant, $connection, $q] = $this->waiting();
        $this->assertNull($this->answer($tenant, $q));

        // Yasak kelime: kanal cevabı reddeder, soru yeniden cevap bekler.
        $this->questions['100']['status'] = 'WAITING_FOR_ANSWER';
        $this->questions['100']['rejectedAnswer'] = ['id' => 777, 'text' => 'x', 'reason' => 'Yasaklı kelime', 'creationDate' => 1791810000000];
        $this->travel(15)->minutes();
        $this->syncQuestions($connection);

        $q = $this->fresh($tenant, $q);
        $this->assertSame(ChannelQuestion::ANSWER_REJECTED, $q->answer_status);
        $this->assertSame(QuestionReason::QUESTION_ANSWER_REJECTED->value, $q->answer_reason_code);
        $this->assertSame(1, $this->unanswered($tenant));
        $this->assertNull($this->answer($tenant, $q, 'Ürünümüz tamamen pamuktur.'), 'Reddedilen cevaptan sonra yeniden cevaplanabilir.');
    }

    #[Test]
    public function credentials_alone_do_not_authorize_answering(): void
    {
        [$tenant, , $q] = $this->waiting(allowed: false);

        $this->assertSame(QuestionReason::QUESTION_ACTION_DISABLED, $this->answer($tenant, $q));
        $this->assertSame([], $this->sent);
    }

    #[Test]
    public function permission_revoked_after_dispatch_stops_the_queued_answer(): void
    {
        Queue::fake();
        [$tenant, $connection, $q] = $this->waiting();

        $this->assertNull($this->answer($tenant, $q));
        $this->allow($connection, OutboundWrite::QUESTION_ANSWER, false);
        $this->runJob($tenant, $q);

        $this->assertSame([], $this->sent);
        $this->assertSame(QuestionReason::QUESTION_ACTION_DISABLED->value, $this->fresh($tenant, $q)->answer_reason_code);
    }

    #[Test]
    public function duplicate_attempts_send_a_single_answer(): void
    {
        Queue::fake();
        [$tenant, , $q] = $this->waiting();

        $this->assertNull($this->answer($tenant, $q));
        $this->assertSame(QuestionReason::QUESTION_ALREADY_ANSWERED, $this->answer($tenant, $q));
        $this->runJob($tenant, $q);
        $this->runJob($tenant, $q);

        $this->assertCount(1, $this->sent);
    }

    #[Test]
    public function answer_length_is_validated_before_anything_is_queued(): void
    {
        [$tenant, , $q] = $this->waiting();

        $this->assertSame(QuestionReason::QUESTION_ANSWER_INVALID, $this->answer($tenant, $q, 'Evet.'));
        $this->assertSame(QuestionReason::QUESTION_ANSWER_INVALID, $this->answer($tenant, $q, str_repeat('a', 2001)));
        $this->assertNull($this->fresh($tenant, $q)->answer_status);
    }

    #[Test]
    public function timeout_after_possible_acceptance_is_reconciled_not_resent(): void
    {
        Queue::fake();
        [$tenant, $connection, $q] = $this->waiting();
        $this->onAnswer = $this->timeout();

        $this->assertNull($this->answer($tenant, $q));
        $this->runJob($tenant, $q);
        $this->runJob($tenant, $q);

        $this->assertCount(1, $this->sent);
        $q = $this->fresh($tenant, $q);
        $this->assertSame(ChannelQuestion::ANSWER_UNKNOWN, $q->answer_status);
        $this->assertSame(QuestionReason::QUESTION_REMOTE_STATE_UNKNOWN->value, $q->answer_reason_code);
        $this->assertSame(1, $this->unanswered($tenant), 'Belirsiz cevap satıcıya gösterilir.');

        // Kanal cevabı almıştı: tazeleme çözer.
        $this->questions['100']['status'] = 'WAITING_FOR_APPROVE';
        $this->travel(15)->minutes();
        $this->syncQuestions($connection);
        $this->assertSame(ChannelQuestion::ANSWER_SUBMITTED, $this->fresh($tenant, $q)->answer_status);
    }

    #[Test]
    public function question_answered_elsewhere_is_not_answered_again(): void
    {
        Queue::fake();
        [$tenant, , $q] = $this->waiting();

        $this->assertNull($this->answer($tenant, $q));
        // Satıcı panelinden cevaplandı — iş göndermeden önce okur.
        $this->questions['100']['status'] = 'WAITING_FOR_APPROVE';
        $this->questions['100']['answer'] = ['id' => 5, 'text' => 'Panelden', 'creationDate' => 1791810000000];
        $this->runJob($tenant, $q);

        $this->assertSame([], $this->sent);
        $this->assertSame(QuestionReason::QUESTION_ALREADY_ANSWERED->value, $this->fresh($tenant, $q)->answer_reason_code);
    }

    #[Test]
    public function channel_refusal_is_recorded_with_a_stable_reason(): void
    {
        Queue::fake();
        [$tenant, , $q] = $this->waiting();
        $this->onAnswer = fn () => Http::response(['errors' => [['message' => 'Question already answered']]], 400);

        $this->assertNull($this->answer($tenant, $q));
        $this->runJob($tenant, $q);

        $q = $this->fresh($tenant, $q);
        $this->assertSame(ChannelQuestion::ANSWER_FAILED, $q->answer_status);
        $this->assertSame(QuestionReason::QUESTION_ALREADY_ANSWERED->value, $q->answer_reason_code);
    }

    #[Test]
    public function an_existing_answer_on_the_channel_blocks_a_new_post_even_if_status_lags(): void
    {
        Queue::fake();
        [$tenant, , $q] = $this->waiting();

        $this->assertNull($this->answer($tenant, $q));
        // Durum henüz güncellenmemiş ama kanalda yayında bir cevap var.
        $this->questions['100']['answer'] = ['id' => 5, 'text' => 'Panelden', 'creationDate' => 1791810000000];
        $this->runJob($tenant, $q);

        $this->assertSame([], $this->sent);
        $this->assertSame(QuestionReason::QUESTION_ALREADY_ANSWERED->value, $this->fresh($tenant, $q)->answer_reason_code);
    }
}
