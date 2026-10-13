<?php

declare(strict_types=1);

namespace Tests\Feature\Returns;

use App\Domain\Channels\Adapters\Hepsiburada\HepsiburadaAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ConnectionSettingsWriter;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Returns\Actions\IngestReturns;
use App\Domain\Returns\Actions\RestoreReturnedStock;
use App\Domain\Returns\Enums\ReturnReason;
use App\Domain\Returns\Enums\ReturnStatus;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnRequestLine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S4 · iade talebi içe alma, eşleşme ve STOK İADESİ.
 *
 * Temel değişmezler:
 *   talep var ≠ stok döndü · aynı ekonomik iade ≠ birden çok stok iadesi ·
 *   iade adedi satırı aşamaz · HTTP hatası "iade yok" değildir.
 */
final class ReturnIngestTest extends TestCase
{
    use BuildsReturnScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReturns();
    }

    #[Test]
    public function first_import_records_the_claim_matched_to_the_order_line_without_touching_stock(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        [$order, $variant, $wh] = $this->soldOrder($tenant, $connection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 3]]);
        $this->assertSame(7, $this->onHand($tenant, $wh, $variant));

        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'WaitingInAction']]);
        $this->syncReturns($connection);

        $request = TenantContext::runFor($tenant->id, fn () => ReturnRequest::query()->with('lines')->sole());
        $line = $request->lines->sole();

        $this->assertSame($order->id, $request->order_id);
        $this->assertSame('matched', $request->match_status);
        $this->assertSame(ReturnStatus::AWAITING_ACTION->value, $request->status);
        $this->assertSame('WaitingInAction', $line->channel_status);
        $this->assertSame(1, $line->quantity);
        $this->assertNotNull($line->receipt_proven_at, 'WaitingInAction = ürün depoya ulaştı.');
        $this->assertSame('Küçük geldi', $line->customer_note);
        $this->assertSame(ReturnRequestLine::RESTOCK_NOT_APPLICABLE, $line->restock_status);
        $this->assertSame(7, $this->onHand($tenant, $wh, $variant), 'Talep var ≠ stok döndü.');
        $this->assertSame(0, $this->returnMovements($tenant));
    }

    #[Test]
    public function repeated_and_overlapping_polls_keep_one_claim_and_one_line(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->soldOrder($tenant, $connection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 1]]);
        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'Created']]);

        $this->syncReturns($connection);
        $this->syncReturns($connection);
        $this->travel(2)->hours();
        $this->syncReturns($connection);

        $this->assertSame(1, TenantContext::runFor($tenant->id, fn () => ReturnRequest::query()->count()));
        $this->assertSame(1, TenantContext::runFor($tenant->id, fn () => ReturnRequestLine::query()->count()));
    }

    #[Test]
    public function accepted_partial_return_restores_exactly_the_returned_unit_once(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        [$order, $variant, $wh] = $this->soldOrder($tenant, $connection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 3]]);

        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'WaitingInAction']]);
        $this->syncReturns($connection);
        $this->setClaimItems('c-1', ['ci-1'], 'Accepted');

        $this->syncReturns($connection);
        $this->syncReturns($connection);
        $this->syncReturns($connection);

        $line = TenantContext::runFor($tenant->id, fn () => ReturnRequestLine::query()->sole());
        $this->assertSame(ReturnRequestLine::RESTOCK_RESTORED, $line->restock_status);
        $this->assertNotNull($line->restock_order_event_id);
        $this->assertSame(8, $this->onHand($tenant, $wh, $variant), '3 adetlik satırdan 1 iade = +1, 3 değil.');
        $this->assertSame(1, $this->returnMovements($tenant), 'Tekrarlanan gözlem yeni hareket üretmez.');
        $this->assertSame(1, TenantContext::runFor($tenant->id, fn () => $order->lines()->sole()->quantity_returned));
        $this->assertSame('partially_returned', TenantContext::runFor($tenant->id, fn () => (string) ($order->fresh()->state?->value ?? $order->fresh()->state)));
    }

    #[Test]
    public function two_legitimate_partial_returns_restore_two_units_and_never_more(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        [, $variant, $wh] = $this->soldOrder($tenant, $connection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 3]]);

        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'WaitingInAction']]);
        $this->syncReturns($connection);
        $this->setClaimItems('c-1', ['ci-1'], 'Accepted');
        $this->syncReturns($connection);

        $this->claim('c-2', '5001', [['ci-2', '71', 'TS-1', 'WaitingInAction']]);
        $this->syncReturns($connection);
        $this->setClaimItems('c-2', ['ci-2'], 'Accepted');
        $this->syncReturns($connection);
        $this->syncReturns($connection);

        $this->assertSame(9, $this->onHand($tenant, $wh, $variant));
        $this->assertSame(2, $this->returnMovements($tenant));
    }

    #[Test]
    public function the_same_economic_return_through_a_different_path_does_not_restock_twice(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        [, $variant, $wh] = $this->soldOrder($tenant, $connection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 2]]);

        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'WaitingInAction']]);
        $this->syncReturns($connection);
        $this->setClaimItems('c-1', ['ci-1'], 'Accepted');
        $this->syncReturns($connection);

        // Kayıt kaybolmuş gibi: satır "eklenmedi"ye döner ve elle uzlaştırma
        // (başka bir yol) aynı kalemi yeniden işler.
        $line = TenantContext::runFor($tenant->id, fn () => ReturnRequestLine::query()->sole());
        TenantContext::runFor($tenant->id, fn () => $line->forceFill(['restock_status' => 'pending', 'restock_order_event_id' => null])->save());
        TenantContext::runFor($tenant->id, fn () => app(RestoreReturnedStock::class)->run($line->fresh()));

        $this->assertSame(9, $this->onHand($tenant, $wh, $variant));
        $this->assertSame(1, $this->returnMovements($tenant));
        $line = TenantContext::runFor($tenant->id, fn () => $line->fresh());
        $this->assertSame(ReturnRequestLine::RESTOCK_RESTORED, $line->restock_status);
        $this->assertSame(ReturnReason::RETURN_STOCK_ALREADY_RESTORED->value, $line->restock_reason_code);
    }

    #[Test]
    public function over_reported_return_cannot_restore_more_than_the_line_sold(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        [, $variant, $wh] = $this->soldOrder($tenant, $connection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 1]]);

        // Kanal tek birimlik satıra İKİ iade kalemi bildiriyor.
        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'WaitingInAction'], ['ci-2', '71', 'TS-1', 'WaitingInAction']]);
        $this->syncReturns($connection);
        $this->setClaimItems('c-1', ['ci-1', 'ci-2'], 'Accepted');
        $this->syncReturns($connection);
        $this->syncReturns($connection);

        $this->assertSame(10, $this->onHand($tenant, $wh, $variant), '9 + 1; ikinci kalem stok üretmez.');
        $this->assertSame(1, $this->returnMovements($tenant));

        $lines = TenantContext::runFor($tenant->id, fn () => ReturnRequestLine::query()->orderBy('external_item_id')->get());
        $this->assertSame(ReturnRequestLine::RESTOCK_RESTORED, $lines[0]->restock_status);
        $this->assertSame(ReturnRequestLine::RESTOCK_BLOCKED, $lines[1]->restock_status);
        $this->assertSame(ReturnReason::RETURN_QUANTITY_CONFLICT->value, $lines[1]->restock_reason_code);
    }

    #[Test]
    public function acceptance_without_proof_of_physical_receipt_does_not_restock(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        [, $variant, $wh] = $this->soldOrder($tenant, $connection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 1]]);

        // Ara durum (WaitingInAction) hiç görülmedi; denetim kaydı da yok.
        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'Accepted']]);
        $this->syncReturns($connection);

        $line = TenantContext::runFor($tenant->id, fn () => ReturnRequestLine::query()->sole());
        $this->assertSame(ReturnRequestLine::RESTOCK_PENDING, $line->restock_status);
        $this->assertSame(ReturnReason::RETURN_RECEIPT_UNPROVEN->value, $line->restock_reason_code);
        $this->assertSame(9, $this->onHand($tenant, $wh, $variant));

        // Denetim kaydı varışı gösterince stok döner.
        $this->audits['ci-1'] = ['Created', 'WaitingInAction', 'Accepted'];
        $this->syncReturns($connection);

        $this->assertSame(10, $this->onHand($tenant, $wh, $variant));
    }

    #[Test]
    public function rejected_cancelled_and_unknown_states_never_restock(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        [, $variant, $wh] = $this->soldOrder($tenant, $connection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 3]]);

        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'WaitingInAction'], ['ci-2', '71', 'TS-1', 'WaitingInAction'], ['ci-3', '71', 'TS-1', 'WaitingInAction']]);
        $this->syncReturns($connection);
        $this->setClaimItems('c-1', ['ci-1'], 'Rejected');
        $this->setClaimItems('c-1', ['ci-2'], 'Cancelled');
        $this->setClaimItems('c-1', ['ci-3'], 'SomethingNew');
        $this->syncReturns($connection);

        $this->assertSame(7, $this->onHand($tenant, $wh, $variant));
        $this->assertSame(ReturnStatus::UNKNOWN->value, TenantContext::runFor($tenant->id, fn () => ReturnRequestLine::query()->where('external_item_id', 'ci-3')->value('status')));
    }

    #[Test]
    public function return_before_the_order_exists_is_matched_and_restocked_later(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'WaitingInAction']]);
        $this->syncReturns($connection);
        $this->setClaimItems('c-1', ['ci-1'], 'Accepted');
        $this->syncReturns($connection);

        $line = TenantContext::runFor($tenant->id, fn () => ReturnRequestLine::query()->sole());
        $this->assertSame('order_missing', $line->match_status);
        $this->assertSame(ReturnReason::RETURN_ORDER_NOT_FOUND->value, $line->restock_reason_code);

        [, $variant, $wh] = $this->soldOrder($tenant, $connection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 1]]);
        $this->assertSame(9, $this->onHand($tenant, $wh, $variant));

        // Talep kanalda değişmese de uzlaştırma eşler ve stoğu getirir.
        $this->claims = [];
        $this->syncReturns($connection);

        $this->assertSame('matched', TenantContext::runFor($tenant->id, fn () => $line->fresh()->match_status));
        $this->assertSame(10, $this->onHand($tenant, $wh, $variant));
    }

    #[Test]
    public function ambiguous_line_match_stays_action_required_and_never_restocks(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        [$order, $variant, $wh] = $this->soldOrder($tenant, $connection, '5001', [
            ['line' => '71', 'sku' => 'TS-1', 'qty' => 1],
            ['line' => '72', 'sku' => 'TS-1', 'qty' => 1],
        ]);

        // Talepteki satır kimliği bizde yok; SKU iki satıra uyuyor.
        $this->claim('c-1', '5001', [['ci-1', '999', 'TS-1', 'WaitingInAction']]);
        $this->syncReturns($connection);
        $this->setClaimItems('c-1', ['ci-1'], 'Accepted');
        $this->syncReturns($connection);

        $line = TenantContext::runFor($tenant->id, fn () => ReturnRequestLine::query()->sole());
        $this->assertSame('ambiguous', $line->match_status);
        $this->assertNull($line->order_line_id);
        $this->assertSame(ReturnReason::RETURN_MATCH_REQUIRED->value, $line->restock_reason_code);
        $this->assertSame(8, $this->onHand($tenant, $wh, $variant));
    }

    #[Test]
    public function http_errors_are_not_treated_as_no_returns_and_do_not_advance_the_cursor(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->onClaimList = fn () => Http::response(['errors' => 'down'], 503);

        $this->assertSame(['connections' => 0, 'stored' => 0, 'restored' => 0, 'failed' => 1], app(IngestReturns::class)->run());
        $this->assertArrayNotHasKey(IngestReturns::CURSOR_KEY, $connection->fresh()->settings ?? []);

        $this->onClaimList = fn () => Http::response(['errors' => 'slow down'], 429);
        $this->assertSame(1, app(IngestReturns::class)->run()['failed']);
        $this->assertArrayNotHasKey(IngestReturns::CURSOR_KEY, $connection->fresh()->settings ?? []);

        $this->onClaimList = null;
        $this->syncReturns($connection);
        $this->assertArrayHasKey(IngestReturns::CURSOR_KEY, $connection->fresh()->settings);
    }

    #[Test]
    public function every_page_of_a_window_is_read_before_the_cursor_moves(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'Created']]);
        $this->claim('c-2', '5002', [['ci-2', '72', 'TS-2', 'Created']]);
        $pages = [[$this->claims['c-1']], [$this->claims['c-2']]];

        $this->onClaimList = function (array $q) use ($pages) {
            $page = (int) ($q['page'] ?? 0);

            return Http::response(['totalElements' => 2, 'totalPages' => 2, 'page' => $page, 'size' => 1, 'content' => $pages[$page] ?? []]);
        };

        $this->syncReturns($connection);

        $this->assertSame(2, TenantContext::runFor($tenant->id, fn () => ReturnRequest::query()->count()));
    }

    #[Test]
    public function hepsiburada_claims_are_read_but_never_restocked_through_s4(): void
    {
        [$tenant, $trendyol] = $this->trendyolTenant();
        TenantContext::runAsSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'hepsiburada'], [
            'name' => 'Hepsiburada', 'kind' => 'marketplace', 'adapter_class' => HepsiburadaAdapter::class,
            'supports_webhooks' => false, 'is_active' => true,
        ]));
        $hb = TenantContext::runFor($tenant->id, function () {
            $c = ChannelConnection::factory()->create(['channel_type_code' => 'hepsiburada', 'status' => 'active', 'connected_at' => '2026-10-10', 'settings' => [HepsiburadaAdapter::MERCHANT_ID_KEY => 'm-1', HepsiburadaAdapter::INTEGRATOR_KEY => 'entegrator34']]);
            app(CredentialVault::class)->store($c, ['username' => 'u', 'password' => 'p', HepsiburadaAdapter::SERVICE_KEY_SECRET => 'sk']);

            return $c->fresh(['channelType']);
        });

        $this->onOther = fn () => Http::response([[
            'number' => 'HB-CL-1', 'status' => 'Accepted', 'quantity' => 1, 'sku' => 'HBV00001', 'orderNumber' => '4000000001',
            'claimType' => 'ProductisDamaged', 'explanation' => 'Kırık geldi', 'claimDate' => '2026-10-12T10:00:00',
        ]]);

        $this->syncReturns($hb);

        $line = TenantContext::runFor($tenant->id, fn () => ReturnRequestLine::query()->sole());
        $this->assertSame(ReturnStatus::ACCEPTED->value, $line->status);
        $this->assertSame('Kırık geldi', $line->customer_note);
        $this->assertNull($line->receipt_proven_at, 'HB kabulü fiziksel varışı kanıtlamaz.');
        $this->assertSame(0, $this->returnMovements($tenant));
    }

    #[Test]
    public function restore_called_directly_never_restocks_a_claim_that_is_not_accepted(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        [, $variant, $wh] = $this->soldOrder($tenant, $connection, '5001', [['line' => '71', 'sku' => 'TS-1', 'qty' => 1]]);
        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'WaitingInAction']]);
        $this->syncReturns($connection);

        $line = TenantContext::runFor($tenant->id, fn () => ReturnRequestLine::query()->sole());
        $this->assertNotNull($line->receipt_proven_at);

        $this->assertSame(ReturnRequestLine::RESTOCK_NOT_APPLICABLE, TenantContext::runFor($tenant->id, fn () => app(RestoreReturnedStock::class)->run($line)));
        $this->assertSame(9, $this->onHand($tenant, $wh, $variant), 'Depoya ulaştı ama henüz kabul yok: stok değişmez.');
    }

    #[Test]
    public function a_window_cut_by_the_page_limit_does_not_advance_the_cursor(): void
    {
        [, $connection] = $this->trendyolTenant();
        // Hız kovası sayfa sınırından önce turu kesmesin: sınır gerçekten sınansın.
        ConnectionSettingsWriter::merge($connection, [
            'learned_rate_limit' => ['requests_per_second' => 1000, 'burst_capacity' => 1000],
        ]);
        $this->claim('c-1', '5001', [['ci-1', '71', 'TS-1', 'Created']]);
        $this->onClaimList = fn (array $q) => Http::response(['totalElements' => 5000, 'totalPages' => 1000, 'page' => (int) ($q['page'] ?? 0), 'size' => 50, 'content' => [$this->claims['c-1']]]);

        $this->syncReturns($connection);

        $this->assertArrayNotHasKey(IngestReturns::CURSOR_KEY, $connection->fresh()->settings ?? []);
    }
}
