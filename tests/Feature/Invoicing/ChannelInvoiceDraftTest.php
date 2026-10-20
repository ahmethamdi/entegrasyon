<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Domain\Channels\Contracts\SupportsInvoiceData;
use App\Domain\Channels\Contracts\SupportsInvoiceUpload;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoicing\Exceptions\InvoiceDataUnavailable;
use App\Domain\Invoicing\Support\InvoiceBuyer;
use App\Domain\Invoicing\Support\InvoiceDraft;
use App\Domain\Orders\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S6 · faturayı kanallara yay — Hepsiburada, n11, Shopify taslakları.
 *
 * Yanıt biçimleri: HB resmî OpenAPI (`docs/hepsiburada-openapi/siparis.json`),
 * n11 developer.n11.com (`docs/YENI-KANALLAR-API-NOTLARI.md` §4), Shopify
 * Admin GraphQL. Üç kanalda da alıcı ANLIK okunur, KDV'siz kalemle fatura
 * KESİLMEZ ve dosya yükleme YOKTUR (HB/n11 yalnız bağlantı alır, Shopify
 * hiç almaz).
 */
final class ChannelInvoiceDraftTest extends TestCase
{
    use InvoicingFixtures;
    use RefreshDatabase;

    // ─────────────────────────────────────────────────── Hepsiburada

    #[Test]
    public function hepsiburada_reads_the_order_detail_live(): void
    {
        [$tenant, , $order] = $this->trendyolOrder('hepsiburada');
        $this->fakeChannel($this->hbOrder());

        $draft = $this->draftFor($tenant, $order);

        $request = $this->sent[0];
        $this->assertSame('https://oms-external.hepsiburada.com/orders/merchantid/hb-merchant-1/ordernumber/10500001', $request->url());
        $this->assertSame('Basic '.base64_encode('hb-merchant-1:hb_servis_anahtari_123'), $request->header('Authorization')[0]);

        $this->assertSame('Ayşe Yılmaz', $draft->buyer->name);
        $this->assertSame('12345678901', $draft->buyer->taxNumber);
        $this->assertSame('Kadıköy', $draft->buyer->district);
        $this->assertCount(1, $draft->lines, 'İptal kalemi faturaya girdi.');
        $this->assertSame(2, $draft->lines[0]->quantity);
        $this->assertSame('120.0000', $draft->lines[0]->grossUnitPrice);
        $this->assertSame(20, $draft->lines[0]->vatRate);
        $this->assertSame('Hepsiburada', $draft->platformName);
    }

    #[Test]
    public function hepsiburada_company_buyer_and_fractional_vat(): void
    {
        [$tenant, , $order] = $this->trendyolOrder('hepsiburada');
        $this->fakeChannel($this->hbOrder([
            'invoice' => ['taxNumber' => '1234567890', 'taxOffice' => 'Kadıköy', 'turkishIdentityNumber' => null, 'address' => ['name' => 'Örnek Ltd.']],
        ], vat: 0.1));

        $draft = $this->draftFor($tenant, $order);

        $this->assertTrue($draft->buyer->isCompany);
        $this->assertSame('Örnek Ltd.', $draft->buyer->name);
        $this->assertSame('Kadıköy', $draft->buyer->taxOffice);
        $this->assertSame(10, $draft->lines[0]->vatRate);
    }

    #[Test]
    public function hepsiburada_refuses_a_line_without_vat_and_a_missing_order(): void
    {
        [$tenant, , $order] = $this->trendyolOrder('hepsiburada');

        $this->fakeChannel($this->hbOrder(vat: null));
        $this->assertUnavailable($tenant, $order);

        $this->fakeChannel([[], 404]);
        $this->assertUnavailable($tenant, $order);
    }

    // ─────────────────────────────────────────────────── n11

    #[Test]
    public function n11_uses_the_seller_invoice_amount_and_skips_depot_and_cancelled_lines(): void
    {
        [$tenant, , $order] = $this->trendyolOrder('n11');
        $this->fakeChannel(['content' => [$this->n11Package()]]);

        $draft = $this->draftFor($tenant, $order);

        $query = $this->sent[0]->data();
        $this->assertStringContainsString('/rest/delivery/v1/shipmentPackages', $this->sent[0]->url());
        $this->assertSame('10500001', (string) $query['orderNumber']);
        $this->assertSame('SELLER', $query['sender']);

        $this->assertCount(1, $draft->lines);
        // sellerInvoiceAmount 220 / 2 adet — n11'in karşıladığı indirim düşülmez.
        $this->assertSame('110.0000', $draft->lines[0]->grossUnitPrice);
        $this->assertSame(20, $draft->lines[0]->vatRate);
        $this->assertSame('Ayşe Yılmaz', $draft->buyer->name);
        $this->assertSame(InvoiceBuyer::ANONYMOUS_TCKN, $draft->buyer->taxNumber);
        $this->assertSame('n11', $draft->platformName);
        $this->assertSame(1759928400, $draft->orderedAt->getTimestamp());
    }

