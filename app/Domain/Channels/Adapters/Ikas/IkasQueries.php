<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ikas;

/**
 * ikas Admin API (v2) adresleri ve GraphQL belgeleri — TEK YER.
 *
 * Kaynak: ikas'ın kendi istemci paketi `@ikas/admin-api-client` 2.1.0
 * (23 Haz 2026) içindeki ÜRETİLMİŞ v2 şema tipleri
 * (`dist/api/admin/v2/generated/index.d.ts`) + builders.ikas.com (8 Eki
 * 2026). Alan adları elle tahmin edilmedi; şemada olmayan alan istenirse
 * GraphQL bütün sorguyu reddeder ve o istek ikas'ın HATA ORANINA yazılır
 * (bkz. `IkasAdapter` sınıf notu — kalıcı engel kuralı).
 *
 * Gerçek mağazayla HENÜZ SINANMADI.
 */
final class IkasQueries
{
    /** GraphQL uç noktası — v2. builders.ikas.com cURL örneğiyle aynı. */
    public const GRAPHQL_URL = 'https://api.myikas.com/api/v2/admin/graphql';

    /**
     * Token uç noktası — `client_credentials`, form gövdesi, 4 saatlik token.
     *
     * Mağaza alt alan adı İSTEMEZ (v1 dokümanındaki `{magaza}.myikas.com`
     * biçimi eski). Private app sayfasındaki cURL örneği bu adresi kullanır.
     */
    public const TOKEN_URL = 'https://api.myikas.com/api/admin/oauth/token';

    /** `tokenEndpointFragment()` süzgeci için yol parçası. */
    public const TOKEN_PATH = '/api/admin/oauth/token';

    public const MERCHANT = <<<'GQL'
        query { getMerchant { id storeName merchantName } }
        GQL;

    public const STOCK_LOCATIONS = <<<'GQL'
        query { listStockLocation { id name type deleted } }
        GQL;

    /**
     * Ürün sayfası — varyantlar ürünün İÇİNDE gelir.
     *
     * `id` süzgeci mutabakatta kullanılır (`in`). Sıralama `createdAt`:
     * sayfa NUMARASIYLA yürünür ve değişen alana (`updatedAt`) göre
     * sıralansaydı tur sırasında güncellenen ürün sayfalar arasında kayar,
     * biri iki kez görünür, öteki HİÇ görünmezdi.
     */
    public const PRODUCTS = <<<'GQL'
        query ($pagination: PaginationInput, $id: StringFilterInput) {
          listProduct(pagination: $pagination, id: $id, sort: "createdAt") {
            count hasNext page limit
            data {
              id name description type deleted
              brand { name }
              variants {
                id sku barcodeList isActive deleted
                prices { sellPrice discountPrice currency priceListId }
                stocks { stockLocationId stockCount deleted }
                variantValues { variantTypeName variantValueName }
              }
            }
          }
        }
        GQL;

    public const SAVE_STOCKS = <<<'GQL'
        mutation ($input: SaveVariantStocksInput!) {
          saveVariantStocks(input: $input) { isSuccess errorInputs { productId variantId } }
        }
        GQL;

    public const UPDATE_PRICES = <<<'GQL'
        mutation ($input: UpdateVariantPricesInput!) {
          updateVariantPrices(input: $input) { isSuccess errorInputs { productId variantId } }
        }
        GQL;

    /**
     * Sipariş sayfası — SON GÜNCELLEMEYE göre (`updatedAt`), eskiden yeniye.
     *
     * Kişisel veri (ad, adres, telefon) İSTENMEZ: istenmeyen alan hiç
     * gelmez, ne inbox'a ne günlüğe düşer.
     */
    public const ORDERS = <<<'GQL'
        query ($pagination: PaginationInput, $updatedAt: DateFilterInput) {
          listOrder(pagination: $pagination, updatedAt: $updatedAt, sort: "updatedAt") {
            count hasNext page limit
            data {
              id orderNumber status orderPaymentStatus orderedAt updatedAt cancelledAt
              currencyCode totalPrice totalFinalPrice customerId
              orderLineItems {
                id quantity price finalPrice finalUnitPrice unitPrice status
                statusUpdatedAt originalOrderLineItemId deleted
                variant { id productId sku name }
              }
            }
          }
        }
        GQL;
}
