<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Shopify\ShopifyOrderNormalizer;
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
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderRefund;
use App\Domain\Orders\Support\OrderContribution;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Support\Channels\FakeOrderAdapter;
use Tests\TestCase;

/**
 * İade / para iadesi finans modeli + katkı payı çekirdeği (ADR-002).
 */
final class OrderRefundFinancialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeOrderAdapter::reset();
    }

    /** Kısmi iade: para kaydı + adet; tekrar gelen olay ikinci kayıt açmaz. */
    #[Test]
    public function a_partial_refund_is_recorded_once_with_amount_and_lines(): void
    {
        [$tenant, $connection, $variant] = $this->context();
        $this->send($tenant, $connection, $this->created($variant, 2, '200.00'));

        $refund = $this->refundEvent('R-1', 1, '100.00', 'Beden olmadı');
        $this->send($tenant, $connection, $refund);
        $this->send($tenant, $connection, $refund);

        $this->asTenant($tenant, function (): void {
            $record = OrderRefund::query()->with('lines')->sole();
            $this->assertSame('refund', $record->kind);
            $this->assertSame('100.00', $record->amount);
            $this->assertSame('KANAL-R-1', $record->external_refund_id);
            $this->assertSame('Beden olmadı', $record->reason);
            $this->assertSame(1, $record->lines->sole()->quantity);
            $this->assertNotNull($record->lines->sole()->order_line_id);
        });
    }

    /** Tam iade ayrı kayıt; tutar bilinmeyen iade "return" olarak NULL tutarla. */
    #[Test]
    public function full_refunds_and_amountless_returns_are_distinguished(): void
    {
        [$tenant, $connection, $variant] = $this->context();
        $this->send($tenant, $connection, $this->created($variant, 2, '200.00'));

        $this->send($tenant, $connection, [
            'type' => 'returned', 'external_order_id' => 'ORD-1', 'external_ref' => 'RET-X',
            'lines' => [['external_line_id' => 'l1', 'quantity' => 2]],
        ]);

        $this->asTenant($tenant, function (): void {
            $record = OrderRefund::query()->sole();
            $this->assertSame('return', $record->kind);
            $this->assertNull($record->amount, 'Kanal tutar bildirmedi — 0 uydurulmaz.');
            $this->assertSame('returned', Order::query()->sole()->state);
        });
    }

    /** Stoğa dönmeyen (no_restock) Shopify iadesi de para iadesidir. */
    #[Test]
    public function a_no_restock_shopify_refund_still_records_money(): void
    {
        $message = new InboxMessage;
        $message->event_type = 'refunds/create';
        $message->external_event_id = 'evt-r';
        $message->payload = [
            'id' => 77, 'order_id' => 9001, 'note' => 'Hasarlı', 'created_at' => '2026-10-11T10:00:00Z',
            'transactions' => [
                ['kind' => 'refund', 'status' => 'success', 'amount' => '59.90', 'currency' => 'TRY'],
                ['kind' => 'refund', 'status' => 'failure', 'amount' => '59.90', 'currency' => 'TRY'],
            ],
            'order_adjustments' => [['kind' => 'shipping_refund', 'amount' => '-9.90']],
            'refund_line_items' => [['line_item_id' => 555, 'quantity' => 1, 'subtotal' => '50.00', 'restock_type' => 'no_restock']],
        ];

        $event = ShopifyOrderNormalizer::normalize($message);

        $this->assertSame([], $event->payload['lines'], 'no_restock stok üretmez.');
        $record = $event->payload['refund_records'][0];
        $this->assertSame('77', $record['external_refund_id']);
        $this->assertSame('59.90', $record['amount'], 'Yalnız başarılı işlem sayılır.');
        $this->assertSame('9.90', $record['shipping_amount']);
        $this->assertFalse($record['lines'][0]['restock']);
    }

    #[Test]
    public function refund_amounts_are_append_only(): void
    {
        [$tenant, $connection, $variant] = $this->context();
        $this->send($tenant, $connection, $this->created($variant, 2, '200.00'));
        $this->send($tenant, $connection, $this->refundEvent('R-1', 1, '100.00'));

        $this->expectException(QueryException::class);
        $this->asSystem(fn () => DB::table('order_refunds')->update(['amount' => '1.00']));
    }

    /**
     * gelir − iade ± kesinti − satış anı maliyeti, kuruşu kuruşuna.
     * Komisyonda GERÇEK, kargoda (gerçek yok) BEKLENEN kullanılır.
     */
    #[Test]
    public function contribution_combines_revenue_refunds_charges_and_cost_to_the_cent(): void
    {
        [$tenant, $connection, $variant] = $this->context(cost: '40.00');
        $this->send($tenant, $connection, $this->created($variant, 2, '200.00'));
        $this->send($tenant, $connection, $this->refundEvent('R-1', 1, '100.00'));

        $result = $this->asTenant($tenant, function (): OrderContribution {
            $order = Order::query()->sole();
            $record = app(RecordOrderCharge::class);
            $record->run($order, 'commission', 'expected', '-20.00', 'TRY', 'order', 'C-E', now());
            $record->run($order, 'commission', 'actual', '-24.00', 'TRY', 'settlement', 'C-A', now());
            $record->run($order, 'shipping_fee', 'expected', '-15.00', 'TRY', 'order', 'S-E', now());

            return OrderContribution::for($order);
        });

        $this->assertSame('200.00', $result->revenue);
        $this->assertSame('100.00', $result->refunds);
        $this->assertSame(['amount' => '-24.00', 'basis' => 'actual'], $result->charges['commission']);
        $this->assertSame(['amount' => '-15.00', 'basis' => 'expected'], $result->charges['shipping_fee']);
        $this->assertSame('40.00', $result->cost, 'İade edilip stoğa dönen adedin maliyeti düşülmez.');
        $this->assertSame('21.00', $result->contribution);
        $this->assertTrue($result->complete());
    }

    /**
     * Shopify iptali (tüm sipariş) + aynı adetlerin para iadesi ÇİFT sayılmaz;
     * pazaryeri iptali (para kaydı yok) gelirden düşer. İkisinde de katkı 0.
     */
    #[Test]
    public function a_cancellation_is_not_double_counted_with_its_refund(): void
    {
        $cancel = ['type' => 'cancelled', 'external_order_id' => 'ORD-1', 'external_ref' => 'C-1', 'lines' => [['external_line_id' => 'l1', 'quantity' => 2]]];

        [$tenantA, $connectionA, $variantA] = $this->context(cost: '40.00');
        $this->send($tenantA, $connectionA, $this->created($variantA, 2, '200.00'));
        $this->send($tenantA, $connectionA, $cancel);

        [$tenantB, $connectionB, $variantB] = $this->context(cost: '40.00');
        $this->send($tenantB, $connectionB, $this->created($variantB, 2, '200.00'));
        $this->send($tenantB, $connectionB, $cancel);
        $this->send($tenantB, $connectionB, $this->refundEvent('R-C', 2, '200.00'));

        $a = $this->asTenant($tenantA, fn () => OrderContribution::for(Order::query()->sole()));
        $b = $this->asTenant($tenantB, fn () => OrderContribution::for(Order::query()->sole()));

        $this->assertSame('0.00', $a->revenue);
        $this->assertSame('0.00', $a->contribution);
        $this->assertSame('200.00', $b->revenue, 'İadesi kayıtlı iptal adedi gelirde kalır…');
        $this->assertSame('200.00', $b->refunds, '…ve iade tutarıyla düşer.');
        $this->assertSame('0.00', $b->contribution, 'İptal + aynı adedin iadesi iki kez düşülmemeli.');
    }

    #[Test]
    public function missing_cost_or_refund_amount_marks_the_result_incomplete(): void
    {
        [$tenant, $connection, $variant] = $this->context(cost: null);
        $this->send($tenant, $connection, $this->created($variant, 1, '100.00'));
        $this->send($tenant, $connection, ['type' => 'returned', 'external_order_id' => 'ORD-1', 'external_ref' => 'X', 'lines' => [['external_line_id' => 'l1', 'quantity' => 1]]]);

        $result = $this->asTenant($tenant, fn () => OrderContribution::for(Order::query()->sole()));

        $this->assertFalse($result->complete());
        $this->assertCount(1, array_filter($result->missing, static fn (string $m): bool => str_starts_with($m, 'refund_amount:')));
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: ChannelConnection, 2: Variant} */
    private function context(?string $cost = null): array
    {
        $tenant = (new CreateTenant)->run(name: 'İade '.uniqid(), owner: User::factory()->create());

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

    /** @return array<string, mixed> */
    private function created(Variant $variant, int $quantity, string $net): array
    {
        return [
            'type' => 'created', 'external_order_id' => 'ORD-1', 'currency' => 'TRY', 'status' => 'Delivered',
            'lines' => [['external_line_id' => 'l1', 'sku' => $variant->sku, 'quantity' => $quantity, 'net_line_total' => $net]],
        ];
    }

    /** @return array<string, mixed> */
    private function refundEvent(string $ref, int $quantity, string $amount, ?string $reason = null): array
    {
        return [
            'type' => 'returned', 'external_order_id' => 'ORD-1', 'external_ref' => $ref,
            'lines' => [['external_line_id' => 'l1', 'quantity' => $quantity]],
            'refund_records' => [array_filter([
                'external_refund_id' => 'KANAL-'.$ref, 'amount' => $amount, 'currency' => 'TRY', 'reason' => $reason,
                'lines' => [['external_line_id' => 'l1', 'quantity' => $quantity, 'amount' => $amount, 'restock' => true]],
            ])],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function send(Tenant $tenant, ChannelConnection $connection, array $payload): void
    {
        $message = (new IngestInboxMessage)->run($connection, 'webhook', 'DLV-'.uniqid('', true), (string) $payload['type'], json_encode($payload, JSON_THROW_ON_ERROR));

        (new ProcessInboxMessage($tenant->id, $message->id))->handle();
    }
}
