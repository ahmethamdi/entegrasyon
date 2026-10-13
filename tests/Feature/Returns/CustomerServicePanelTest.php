<?php

declare(strict_types=1);

namespace Tests\Feature\Returns;

use App\Domain\Channels\Actions\SetOutboundWrite;
use App\Domain\Channels\Adapters\Hepsiburada\HepsiburadaAdapter;
use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Exceptions\OutboundWriteLocked;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Questions\Models\ChannelQuestion;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnRequestLine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

/**
 * S4 · panel: kiracı yalıtımı, sahte istekler, yazma izni kilidi.
 *
 * Başka kiracının ya da hiç olmayan kimliğin cevabı AYNI 404'tür —
 * varlık sızdırılmaz.
 */
final class CustomerServicePanelTest extends TestCase
{
    use BuildsReturnScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReturns();
    }

    #[Test]
    public function foreign_and_nonexistent_returns_and_questions_are_indistinguishable_404s(): void
    {
        [$victim, $victimConnection] = $this->trendyolTenant('999001');
        $this->soldOrder($victim, $victimConnection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 1]]);
        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'WaitingInAction']]);
        $this->question('100');
        $this->syncReturns($victimConnection);
        $this->syncQuestions($victimConnection);
        $this->allow($victimConnection, OutboundWrite::RETURN_DECISION);
        $this->allow($victimConnection, OutboundWrite::QUESTION_ANSWER);
        $return = TenantContext::runFor($victim->id, fn () => ReturnRequest::query()->sole());
        $question = TenantContext::runFor($victim->id, fn () => ChannelQuestion::query()->sole());

        $this->trendyolTenant('999002');
        $attacker = $this->owner;
        $missing = (string) new UuidV7;

        foreach ([
            ["/returns/{$return->id}/approve", []],
            ["/returns/{$missing}/approve", []],
            ["/returns/{$return->id}/reject", ['reason_id' => '451', 'description' => 'sahte']],
            ["/returns/{$missing}/reject", ['reason_id' => '451', 'description' => 'sahte']],
            ["/questions/{$question->id}/answer", ['text' => 'Sahte cevap metni burada']],
            ["/questions/{$missing}/answer", ['text' => 'Sahte cevap metni burada']],
        ] as [$url, $body]) {
            $this->actingAs($attacker)->post($url, $body)->assertNotFound();
        }

        $this->assertSame([], $this->sent, 'Sahte istek kanala hiçbir şey göndermez.');
        $this->assertNull(TenantContext::runFor($victim->id, fn () => ReturnRequestLine::query()->sole()->decision_status));
        $this->assertNull(TenantContext::runFor($victim->id, fn () => $question->fresh()->answer_status));
    }

    #[Test]
    public function screens_list_only_the_tenants_own_returns_and_questions(): void
    {
        [$a, $aConnection] = $this->trendyolTenant('999001');
        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'WaitingInAction']]);
        $this->question('100');
        $this->syncReturns($aConnection);
        $this->syncQuestions($aConnection);

        $this->claims = [];
        $this->questions = [];
        $this->trendyolTenant('999002');

        $this->actingAs($this->owner)->get('/returns?filter=all')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Returns/Index')->where('requests.total', 0));
        $this->actingAs($this->owner)->get('/questions?filter=all')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Questions/Index')->where('questions.total', 0)->where('unansweredCount', 0));
    }

    #[Test]
    public function returns_screen_separates_return_requested_from_stock_restored(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->soldOrder($tenant, $connection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 2]]);
        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'WaitingInAction'], ['ci-2', '71', 'TS-1', 'Accepted']]);
        $this->syncReturns($connection);

        $this->actingAs($this->owner)->get('/returns?filter=all')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('requests.data.0.lines.0.restockStatus', 'not_applicable')
                ->where('requests.data.0.lines.1.restockStatus', 'pending')
                ->where('requests.data.0.decision.available', true)
                ->where('requests.data.0.decision.enabled', false));
    }

    #[Test]
    public function http_approve_goes_through_the_gate_and_reports_a_stable_reason(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->soldOrder($tenant, $connection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 1]]);
        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'WaitingInAction']]);
        $this->syncReturns($connection);
        $return = TenantContext::runFor($tenant->id, fn () => ReturnRequest::query()->sole());

        $this->actingAs($this->owner)->post("/returns/{$return->id}/approve")->assertSessionHas('warning', __('İade kararı izni kapalı'));
        $this->assertSame([], $this->sent);

        $this->actingAs($this->owner)->put("/channels/{$connection->id}/outbound", ['write' => 'return_decision', 'enabled' => true])->assertRedirect();
        $this->actingAs($this->owner)->post("/returns/{$return->id}/approve")->assertSessionHas('success');
        $this->assertCount(1, $this->sent);
    }

    #[Test]
    public function customer_service_writes_stay_closed_and_locked_for_unverified_channels(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();

        $this->assertFalse($connection->fresh()->return_decision_enabled, 'Yeni bağlantıda kapalı başlar.');
        $this->assertFalse($connection->fresh()->question_answer_enabled);

        TenantContext::runAsSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'hepsiburada'], [
            'name' => 'Hepsiburada', 'kind' => 'marketplace', 'adapter_class' => HepsiburadaAdapter::class,
            'supports_webhooks' => false, 'is_active' => true,
        ]));
        $hb = TenantContext::runFor($tenant->id, fn () => ChannelConnection::factory()->create(['channel_type_code' => 'hepsiburada']));

        foreach ([OutboundWrite::RETURN_DECISION, OutboundWrite::QUESTION_ANSWER] as $write) {
            $this->assertThrows(fn () => app(SetOutboundWrite::class)->run($hb, $write, true), OutboundWriteLocked::class);
        }

        $this->assertFalse((bool) $hb->fresh()->return_decision_enabled);
    }

    #[Test]
    public function trendyol_package_return_event_no_longer_restocks_by_itself(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        [$order, $variant, $wh] = $this->soldOrder($tenant, $connection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 1]]);
        $adapter = TenantContext::runFor($tenant->id, fn () => app(AdapterRegistry::class)->for($connection->fresh()));

        $payload = (fn (array $p, string $type, string $no, string $status) => $this->toCanonicalOrderPayload($p, $type, $no, $status))
            ->call($adapter, ['lines' => [['lineId' => 71, 'stockCode' => 'TS-1', 'quantity' => 1]]], 'returned', 'N-5001', 'Returned');

        $this->assertTrue($payload['returns_restocked_via_claims'], 'Trendyol paket iadesi stoğu talep yoluna bırakır.');
        $this->assertSame(9, $this->onHand($tenant, $wh, $variant));
    }
}
