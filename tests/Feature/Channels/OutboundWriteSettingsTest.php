<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Actions\ConnectChannel;
use App\Domain\Channels\Actions\SetOutboundWrite;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Enums\OutboundWrite;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Consumers\InventoryLevelChangedConsumer;
use App\Domain\Messaging\Consumers\VariantPriceChangedConsumer;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Support\Channels\ProgrammableInventoryAdapter;
use Tests\TestCase;

/**
 * ADR-010 · Yazma izinlerinin YÖNETİMİ: açık eylem, doğrulanmamış kanal
 * kilidi, geçiş kuralı, fan-out planlaması ve panel görünümü.
 */
final class OutboundWriteSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        ProgrammableInventoryAdapter::reset();
    }

    protected function tearDown(): void
    {
        ProgrammableInventoryAdapter::reset();
        parent::tearDown();
    }

    // ─────────────────────────────────────────── kilit ve açık eylem

    /**
     * Yazma izinleri HER kanalda kapalı DOĞAR ama satıcı açabilir —
     * varsayılan kilit listesi boş (21 Eki; 24 Eki'de Beta kaldırıldı).
     */
    #[Test]
    public function unverified_channel_writes_are_closed_by_default_but_can_be_enabled(): void
    {
        [$tenant] = $this->tenant();

        $this->assertSame([], config('entegrasyon.outbound_write_locked_channels'));

        $connection = $this->connection($tenant, 'hepsiburada', enabled: false);

        $this->assertFalse((bool) $connection->fresh()->stock_sync_enabled);
        $this->assertFalse(SetOutboundWrite::isLocked($connection));
    }

    #[Test]
    public function hb_and_n11_writes_cannot_be_enabled_from_the_panel_until_verified(): void
    {
        // Kilit mekanizması env ile hâlâ çalışır; 21 Eki 2026'dan beri varsayılan liste BOŞ (beta kararı).
        config(['entegrasyon.outbound_write_locked_channels' => ['hepsiburada', 'n11']]);

        [$tenant, $user] = $this->tenant();

        foreach (['hepsiburada', 'n11'] as $code) {
            $connection = $this->connection($tenant, $code, enabled: false);

            foreach (OutboundWrite::cases() as $write) {
                $this->actingAs($user)
                    ->put("/channels/{$connection->id}/outbound", ['write' => $write->value, 'enabled' => true])
                    ->assertRedirect('/channels')
                    ->assertSessionHas('warning');

                $this->assertFalse($this->flag($connection, $write), "{$code} {$write->value} açılmamalı.");
            }
        }
    }

    #[Test]
    public function disabling_is_always_allowed_even_on_a_locked_channel(): void
    {
        [$tenant, $user] = $this->tenant();
        $connection = $this->connection($tenant, 'hepsiburada', enabled: true);

        $this->actingAs($user)
            ->put("/channels/{$connection->id}/outbound", ['write' => 'product_publish', 'enabled' => false])
            ->assertSessionHas('success');

        $this->assertFalse($this->flag($connection, OutboundWrite::PRODUCT_PUBLISH));
        $this->assertTrue($this->flag($connection, OutboundWrite::STOCK_SYNC), 'Diğer izinlere dokunulmamalı.');
    }

    #[Test]
    public function enabling_stock_sync_resyncs_live_listings_but_enabling_publishing_sends_nothing(): void
    {
        [$tenant, $user] = $this->tenant();
        $connection = $this->connection($tenant, 'woocommerce', enabled: false);
        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());
        $live = $this->asTenant($tenant, fn () => Listing::factory()->create(['channel_connection_id' => $connection->id, 'variant_id' => $variant->id]));
        $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $this->asTenant($tenant, fn () => Variant::factory()->create())->id,
            'lifecycle_status' => 'draft',
        ]));

        $this->actingAs($user)->put("/channels/{$connection->id}/outbound", ['write' => 'product_publish', 'enabled' => true]);
        $this->assertSame(0, $this->resyncEvents($tenant), 'Yayın izni açılınca hiçbir şey otomatik gitmez.');

        $this->actingAs($user)->put("/channels/{$connection->id}/outbound", ['write' => 'stock_sync', 'enabled' => true]);
        $events = $this->asTenant($tenant, fn () => OutboxEvent::query()->where('event_type', 'ListingResyncRequested')->get());

        $this->assertCount(1, $events, 'Yalnız canlı satır yeniden senkronlanır.');
        $this->assertSame($live->id, $events[0]->payload['listing_id']);
        $this->assertSame(SyncDomain::INVENTORY->value, $events[0]->payload['domain']);

        // Zaten açıkken yeniden "aç" demek ikinci tur üretmez.
        $this->actingAs($user)->put("/channels/{$connection->id}/outbound", ['write' => 'stock_sync', 'enabled' => true]);
        $this->assertSame(1, $this->resyncEvents($tenant));
    }

    #[Test]
    public function another_tenants_connection_cannot_be_toggled(): void
    {
        [$tenant] = $this->tenant();
        [, $intruder] = $this->tenant();
        $connection = $this->connection($tenant, 'woocommerce', enabled: false);

        $this->actingAs($intruder)
            ->put("/channels/{$connection->id}/outbound", ['write' => 'stock_sync', 'enabled' => true])
            ->assertNotFound();

        $this->assertFalse($this->flag($connection, OutboundWrite::STOCK_SYNC));
    }

    #[Test]
    public function the_channels_screen_shows_connected_separately_from_write_permissions(): void
    {
        // Kilit mekanizması env ile hâlâ çalışır; 21 Eki 2026'dan beri varsayılan liste BOŞ (beta kararı).
        config(['entegrasyon.outbound_write_locked_channels' => ['hepsiburada', 'n11']]);

        [$tenant, $user] = $this->tenant();
        $this->connection($tenant, 'hepsiburada', enabled: false);

        $this->actingAs($user)->get('/channels')->assertInertia(fn ($page) => $page
            ->component('Channels/Index')
            ->where('connections.0.status', 'active')
            ->where('connections.0.writes.product_publish', false)
            ->where('connections.0.writes.price_sync', false)
            ->where('connections.0.writes.stock_sync', false)
            ->where('connections.0.writesLocked', true));
    }

    #[Test]
    public function a_connection_made_through_the_normal_connect_flow_starts_with_every_write_disabled(): void
    {
        [$tenant] = $this->tenant();
        $this->channelType('woocommerce', WooCommerceAdapter::class);
        Http::fake(['*' => Http::response([], 200)]);

        // Kimlik doğrulaması (sağlık kontrolü) başarılı — yine de izin YOK.
        $connection = $this->asTenant($tenant, fn (): ChannelConnection => app(ConnectChannel::class)->run(
            channelTypeCode: 'woocommerce',
            label: 'Yeni',
            storeUrl: 'https://yeni.example.com',
            secrets: ['consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x'],
        ));

        foreach (OutboundWrite::cases() as $write) {
            $this->assertFalse($this->flag($connection, $write), "{$write->value} kendiliğinden açılmamalı.");
        }
    }

    // ─────────────────────────────────────────── fan-out planlaması

    #[Test]
    public function stock_and_price_changes_open_no_operations_for_a_connection_without_permission(): void
    {
        [$tenant] = $this->tenant();
        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());
        $allowed = $this->programmable($tenant, 'trendyol', enabled: true);
        $denied = $this->programmable($tenant, 'shopify', enabled: false);

        foreach ([$allowed, $denied] as $connection) {
            $this->asTenant($tenant, fn () => Listing::factory()->create(['channel_connection_id' => $connection->id, 'variant_id' => $variant->id]));
        }

        $this->asTenant($tenant, fn () => app(InventoryLevelChangedConsumer::class)->handle($this->event($tenant, 'InventoryLevelChanged', $variant, 7)));
        $this->asTenant($tenant, fn () => app(VariantPriceChangedConsumer::class)->handle($this->event($tenant, 'VariantPriceChanged', $variant, 3)));

        $byConnection = $this->asTenant($tenant, fn () => SyncOperation::query()->get()->groupBy('channel_connection_id'));

        $this->assertCount(2, $byConnection[$allowed->id] ?? [], 'İzinli bağlantıya stok + fiyat operasyonu.');
        $this->assertArrayNotHasKey($denied->id, $byConnection->all(), 'İzinsiz bağlantıya operasyon açılmamalı.');
    }

    // ─────────────────────────────────────────── geçiş kuralı

    #[Test]
    public function the_migration_preserves_existing_channels_and_keeps_hb_n11_closed(): void
    {
        [$tenant] = $this->tenant();
        $migration = require base_path('database/migrations/2026_10_15_000100_add_outbound_write_gates_to_channel_connections.php');

        $migration->down();

        $codes = ['trendyol', 'etsy', 'shopify', 'woocommerce', 'hepsiburada', 'n11'];
        $ids = [];

        foreach ($codes as $code) {
            $this->channelType($code, WooCommerceAdapter::class);
            $ids[$code] = (string) new UuidV7;
            $this->asSystem(fn () => DB::table('channel_connections')->insert([
                'id' => $ids[$code], 'tenant_id' => $tenant->id, 'channel_type_code' => $code, 'label' => $code,
                'external_account_id' => $code.'-'.uniqid(), 'status' => 'active', 'settings' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }

        $migration->up();

        foreach ($codes as $code) {
            $row = $this->asSystem(fn () => DB::table('channel_connections')->where('id', $ids[$code])->first());
            $expected = ! in_array($code, ['hepsiburada', 'n11'], true);

            foreach (['product_publish_enabled', 'price_sync_enabled', 'stock_sync_enabled'] as $column) {
                $this->assertSame($expected, $row->{$column}, "{$code}.{$column}");
            }
        }
    }

    // ─────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: User} */
    private function tenant(): array
    {
        $user = User::factory()->create();

        return [(new CreateTenant)->run(name: 'İzin '.uniqid(), owner: $user), $user];
    }

    private function channelType(string $code, string $adapter): void
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => $code], [
            'name' => ucfirst($code), 'kind' => 'marketplace', 'adapter_class' => $adapter,
            'supports_webhooks' => false, 'is_active' => true,
        ]));
    }

    private function connection(Tenant $tenant, string $code, bool $enabled): ChannelConnection
    {
        $this->channelType($code, WooCommerceAdapter::class);

        return $this->asTenant($tenant, fn () => ChannelConnection::factory()
            ->when(! $enabled, fn ($f) => $f->outboundDisabled())
            ->create(['channel_type_code' => $code, 'tenant_id' => $tenant->id]));
    }

    private function programmable(Tenant $tenant, string $code, bool $enabled): ChannelConnection
    {
        $this->channelType($code, ProgrammableInventoryAdapter::class);

        return $this->asTenant($tenant, fn () => ChannelConnection::factory()
            ->when(! $enabled, fn ($f) => $f->outboundDisabled())
            ->create(['channel_type_code' => $code, 'tenant_id' => $tenant->id]));
    }

    private function flag(ChannelConnection $connection, OutboundWrite $write): bool
    {
        return $this->asSystem(fn () => DB::table('channel_connections')->where('id', $connection->id)->value($write->column()));
    }

    private function resyncEvents(Tenant $tenant): int
    {
        return $this->asTenant($tenant, fn () => OutboxEvent::query()->where('event_type', 'ListingResyncRequested')->count());
    }

    private function event(Tenant $tenant, string $type, Variant $variant, int $version): OutboxEvent
    {
        return $this->asTenant($tenant, fn () => OutboxEvent::record(
            aggregateType: 'variant',
            aggregateId: $variant->id,
            eventType: $type,
            payload: ['variant_id' => $variant->id, 'version' => $version, 'origin_connection_id' => null],
            tenantId: $tenant->id,
        ));
    }
}
