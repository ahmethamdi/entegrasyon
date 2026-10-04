<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * WOO İADESİ — kısmi, kalemsiz ve tekrar eden güncellemeler.
 *
 * ⚠️ BU TESTİN VARLIK NEDENİ — DENETİMDE BULUNAN HATA:
 *   WC REST v3 sipariş gövdesindeki `refunds[]` yalnızca `{id, reason,
 *   total}` taşır, KALEM TAŞIMAZ. Normalizer kalem bulamayınca "tüm
 *   sipariş iade edildi" sayıyordu: 5 TL'lik bir para iadesi bile
 *   siparişin TÜM kalemlerini stoğa geri ekliyordu. Üstelik `refunds`
 *   dolu olduğu sürece siparişin sonraki HER güncellemesi (not, kargo)
 *   yeniden iade sayılıyordu.
 *
 *   Woo iadesi için tek bir test yoktu.
 *
 * Akış GERÇEKTİR: imzalı webhook → inbox → gerçek WooCommerceAdapter →
 * `orders/{id}/refunds` okuması (Http::fake) → ApplyOrderReturn.
 */
final class WooRefundTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'wh_secret_refund_test_123';

    private const ORDER_ID = 1234;

    /**
     * `orders/{id}/refunds` ucunun o anki yanıtı.
     *
     * `Http::fake()` aynı testte iki kez çağrılamaz (ikincisi ilkini
     * EZMEZ — DEVIR.md tuzağı); yanıt bu alandan okunur ve test ilerledikçe
     * değiştirilir.
     *
     * @var list<array<string, mixed>>
     */
    private array $refunds = [];

    private Tenant $tenant;

    private ChannelConnection $connection;

    private Variant $variantA;

    private Variant $variantB;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'orders/'.self::ORDER_ID.'/refunds')) {
                return Http::response($this->refunds, 200);
            }

            return Http::response([], 200);
        });

        $this->tenant = (new CreateTenant)->run(name: 'İade '.uniqid(), owner: User::factory()->create());
        $this->variantA = $this->asTenant($this->tenant, fn () => Variant::factory()->create());
        $this->variantB = $this->asTenant($this->tenant, fn () => Variant::factory()->create());
        $this->connection = $this->makeConnection();

        $this->seedStock($this->variantA, 10);
        $this->seedStock($this->variantB, 10);

        // Sipariş: 3 × A, 2 × B → stok 7 / 8.
        $this->deliver($this->orderPayload(status: 'processing'), 'dlv-create', 'order.created');

        $this->assertStock(7, 8, 'Ön koşul: sipariş stoğu düşürmeli.');
    }

    /**
     * ⚠️ KALEMSİZ PARA İADESİ STOK GERİ GETİRMEZ.
     *
     * Kargo ücreti veya fiyat farkı iadesi: Woo `refunds[]`'e bir satır
     * ekler ama hiçbir kalem iade edilmemiştir. Eski davranış 3 + 2
     * adedin TAMAMINI stoğa geri eklerdi.
     */
    #[Test]
    public function a_money_only_refund_restocks_nothing(): void
    {
        $this->refunds = [['id' => 900, 'amount' => '5.00', 'line_items' => []]];

        $this->deliver(
            $this->orderPayload(status: 'processing', refundSummaries: [['id' => 900, 'reason' => 'kargo', 'total' => '-5.00']]),
            'dlv-money',
            'order.updated',
        );

        $this->assertStock(7, 8, 'Para iadesi stok hareketi ÜRETMEMELİ.');
        $this->assertSame('processed', $this->lastMessage()->status);
    }

    /** Kısmi kalem iadesi YALNIZCA iade edilen kalemi geri getirir. */
    #[Test]
    public function a_partial_item_refund_restocks_only_the_refunded_item(): void
    {
        $this->refunds = [$this->refund(901, [[77, $this->variantA->sku, 1]])];

        $this->deliver(
            $this->orderPayload(status: 'processing', refundSummaries: [['id' => 901, 'reason' => '', 'total' => '-100.00']]),
            'dlv-r1',
            'order.updated',
        );

        $this->assertStock(8, 8);

        // Kalemler GERÇEKTEN iade ucundan okundu.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'orders/'.self::ORDER_ID.'/refunds'));
    }

    /**
     * ⚠️ SONRAKİ GÜNCELLEMELER İADEYİ TEKRAR UYGULAMAZ.
     *
     * Woo her güncellemede TÜM iadeleri yeniden gönderir. Eski davranışta
     * her not/kargo güncellemesi yeni bir teslim kimliğiyle geldiği için
     * aynı iade yeniden stoğa eklenirdi.
     */
    #[Test]
    public function later_order_updates_do_not_apply_the_same_refund_again(): void
    {
        $this->refunds = [$this->refund(901, [[77, $this->variantA->sku, 1]])];
        $summaries = [['id' => 901, 'reason' => '', 'total' => '-100.00']];

        $this->deliver($this->orderPayload('processing', $summaries), 'dlv-r1', 'order.updated');
        $this->deliver($this->orderPayload('processing', $summaries, note: 'kargo verildi'), 'dlv-note', 'order.updated');
        $this->deliver($this->orderPayload('completed', $summaries), 'dlv-done', 'order.updated');

        $this->assertStock(8, 8, 'Aynı iade bir kez uygulanmalı.');
    }

    /**
     * İkinci iade YALNIZCA FARKI uygular; tam iade kalanı tamamlar.
     *
     * Kaçırılmış bir webhook da böyle telafi edilir: sonraki mesaj
     * kümülatif durumu taşır.
     */
    #[Test]
    public function a_second_refund_applies_only_the_difference_and_a_full_refund_completes_it(): void
    {
        $this->refunds = [$this->refund(901, [[77, $this->variantA->sku, 1]])];
        $this->deliver($this->orderPayload('processing', [['id' => 901, 'reason' => '', 'total' => '-100.00']]), 'dlv-r1', 'order.updated');
        $this->assertStock(8, 8);

        // İkinci iade: A × 1 daha, B × 2. Woo ikisini birden gönderir.
        $this->refunds = [
            $this->refund(902, [[77, $this->variantA->sku, 1], [78, $this->variantB->sku, 2]]),
            $this->refund(901, [[77, $this->variantA->sku, 1]]),
        ];
        $this->deliver($this->orderPayload('processing', [
            ['id' => 902, 'reason' => '', 'total' => '-300.00'],
            ['id' => 901, 'reason' => '', 'total' => '-100.00'],
        ]), 'dlv-r2', 'order.updated');

        $this->assertStock(9, 10, 'Yalnızca yeni iade edilen adetler eklenmeli.');

        // Tam iade: kalan 1 × A.
        $this->deliver($this->orderPayload('refunded'), 'dlv-full', 'order.updated');

        $this->assertStock(10, 10, 'Tam iade kalanı tamamlamalı, öncekileri iki kez eklememeli.');

        foreach ([$this->variantA, $this->variantB] as $variant) {
            $this->assertLedgerMatchesProjection($this->tenant->id, $this->warehouseId(), $variant->id);
        }
    }

    // ---------------------------------------------------------------- yardımcılar

    /**
     * @param  list<array<string, mixed>>  $refundSummaries  Sipariş gövdesindeki biçim
     * @return array<string, mixed>
     */
    private function orderPayload(string $status, array $refundSummaries = [], ?string $note = null): array
    {
        return array_filter([
            'id' => self::ORDER_ID,
            'number' => (string) self::ORDER_ID,
            'status' => $status,
            'currency' => 'TRY',
            'date_created_gmt' => '2026-10-01T09:15:00',
            'total' => '500.00',
            'customer_note' => $note,
            'line_items' => [
                ['id' => 77, 'name' => 'A', 'sku' => $this->variantA->sku, 'quantity' => 3, 'price' => '100.00', 'total' => '300.00'],
                ['id' => 78, 'name' => 'B', 'sku' => $this->variantB->sku, 'quantity' => 2, 'price' => '100.00', 'total' => '200.00'],
            ],
            // GERÇEK Woo biçimi: kalem YOK.
            'refunds' => $refundSummaries,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * `orders/{id}/refunds` biçiminde tek iade.
     *
     * @param  list<array{0: int, 1: string, 2: int}>  $items  [orijinal satır, sku, adet]
     * @return array<string, mixed>
     */
    private function refund(int $id, array $items): array
    {
        return [
            'id' => $id,
            'amount' => '100.00',
            'line_items' => array_map(fn (array $item): array => [
                'id' => $id * 10 + $item[0],      // iade kaleminin KENDİ kimliği
                'sku' => $item[1],
                'quantity' => -$item[2],          // Woo NEGATİF gönderir
                'meta_data' => [['key' => '_refunded_item_id', 'value' => (string) $item[0]]],
            ], $items),
        ];
    }

    /** @param array<string, mixed> $body */
    private function deliver(array $body, string $deliveryId, string $topic): void
    {
        $raw = json_encode($body, JSON_UNESCAPED_SLASHES);

        $this->call('POST', "/webhooks/{$this->connection->id}", server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $raw, self::WEBHOOK_SECRET, true)),
            'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => $deliveryId,
            'HTTP_X_WC_WEBHOOK_TOPIC' => $topic,
        ], content: $raw)->assertStatus(202);

        $message = $this->lastMessage();

        $this->asTenant($this->tenant, fn () => (new ProcessInboxMessage($this->tenant->id, $message->id))->handle());
    }

    private function lastMessage(): InboxMessage
    {
        return $this->asSystem(fn () => InboxMessage::query()->latest('received_at')->latest('id')->firstOrFail())->fresh();
    }

    private function assertStock(int $a, int $b, string $message = ''): void
    {
        $level = fn (Variant $v): int => $this->asTenant($this->tenant, fn () => InventoryLevel::query()
            ->where('variant_id', $v->id)->firstOrFail())->on_hand;

        $this->assertSame([$a, $b], [$level($this->variantA), $level($this->variantB)], $message);
    }

    private function makeConnection(): ChannelConnection
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'store',
                'adapter_class' => WooCommerceAdapter::class,
                'is_active' => true,
                'rate_limit_profile' => ['requests_per_second' => 5, 'burst_capacity' => 10],
            ],
        ));

        $connection = $this->asTenant($this->tenant, fn () => ChannelConnection::factory()->create([
            'channel_type_code' => 'woocommerce',
            'external_account_id' => 'iade-'.uniqid().'.example.com',
            'settings' => ['base_url' => 'https://iade.example.com/wp-json/wc/v3/'],
        ]));

        $this->asTenant($this->tenant, fn () => app(CredentialVault::class)->store($connection, [
            'consumer_key' => 'ck_refund_test_1234567890',
            'consumer_secret' => 'cs_refund_test_1234567890',
            'webhook_secret' => self::WEBHOOK_SECRET,
        ]));

        return $connection;
    }

    private function seedStock(Variant $variant, int $quantity): void
    {
        $this->asTenant($this->tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $this->warehouseId(),
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: $quantity,
            idempotencyKey: 'import:'.$variant->id,
            sourceType: 'test',
        ));
    }

    private function warehouseId(): string
    {
        return $this->asTenant($this->tenant, fn () => Warehouse::query()->where('is_default', true)->firstOrFail())->id;
    }
}
