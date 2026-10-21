<?php

declare(strict_types=1);

namespace Tests\Unit\Orders;

use App\Domain\Channels\Adapters\Ciceksepeti\CiceksepetiAdapter;
use App\Domain\Channels\Adapters\Ebay\EbayAdapter;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Adapters\Hepsiburada\HepsiburadaAdapter;
use App\Domain\Channels\Adapters\Ikas\IkasAdapter;
use App\Domain\Channels\Adapters\N11\N11Adapter;
use App\Domain\Channels\Adapters\Pazarama\PazaramaAdapter;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Adapters\Ticimax\TicimaxAdapter;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Contracts\MapsOrderState;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Sync\Support\NormalizedOrderEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Her kanalın ham durum → kanonik durum eşlemesi (ADR-001).
 *
 * Değerler kanalların GERÇEK kelimeleridir (adapter sabitleri, testler,
 * canlı gözlem). `null` = durum değişmez (iade talebi, para hareketi,
 * tanınmayan kelime) — uydurma karşılık yok.
 */
final class ChannelOrderStateMappingTest extends TestCase
{
    /** @return iterable<string, array{0: class-string, 1: array<string, mixed>, 2: ?string, 3?: string}> */
    public static function mappings(): iterable
    {
        // Shopify — normalizer kargo durumunu önce, ödemeyi sonra yazar.
        yield 'shopify pending' => [ShopifyAdapter::class, ['status' => 'pending'], 'awaiting_payment'];
        yield 'shopify authorized' => [ShopifyAdapter::class, ['status' => 'authorized'], 'awaiting_payment'];
        yield 'shopify paid' => [ShopifyAdapter::class, ['status' => 'paid'], 'awaiting_shipment'];
        yield 'shopify partially_paid' => [ShopifyAdapter::class, ['status' => 'partially_paid'], 'awaiting_shipment'];
        yield 'shopify partial' => [ShopifyAdapter::class, ['status' => 'partial'], 'awaiting_shipment'];
        yield 'shopify fulfilled' => [ShopifyAdapter::class, ['status' => 'fulfilled'], 'shipped'];
        yield 'shopify cancelled' => [ShopifyAdapter::class, ['status' => 'cancelled'], 'cancelled'];
        yield 'shopify refunded → değişmez' => [ShopifyAdapter::class, ['status' => 'refunded'], null];
        yield 'shopify voided → değişmez' => [ShopifyAdapter::class, ['status' => 'voided'], null];

        // WooCommerce
        yield 'woo pending' => [WooCommerceAdapter::class, ['status' => 'pending'], 'awaiting_payment'];
        yield 'woo on-hold' => [WooCommerceAdapter::class, ['status' => 'on-hold'], 'awaiting_payment'];
        yield 'woo failed (ödeme hatası, iptal DEĞİL)' => [WooCommerceAdapter::class, ['status' => 'failed'], 'awaiting_payment'];
        yield 'woo processing' => [WooCommerceAdapter::class, ['status' => 'processing'], 'awaiting_shipment'];
        yield 'woo completed' => [WooCommerceAdapter::class, ['status' => 'completed'], 'shipped'];
        yield 'woo cancelled' => [WooCommerceAdapter::class, ['status' => 'cancelled'], 'cancelled'];
        yield 'woo refunded → değişmez' => [WooCommerceAdapter::class, ['status' => 'refunded'], null];

        // Trendyol paket durumları
        yield 'trendyol Awaiting' => [TrendyolAdapter::class, ['status' => 'Awaiting'], 'awaiting_payment'];
        yield 'trendyol Created' => [TrendyolAdapter::class, ['status' => 'Created'], 'awaiting_shipment'];
        yield 'trendyol Picking' => [TrendyolAdapter::class, ['status' => 'Picking'], 'awaiting_shipment'];
        yield 'trendyol Invoiced' => [TrendyolAdapter::class, ['status' => 'Invoiced'], 'awaiting_shipment'];
        yield 'trendyol ReadyToShip' => [TrendyolAdapter::class, ['status' => 'ReadyToShip'], 'awaiting_shipment'];
        yield 'trendyol Shipped' => [TrendyolAdapter::class, ['status' => 'Shipped'], 'shipped'];
        yield 'trendyol AtCollectionPoint' => [TrendyolAdapter::class, ['status' => 'AtCollectionPoint'], 'shipped'];
        yield 'trendyol UnDelivered' => [TrendyolAdapter::class, ['status' => 'UnDelivered'], 'shipped'];
        yield 'trendyol Delivered' => [TrendyolAdapter::class, ['status' => 'Delivered'], 'delivered'];
        yield 'trendyol Cancelled' => [TrendyolAdapter::class, ['status' => 'Cancelled'], 'cancelled'];
        yield 'trendyol UnSupplied' => [TrendyolAdapter::class, ['status' => 'UnSupplied'], 'cancelled'];
        yield 'trendyol UnPacked' => [TrendyolAdapter::class, ['status' => 'UnPacked'], 'superseded'];
        yield 'trendyol Returned → sayaçtan' => [TrendyolAdapter::class, ['status' => 'Returned', 'financial_status' => 'refunded'], null];

        // Hepsiburada
        yield 'hb Open' => [HepsiburadaAdapter::class, ['status' => 'Open'], 'awaiting_shipment'];

        // N11 (yazım tutarsız)
        yield 'n11 Created' => [N11Adapter::class, ['status' => 'Created'], 'awaiting_shipment'];
        yield 'n11 Picking' => [N11Adapter::class, ['status' => 'Picking'], 'awaiting_shipment'];
        yield 'n11 Shipped' => [N11Adapter::class, ['status' => 'Shipped'], 'shipped'];
        yield 'n11 Delivered' => [N11Adapter::class, ['status' => 'Delivered'], 'delivered'];
        yield 'n11 Cancelled' => [N11Adapter::class, ['status' => 'Cancelled'], 'cancelled'];
        yield 'n11 Canceled' => [N11Adapter::class, ['status' => ' canceled '], 'cancelled'];
        yield 'n11 UnSupplied' => [N11Adapter::class, ['status' => 'UnSupplied'], 'cancelled'];

        // Çiçeksepeti
        yield 'cs Created' => [CiceksepetiAdapter::class, ['status' => 'Created'], 'awaiting_shipment'];
        yield 'cs Picking' => [CiceksepetiAdapter::class, ['status' => 'Picking'], 'awaiting_shipment'];
        yield 'cs Shipped' => [CiceksepetiAdapter::class, ['status' => 'Shipped'], 'shipped'];
        yield 'cs Delivered' => [CiceksepetiAdapter::class, ['status' => 'Delivered'], 'delivered'];
        yield 'cs Cancelled' => [CiceksepetiAdapter::class, ['status' => 'Cancelled'], 'cancelled'];
        yield 'cs ReturnRequested → değişmez' => [CiceksepetiAdapter::class, ['status' => 'ReturnRequested'], null];
        yield 'cs bilinmeyen kod → değişmez' => [CiceksepetiAdapter::class, ['status' => '4'], null];

        // Pazarama
        yield 'pz Created' => [PazaramaAdapter::class, ['status' => 'Created'], 'awaiting_shipment'];
        yield 'pz Shipped' => [PazaramaAdapter::class, ['status' => 'Shipped'], 'shipped'];
        yield 'pz AtCollectionPoint' => [PazaramaAdapter::class, ['status' => 'AtCollectionPoint'], 'shipped'];
        yield 'pz Delivered' => [PazaramaAdapter::class, ['status' => 'Delivered'], 'delivered'];
        yield 'pz Cancelled' => [PazaramaAdapter::class, ['status' => 'Cancelled'], 'cancelled'];
        yield 'pz UnSupplied' => [PazaramaAdapter::class, ['status' => 'UnSupplied'], 'cancelled'];
        yield 'pz CancelRequested → değişmez' => [PazaramaAdapter::class, ['status' => 'CancelRequested'], null];
        yield 'pz ReturnRejected → değişmez' => [PazaramaAdapter::class, ['status' => 'ReturnRejected'], null];

        // ikas
        yield 'ikas CREATED' => [IkasAdapter::class, ['status' => 'CREATED', 'financial_status' => 'paid'], 'awaiting_shipment'];
        yield 'ikas CANCELLED' => [IkasAdapter::class, ['status' => 'CANCELLED'], 'cancelled'];
        yield 'ikas PARTIALLY_CANCELLED → sayaçtan' => [IkasAdapter::class, ['status' => 'PARTIALLY_CANCELLED'], null];

        // eBay (`orderStatusKey` biçimi: iptal | ödeme:kargo)
        yield 'ebay pending' => [EbayAdapter::class, ['status' => 'pending:not_started'], 'awaiting_payment'];
        yield 'ebay paid' => [EbayAdapter::class, ['status' => 'paid:not_started'], 'awaiting_shipment'];
        yield 'ebay in progress' => [EbayAdapter::class, ['status' => 'paid:in_progress'], 'awaiting_shipment'];
        yield 'ebay fulfilled' => [EbayAdapter::class, ['status' => 'paid:fulfilled'], 'shipped'];
        yield 'ebay cancelled' => [EbayAdapter::class, ['status' => 'cancelled'], 'cancelled'];
        yield 'ebay refunded → değişmez' => [EbayAdapter::class, ['status' => 'refunded:fulfilled'], null];

        // Etsy (`statusKey` biçimi)
        yield 'etsy Payment Processing' => [EtsyAdapter::class, ['status' => 'Payment Processing'], 'awaiting_payment'];
        yield 'etsy Paid' => [EtsyAdapter::class, ['status' => 'Paid'], 'awaiting_shipment'];
        yield 'etsy Completed' => [EtsyAdapter::class, ['status' => 'Completed'], 'shipped'];
        yield 'etsy Canceled' => [EtsyAdapter::class, ['status' => 'Canceled'], 'cancelled'];
        yield 'etsy Fully Refunded → değişmez' => [EtsyAdapter::class, ['status' => 'Fully Refunded', 'financial_status' => 'refunded'], null];

        // Ticimax — mağaza METNİ değil durum KODU
        yield 'ticimax yeni sipariş' => [TicimaxAdapter::class, ['status' => 'Onay Bekliyor', 'status_code' => 1], 'awaiting_shipment', 'created'];
        yield 'ticimax 6 kargoda' => [TicimaxAdapter::class, ['status' => 'Kargoya verildi', 'status_code' => 6], 'shipped', 'created'];
        yield 'ticimax 7 teslim' => [TicimaxAdapter::class, ['status' => 'x', 'status_code' => '7'], 'delivered', 'updated'];
        yield 'ticimax 8 iptal' => [TicimaxAdapter::class, ['status' => 'İptal edildi', 'status_code' => 8], 'cancelled', 'cancelled'];
        yield 'ticimax 9 iade → sayaçtan' => [TicimaxAdapter::class, ['status' => 'İade', 'status_code' => 9], null, 'returned'];
    }

    /** @param class-string $adapter */
    #[Test]
    #[DataProvider('mappings')]
    public function each_channel_maps_its_raw_status(string $adapter, array $payload, ?string $expected, string $type = 'updated'): void
    {
        /** @var MapsOrderState $instance */
        $instance = (new ReflectionClass($adapter))->newInstanceWithoutConstructor();

        $state = $instance->orderStateFor(new NormalizedOrderEvent($type, 'X-1', null, $payload));

        $this->assertSame($expected, $state?->value);
    }

    /** Sipariş alan HER adapter eşleme yeteneği taşımalı — yeni kanal unutulmasın. */
    #[Test]
    public function every_order_channel_maps_states(): void
    {
        $missing = [];

        foreach (glob(dirname(__DIR__, 3).'/app/Domain/Channels/Adapters/*/*Adapter.php') ?: [] as $file) {
            $class = 'App\\Domain\\Channels\\Adapters\\'.basename(dirname($file)).'\\'.basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, SupportsOrders::class) && ! is_subclass_of($class, MapsOrderState::class)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing);
    }
}
