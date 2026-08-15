<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsTaxonomy;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Channels\FakeAdapter;
use Tests\TestCase;

/**
 * P0 testi — adapter örnekleri ASLA paylaşılmaz.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · Registry, §1 · Karar 20, §17 · P0.
 *
 * DEĞİŞMEZ KURAL:
 *   AdapterRegistry::for() HER ÇAĞRIDA yeni örnek üretir. Container'da
 *   singleton bağlama YASAKTIR ve önbellek tutulmaz.
 *
 * GEREKÇE — BU BİR GÜVENLİK KURALI:
 *   Adapter bağlantı taşır. Paylaşılan bir örnek, aynı worker sürecinde
 *   kiracı A'nın bağlantısını (ve kimlik bilgilerini) kiracı B'nin işinde
 *   kullanır. Kuyruk worker'ları uzun ömürlüdür; bu sızıntı testte değil
 *   üretimde, hem de sessizce ortaya çıkar.
 */
final class AdapterIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeAdapter::resetCounter();
    }

    /** İki kiracının bağlantısı asla aynı örneği paylaşmaz. */
    #[Test]
    public function adapter_instances_are_never_shared(): void
    {
        [$tenantA, $connectionA] = $this->makeConnection();
        [$tenantB, $connectionB] = $this->makeConnection();

        $registry = app(AdapterRegistry::class);

        $a1 = $this->asTenant($tenantA, fn () => $registry->for($connectionA));
        $b1 = $this->asTenant($tenantB, fn () => $registry->for($connectionB));
        $a2 = $this->asTenant($tenantA, fn () => $registry->for($connectionA));

        // Farklı kiracılar farklı örnek.
        $this->assertNotSame($a1, $b1);

        // AYNI bağlantı bile paylaşılmaz — önbellek yok.
        $this->assertNotSame($a1, $a2);

        // Her örnek KENDİ bağlantısını taşır.
        $this->assertSame($connectionA->id, $a1->connection()->id);
        $this->assertSame($connectionB->id, $b1->connection()->id);
        $this->assertSame($connectionA->id, $a2->connection()->id);

        // Üç çağrı, üç örnekleme.
        $this->assertSame(3, FakeAdapter::instantiations());
    }

    /**
     * Registry'nin KENDİSİ de singleton olmamalı.
     *
     * Singleton bağlanırsa gelecekte içine önbellek eklemek kolaylaşır ve
     * kural sessizce delinir. bind, her çözümlemede yeni registry verir.
     */
    #[Test]
    public function registry_itself_is_bound_not_singleton(): void
    {
        $first = app(AdapterRegistry::class);
        $second = app(AdapterRegistry::class);

        $this->assertNotSame(
            $first,
            $second,
            'AdapterRegistry container\'a bind ile bağlanmalı, singleton DEĞİL.',
        );
    }

    /** Bağlantı kimlik bilgileri örnekler arasında sızmaz. */
    #[Test]
    public function each_instance_carries_its_own_connection_identity(): void
    {
        [$tenantA, $connectionA] = $this->makeConnection(accountId: 'magaza-a.example.com');
        [$tenantB, $connectionB] = $this->makeConnection(accountId: 'magaza-b.example.com');

        $registry = app(AdapterRegistry::class);

        $a = $this->asTenant($tenantA, fn () => $registry->for($connectionA));
        $b = $this->asTenant($tenantB, fn () => $registry->for($connectionB));

        $this->assertSame('magaza-a.example.com', $a->connection()->external_account_id);
        $this->assertSame('magaza-b.example.com', $b->connection()->external_account_id);

        $this->assertSame($tenantA->id, $a->connection()->tenant_id);
        $this->assertSame($tenantB->id, $b->connection()->tenant_id);
    }

    /**
     * Yetenekler tip sisteminden okunur — panelde "if type === ..." yok.
     *
     * FakeAdapter stok destekler, katalog ve taksonomi desteklemez.
     */
    #[Test]
    public function capabilities_are_discovered_via_interfaces(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        $adapter = $this->asTenant($tenant, fn () => app(AdapterRegistry::class)->for($connection));

        $this->assertInstanceOf(SupportsInventory::class, $adapter);
        $this->assertNotInstanceOf(SupportsCatalog::class, $adapter);
        $this->assertNotInstanceOf(SupportsTaxonomy::class, $adapter);
    }

    /** Yetenek haritası panele gönderilmeye hazır biçimde üretilir. */
    #[Test]
    public function registry_exposes_capability_map_for_the_panel(): void
    {
        [$tenant, $connection] = $this->makeConnection();

        $capabilities = $this->asTenant(
            $tenant,
            fn () => app(AdapterRegistry::class)->capabilitiesFor($connection),
        );

        $this->assertTrue($capabilities['inventory']);
        $this->assertFalse($capabilities['catalog']);
        $this->assertFalse($capabilities['taxonomy']);
        $this->assertFalse($capabilities['approval']);
        $this->assertFalse($capabilities['fulfillment']);
        $this->assertFalse($capabilities['pricing']);
        $this->assertFalse($capabilities['orders']);
    }

    /** Tanımlı olmayan adapter sınıfı sessizce geçilmez. */
    #[Test]
    public function unknown_adapter_class_throws(): void
    {
        [$tenant, $connection] = $this->makeConnection(
            adapterClass: 'App\\Domain\\Channels\\Adapters\\HicOlmayanAdapter',
        );

        $this->expectException(\RuntimeException::class);

        $this->asTenant($tenant, fn () => app(AdapterRegistry::class)->for($connection));
    }

    /** ChannelAdapter uygulamayan bir sınıf reddedilir. */
    #[Test]
    public function adapter_class_not_implementing_contract_throws(): void
    {
        [$tenant, $connection] = $this->makeConnection(
            adapterClass: \stdClass::class,
        );

        $this->expectException(\RuntimeException::class);

        $this->asTenant($tenant, fn () => app(AdapterRegistry::class)->for($connection));
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function makeConnection(
        ?string $accountId = null,
        string $adapterClass = FakeAdapter::class,
        ?string $typeCode = null,
    ): array {
        $tenant = (new CreateTenant)->run(
            name: 'Adapter '.uniqid(),
            owner: User::factory()->create(),
        );

        $code = $typeCode ?? 'fake-'.substr(md5($adapterClass.$accountId), 0, 8);

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => 'Sahte Kanal',
                'kind' => 'marketplace',
                'adapter_class' => $adapterClass,
                'rate_limit_profile' => ['requests_per_second' => 5, 'burst_capacity' => 10],
                'is_active' => true,
            ],
        ));

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'channel_type_code' => $code,
            'external_account_id' => $accountId ?? uniqid().'.example.com',
        ]));

        return [$tenant, $connection];
    }
}
