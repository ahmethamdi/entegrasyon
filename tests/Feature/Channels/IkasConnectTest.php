<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Actions\ConnectChannel;
use App\Domain\Channels\Adapters\Ikas\IkasAdapter;
use App\Domain\Channels\Adapters\Ikas\IkasQueries;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelConnectForm;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ikas bağlanırken uygulama çifti İLK ERİŞİM ANAHTARIYLA değiştirilir.
 *
 * Değiştirilmeseydi sağlık kontrolü kimliksiz gider, doğru çift "yanlış"
 * görünür ve bağlantı hiç `active` olmazdı. Süre kasaya yazılmalı:
 * yazılmasaydı tarama anahtarı hiç yenilemez, 4 saat sonra bağlantı ölürdü.
 */
final class IkasConnectTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function connecting_exchanges_the_client_pair_for_a_token_then_checks_health(): void
    {
        Http::fake([
            IkasQueries::TOKEN_URL => Http::response(['access_token' => 'TOKEN-1', 'expires_in' => 14400]),
            IkasQueries::GRAPHQL_URL => Http::response(['data' => ['getMerchant' => ['id' => 'm1', 'storeName' => 'magazam']]]),
        ]);

        $tenant = $this->makeTenant();
        $connection = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect());

        $this->assertSame('active', $connection->status);
        $this->assertSame('magazam', $connection->external_account_id);

        $secrets = $this->asTenant($tenant, fn (): array => app(CredentialVault::class)->read($connection));
        $this->assertSame('TOKEN-1', $secrets['access_token']);
        $this->assertSame('CSECRET-123', $secrets['client_secret']);

        $expiresAt = DB::table('channel_credentials')->where('channel_connection_id', $connection->id)->whereNull('revoked_at')->value('expires_at');
        $this->assertNotNull($expiresAt);

        // Önce anahtar, sonra kontrol — kontrol Bearer ile gider.
        $recorded = Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all();
        $this->assertSame([IkasQueries::TOKEN_URL, IkasQueries::GRAPHQL_URL], $recorded);
        Http::assertSent(static fn (Request $r): bool => $r->url() === IkasQueries::GRAPHQL_URL && $r->hasHeader('Authorization', 'Bearer TOKEN-1'));
    }

    /**
     * Çift reddedilirse bağlantı `pending` kalır, kanalın sebebi korunur ve
     * kimliksiz sağlık isteği ATILMAZ (ikas hata oranı).
     */
    #[Test]
    public function a_rejected_client_pair_leaves_the_connection_pending_without_further_calls(): void
    {
        Http::fake([
            IkasQueries::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401),
            '*' => Http::response(['data' => []]),
        ]);

        $tenant = $this->makeTenant();
        $connection = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect());

        $this->assertSame('pending', $connection->status);
        $this->assertSame('unhealthy', $connection->health_status);
        $this->assertNotNull($connection->last_error);

        Http::assertNotSent(static fn (Request $r): bool => $r->url() === IkasQueries::GRAPHQL_URL);
    }

    /** Form tanımı adapter sabitleriyle konuşur; hesap kimliği mağaza adıdır. */
    #[Test]
    public function the_form_declares_the_token_exchange(): void
    {
        $this->assertTrue(ChannelConnectForm::exchangesToken('ikas'));
        $this->assertFalse(ChannelConnectForm::exchangesToken('trendyol'));
        $this->assertSame(IkasAdapter::STORE_NAME_KEY, ChannelConnectForm::accountField('ikas'));
        $this->assertSame(['client_id', 'client_secret'], array_column(ChannelConnectForm::secretFields('ikas'), 'name'));
    }

    private function connect(): ChannelConnection
    {
        return app(ConnectChannel::class)->run(
            channelTypeCode: 'ikas',
            label: 'ikas mağazam',
            storeUrl: null,
            secrets: ['client_id' => 'CID', 'client_secret' => 'CSECRET-123'],
            settings: [IkasAdapter::STORE_NAME_KEY => 'magazam'],
            accountId: 'magazam',
        );
    }

    private function makeTenant(): Tenant
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'ikas'],
            [
                'name' => 'ikas',
                'kind' => 'storefront',
                'adapter_class' => IkasAdapter::class,
                'supports_webhooks' => false,
                'is_active' => true,
            ],
        ));

        return (new CreateTenant)->run(name: 'ikas Bağlan '.uniqid(), owner: User::factory()->create());
    }
}
