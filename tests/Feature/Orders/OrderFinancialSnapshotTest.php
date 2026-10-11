<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Actions\SetVariantCost;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Shopify\ShopifyOrderNormalizer;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Adapters\WooCommerce\WooOrderNormalizer;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Messaging\Actions\IngestInboxMessage;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Actions\RecordOrderCharge;
use App\Domain\Orders\Actions\ResolveUnmatchedOrderLines;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderCharge;
use App\Domain\Orders\Models\OrderLine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Uid\UuidV7;
use Tests\Support\Channels\FakeOrderAdapter;
use Tests\TestCase;

/**
 * Satış anı finansal görüntüsü (ADR-002).
 *
 * Görüntü bir kez yazılır, değişmez; bilinmeyen değer NULL'dır (0 değil);
 * maliyet SATIŞ ANINDAKİ değerdir.
 */
final class OrderFinancialSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeOrderAdapter::reset();
    }

    #[Test]
    public function profit_uses_cost_at_sale_time_not_current_cost(): void
    {
        [$tenant, $connection, $variant] = $this->context(cost: '40.00');

        $this->send($tenant, $connection, $this->created($variant, 2, ['net_line_total' => '200.00']));

        $this->asTenant($tenant, fn () => app(SetVariantCost::class)->run($variant->fresh(), '60.00'));

        $line = $this->line($tenant);
        $this->assertSame('40.0000', $line->unit_cost, 'Satış anı maliyeti dondurulmalı.');
        $this->assertSame('TRY', $line->cost_currency);
        $this->assertNotNull($line->cost_captured_at);
        $this->assertSame('60.00', (string) $variant->fresh()->cost_price);
    }

    #[Test]
    public function unknown_financials_stay_null_not_zero(): void
    {
        [$tenant, $connection, $variant] = $this->context(cost: null);

        $this->send($tenant, $connection, $this->created($variant, 1));

        $line = $this->line($tenant);
        $this->assertNull($line->net_line_total);
        $this->assertNull($line->discount_total);
        $this->assertNull($line->vat_rate);
        $this->assertNull($line->unit_cost, 'Maliyet girilmemişse 0 uydurulmaz.');
        $this->assertNull($line->financials_captured_at);
    }

    #[Test]
    public function the_snapshot_is_immutable_in_the_database(): void
    {
        [$tenant, $connection, $variant] = $this->context(cost: '40.00');

        $this->send($tenant, $connection, $this->created($variant, 2, [
            'gross_line_total' => '220.00', 'discount_total' => '20.00', 'net_line_total' => '200.00', 'vat_rate' => '20',
        ]));

        $id = $this->line($tenant)->id;

        // Görüntü dışı sütunlar (iptal sayacı) yine güncellenir.
        $this->asSystem(fn () => DB::table('order_lines')->where('id', $id)->update(['quantity_cancelled' => 1]));

        foreach (['net_line_total' => '1.00', 'unit_cost' => '1.0000', 'vat_rate' => '1'] as $column => $value) {
            try {
                // Ayrı kayıt noktası: reddedilen UPDATE dış işlemi kirletmesin.
                $this->asSystem(fn () => DB::transaction(fn () => DB::table('order_lines')->where('id', $id)->update([$column => $value])));
                $this->fail("{$column} değiştirilebildi.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('değiştirilemez', $e->getMessage());
            }
        }
    }

    /** Sonradan eşleşen kalem: maliyet EŞLEŞME anında yakalanır ve bunu söyler. */
    #[Test]
    public function a_late_matched_line_captures_cost_when_it_is_matched(): void
    {
        [$tenant, $connection] = $this->context(cost: null);

        $this->send($tenant, $connection, [
            'type' => 'created', 'external_order_id' => 'ORD-1',
            'lines' => [['external_line_id' => 'l1', 'sku' => 'SONRADAN-1', 'quantity' => 1]],
        ]);

        $this->asTenant($tenant, function (): void {
            $variant = Variant::factory()->create(['sku' => 'SONRADAN-1', 'cost_price' => '12.50', 'created_at' => now()->subDay()]);
            $this->assertNotNull($variant);
        });

        $this->asTenant($tenant, fn () => app(ResolveUnmatchedOrderLines::class)->run());

        $line = $this->line($tenant);
        $this->assertNotNull($line->variant_id);
        $this->assertSame('12.5000', $line->unit_cost);
        $this->assertNotNull($line->cost_captured_at);
    }

    #[Test]
    public function shopify_lines_carry_discount_and_tax_from_the_webhook(): void
    {
        $event = ShopifyOrderNormalizer::normalize($this->message('orders/create', [
            'id' => 9001, 'currency' => 'TRY', 'taxes_included' => true, 'created_at' => '2026-10-11T10:00:00Z',
            'line_items' => [[
                'id' => 1, 'sku' => 'A', 'quantity' => 2, 'price' => '60.00', 'total_discount' => '20.00',
                'discount_allocations' => [['amount' => '15.00'], ['amount' => '5.00']],
                'tax_lines' => [['rate' => 0.2, 'price' => '16.67']],
            ]],
        ]));

        $line = $event->payload['lines'][0];
        $this->assertSame('120.00', $line['gross_line_total']);
        $this->assertSame('20.00', $line['discount_total']);
        $this->assertSame('100.00', $line['net_line_total']);
        $this->assertSame('20.00', $line['vat_rate']);
        $this->assertSame('16.67', $line['vat_amount']);
        $this->assertTrue($line['amounts_include_vat']);
    }

    /** Mutabakat gövdesi indirim/vergi taşımaz — "indirim 0" yazılmaz. */
    #[Test]
    public function a_shopify_payload_without_discount_fields_does_not_invent_a_zero_discount(): void
    {
        $event = ShopifyOrderNormalizer::normalize($this->message('orders/create', [
            'id' => 9002, 'line_items' => [['id' => 1, 'sku' => 'A', 'quantity' => 2, 'price' => '60.00']],
        ]));

        $line = $event->payload['lines'][0];
        $this->assertSame('120.00', $line['gross_line_total']);
        $this->assertArrayNotHasKey('discount_total', $line);
        $this->assertArrayNotHasKey('net_line_total', $line);
    }

    #[Test]
    public function trendyol_lines_derive_discount_from_gross_and_discounted_unit_price(): void
    {
        $adapter = (new ReflectionClass(TrendyolAdapter::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(TrendyolAdapter::class))->getMethod('orderLines');

        $lines = $method->invoke($adapter, ['lines' => [[
            'lineId' => 7, 'barcode' => 'B', 'stockCode' => 'S', 'quantity' => 2,
            'lineUnitPrice' => 45.5, 'lineGrossAmount' => 100, 'vatRate' => 20,
        ]]]);

        $this->assertSame('100.00', $lines[0]['gross_line_total']);
        $this->assertSame('91.00', $lines[0]['net_line_total']);
        $this->assertSame('9.00', $lines[0]['discount_total']);
        $this->assertSame('15.17', $lines[0]['vat_amount'], 'KDV dahil 91,00 içindeki %20.');
        $this->assertSame(45.5, $lines[0]['financials_source']['lineUnitPrice']);
    }

    #[Test]
    public function woo_lines_are_tax_exclusive_with_separate_tax(): void
    {
        $event = WooOrderNormalizer::normalize($this->message('order.created', [
            'id' => 5, 'status' => 'processing',
            'line_items' => [['id' => 1, 'sku' => 'A', 'quantity' => 1, 'price' => 80, 'subtotal' => '100.00', 'total' => '80.00', 'total_tax' => '16.00']],
        ]));

        $line = $event->payload['lines'][0];
        $this->assertSame('20.00', $line['discount_total']);
        $this->assertSame('80.00', $line['net_line_total']);
        $this->assertSame('16.00', $line['vat_amount']);
        $this->assertFalse($line['amounts_include_vat']);
    }

    /** Hakediş yeniden çekilirse komisyon iki kez sayılmaz; beklenen ve gerçek ayrı. */
    #[Test]
    public function charges_are_idempotent_and_keep_expected_apart_from_actual(): void
    {
        [$tenant, $connection, $variant] = $this->context(cost: null);
        $this->send($tenant, $connection, $this->created($variant, 1));

        $this->asTenant($tenant, function (): void {
            $order = Order::query()->sole();
            $record = app(RecordOrderCharge::class);

            $this->assertTrue($record->run($order, 'commission', 'expected', '-10.00', 'try', 'order', 'C-EXP-1', now(), rate: '10'));
            $this->assertFalse($record->run($order, 'commission', 'expected', '-10.00', 'TRY', 'order', 'C-EXP-1', now()));
            $this->assertTrue($record->run($order, 'commission', 'actual', '-12.50', 'TRY', 'settlement', 'STL-1', now()));

            $this->assertSame(2, OrderCharge::query()->count());
            $this->assertSame('-12.50', (string) OrderCharge::query()->where('basis', 'actual')->value('amount'));
        });

        $this->expectException(QueryException::class);
        $this->asSystem(fn () => DB::table('order_charges')->where('basis', 'actual')->update(['amount' => '-1.00']));
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: ChannelConnection, 2: Variant} */
    private function context(?string $cost): array
    {
        $tenant = (new CreateTenant)->run(name: 'Finans '.uniqid(), owner: User::factory()->create());

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'fake-orders'], [
            'name' => 'Sahte', 'kind' => 'marketplace', 'adapter_class' => FakeOrderAdapter::class, 'is_active' => true,
        ]));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create(['channel_type_code' => 'fake-orders']));
        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create(['cost_price' => $cost, 'currency' => 'TRY']));
        $warehouse = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);

        $this->asTenant($tenant, fn () => DB::transaction(function () use ($warehouse, $variant): void {
            (new LockInventoryRows)->run($warehouse, [$variant->id]);
            (new ApplyMovement)->run($warehouse, $variant->id, MovementType::IMPORT, 10, MovementKey::import((string) new UuidV7), 'import_row');
        }));

        return [$tenant, $connection, $variant];
    }

    /**
     * @param  array<string, mixed>  $financials
     * @return array<string, mixed>
     */
    private function created(Variant $variant, int $quantity, array $financials = []): array
    {
        return [
            'type' => 'created', 'external_order_id' => 'ORD-1',
            'lines' => [['external_line_id' => 'l1', 'sku' => $variant->sku, 'quantity' => $quantity, ...$financials]],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function send(Tenant $tenant, ChannelConnection $connection, array $payload): void
    {
        $message = (new IngestInboxMessage)->run($connection, 'webhook', 'DLV-'.uniqid('', true), (string) $payload['type'], json_encode($payload, JSON_THROW_ON_ERROR));

        (new ProcessInboxMessage($tenant->id, $message->id))->handle();
    }

    private function line(Tenant $tenant): OrderLine
    {
        return $this->asTenant($tenant, fn () => OrderLine::query()->sole());
    }

    /** @param array<string, mixed> $payload */
    private function message(string $topic, array $payload): InboxMessage
    {
        $message = new InboxMessage;
        $message->event_type = $topic;
        $message->external_event_id = 'evt-1';
        $message->payload = $payload;

        return $message;
    }
}
