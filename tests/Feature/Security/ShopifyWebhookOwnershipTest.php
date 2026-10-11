<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\InboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shopify webhook'u bağlantının mağazasına bağlı mı? (mimari denetim P0-5)
 *
 * Uygulama üzerinden bağlanan her mağazanın webhook'u aynı istemci sırrıyla
 * imzalanır: imza mağazayı kanıtlamaz. A mağazasının geçerli imzalı
 * webhook'u B bağlantısının adresine gelirse sipariş B kiracısına işlenirdi.
 * Her negatif durumda 401 ve inbox'a HİÇBİR satır yazılmaz.
 */
final class ShopifyWebhookOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec-sahiplik';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function a_shopify_webhook_for_another_shop_is_rejected(): void
    {
        $connection = $this->shop('benim-magazam.myshopify.com');

        $this->send($connection, 'baska-magaza.myshopify.com')->assertStatus(401);

        $this->assertSame(0, $this->asSystem(fn (): int => InboxMessage::query()->count()));
    }

    #[Test]
    public function a_shopify_webhook_without_a_shop_domain_is_rejected(): void
    {
        $connection = $this->shop('benim-magazam.myshopify.com');

        $this->send($connection, null)->assertStatus(401);

        $this->assertSame(0, $this->asSystem(fn (): int => InboxMessage::query()->count()));
    }

    /** İki gerçek mağaza: A'nın imzalı webhook'u B'nin adresine gönderilir. */
    #[Test]
    public function a_signed_webhook_cannot_be_replayed_against_another_tenants_connection(): void
    {
        $this->shop('a-magazasi.myshopify.com');
        $victim = $this->shop('b-magazasi.myshopify.com');

        $this->send($victim, 'a-magazasi.myshopify.com')->assertStatus(401);

        $this->assertSame(0, $this->asSystem(fn (): int => InboxMessage::query()->count()));
    }

    #[Test]
    public function the_own_shop_is_accepted_case_insensitively(): void
    {
        $connection = $this->shop('benim-magazam.myshopify.com');

        $this->send($connection, 'Benim-Magazam.MyShopify.com')->assertStatus(202);

        $this->assertSame(1, $this->asSystem(fn (): int => InboxMessage::query()->count()));
    }

    /** Alan adı doğru ama imza yanlış: imza kapısı hâlâ çalışır. */
    #[Test]
    public function the_signature_is_still_required(): void
    {
        $connection = $this->shop('benim-magazam.myshopify.com');

        $this->send($connection, 'benim-magazam.myshopify.com', signature: 'bozuk')->assertStatus(401);

        $this->assertSame(0, $this->asSystem(fn (): int => InboxMessage::query()->count()));
    }

    private function send(ChannelConnection $connection, ?string $shopDomain, ?string $signature = null): TestResponse
    {
        $body = (string) json_encode(['id' => 820982911946154508, 'line_items' => []]);

        $server = array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
            'HTTP_X_SHOPIFY_EVENT_ID' => 'evt-'.uniqid(),
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $signature ?? base64_encode(hash_hmac('sha256', $body, self::SECRET, true)),
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shopDomain,
        ], static fn ($v): bool => $v !== null);

        return $this->call('POST', '/webhooks/'.$connection->id, server: $server, content: $body);
    }

    private function shop(string $domain): ChannelConnection
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'shopify'], [
            'name' => 'Shopify', 'kind' => 'storefront', 'adapter_class' => ShopifyAdapter::class,
            'supports_webhooks' => true, 'is_active' => true,
        ]));

        $tenant = (new CreateTenant)->run(name: 'Sahiplik '.uniqid(), owner: User::factory()->create());

        return $this->asTenant($tenant, function () use ($domain): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'shopify',
                'external_account_id' => $domain,
                'status' => 'active',
            ]);

            app(CredentialVault::class)->store($connection, ['access_token' => 'erisim', 'webhook_secret' => self::SECRET]);

            return $connection;
        });
    }
}
