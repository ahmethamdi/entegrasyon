<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Domain\Channels\Contracts\SupportsInvoiceData;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoicing\Exceptions\InvoiceDataUnavailable;
use App\Domain\Invoicing\Support\InvoiceBuyer;
use App\Domain\Invoicing\Support\InvoiceDraft;
use App\Domain\Orders\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Trendyol paketinden fatura taslağı — alıcı ve kalemler ANLIK okunur.
 */
final class TrendyolInvoiceDraftTest extends TestCase
{
    use InvoicingFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_package_is_fetched_live_and_mapped(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->fakeApis();

        $draft = $this->draftFor($tenant, $order);

        $request = $this->sentTo('apigw.trendyol.com')[0];
        $this->assertStringContainsString('/order/sellers/777/v2/orders', $request->url());
        $this->assertSame('9001', (string) $request->data()['shipmentPackageIds']);
        $this->assertSame('10500001', (string) $request->data()['orderNumber']);

        $this->assertSame('Ayşe Yılmaz', $draft->buyer->name);
        $this->assertFalse($draft->buyer->isCompany);
        $this->assertSame('12345678901', $draft->buyer->taxNumber);
        $this->assertSame('Atatürk Cad. No:1 Kadıköy İstanbul', $draft->buyer->address);
        $this->assertSame('Kadıköy', $draft->buyer->district);

        $this->assertCount(1, $draft->lines);
        $this->assertSame(20, $draft->lines[0]->vatRate);
        $this->assertSame(2, $draft->lines[0]->quantity);
        $this->assertSame('120', $draft->lines[0]->grossUnitPrice);
        $this->assertSame('Trendyol', $draft->platformName);
        $this->assertSame('10500001', $draft->orderNumber);
    }

    /** Kurumsal sipariş + 10 haneli VKN → firma adına, vergi dairesiyle. */
    #[Test]
    public function a_commercial_order_is_invoiced_to_the_company(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->fakeApis(['package' => $this->trendyolPackage([
            'commercial' => true,
            'taxNumber' => '1234567890',
            'invoiceAddress' => ['company' => 'Örnek Ltd. Şti.', 'taxOffice' => 'Kadıköy'],
        ])]);

        $buyer = $this->draftFor($tenant, $order)->buyer;

        $this->assertTrue($buyer->isCompany);
        $this->assertSame('Örnek Ltd. Şti.', $buyer->name);
        $this->assertSame('1234567890', $buyer->taxNumber);
        $this->assertSame('Kadıköy', $buyer->taxOffice);
    }

    /** TCKN paylaşılmamış → GİB'in anonim kimliği. */
    #[Test]
    public function a_missing_identity_number_becomes_the_anonymous_one(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->fakeApis(['package' => $this->trendyolPackage(['identityNumber' => null])]);

        $this->assertSame(InvoiceBuyer::ANONYMOUS_TCKN, $this->draftFor($tenant, $order)->buyer->taxNumber);
    }

    #[Test]
    public function cancelled_lines_are_left_out(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $package = $this->trendyolPackage();
        $package['lines'][] = [...$package['lines'][0], 'lineId' => 2, 'productName' => 'İptal', 'orderLineItemStatusName' => 'Cancelled'];
        $this->fakeApis(['package' => $package]);

        $lines = $this->draftFor($tenant, $order)->lines;

        $this->assertCount(1, $lines);
        $this->assertSame('Kahve 250 g', $lines[0]->description);
    }

    #[Test]
    public function a_cancelled_package_is_not_invoiced(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->fakeApis(['package' => $this->trendyolPackage(['shipmentPackageStatus' => 'Cancelled'])]);

        $this->expectException(InvoiceDataUnavailable::class);
        $this->draftFor($tenant, $order);
    }

    /** KDV oranı yoksa varsayılanla KESİLMEZ — yanlış KDV yasal hatadır. */
    #[Test]
    public function a_line_without_vat_rate_is_refused(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $package = $this->trendyolPackage();
        unset($package['lines'][0]['vatRate']);
        $this->fakeApis(['package' => $package]);

        $this->expectException(InvoiceDataUnavailable::class);
        $this->draftFor($tenant, $order);
    }

    #[Test]
    public function a_package_missing_from_the_response_is_refused(): void
    {
        [$tenant, , $order] = $this->trendyolOrder();
        $this->fakeApis(['package' => null]);

        $this->expectException(InvoiceDataUnavailable::class);
        $this->draftFor($tenant, $order);
    }

    /** Alıcı `dump()`/istisna bağlamıyla log'a sızmaz. */
    #[Test]
    public function the_buyer_hides_personal_data_from_debug_output(): void
    {
        $buyer = new InvoiceBuyer('Ayşe Yılmaz', false, '12345678901', null, 'Atatürk Cad.', 'İstanbul', 'Kadıköy');

        $this->assertStringNotContainsString('Ayşe', print_r($buyer, true));
        $this->assertStringNotContainsString('12345678901', print_r($buyer, true));
    }

    private function draftFor(Tenant $tenant, Order $order): InvoiceDraft
    {
        return $this->asTenant($tenant, function () use ($order): InvoiceDraft {
            $adapter = app(AdapterRegistry::class)->for($order->connection);
            $this->assertInstanceOf(SupportsInvoiceData::class, $adapter);

            return $adapter->fetchInvoiceDraft($order);
        });
    }
}
