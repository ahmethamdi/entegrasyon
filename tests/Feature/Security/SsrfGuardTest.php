<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Exceptions\BlockedDestinationException;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Channels\Support\OutboundUrlGuard;
use App\Domain\Channels\Support\StoreUrl;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Logging\PayloadRedactor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B3 · SSRF — sunucu satıcının yazdığı adrese istek atar; iç ağa ATMAMALI.
 *
 * Önceden `127.0.0.1`, `169.254.169.254` (bulut metadata), `postgres` ve
 * `redis` (Docker servis adları) mağaza adresi olarak kabul ediliyor ve
 * sunucu oraya kasadaki anahtarla istek atıyordu; yanıt api_calls'a
 * yazıldığı için iç servis panelden OKUNABİLİYORDU.
 */
final class SsrfGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string}> */
    public static function internalAddresses(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'localhost' => ['localhost'];
        yield 'bulut metadata' => ['169.254.169.254'];
        yield 'özel 10/8' => ['10.0.0.5'];
        yield 'özel 192.168' => ['https://192.168.1.10/magaza'];
        yield 'özel 172.16/12' => ['172.20.0.3'];
        yield 'taşıyıcı NAT' => ['100.64.1.1'];
        yield 'docker servisi' => ['postgres'];
        yield 'docker servisi + port' => ['redis:6379'];
        yield 'IPv6 loopback' => ['https://[::1]/'];
        yield 'IPv4 eşlemeli IPv6' => ['https://[::ffff:10.0.0.1]/'];
        yield '.local' => ['magaza.local'];
        yield '.internal' => ['metadata.google.internal'];
        yield 'genel adres + iç port' => ['magaza.example.com:8080'];
    }

    #[Test]
    #[DataProvider('internalAddresses')]
    public function an_internal_store_address_is_rejected_at_parse_time(string $input): void
    {
        $this->expectException(BlockedDestinationException::class);

        StoreUrl::parse($input);
    }

    /** Gerçek mağaza adresleri etkilenmez. */
    #[Test]
    public function public_store_addresses_still_parse(): void
    {
        $this->assertSame('magaza.example.com', StoreUrl::parse('https://Magaza.example.com/')->host);
        $this->assertSame('magaza.myshopify.com', StoreUrl::parse('magaza.myshopify.com')->host);
        $this->assertSame('magaza.example.com:443', StoreUrl::parse('magaza.example.com:443')->host);
        $this->assertSame('93.184.216.34', StoreUrl::parse('93.184.216.34')->host);
    }

    /** Panelden iç adres bağlanamaz; form hatası döner, istek GİTMEZ. */
    #[Test]
    public function the_panel_refuses_to_connect_an_internal_address(): void
    {
        [$tenant, $user] = $this->context();

        $this->actingAs($user)->post('/channels', [
            'channel_type_code' => 'woocommerce',
            'label' => 'İç ağ',
            'store_url' => '169.254.169.254',
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ])->assertSessionHasErrors();

        Http::assertNothingSent();
        $this->assertSame(0, TenantContext::runAsSystem(
            fn (): int => ChannelConnection::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
        ));
    }

    /**
     * Ad kayıtta zararsız görünse de İSTEK ANINDA iç adrese çözülüyorsa
     * istek gitmez (DNS rebinding / iç ağı gösteren genel ad).
     */
    #[Test]
    public function a_name_resolving_to_an_internal_address_is_blocked_at_request_time(): void
    {
        $this->resolvesTo(['10.0.0.5']);

        [$tenant] = $this->context();
        $client = $this->clientFor($tenant, 'https://kotu.example.com/wp-json/wc/v3');

        try {
            TenantContext::runFor($tenant->id, fn () => $client->get('products'));
            $this->fail('İç adrese çözülen ada istek GİTMEMELİ.');
        } catch (BlockedDestinationException) {
        }

        Http::assertNothingSent();

        // Engellenen istek de iz bırakır.
        $this->assertSame('VALIDATION', TenantContext::runAsSystem(
            fn () => DB::table('api_calls')->where('tenant_id', $tenant->id)->value('error_class'),
        ));
    }

    /** Adreslerden BİRİ bile iç ağdaysa engellenir. */
    #[Test]
    public function one_internal_address_among_many_is_enough_to_block(): void
    {
        $guard = new OutboundUrlGuard(static fn (): array => ['93.184.216.34', '127.0.0.1']);

        $this->expectException(BlockedDestinationException::class);

        $guard->resolve('karisik.example.com');
    }

    /** Genel adrese çözülen ad normal çalışır. */
    #[Test]
    public function a_public_name_is_requested_normally(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        [$tenant] = $this->context();
        $client = $this->clientFor($tenant, 'https://iyi.example.com/wp-json/wc/v3');

        TenantContext::runFor($tenant->id, fn () => $client->get('products'));

        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @param list<string> $ips */
    private function resolvesTo(array $ips): void
    {
        $this->app->instance(OutboundUrlGuard::class, new OutboundUrlGuard(static fn (): array => $ips));
    }

    /** @return array{0: Tenant, 1: User} */
    private function context(): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'SSRF '.uniqid(), owner: $user);

        TenantContext::runAsSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'storefront',
                'adapter_class' => WooCommerceAdapter::class,
                'is_active' => true,
            ],
        ));

        return [$tenant, $user];
    }

    private function clientFor(Tenant $tenant, string $baseUrl): ChannelHttpClient
    {
        $connection = TenantContext::runFor($tenant->id, fn () => ChannelConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_type_code' => 'woocommerce',
            'settings' => ['base_url' => $baseUrl],
        ]));

        return new ChannelHttpClient(
            connection: $connection,
            vault: app(CredentialVault::class),
            redactor: app(PayloadRedactor::class),
        );
    }
}
