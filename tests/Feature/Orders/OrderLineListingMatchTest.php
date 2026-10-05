<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Routing\OrderPayloadMapper;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SKU'suz sipariş satırı kanal bağından (`Listing`) eşlenir.
 *
 * Shopify'da SKU zorunlu değildir; içe aktarma SKU'suz ürüne SKU üretir ve
 * ürünü kanal varyantına bağlar. Sipariş o varyantın kimliğiyle gelir ve
 * SKU'su BOŞTUR — bağa bakılmasaydı satır eşleşmez, stok hiç düşmezdi.
 */
final class OrderLineListingMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChannelTypeSeeder::class);
    }

    #[Test]
    public function a_line_without_a_sku_is_matched_through_the_channel_link(): void
    {
        [$tenant, $connection, $variant] = $this->linked('gid://shopify/ProductVariant/48213');

        $order = $this->map($tenant, $connection, ['sku' => '', 'external_variant_id' => 'gid://shopify/ProductVariant/48213']);

        $this->assertSame($variant->id, $order->lines[0]->variantId, 'SKU\'suz satır eşleşmedi — stok düşmez.');
        $this->assertSame($variant->sku, $order->lines[0]->sku, 'Satır bizim SKU\'muzu taşımalı.');
    }

    /**
     * BAŞKA BAĞLANTININ AYNI KİMLİĞİ EŞLENMEZ: iki mağazanın varyant
     * kimlikleri çakışabilir; eşlenseydi A mağazasının siparişi B'nin
     * ürününden stok düşerdi.
     */
    #[Test]
    public function a_link_of_another_connection_is_not_used(): void
    {
        [$tenant, $connection] = $this->linked('gid://shopify/ProductVariant/1');

        $other = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create(['tenant_id' => $tenant->id]));

        $order = $this->map($tenant, $other, ['sku' => '', 'external_variant_id' => 'gid://shopify/ProductVariant/1']);

        $this->assertNull($order->lines[0]->variantId);
    }

    /**
     * SKU TUTARSA SKU KAZANIR — mevcut davranış değişmez.
     */
    #[Test]
    public function a_matching_sku_still_wins(): void
    {
        [$tenant, $connection] = $this->linked('gid://shopify/ProductVariant/2');

        $bySku = $this->asTenant($tenant, fn () => Variant::factory()->create(['tenant_id' => $tenant->id, 'sku' => 'KENDI-SKU']));

        $order = $this->map($tenant, $connection, ['sku' => 'KENDI-SKU', 'external_variant_id' => 'gid://shopify/ProductVariant/2']);

        $this->assertSame($bySku->id, $order->lines[0]->variantId);
    }

    /** @return array{0: Tenant, 1: ChannelConnection, 2: Variant} */
    private function linked(string $externalId): array
    {
        $tenant = (new CreateTenant)->run(name: 'Eşleşme '.uniqid(), owner: User::factory()->create());

        return $this->asTenant($tenant, function () use ($tenant, $externalId): array {
            $connection = ChannelConnection::factory()->create(['tenant_id' => $tenant->id]);
            $variant = Variant::factory()->create(['tenant_id' => $tenant->id, 'sku' => 'SHO-'.uniqid()]);

            Listing::factory()->create([
                'tenant_id' => $tenant->id,
                'channel_connection_id' => $connection->id,
                'variant_id' => $variant->id,
                'external_id' => $externalId,
            ]);

            return [$tenant, $connection, $variant];
        });
    }

    /** @param array<string, mixed> $line */
    private function map(Tenant $tenant, ChannelConnection $connection, array $line): IncomingOrder
    {
        $event = new NormalizedOrderEvent(
            type: 'created',
            externalOrderId: '1001',
            externalRef: 'e-1',
            payload: ['lines' => [['external_line_id' => '1', 'title' => 'Ürün', 'quantity' => 1, ...$line]]],
        );

        return $this->asTenant($tenant, fn () => OrderPayloadMapper::toIncomingOrder($event, $connection->id));
    }
}
