<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Channels\Support\OutboundWriteGate;
use App\Domain\Channels\Support\WooAuth;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S5 · WooCommerce bağlantı sihirbazı — resmî `/wc-auth/v1/authorize`.
 *
 * Kimlik doğrulandı ≠ yazmaya yetkili (ADR-010): sihirbaz hiçbir yazma
 * iznini açmaz. Callback'in tek kimlik doğrulaması tek kullanımlık, süreli
 * nonce'tur; tekrar, süresi dolmuş ya da bilinmeyen nonce reddedilir.
 */
final class WooAuthWizardTest extends TestCase
{
    use RefreshDatabase;

    private const SYSTEM_STATUS = '*/wp-json/wc/v3/system_status*';

    private const WEBHOOKS = '*/wp-json/wc/v3/webhooks*';

    #[Test]
    public function authorize_opens_a_secretless_pending_connection_and_redirects_to_the_store(): void
    {
        Http::fake();
        [$tenant, $user] = $this->makeTenant();

        $response = $this->actingAs($user)
            ->withHeader('X-Inertia', 'true')
            ->post('/channels/woocommerce/authorize', ['label' => 'Ana Mağaza', 'store_url' => 'magaza.example.com']);

        $response->assertStatus(409);
        $location = (string) $response->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith('https://magaza.example.com/wc-auth/v1/authorize?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('read_write', $query['scope']);
        $this->assertSame(route('webhooks.woocommerce.auth'), $query['callback_url']);
        $this->assertSame(route('channels.woocommerce.return'), $query['return_url']);
        $this->assertSame(48, strlen($query['user_id']));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::query()->firstOrFail());
        $this->assertSame('pending', $connection->status);
        $this->assertNull($this->asTenant($tenant, fn () => $connection->activeCredential()->first()));
        // Ham nonce saklanmaz, yalnız özeti.
        $this->assertSame(hash('sha256', $query['user_id']), $connection->settings[WooAuth::SETTINGS_KEY]['nonce_hash']);
        $this->assertStringNotContainsString($query['user_id'], json_encode($connection->settings));
        $this->assertWritesClosed($tenant, $connection);
        Http::assertNothingSent();
    }

    #[Test]
    public function internal_store_addresses_are_rejected(): void
    {
        [$tenant, $user] = $this->makeTenant();

        foreach (['localhost', 'https://127.0.0.1', 'https://10.0.0.5', 'https://magaza.internal', 'https://magaza.example.com:8443'] as $url) {
            $this->actingAs($user)->post('/channels/woocommerce/authorize', ['label' => 'X', 'store_url' => $url])
                ->assertSessionHasErrors('store_url');
        }

        $this->assertSame(0, $this->asTenant($tenant, fn () => ChannelConnection::query()->count()));
    }

    #[Test]
    public function callback_stores_keys_once_then_health_and_webhooks_run_with_writes_closed(): void
    {
        [$tenant, $user] = $this->makeTenant();
        $nonce = $this->begin($user);

        Http::fake([
            self::SYSTEM_STATUS => Http::response(['environment' => []]),
            self::WEBHOOKS => fn (Request $r) => $r->method() === 'GET' ? Http::response([]) : Http::response(['id' => random_int(1, 999)], 201),
        ]);

        $this->postJson('/webhooks/woocommerce/auth', $this->payload($nonce))->assertOk()->assertJson(['ok' => true]);

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::query()->firstOrFail());
        $secrets = $this->asTenant($tenant, fn () => app(CredentialVault::class)->read($connection));
        $this->assertSame('ck_'.str_repeat('a', 40), $secrets['consumer_key']);
        $this->assertSame('cs_'.str_repeat('b', 40), $secrets['consumer_secret']);
        $this->assertArrayHasKey('webhook_secret', $secrets);
        $this->assertSame('active', $connection->status);
        $this->assertArrayNotHasKey(WooAuth::SETTINGS_KEY, $connection->settings);
        $this->assertSame('read_write', $connection->settings['wc_auth_key_permissions']);
        $this->assertWritesClosed($tenant, $connection);

