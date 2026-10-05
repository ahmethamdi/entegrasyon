<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Actions\RegisterChannelWebhooks;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Webhook konuları BİRBİRİNDEN BAĞIMSIZ kurulur (5 Eki 2026).
 *
 * Sipariş konuları Shopify'ın korumalı müşteri verisi onayı yoksa
 * reddedilir. Eskiden ilk ret döngüyü keserdi: kaldırma bildirimi hiç
 * kurulmaz, kaldırılan uygulamanın bağlantısı `active` kalırdı.
 */
final class ShopifyWebhookRegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_refused_topic_does_not_stop_the_others(): void
    {
        $connection = $this->connection();

        Http::fake(['*/admin/api/*/graphql.json' => function (Request $r) {
            $q = (string) $r['query'];

            if (str_contains($q, 'WebhookList')) {
                return Http::response(['data' => ['webhookSubscriptions' => ['nodes' => []]]]);
            }

            $refused = $r['variables']['topic'] === 'ORDERS_CREATE';

            return Http::response(['data' => ['webhookSubscriptionCreate' => [
                'webhookSubscription' => $refused ? null : ['id' => 'gid://shopify/WebhookSubscription/1'],
                'userErrors' => $refused ? [['field' => ['topic'], 'message' => 'Protected customer data access required']] : [],
            ]]]);
        }]);

        $ok = TenantContext::runAsSystem(fn () => app(RegisterChannelWebhooks::class)->run($connection));

        $attempted = collect(Http::recorded())->map(fn ($pair) => $pair[0]['variables']['topic'] ?? null)->filter()->values()->all();

        $this->assertSame(ShopifyAdapter::WEBHOOK_TOPICS, $attempted, 'Ret sonrası kalan konular da denenmeli.');
        $this->assertSame('APP_UNINSTALLED', $attempted[0], 'Kaldırma bildirimi korumalı konulardan önce kurulmalı.');
        $this->assertFalse($ok);

        $error = TenantContext::runAsSystem(fn () => $connection->fresh()->settings['webhooks']['error']);
        $this->assertStringContainsString('ORDERS_CREATE', $error, 'Reddedilen konu adıyla görünmeli.');
        $this->assertStringNotContainsString('ORDERS_UPDATED', $error);
    }

    private function connection(): ChannelConnection
    {
        config()->set('services.shopify.client_secret', 'sir');

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'shopify'], [
            'name' => 'Shopify', 'kind' => 'storefront', 'adapter_class' => ShopifyAdapter::class,
            'supports_webhooks' => true, 'is_active' => true,
        ]));

        $tenant = (new CreateTenant)->run(name: 'Webhook '.uniqid(), owner: User::factory()->create());

        return $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'shopify',
                'external_account_id' => 'magaza.myshopify.com',
                'status' => 'active',
            ]);
            app(CredentialVault::class)->store($connection, ['access_token' => 'erisim', 'webhook_secret' => 'whsec']);

            return $connection;
        });
    }
}