    #[Test]
    public function n11_with_only_cancelled_packages_is_not_invoiced(): void
    {
        [$tenant, , $order] = $this->trendyolOrder('n11');
        $this->fakeChannel(['content' => [$this->n11Package(['shipmentPackageStatus' => 'Cancelled'])]]);

        $this->assertUnavailable($tenant, $order);
    }

    // ─────────────────────────────────────────────────── Shopify

    #[Test]
    public function shopify_reads_the_order_by_gid_and_grosses_up_exclusive_prices(): void
    {
        [$tenant, , $order] = $this->trendyolOrder('shopify');
        $this->fakeChannel(['data' => ['order' => $this->shopifyOrder(taxesIncluded: false)]]);

        $draft = $this->draftFor($tenant, $order);

        $body = $this->sent[0]->data();
        $this->assertSame('gid://shopify/Order/9001', $body['variables']['id']);
        $this->assertSame('shpat_fatura_test_123', $this->sent[0]->header('X-Shopify-Access-Token')[0]);

        $this->assertCount(1, $draft->lines, 'Tamamı iade edilen kalem faturaya girdi.');
        $this->assertSame('120.0000', $draft->lines[0]->grossUnitPrice);
        $this->assertSame(20, $draft->lines[0]->vatRate);
        $this->assertSame('Ayşe Yılmaz', $draft->buyer->name);
        $this->assertSame(InvoiceBuyer::ANONYMOUS_TCKN, $draft->buyer->taxNumber);
        $this->assertSame('https://fatura-test.myshopify.com', $draft->platformUrl);
    }

    #[Test]
    public function shopify_inclusive_prices_are_used_as_they_are(): void
    {
        [$tenant, , $order] = $this->trendyolOrder('shopify');
        $this->fakeChannel(['data' => ['order' => $this->shopifyOrder(taxesIncluded: true)]]);

        $this->assertSame('100.0000', $this->draftFor($tenant, $order)->lines[0]->grossUnitPrice);
    }

    #[Test]
    public function shopify_without_tax_lines_cancelled_or_without_customer_access_is_refused(): void
    {
        [$tenant, , $order] = $this->trendyolOrder('shopify');

        $this->fakeChannel(['data' => ['order' => $this->shopifyOrder(taxesIncluded: true, taxLines: [])]]);
        $this->assertUnavailable($tenant, $order);

        $this->fakeChannel(['data' => ['order' => [...$this->shopifyOrder(taxesIncluded: true), 'cancelledAt' => '2026-10-09T10:00:00Z']]]);
        $this->assertUnavailable($tenant, $order);

        $this->fakeChannel([
            'data' => ['order' => null],
            'errors' => [['message' => 'Access denied for billingAddress field.', 'extensions' => ['code' => 'ACCESS_DENIED']]],
        ]);
        $this->assertUnavailable($tenant, $order, 'korumalı müşteri verisi');
    }

    // ─────────────────────────────────────────────────── yetenek

