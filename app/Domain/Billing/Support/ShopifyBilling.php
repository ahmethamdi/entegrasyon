<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Models\Plan;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use DateTimeImmutable;
use RuntimeException;

/**
 * Shopify Billing — Shopify'dan faturalanan satıcının abonelik kapısı.
 *
 * App Store kuralı 1.2.1: Shopify mağazası olan satıcı uygulama ücretini
 * Shopify faturasıyla öder; Stripe'a yönlendirmek ret sebebidir
 * ([[shopify-app-store-kurallari]]). Hangi kiracının buradan ödediğine
 * `forTenant()` karar verir: aktif bir Shopify bağlantısı varsa.
 *
 * TAŞIMA ADAPTER'DA, MANTIK BURADA: GraphQL isteği `ShopifyAdapter::gql()`
 * ile gider (anahtar yenileme, kova, hata sınıflandırması orada); hangi
 * abonelik, hangi fiyat, hangi durum bu sınıfın işi.
 *
 * TEK AKTİF ABONELİK: Shopify uygulama başına mağazada bir aktif abonelik
 * tutar. Plan değişikliği YENİ abonelik açar; satıcı onaylayınca Shopify
 * eskisini kendisi iptal eder — Stripe'taki çift abonelik tuzağı burada
 * yoktur.
 *
 * YAZMA YOK (Stripe kapısıyla aynı kural): yerel abonelik
 * `SyncSubscriptionFromShopify` ile yazılır.
 */
class ShopifyBilling
{
    public function __construct(private readonly AdapterRegistry $adapters) {}

    /**
     * Kiracı Shopify'dan mı faturalanıyor? Aktif Shopify bağlantısı varsa
     * EN ESKİSİ (abonelik o mağazaya bağlanır; her açılışta aynı mağaza).
     */
    public function connectionForCurrentTenant(): ?ChannelConnection
    {
        return ChannelConnection::query()
            ->where('channel_type_code', 'shopify')
            ->where('status', 'active')
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Abonelik oluşturur, satıcının onay sayfası adresini döner.
     *
     * @return array{id: string, confirmationUrl: string, test: bool}
     */
    public function create(ChannelConnection $connection, Plan $plan, string $returnUrl): array
    {
        $price = $plan->shopify_price_usd;

        if ($price === null || (float) $price <= 0) {
            throw new RuntimeException("{$plan->code} planı Shopify'da satılmıyor.");
        }

        $adapter = $this->adapter($connection);
        $test = $this->isTestStore($adapter);

        $data = $adapter->gql(
            <<<'GQL'
            mutation AppSubscriptionCreate($name: String!, $returnUrl: URL!, $test: Boolean, $lineItems: [AppSubscriptionLineItemInput!]!) {
              appSubscriptionCreate(name: $name, returnUrl: $returnUrl, test: $test, lineItems: $lineItems) {
                appSubscription { id }
                confirmationUrl
                userErrors { field message }
              }
            }
            GQL,
            variables: [
                // Satıcının Shopify faturasında görünen ad.
                'name' => '34Pazar '.$plan->name,
                'returnUrl' => $returnUrl,
                'test' => $test,
                'lineItems' => [[
                    'plan' => ['appRecurringPricingDetails' => [
                        'price' => ['amount' => (string) $price, 'currencyCode' => 'USD'],
                        'interval' => 'EVERY_30_DAYS',
                    ]],
                ]],
            ],
            operation: 'AppSubscriptionCreate',
            userErrorPath: 'appSubscriptionCreate',
        );

        $id = $data['appSubscriptionCreate']['appSubscription']['id'] ?? null;
        $url = $data['appSubscriptionCreate']['confirmationUrl'] ?? null;

        if (! is_string($id) || ! is_string($url)) {
            throw new RuntimeException('Shopify abonelik yanıtı eksik.');
        }

        return ['id' => $id, 'confirmationUrl' => $url, 'test' => $test];
    }

    /**
     * Shopify'daki güncel durum — dönüşte ve webhook'ta YEREL kaydı
     * doğrulamak için. Satıcının tarayıcısından gelen parametreye
     * GÜVENİLMEZ; durum her zaman Shopify'dan okunur.
     *
     * @return array{status: string, currentPeriodEnd: ?DateTimeImmutable, test: bool}|null
     */
    public function fetch(ChannelConnection $connection, string $subscriptionId): ?array
    {
        $data = $this->adapter($connection)->gql(
            <<<'GQL'
            query AppSubscriptionNode($id: ID!) {
              node(id: $id) { ... on AppSubscription { id status currentPeriodEnd test } }
            }
            GQL,
            variables: ['id' => $subscriptionId],
            operation: 'AppSubscriptionNode',
        );

        $node = $data['node'] ?? null;

        if (! is_array($node) || ! is_string($node['status'] ?? null)) {
            return null;
        }

        return [
            'status' => $node['status'],
            'currentPeriodEnd' => is_string($node['currentPeriodEnd'] ?? null)
                ? new DateTimeImmutable($node['currentPeriodEnd'])
                : null,
            'test' => (bool) ($node['test'] ?? false),
        ];
    }

    public function cancel(ChannelConnection $connection, string $subscriptionId): void
    {
        $this->adapter($connection)->gql(
            <<<'GQL'
            mutation AppSubscriptionCancel($id: ID!) {
              appSubscriptionCancel(id: $id) {
                appSubscription { id status }
                userErrors { field message }
              }
            }
            GQL,
            variables: ['id' => $subscriptionId],
            operation: 'AppSubscriptionCancel',
            userErrorPath: 'appSubscriptionCancel',
        );
    }

    /**
     * Geliştirme mağazası gerçek ücret ÖDEYEMEZ — test aboneliği açılır.
     * App Store incelemesi de geliştirme mağazasında yapılır; test bayrağı
     * olmadan onay ekranı hata verirdi. `services.shopify.billing_test`
     * bütün mağazaları teste zorlar (canlıdan önce deneme).
     */
    private function isTestStore(ShopifyAdapter $adapter): bool
    {
        if (config('services.shopify.billing_test') === true) {
            return true;
        }

        $data = $adapter->gql(
            <<<'GQL'
            query ShopPlan { shop { plan { partnerDevelopment } } }
            GQL,
            operation: 'ShopPlan',
        );

        return ($data['shop']['plan']['partnerDevelopment'] ?? false) === true;
    }

    private function adapter(ChannelConnection $connection): ShopifyAdapter
    {
        $adapter = $this->adapters->for($connection);

        if (! $adapter instanceof ShopifyAdapter) {
            throw new RuntimeException('Bağlantı Shopify değil.');
        }

        return $adapter;
    }
}
