<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Finance\Actions\IngestFinancialTransactions;
use App\Domain\Finance\Models\ChannelFinancialTransaction as Tx;
use App\Domain\Finance\Support\TrendyolFinancialTypes;
use App\Domain\Identity\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Finance\BuildsFinanceScenario;
use Tests\TestCase;

/**
 * S1 · Trendyol cari hesap ekstresi — ham defter, idempotens, eşleme,
 * sınıflandırma. Gövdeler 11 Eki 2026 canlı yanıtından (anonim).
 *
 * Bu testler UYGULAMA davranışını kanıtlar; pazaryeri ANLAMINI kanıtlamaz
 * (S1 kararı 13) — VERIFIED yalnız canlıda gözlenen etiketlerdir.
 */
final class FinancialLedgerIngestionTest extends TestCase
{
    use BuildsFinanceScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-11 12:00:00');
    }

    #[Test]
    public function the_live_response_is_stored_raw_with_explicit_classification(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->fakeFinance($this->livePage('settlements'), $this->livePage('otherfinancials'));

        $this->ingest($connection);

        $rows = $this->rows($tenant)->keyBy('type_raw');

        $this->assertSame(['Gelen Havale', 'Komisyon Faturası', 'Satış', 'Ödeme'], $rows->keys()->sort()->values()->all(), 'Türkçe ham etiket saklanır.');

        $sale = $rows['Satış'];
        $this->assertSame([TrendyolFinancialTypes::SALE, Tx::VERIFIED], [$sale->type, $sale->classification]);
        $this->assertSame(['1789.00', '0.00', '339.91', '19.0000', '1449.09', 'TRY'], [
            (string) $sale->credit, (string) $sale->debt, (string) $sale->commission_amount,
            (string) $sale->commission_rate, (string) $sale->seller_revenue, $sale->currency,
        ]);
        $this->assertSame('3000000001', $sale->shipment_package_id);
        $this->assertSame('DCA2026000453682', $sale->commission_invoice_serial);
        $this->assertSame(339.91, $sale->raw['commissionAmount'], 'Ham gövde aynen saklanır.');

        foreach (['Ödeme' => 'payout', 'Komisyon Faturası' => 'commission_invoice', 'Gelen Havale' => 'incoming_transfer'] as $label => $type) {
            $this->assertSame([$type, Tx::VERIFIED, Tx::NOT_ORDER_LEVEL], [$rows[$label]->type, $rows[$label]->classification, $rows[$label]->match_status], $label);
        }
    }

    #[Test]
    public function a_duplicate_transaction_and_overlapping_windows_create_one_row(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        // Aynı satır her pencerede VE aynı sayfada iki kez döner.
        $this->fakeFinance($this->page([$this->saleRow(), $this->saleRow()]));

        $first = $this->ingest($connection);
        $this->travel(2)->hours();
        $second = $this->ingest($connection);

        $this->assertSame(1, $first['inserted']);
        $this->assertGreaterThan(0, $first['duplicates']);
        $this->assertSame(0, $second['inserted'], 'Örtüşen ikinci tur yeni işlem yaratmamalı.');
        $this->assertSame(1, $this->rows($tenant)->count());
    }

    #[Test]
    public function windows_are_fifteen_days_overlap_three_days_and_the_cursor_advances(): void
    {
        [, $connection] = $this->trendyolTenant(connectedAt: '2026-10-10 00:00:00');
        $windows = [];
        $this->fakeFinance(function (Request $r) use (&$windows) {
            $windows[] = [(int) $r['startDate'], (int) $r['endDate']];

            return $this->page([]);
        });

        $this->ingest($connection);

        foreach ($windows as [$from, $to]) {
            $this->assertLessThanOrEqual(15 * 86_400_000, $to - $from, 'Pencere 15 günü aşmamalı.');
        }
        $this->assertSame('2026-09-10', date('Y-m-d', intdiv($windows[0][0], 1000)), 'İlk tur bağlantıdan 30 gün geri.');

        $cursor = $connection->fresh()->settings[IngestFinancialTransactions::CURSOR_KEY];
        $this->assertStringStartsWith('2026-10-11T12:00:00', $cursor);

        $windows = [];
        $this->travel(1)->hours();
        $this->ingest($connection);
        $this->assertSame('2026-10-08', date('Y-m-d', intdiv($windows[0][0], 1000)), 'Sonraki tur imleçten 3 gün geri.');
    }

    #[Test]
    public function all_pages_are_read(): void
    {
        [$tenant, $connection] = $this->trendyolTenant(connectedAt: '2026-10-11 00:00:00');
        $this->fakeFinance(function (Request $r) {
            $page = (int) $r['page'];

            return $this->page([$this->saleRow(['id' => "S-{$page}", 'shipmentPackageId' => 3000000100 + $page])], $page, 2);
        });

        $this->ingest($connection);

        $this->assertSame(['S-0', 'S-1'], $this->rows($tenant)->pluck('external_id')->unique()->sort()->values()->all());
    }

    #[Test]
    public function a_partial_failure_keeps_what_was_written_and_the_retry_completes_without_duplicates(): void
    {
        [$tenant, $connection] = $this->trendyolTenant(connectedAt: '2026-10-10 00:00:00');
        $fail = true;
        $this->fakeFinance(function (Request $r) use (&$fail) {
            $windowStart = date('Y-m-d', intdiv((int) $r['startDate'], 1000));

            if ($fail && $windowStart >= '2026-09-25') {
                return 503;
            }

            return $this->page([$this->saleRow(['id' => 'W-'.$windowStart, 'shipmentPackageId' => crc32($windowStart)])]);
        });

        $summary = app(IngestFinancialTransactions::class)->run();
        $this->assertSame(1, $summary['failed'], 'Hata yutulmaz, sayılır.');
        $afterFailure = $this->rows($tenant)->count();
        $cursor = $connection->fresh()->settings[IngestFinancialTransactions::CURSOR_KEY] ?? null;

        $this->assertSame(1, $afterFailure, 'Başarılı ilk pencere korunur.');
        $this->assertStringStartsWith('2026-09-24', (string) $cursor, 'İmleç yalnız tamamlanan pencereye ilerler.');

        $fail = false;
        $this->ingest($connection);

        $ids = $this->rows($tenant)->pluck('external_id');
        $this->assertSame($ids->unique()->count(), $ids->count(), 'Yeniden deneme mükerrer yaratmaz.');
        $this->assertGreaterThan($afterFailure, $ids->count());
    }

    #[Test]
    public function one_broken_connection_does_not_stop_another(): void
    {
        [$tenantA, $broken] = $this->trendyolTenant(seller: '111');
        [$tenantB] = $this->trendyolTenant(seller: '222');

        Http::fake(fn (Request $r) => match (true) {
            str_contains($r->url(), '/sellers/111/') => Http::response(['errors' => 'down'], 500),
            str_contains($r->url(), '/settlements') => Http::response($this->page([$this->saleRow()]), 200),
            default => Http::response($this->page([]), 200),
        });

        $summary = app(IngestFinancialTransactions::class)->run();

        $this->assertSame(1, $summary['failed']);
        $this->assertSame(0, $this->rows($tenantA)->count());
        $this->assertSame(1, $this->rows($tenantB)->count());
    }

    // ------------------------------------------------------------- eşleme

    #[Test]
    public function an_unmatched_settlement_is_kept_visible_and_matched_when_the_order_arrives_later(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->fakeFinance($this->page([$this->saleRow()]));

        $this->ingest($connection);
        $sale = $this->rows($tenant)->sole();
        $this->assertSame([Tx::UNMATCHED, null], [$sale->match_status, $sale->order_id]);
        $this->assertStringContainsString('3000000001', (string) $sale->match_reason);

        // Gecikmeli: sipariş sonradan gelir, sonraki tur eşler.
        $order = $this->trendyolOrder($tenant, $connection, '3000000001', '10000000001', [['net' => '1789.00']]);
        $this->travel(3)->hours();
        $this->ingest($connection);

        $sale = $this->rows($tenant)->sole();
        $this->assertSame([Tx::MATCHED, $order->id], [$sale->match_status, $sale->order_id]);
    }

    #[Test]
    public function a_package_whose_order_number_disagrees_is_ambiguous_not_guessed(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->trendyolOrder($tenant, $connection, '3000000001', 'BASKA-SIPARIS', [['net' => '1789.00']]);
        $this->fakeFinance($this->page([$this->saleRow()]));

        $this->ingest($connection);

        $sale = $this->rows($tenant)->sole();
        $this->assertSame([Tx::AMBIGUOUS, null], [$sale->match_status, $sale->order_id]);
    }

    #[Test]
    public function a_package_that_is_not_ours_is_not_matched_by_order_number_to_a_sibling_package(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        // Aynı siparişin BAŞKA paketi bizde var; işlemin paketi yok.
        $this->trendyolOrder($tenant, $connection, '3000000999', '10000000001', [['net' => '10.00']]);
        $this->fakeFinance($this->page([$this->saleRow()]));

        $this->ingest($connection);

        $this->assertSame(Tx::UNMATCHED, $this->rows($tenant)->sole()->match_status);
    }

    #[Test]
    public function matching_never_crosses_tenants_or_connections(): void
    {
        [$tenantA, $connectionA] = $this->trendyolTenant(seller: '111');
        [$tenantB, $connectionB] = $this->trendyolTenant(seller: '222');
        $orderA = $this->trendyolOrder($tenantA, $connectionA, '3000000001', '10000000001', [['net' => '1789.00']]);
        $orderB = $this->trendyolOrder($tenantB, $connectionB, '3000000001', '10000000001', [['net' => '1789.00']]);
        $this->fakeFinance($this->page([$this->saleRow()]));

        app(IngestFinancialTransactions::class)->run();

        $this->assertSame($orderA->id, $this->rows($tenantA)->sole()->order_id);
        $this->assertSame($orderB->id, $this->rows($tenantB)->sole()->order_id);
        $this->assertSame(1, TenantContext::runFor($tenantA->id, fn (): int => Tx::query()->count()), 'Kiracı kapsamı öteki kiracının işlemini göstermez.');
    }

    // ----------------------------------------------------- sınıflandırma

    #[Test]
    public function unknown_and_english_labels_are_kept_but_never_verified(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->fakeFinance($this->page([
            $this->saleRow(['id' => 'X1', 'transactionType' => 'Kargo Kesintisi', 'credit' => 0, 'debt' => 45.5]),
            $this->saleRow(['id' => 'X2', 'transactionType' => 'Sale']),
            $this->saleRow(['id' => 'X3', 'transactionType' => 'Return', 'credit' => 0, 'debt' => 1789]),
        ]));

        $this->ingest($connection);
        $rows = $this->rows($tenant)->keyBy('external_id');

        $this->assertSame([null, Tx::UNKNOWN, 'Kargo Kesintisi'], [$rows['X1']->type, $rows['X1']->classification, $rows['X1']->type_raw]);
        $this->assertSame(['45.50'], [(string) $rows['X1']->debt], 'Bilinmeyen tür de tutarıyla saklanır.');
        $this->assertSame([TrendyolFinancialTypes::SALE, Tx::UNVERIFIED], [$rows['X2']->type, $rows['X2']->classification]);
        $this->assertSame(['return', Tx::UNVERIFIED], [$rows['X3']->type, $rows['X3']->classification]);
        $this->assertSame(Tx::UNMATCHED, $rows['X1']->match_status, 'Paketli bilinmeyen tür sipariş düzeyidir.');
    }

    #[Test]
    public function a_sale_row_is_verified_only_in_the_observed_shape(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->fakeFinance($this->page([
            $this->saleRow(['id' => 'OK']),
            $this->saleRow(['id' => 'REV', 'sellerRevenue' => 1450]),
            $this->saleRow(['id' => 'DEBT', 'debt' => 10]),
            $this->saleRow(['id' => 'ZERO', 'credit' => 0, 'commissionAmount' => 0, 'sellerRevenue' => 0]),
            $this->saleRow(['id' => 'NOCOM', 'commissionAmount' => null]),
            $this->saleRow(['id' => 'USD', 'currency' => 'USD']),
        ]));

        $this->ingest($connection);
        $class = $this->rows($tenant)->pluck('classification', 'external_id');

        $this->assertSame(Tx::VERIFIED, $class['OK']);
        foreach (['REV', 'DEBT', 'ZERO', 'NOCOM', 'USD'] as $id) {
            $this->assertSame(Tx::UNVERIFIED, $class[$id], $id);
        }
        $this->assertNull($this->rows($tenant)->firstWhere('external_id', 'NOCOM')->commission_amount, 'Bilinmeyen komisyon 0 yazılmaz.');
    }

    #[Test]
    public function a_changed_record_at_the_source_does_not_overwrite_the_raw_fact(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $amount = 1789;
        $this->fakeFinance(function () use (&$amount) {
            return $this->page([$this->saleRow(['credit' => $amount, 'sellerRevenue' => $amount - 339.91])]);
        });

        $this->ingest($connection);
        $amount = 1800;
        $this->travel(1)->hours();
        $result = $this->ingest($connection);

        $this->assertGreaterThan(0, $result['conflicts']);
        $this->assertSame('1789.00', (string) $this->rows($tenant)->sole()->credit, 'İlk ham olgu korunur.');
    }

    #[Test]
    public function raw_columns_are_immutable_in_the_database_but_interpretation_is_not(): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $this->fakeFinance($this->page([$this->saleRow()]));
        $this->ingest($connection);

        DB::table('channel_financial_transactions')->update(['classification' => Tx::UNVERIFIED]);
        $this->assertSame(Tx::UNVERIFIED, $this->rows($tenant)->sole()->classification);

        $this->expectException(QueryException::class);
        DB::table('channel_financial_transactions')->update(['credit' => '1.00']);
    }

    // ---------------------------------------------------------------- iç

    /** @return array{inserted: int, duplicates: int, conflicts: int, windows: int} */
    private function ingest(ChannelConnection $connection): array
    {
        return app(IngestFinancialTransactions::class)->forConnection($connection->fresh(['channelType']));
    }

    private function rows(Tenant $tenant)
    {
        return TenantContext::runFor($tenant->id, fn () => Tx::query()->orderBy('external_id')->get());
    }
}
