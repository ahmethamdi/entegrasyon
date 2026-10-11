<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Variant;
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
use App\Domain\Orders\Actions\TransitionOrderState;
use App\Domain\Orders\Enums\OrderState;
use App\Domain\Orders\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Support\Channels\FakeOrderAdapter;
use Tests\TestCase;

/**
 * Kanonik sipariş durumu uçtan uca (ADR-001): adapter eşler, çekirdek
 * yalnız geçiş kuralıyla yazar, sayaçlar iptal/iadeyi belirler.
 */
final class CanonicalOrderStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeOrderAdapter::reset();
    }

    #[Test]
    public function order_state_never_moves_backwards(): void
    {
        [$tenant, $connection, $variant] = $this->context();

        $this->send($tenant, $connection, ['type' => 'created', 'status' => 'Created'] + $this->order($variant, 2));
        $this->assertSame('awaiting_shipment', $this->order_($tenant)->state);

        $this->send($tenant, $connection, ['type' => 'updated', 'external_order_id' => 'ORD-1', 'external_ref' => 'U-1', 'status' => 'Shipped']);
        $this->assertSame('shipped', $this->order_($tenant)->state);

        // Sırasız gelen ESKİ durum: kanonik durum geri gitmez, ham kelime
        // tanı için yine yazılır.
        $this->send($tenant, $connection, ['type' => 'updated', 'external_order_id' => 'ORD-1', 'external_ref' => 'U-0', 'status' => 'Picking']);

        $order = $this->order_($tenant);
        $this->assertSame('shipped', $order->state);
        $this->assertSame('Picking', $order->status, 'Ham kanal durumu tanı için korunur.');

        $this->send($tenant, $connection, ['type' => 'updated', 'external_order_id' => 'ORD-1', 'external_ref' => 'U-2', 'status' => 'Delivered']);
        $this->assertSame('delivered', $this->order_($tenant)->state);
    }

    #[Test]
    public function a_full_cancellation_ends_in_cancelled_and_stays_terminal(): void
    {
        [$tenant, $connection, $variant] = $this->context();

        $this->send($tenant, $connection, ['type' => 'created', 'status' => 'Created'] + $this->order($variant, 2));
        $this->send($tenant, $connection, $this->event('cancelled', 2, 'C-1'));

        $this->assertSame('cancelled', $this->order_($tenant)->state);

        $this->send($tenant, $connection, ['type' => 'updated', 'external_order_id' => 'ORD-1', 'external_ref' => 'U-9', 'status' => 'Shipped']);
        $this->assertSame('cancelled', $this->order_($tenant)->state, 'Terminal durumdan çıkılmaz.');
    }

    #[Test]
    public function returns_move_to_partially_returned_then_returned(): void
    {
        [$tenant, $connection, $variant] = $this->context();

        $this->send($tenant, $connection, ['type' => 'created', 'status' => 'Delivered'] + $this->order($variant, 3));
        $this->assertSame('delivered', $this->order_($tenant)->state);

        $this->send($tenant, $connection, $this->event('returned', 1, 'R-1'));
        $this->assertSame('partially_returned', $this->order_($tenant)->state);

        $this->send($tenant, $connection, $this->event('returned', 2, 'R-2'));
        $this->assertSame('returned', $this->order_($tenant)->state);
    }

    /** Trendyol bölünen paket: kalemler iptal sayacına girer ama durum `superseded` kalır. */
    #[Test]
    public function a_split_package_is_superseded_not_cancelled(): void
    {
        [$tenant, $connection, $variant] = $this->context();

        $this->send($tenant, $connection, ['type' => 'created', 'status' => 'Created'] + $this->order($variant, 2));
        $this->send($tenant, $connection, $this->event('cancelled', 2, 'UNPACK-1') + ['status' => 'UnPacked']);

        $this->assertSame('superseded', $this->order_($tenant)->state);
    }

    /** Sıradan bağımsızlık: geri yazım satırı bulamaz (koşullu UPDATE). */
    #[Test]
    public function concurrent_out_of_order_transitions_converge(): void
    {
        [$tenant, $connection, $variant] = $this->context();

        $this->send($tenant, $connection, ['type' => 'created', 'status' => 'Created'] + $this->order($variant, 1));

        $this->asTenant($tenant, function (): void {
            $stale = Order::query()->firstOrFail();   // iki işçinin elindeki iki kopya
            $fresh = Order::query()->firstOrFail();

            (new TransitionOrderState)->run($fresh, OrderState::SHIPPED);
            // Eski kopya hâlâ `awaiting_shipment` görüyor ve geri yazmaya çalışıyor.
            $this->assertFalse((new TransitionOrderState)->run($stale, OrderState::AWAITING_SHIPMENT));
        });

        $this->assertSame('shipped', $this->order_($tenant)->state);
    }

    #[Test]
    public function awaiting_shipment_uses_the_canonical_state_with_a_legacy_fallback(): void
    {
        [$tenant, $connection] = $this->context();

        $this->asTenant($tenant, function () use ($connection, $tenant): void {
            foreach ([
                ['ready', 'ReadyToShip', 'awaiting_shipment'],   // önceden listede YOKTU
                ['open', 'Open', 'awaiting_shipment'],           // Hepsiburada — önceden YOKTU
                ['legacy', 'processing', null],                  // eşlenmemiş eski satır
                ['shipped', 'Shipped', 'shipped'],
                ['legacy-cancel', 'cancelled', null],
            ] as [$id, $raw, $state]) {
                DB::table('orders')->insert([
                    'id' => (string) new UuidV7, 'tenant_id' => $tenant->id, 'channel_connection_id' => $connection->id,
                    'external_id' => $id, 'status' => $raw, 'state' => $state, 'placed_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $ids = Order::query()->awaitingShipment()->orderBy('external_id')->pluck('external_id')->all();

            $this->assertSame(['legacy', 'open', 'ready'], $ids);
        });
    }

    #[Test]
    public function the_backfill_fills_only_empty_states_and_is_idempotent(): void
    {
        [$tenant, $connection] = $this->context();

        $this->asTenant($tenant, function () use ($connection, $tenant): void {
            foreach ([['a', 'Shipped', null], ['b', 'UnPacked', null], ['c', 'Picking', 'delivered'], ['d', 'Mystery', null]] as [$id, $raw, $state]) {
                DB::table('orders')->insert([
                    'id' => (string) new UuidV7, 'tenant_id' => $tenant->id, 'channel_connection_id' => $connection->id,
                    'external_id' => $id, 'status' => $raw, 'state' => $state, 'placed_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->artisan('orders:backfill-state')->expectsOutputToContain('filled=2 unmapped=1')->assertSuccessful();
        $this->artisan('orders:backfill-state')->expectsOutputToContain('filled=0 unmapped=1')->assertSuccessful();

        $states = $this->asTenant($tenant, fn () => Order::query()->orderBy('external_id')->pluck('state', 'external_id')->all());

        $this->assertSame(['a' => 'shipped', 'b' => 'superseded', 'c' => 'delivered', 'd' => null], $states);
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: ChannelConnection, 2: Variant} */
    private function context(): array
    {
        $tenant = (new CreateTenant)->run(name: 'Durum '.uniqid(), owner: User::factory()->create());

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'fake-orders'], [
            'name' => 'Sahte', 'kind' => 'marketplace', 'adapter_class' => FakeOrderAdapter::class, 'is_active' => true,
        ]));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create(['channel_type_code' => 'fake-orders']));
        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());
        $warehouse = $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id);

        $this->asTenant($tenant, fn () => DB::transaction(function () use ($warehouse, $variant): void {
            (new LockInventoryRows)->run($warehouse, [$variant->id]);
            (new ApplyMovement)->run($warehouse, $variant->id, MovementType::IMPORT, 10, MovementKey::import((string) new UuidV7), 'import_row');
        }));

        return [$tenant, $connection, $variant];
    }

    /** @return array<string, mixed> */
    private function order(Variant $variant, int $quantity): array
    {
        return ['external_order_id' => 'ORD-1', 'lines' => [['external_line_id' => 'l1', 'sku' => $variant->sku, 'quantity' => $quantity]]];
    }

    /** @return array<string, mixed> */
    private function event(string $type, int $quantity, string $ref): array
    {
        return ['type' => $type, 'external_order_id' => 'ORD-1', 'external_ref' => $ref, 'lines' => [['external_line_id' => 'l1', 'quantity' => $quantity]]];
    }

    /** @param array<string, mixed> $payload */
    private function send(Tenant $tenant, ChannelConnection $connection, array $payload): void
    {
        $message = (new IngestInboxMessage)->run($connection, 'webhook', 'DLV-'.uniqid('', true), (string) $payload['type'], json_encode($payload, JSON_THROW_ON_ERROR));

        (new ProcessInboxMessage($tenant->id, $message->id))->handle();
    }

    private function order_(Tenant $tenant): Order
    {
        return $this->asTenant($tenant, fn () => Order::query()->where('external_id', 'ORD-1')->firstOrFail());
    }
}