    #[Test]
    public function the_three_channels_draft_invoices_but_take_no_files(): void
    {
        foreach (['hepsiburada', 'n11', 'shopify'] as $channel) {
            [$tenant, , $order] = $this->trendyolOrder($channel);

            $this->asTenant($tenant, function () use ($order, $channel): void {
                $adapter = app(AdapterRegistry::class)->for($order->connection);
                $this->assertInstanceOf(SupportsInvoiceData::class, $adapter, $channel);
                $this->assertNotInstanceOf(SupportsInvoiceUpload::class, $adapter, $channel);
            });
        }
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** Tek sahte yanıt; gönderilen istekler `$this->sent`'e. Tekrar çağrılınca yanıt değişir. */
    private function fakeChannel(array $response): void
    {
        $this->sent = [];
        $this->apiOptions = ['response' => array_is_list($response) ? $response : [$response, 200]];

        if ($this->apisFaked) {
            return;
        }

        $this->apisFaked = true;

        Http::fake(function (HttpRequest $request) {
            $this->sent[] = $request;

            return Http::response(...$this->apiOptions['response']);
        });
    }

    private function hbOrder(array $overrides = [], float|int|null $vat = 20): array
    {
        return array_replace_recursive([
            'orderNumber' => '10500001',
            'orderDate' => '2026-10-08T13:00:00',
            'customer' => ['name' => 'Ayşe Yılmaz'],
            'invoice' => [
                'taxNumber' => null,
                'taxOffice' => null,
                'turkishIdentityNumber' => '12345678901',
                'address' => ['name' => 'Ayşe Yılmaz', 'address' => 'Atatürk Cad. No:1', 'city' => 'İstanbul', 'town' => 'Kadıköy', 'countryCode' => 'TR', 'email' => 'x@hepsiburada.com'],
            ],
            'items' => [
                ['orderNumber' => '10500001', 'name' => 'Kahve 250 g', 'merchantSKU' => 'KHV-1', 'quantity' => 2, 'status' => 'Open', 'vatRate' => $vat, 'totalPrice' => ['amount' => 240.00, 'currency' => 'TRY']],
                ['orderNumber' => '10500001', 'name' => 'Çay', 'merchantSKU' => 'CAY-1', 'quantity' => 1, 'status' => 'CancelledByCustomer', 'vatRate' => 20, 'totalPrice' => ['amount' => 50.00, 'currency' => 'TRY']],
            ],
        ], $overrides);
    }

    private function n11Package(array $overrides = []): array
    {
        return array_replace_recursive([
            'orderNumber' => '10500001',
            'shipmentPackageStatus' => 'Picking',
            'customerfullName' => 'Ayşe Yılmaz',
            'customerEmail' => 'x@n11.com',
            'taxId' => null,
            'billingAddress' => ['fullName' => 'Ayşe Yılmaz', 'address' => 'Atatürk Cad. No:1', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'tcId' => null],
            'packageHistories' => [['createdDate' => 1759928400000, 'status' => 'Created']],
            'lines' => [
                ['orderLineId' => 1, 'stockCode' => 'KHV-1', 'productName' => 'Kahve', 'quantity' => 2, 'price' => 120, 'sellerInvoiceAmount' => 220, 'vatRate' => 20, 'orderItemLineItemStatusName' => 'Picking', 'sender' => 'SELLER'],
                ['orderLineId' => 2, 'stockCode' => 'DEP-1', 'productName' => 'Depo', 'quantity' => 1, 'price' => 50, 'sellerInvoiceAmount' => 50, 'vatRate' => 20, 'orderItemLineItemStatusName' => 'Picking', 'sender' => 'N11'],
                ['orderLineId' => 3, 'stockCode' => 'CAY-1', 'productName' => 'Çay', 'quantity' => 1, 'price' => 50, 'sellerInvoiceAmount' => 50, 'vatRate' => 20, 'orderItemLineItemStatusName' => 'Cancelled', 'sender' => 'SELLER'],
            ],
        ], $overrides);
    }

    private function shopifyOrder(bool $taxesIncluded, ?array $taxLines = null): array
    {
        return [
            'name' => '#1001',
            'createdAt' => '2026-10-08T10:00:00Z',
            'cancelledAt' => null,
            'currencyCode' => 'TRY',
            'taxesIncluded' => $taxesIncluded,
            'email' => 'x@example.com',
            'billingAddress' => ['name' => 'Ayşe Yılmaz', 'company' => null, 'address1' => 'Atatürk Cad. No:1', 'address2' => null, 'city' => 'Kadıköy', 'province' => 'İstanbul', 'countryCodeV2' => 'TR'],
            'lineItems' => ['nodes' => [
                ['title' => 'Kahve', 'sku' => 'KHV-1', 'currentQuantity' => 2, 'discountedUnitPriceAfterAllDiscountsSet' => ['shopMoney' => ['amount' => '100.00']], 'taxLines' => $taxLines ?? [['rate' => 0.2]]],
                ['title' => 'İade edilen', 'sku' => 'IAD-1', 'currentQuantity' => 0, 'discountedUnitPriceAfterAllDiscountsSet' => ['shopMoney' => ['amount' => '10.00']], 'taxLines' => [['rate' => 0.2]]],
            ]],
        ];
    }

    private function assertUnavailable(Tenant $tenant, Order $order, ?string $contains = null): void
    {
        try {
            $this->draftFor($tenant, $order);
            $this->fail('Faturalanamayan sipariş için taslak üretildi.');
        } catch (InvoiceDataUnavailable $e) {
            $this->assertNotSame('', $e->getMessage());

            if ($contains !== null) {
                $this->assertStringContainsString($contains, $e->getMessage());
            }
        }
    }

    private function draftFor(Tenant $tenant, Order $order): InvoiceDraft
    {
        return $this->asTenant($tenant, fn (): InvoiceDraft => app(AdapterRegistry::class)->for($order->connection)->fetchInvoiceDraft($order));
    }
}
