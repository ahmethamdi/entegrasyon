<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Ticimax\TicimaxAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Routing\OrderEventRouter;
use App\Domain\Orders\Support\PollChannelOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\TestCase;

/**
 * Ticimax siparişi stoğu düşürür, sipariş iptali/iadesi geri ekler — zincir
 * gerçek sınıflarla (IkasOrderSliceTest'in kardeşi).
 *
 * İptal ve iade SİPARİŞ düzeyindedir (durum 8 / 9); kalemler detaydan
 * (`SelectSiparisUrun`) okunur çünkü liste iptal edilmiş kalemleri taşımaz.
 */
final class TicimaxOrderSliceTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    private int $status = 1;

    private bool $detailCalled = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            if (str_contains($request->body(), '<SelectSiparisUrun ')) {
                $this->detailCalled = true;

                return Http::response($this->soap('SelectSiparisUrun', $this->lines()));
            }

            return Http::response($this->soap('SelectSiparis', '<a:WebSiparis><a:Durum>'.$this->status.'</a:Durum>'
                .'<a:DuzenlemeTarihi>2026-10-08T12:00:00</a:DuzenlemeTarihi><a:ID>501</a:ID><a:Mail>musteri@ornek.com</a:Mail>'
                .'<a:ParaBirimi>TRY</a:ParaBirimi><a:SiparisDurumu>Durum '.$this->status.'</a:SiparisDurumu><a:SiparisNo>TCX-501</a:SiparisNo>'
                .'<a:SiparisTarihi>2026-10-08T11:30:00</a:SiparisTarihi><a:SiparisToplamTutari>250</a:SiparisToplamTutari>'
                .'<a:Urunler>'.$this->lines().'</a:Urunler></a:WebSiparis>'));
        });
    }

    #[Test]
    public function an_order_reduces_stock_and_its_cancellation_returns_it(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $tabak = $this->variant($tenant, 'TABAK-01');
        $this->seedStock($tenant, $kupa, 10);
        $this->seedStock($tenant, $tabak, 10);

        $this->poll($tenant);

        $this->assertSame(7, $this->availableFor($tenant, $kupa));
        $this->assertSame(8, $this->availableFor($tenant, $tabak));

        $order = $this->asTenant($tenant, fn () => Order::query()->where('external_id', '501')->firstOrFail());
        $this->assertSame('TCX-501', $order->external_number);
        // 11:30 Türkiye = 08:30 UTC.
        $this->assertSame('2026-10-08T08:30:00+00:00', $order->placed_at->toIso8601String());

        // Kişisel veri inbox'a girmez.
        $payload = $this->asTenant($tenant, fn () => InboxMessage::query()->firstOrFail()->payload);
        $this->assertArrayNotHasKey('Mail', $payload);

        // Aynı veri yeniden: hiçbir şey ikinci kez düşmez.
        $this->poll($tenant);
        $this->assertSame(7, $this->availableFor($tenant, $kupa));

        $this->status = 8;
        $this->poll($tenant);

        $this->assertTrue($this->detailCalled);
        $this->assertSame(10, $this->availableFor($tenant, $kupa));
        $this->assertSame(10, $this->availableFor($tenant, $tabak));

        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $kupa->id);
        $this->assertLedgerMatchesProjection($tenant->id, $this->warehouse($tenant)->id, $tabak->id);
    }

    /** İlk kez iptal hâlinde görülen sipariş net sıfırdır: ne düşülür ne eklenir. */
    #[Test]
    public function an_order_first_seen_cancelled_changes_nothing(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        $this->status = 8;
        $this->poll($tenant);

        $this->assertSame(10, $this->availableFor($tenant, $kupa));
        $this->assertFalse($this->detailCalled);
    }

    /** İade edildi (9) bütün kalemleri stoğa döndürür — sipariş önce alındıysa. */
    #[Test]
    public function a_returned_order_restocks(): void
    {
        [$tenant] = $this->setUpConnection();

        $kupa = $this->variant($tenant, 'KUPA-01');
        $this->seedStock($tenant, $kupa, 10);

        $this->poll($tenant);
        $this->assertSame(7, $this->availableFor($tenant, $kupa));

        $this->status = 9;
        $this->poll($tenant);

        $this->assertSame(10, $this->availableFor($tenant, $kupa));
    }

    private function lines(): string
    {
        $line = static fn (int $id, int $variantId, string $sku, int $qty): string => '<a:WebSiparisUrun><a:Adet>'.$qty.'</a:Adet>'
            .'<a:ID>'.$id.'</a:ID><a:StokKodu>'.$sku.'</a:StokKodu><a:Tutar>50</a:Tutar><a:UrunAdi>'.$sku.'</a:UrunAdi>'
            .'<a:UrunID>'.$variantId.'</a:UrunID><a:UrunKartiID>10</a:UrunKartiID></a:WebSiparisUrun>';

        return $line(9001, 101, 'KUPA-01', 3).$line(9002, 102, 'TABAK-01', 2);
    }

    private function soap(string $operation, string $inner): string
    {
        return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            .'<'.$operation.'Response xmlns="http://tempuri.org/"><'.$operation.'Result xmlns:a="http://schemas.datacontract.org/2004/07/" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">'
            .$inner.'</'.$operation.'Result></'.$operation.'Response></s:Body></s:Envelope>';
    }

    private function poll(Tenant $tenant): void
    {
        app(PollChannelOrders::class)->run();

        $ids = $this->asTenant($tenant, fn (): array => InboxMessage::query()
            ->where('status', 'pending')
            ->orderBy('received_at')
            ->orderBy('id')
            ->pluck('id')
            ->all());

        foreach ($ids as $id) {
            (new ProcessInboxMessage($tenant->id, $id))->handle(app(OrderEventRouter::class));
        }
    }

    private function availableFor(Tenant $tenant, Variant $variant): int
    {
        return (int) $this->asTenant($tenant, fn () => DB::table('inventory_levels')
            ->where('tenant_id', $tenant->id)
            ->where('variant_id', $variant->id)
            ->value('available'));
    }

    private function variant(Tenant $tenant, string $sku): Variant
    {
        return $this->asTenant($tenant, function () use ($sku): Variant {
            $product = Product::factory()->create();

            return Variant::factory()->create(['product_id' => $product->id, 'sku' => $sku]);
        });
    }

    private function seedStock(Tenant $tenant, Variant $variant, int $quantity): void
    {
        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $this->warehouse($tenant)->id,
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: $quantity,
            idempotencyKey: 'import:'.$variant->id,
            sourceType: 'test',
        ));
    }

    private function warehouse(Tenant $tenant): Warehouse
    {
        return $this->asTenant($tenant, fn (): Warehouse => Warehouse::query()
            ->where('is_default', true)
            ->firstOrFail());
    }

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function setUpConnection(): array
    {
        $tenant = (new CreateTenant)->run(name: 'Ticimax Dilim '.uniqid(), owner: User::factory()->create());

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'ticimax'],
            [
                'name' => 'Ticimax',
                'kind' => 'storefront',
                'adapter_class' => TicimaxAdapter::class,
                'capabilities' => [
                    'catalog' => false, 'catalog_import' => true, 'inventory' => true, 'pricing' => true,
                    'orders' => true, 'taxonomy' => false, 'approval' => false, 'fulfillment' => false,
                ],
                'rate_limit_profile' => ['requests_per_second' => 2, 'burst_capacity' => 4],
                'supports_webhooks' => false,
                'is_active' => true,
            ],
        ));

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ticimax',
                'external_account_id' => 'www.magazam.com',
                'status' => 'active',
                'connected_at' => now()->setDate(2026, 10, 1),
                'settings' => [],
            ]);

            app(CredentialVault::class)->store($connection, ['uye_kodu' => 'YETKI-KODU-123']);

            return $connection;
        });

        return [$tenant, $connection];
    }
}