        // Aynı nonce ikinci kez: reddedilir, anahtar değişmez.
        $this->postJson('/webhooks/woocommerce/auth', $this->payload($nonce, key: 'ck_'.str_repeat('c', 40)))->assertNotFound();
        $after = $this->asTenant($tenant, fn () => app(CredentialVault::class)->read($connection->refresh()));
        $this->assertSame('ck_'.str_repeat('a', 40), $after['consumer_key']);
    }

    #[Test]
    public function unknown_expired_or_malformed_callbacks_store_nothing(): void
    {
        Http::fake();
        [$tenant, $user] = $this->makeTenant();
        $nonce = $this->begin($user);

        $this->postJson('/webhooks/woocommerce/auth', $this->payload(str_repeat('x', 48)))->assertNotFound();
        $this->postJson('/webhooks/woocommerce/auth', $this->payload($nonce, key: 'not-a-key'))->assertStatus(400);
        $this->postJson('/webhooks/woocommerce/auth', $this->payload($nonce, permissions: 'write'))->assertStatus(400);
        $this->call('POST', '/webhooks/woocommerce/auth', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')->assertStatus(400);

        $this->travel(WooAuth::NONCE_TTL_MINUTES + 1)->minutes();
        $this->postJson('/webhooks/woocommerce/auth', $this->payload($nonce))->assertStatus(409);

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::query()->firstOrFail());
        $this->assertNull($this->asTenant($tenant, fn () => $connection->activeCredential()->first()));
        Http::assertNothingSent();
    }

    #[Test]
    public function revoked_or_wrong_keys_leave_the_connection_unhealthy(): void
    {
        [$tenant, $user] = $this->makeTenant();
        $nonce = $this->begin($user);
        Http::fake([self::SYSTEM_STATUS => Http::response(['code' => 'woocommerce_rest_cannot_view'], 401)]);

        $this->postJson('/webhooks/woocommerce/auth', $this->payload($nonce))->assertOk();

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::query()->firstOrFail());
        $this->assertNotSame('active', $connection->status);
        $this->assertSame('unhealthy', $connection->health_status);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/webhooks'));
    }

    #[Test]
    public function the_same_store_cannot_be_connected_by_another_tenant(): void
    {
        Http::fake();
        [, $userA] = $this->makeTenant('A');
        [$tenantB, $userB] = $this->makeTenant('B');
        $this->begin($userA);

        $this->actingAs($userB)->post('/channels/woocommerce/authorize', ['label' => 'Kopya', 'store_url' => 'https://magaza.example.com'])
            ->assertSessionHasErrors('store_url');

        $this->assertSame(0, $this->asTenant($tenantB, fn () => ChannelConnection::query()->count()));
    }

    #[Test]
    public function reauthorising_the_same_store_reuses_the_connection_and_keeps_the_webhook_secret(): void
    {
        [$tenant, $user] = $this->makeTenant();
        Http::fake([
            self::SYSTEM_STATUS => Http::response(['environment' => []]),
            self::WEBHOOKS => fn (Request $r) => $r->method() === 'GET' ? Http::response([]) : Http::response(['id' => 1], 201),
        ]);
        $this->postJson('/webhooks/woocommerce/auth', $this->payload($this->begin($user)))->assertOk();
        $connection = $this->asTenant($tenant, fn () => ChannelConnection::query()->firstOrFail());
        $firstSecret = $this->asTenant($tenant, fn () => app(CredentialVault::class)->read($connection)['webhook_secret']);

        $this->postJson('/webhooks/woocommerce/auth', $this->payload($this->begin($user), key: 'ck_'.str_repeat('d', 40)))->assertOk();

        $this->assertSame(1, $this->asTenant($tenant, fn () => ChannelConnection::query()->count()));
        $secrets = $this->asTenant($tenant, fn () => app(CredentialVault::class)->read($connection->refresh()));
        $this->assertSame('ck_'.str_repeat('d', 40), $secrets['consumer_key']);
        $this->assertSame($firstSecret, $secrets['webhook_secret']);
    }

    #[Test]
    public function return_url_reports_denial_without_touching_anything(): void
    {
        [, $user] = $this->makeTenant();

        $this->actingAs($user)->get('/channels/woocommerce/return?success=0&user_id=x')
            ->assertRedirect('/channels')
            ->assertSessionHas('warning');
        $this->actingAs($user)->get('/channels/woocommerce/return?success=1&user_id=x')
            ->assertRedirect('/channels')
            ->assertSessionHas('success');
    }

    #[Test]
    public function inactive_channel_type_cannot_start_the_wizard(): void
    {
        [$tenant, $user] = $this->makeTenant();
        $this->asSystem(fn () => ChannelType::query()->where('code', 'woocommerce')->update(['is_active' => false]));

        $this->actingAs($user)->post('/channels/woocommerce/authorize', ['label' => 'X', 'store_url' => 'https://magaza.example.com'])
            ->assertSessionHasErrors('store_url');

        $this->assertSame(0, $this->asTenant($tenant, fn () => ChannelConnection::query()->count()));
    }

    // ---------------------------------------------------------------- yardımcılar

    private function begin(User $user): string
    {
        $response = $this->actingAs($user)
            ->withHeader('X-Inertia', 'true')
            ->post('/channels/woocommerce/authorize', ['label' => 'Ana Mağaza', 'store_url' => 'https://magaza.example.com']);

        parse_str((string) parse_url((string) $response->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $query);
        $this->flushHeaders();

        return (string) $query['user_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $nonce, ?string $key = null, string $permissions = 'read_write'): array
    {
        return [
            'key_id' => 7,
            'user_id' => $nonce,
            'consumer_key' => $key ?? 'ck_'.str_repeat('a', 40),
            'consumer_secret' => 'cs_'.str_repeat('b', 40),
            'key_permissions' => $permissions,
        ];
    }

    private function assertWritesClosed(Tenant $tenant, ChannelConnection $connection): void
    {
        foreach ([OutboundWrite::PRODUCT_PUBLISH, OutboundWrite::PRICE_SYNC, OutboundWrite::STOCK_SYNC] as $write) {
            $this->assertFalse(
                app(OutboundWriteGate::class)->allows($connection->id, $tenant->id, $write),
                $write->name,
            );
        }
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function makeTenant(string $name = 'Woo'): array
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'storefront',
                'adapter_class' => WooCommerceAdapter::class,
                'supports_webhooks' => true,
                'is_active' => true,
            ],
        ));

        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);

        return [$tenant, $user];
    }
}
