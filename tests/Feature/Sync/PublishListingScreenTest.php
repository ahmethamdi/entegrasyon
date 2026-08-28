<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Jobs\PushListing;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Channels\ProgrammableCatalogAdapter;
use Tests\Support\Channels\ProgrammableInventoryAdapter;
use Tests\Support\Channels\ProgrammableOfferAdapter;
use Tests\TestCase;

/**
 * Panelden kanala gönderme akışı — §13 · faz 1.5 · "Panelde kanal seçimi,
 * gönderme akışı, senkron durumu rozeti".
 *
 * DEĞİŞMEZ KURAL — SAĞLIKSIZ KANALA GÖNDERİLMEZ:
 *   `active` olmayan bağlantı seçenek olarak SUNULMAZ ve gönderme isteği
 *   reddedilir. Sağlıksız kanala iş atmak, kullanıcıya "gönderildi" deyip
 *   arkada kalıcı hataya düşen operasyon bırakmak demektir.
 *
 * DEĞİŞMEZ KURAL — GÖNDERME İDEMPOTENTTİR:
 *   Aynı ürünü aynı kanala iki kez göndermek İKİNCİ bir listing satırı
 *   AÇMAZ. `(channel_connection_id, variant_id)` zaten tekildir; akış onu
 *   ihlal etmek yerine var olan satırı yeniden kullanır.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ.
 */
final class PublishListingScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Planlamayı sınayan testler Queue::fake() kullanır: gerçek worker
        // asenkrondur ve `sync` sürücü o modeli taklit etmez.
        Queue::fake();

        ProgrammableCatalogAdapter::reset();
    }

    protected function tearDown(): void
    {
        ProgrammableCatalogAdapter::reset();

        parent::tearDown();
    }

    /**
     * Ürün ekranı gönderilebilir kanalları listeler ve mevcut durumu gösterir.
     */
    #[Test]
    public function product_channels_screen_lists_publishable_connections(): void
    {
        [$tenant, $user, $product] = $this->makeContext();

        $connection = $this->connection($tenant, 'woocommerce', label: 'Ana Mağaza');

        $response = $this->actingAs($user)->get("/products/{$product->id}/channels");

        $response->assertOk();

        $page = $response->viewData('page');

        $this->assertSame('Products/Channels', $page['component']);

        $channels = $page['props']['channels'];

        $this->assertCount(1, $channels);
        $this->assertSame($connection->id, $channels[0]['connectionId']);
        $this->assertSame('Ana Mağaza', $channels[0]['label']);
        $this->assertFalse($channels[0]['published'], 'Henüz gönderilmemiş kanal published olmamalı.');
        $this->assertNull($channels[0]['externalId']);

        // MODEL GÖNDERİLMEZ: kimlik bilgisi ve settings sızmamalı.
        $this->assertArrayNotHasKey('settings', $channels[0]);
        $this->assertArrayNotHasKey('credentials', $channels[0]);
    }

    /**
     * Gönderme: listing satırı yaratılır, CONTENT operasyonu açılır ve iş atılır.
     */
    #[Test]
    public function publishing_creates_listing_opens_operation_and_dispatches_job(): void
    {
        [$tenant, $user, $product] = $this->makeContext();

        $connection = $this->connection($tenant, 'woocommerce');

        $response = $this->actingAs($user)->post("/products/{$product->id}/channels", [
            'connection_id' => $connection->id,
        ]);

        $response->assertRedirect("/products/{$product->id}/channels");

        $listing = $this->asTenant($tenant, fn () => Listing::query()->firstOrFail());

        $this->assertSame($connection->id, $listing->channel_connection_id);
        $this->assertSame('draft', $listing->lifecycle_status,
            'Kanal onaylamadan CANLI işaretlenmez; canlı işareti PushListing’in işidir.');
        $this->assertNull($listing->external_id);

        $operation = $this->asTenant($tenant, fn () => SyncOperation::query()->firstOrFail());

        $this->assertSame('CONTENT_PUSH', $operation->operation_type);
        $this->assertSame($listing->id, $operation->entity_id);
        $this->assertSame('pending', $operation->status->value);
        $this->assertSame(0, $operation->attempt_count);

        // Sürüm ÜRÜNÜN içerik sürümünden gelir — senkron kapısı ondan beslenir.
        $this->assertSame(
            $product->content_version,
            $operation->entity_version,
            'Operasyon sürümü ürünün content_version’ı olmalı.',
        );

        Queue::assertPushed(PushListing::class, fn (PushListing $job): bool => $job->operationId === $operation->id);
    }

    /**
     * İKİNCİ GÖNDERME İKİNCİ SATIR AÇMAZ.
     *
     * `(channel_connection_id, variant_id)` tekildir; akış var olan satırı
     * yeniden kullanır. Yeni satır denemek kısıt ihlaliyle 500 verirdi.
     */
    #[Test]
    public function publishing_twice_reuses_the_same_listing_row(): void
    {
        [$tenant, $user, $product] = $this->makeContext();

        $connection = $this->connection($tenant, 'woocommerce');

        $this->actingAs($user)->post("/products/{$product->id}/channels", [
            'connection_id' => $connection->id,
        ])->assertRedirect();

        // İçerik değişti → sürüm ilerledi.
        $this->asTenant($tenant, fn () => $product->forceFill([
            'title' => 'Güncellenmiş Başlık',
            'content_version' => $product->content_version + 1,
        ])->save());

        $this->actingAs($user)->post("/products/{$product->id}/channels", [
            'connection_id' => $connection->id,
        ])->assertRedirect();

        $this->assertSame(
            1,
            $this->asTenant($tenant, fn () => Listing::query()->count()),
            'İkinci gönderme ikinci listing satırı AÇMAMALI.',
        );

        // İki farklı sürüm → iki operasyon, eskisi superseded.
        $operations = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->orderBy('entity_version')->get());

        $this->assertCount(2, $operations);
        $this->assertSame('superseded', $operations[0]->status->value,
            'Eski sürüm operasyonu geçersiz kılınmalı.');
        $this->assertSame('pending', $operations[1]->status->value);
    }

    /**
     * AYNI SÜRÜM İKİ KEZ GÖNDERİLİRSE İKİNCİ OPERASYON AÇILMAZ.
     *
     * Sürüm kapısı `desired_version > eventVersion` ile eler; kullanıcı
     * butona iki kez bassa da kanala tek iş gider.
     */
    #[Test]
    public function publishing_same_version_twice_does_not_open_a_second_operation(): void
    {
        [$tenant, $user, $product] = $this->makeContext();

        $connection = $this->connection($tenant, 'woocommerce');

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($user)->post("/products/{$product->id}/channels", [
                'connection_id' => $connection->id,
            ])->assertRedirect();
        }

        $this->assertSame(
            1,
            $this->asTenant($tenant, fn () => SyncOperation::query()->count()),
            'Aynı sürüm için ikinci operasyon açılmamalı.',
        );
    }

    /**
     * SAĞLIKSIZ BAĞLANTIYA GÖNDERİLMEZ — istek reddedilir.
     *
     * Sağlıksız kanala iş atmak, "gönderildi" deyip arkada kalıcı hataya
     * düşen operasyon bırakmaktır.
     */
    #[Test]
    public function publishing_to_an_inactive_connection_is_rejected(): void
    {
        [$tenant, $user, $product] = $this->makeContext();

        $connection = $this->connection($tenant, 'woocommerce', status: 'pending', health: 'unhealthy');

        $response = $this->actingAs($user)->post("/products/{$product->id}/channels", [
            'connection_id' => $connection->id,
        ]);

        $response->assertSessionHasErrors('connection_id');

        $this->assertSame(0, $this->asTenant($tenant, fn () => Listing::query()->count()));
        $this->assertSame(0, $this->asTenant($tenant, fn () => SyncOperation::query()->count()));

        Queue::assertNothingPushed();
    }

    /**
     * Katalog yeteneği OLMAYAN kanal seçenek olarak SUNULMAZ.
     *
     * Yetenek `instanceof` ile okunur; panelde tip kontrolü yazılmaz (§7).
     */
    #[Test]
    public function connection_without_catalog_capability_is_not_offered(): void
    {
        [$tenant, $user, $product] = $this->makeContext();

        $this->connection($tenant, 'stokonly', adapter: ProgrammableInventoryAdapter::class);

        $response = $this->actingAs($user)->get("/products/{$product->id}/channels");

        $this->assertSame(
            [],
            $response->viewData('page')['props']['channels'],
            'Katalog yeteneği olmayan bağlantı gönderme listesinde görünmemeli.',
        );
    }

    /**
     * ⚠️ ÇOK ADIMLI YAYIN KANALI DA GÖNDERME LİSTESİNDE GÖRÜNÜR
     * (§03 · Delta 1).
     *
     * `SupportsCatalog` ile `SupportsOfferLifecycle` AYNI SORUYU
     * sormaz ama ikisi de "bu kanala ürün gönderilebilir" der; hangi
     * işin atılacağına `ContentPushDispatcher` karar verir.
     *
     * ⚠️ EKRAN YALNIZCA `catalog` OKUSAYDI eBay BU LİSTEDE HİÇ
     * GÖRÜNMEZDİ: zincir çalışır, adapter testleri yeşil kalır ve
     * satıcı çalışan özelliği HİÇ göremezdi. Projede bu hata biçimi
     * ÜÇ KEZ yaşandı (Etsy `pricing`/`orders`, Woo `catalog_import`)
     * ve her seferinde davranış testleri yeşildi — çünkü hepsi
     * yeteneği `instanceof` ile okuyordu, EKRANI SÜREN kimse yoktu.
     *
     * MUTASYONLA BULUNDU: gate'ten `offer_lifecycle` okumasını silen
     * mutasyon, bu test yazılmadan önce HAYATTA KALMIŞTI.
     *
     * ⚠️ KANAL ADI SORULMAZ — sahte adapter `SupportsOfferLifecycle`
     * uygular ve `SupportsCatalog` UYGULAMAZ; iddia yeteneğe bağlıdır,
     * eBay'e değil.
     */
    #[Test]
    public function connection_with_only_the_offer_lifecycle_capability_is_offered(): void
    {
        [$tenant, $user, $product] = $this->makeContext();

        $connection = $this->connection(
            $tenant,
            'coadimli',
            label: 'Çok Adımlı Mağaza',
            adapter: ProgrammableOfferAdapter::class,
        );

        $channels = $this->actingAs($user)
            ->get("/products/{$product->id}/channels")
            ->viewData('page')['props']['channels'];

        $this->assertCount(
            1,
            $channels,
            'Çok adımlı yayın kanalı gönderme listesinde GÖRÜNMEDİ — satıcı '
            .'çalışan zinciri panelden hiç kullanamazdı (§05).',
        );

        $this->assertSame($connection->id, $channels[0]['connectionId']);
    }

    /**
     * BAŞKA KİRACININ BAĞLANTISINA GÖNDERİLEMEZ.
     *
     * Bağlantı kimliği istekten gelir; kiracı scope'u ile aranmazsa
     * kurcalanmış bir form başka kiracının mağazasına ürün gönderirdi.
     */
    #[Test]
    public function publishing_to_another_tenants_connection_is_impossible(): void
    {
        [$tenant, $user, $product] = $this->makeContext();
        [$other] = $this->makeContext();

        $foreign = $this->connection($other, 'woocommerce');

        $response = $this->actingAs($user)->post("/products/{$product->id}/channels", [
            'connection_id' => $foreign->id,
        ]);

        $response->assertSessionHasErrors('connection_id');

        $this->assertSame(0, $this->asTenant($tenant, fn () => Listing::query()->count()));
        $this->assertSame(0, $this->asTenant($other, fn () => Listing::query()->count()));
    }

    /**
     * BAŞKA KİRACININ ÜRÜNÜ GÖNDERİLEMEZ — 404.
     */
    #[Test]
    public function publishing_another_tenants_product_is_not_found(): void
    {
        [, $user] = $this->makeContext();
        [$other, , $foreignProduct] = $this->makeContext();

        $this->actingAs($user)
            ->get("/products/{$foreignProduct->id}/channels")
            ->assertNotFound();

        $connection = $this->connection($other, 'woocommerce');

        $this->actingAs($user)
            ->post("/products/{$foreignProduct->id}/channels", ['connection_id' => $connection->id])
            ->assertNotFound();
    }

    /**
     * SENKRON DURUMU ROZETİ — kanala gönderilmiş ürün durumunu gösterir.
     *
     * Rozet sırası: kalıcı hata > geçici hata > bekliyor > senkron.
     * `error_permanent` kullanıcı müdahalesi bekler; "bekliyor" demek
     * satıcıyı kendiliğinden düzelecek sanmaya iter.
     */
    #[Test]
    public function screen_shows_sync_status_badge_for_published_channel(): void
    {
        [$tenant, $user, $product] = $this->makeContext();

        $connection = $this->connection($tenant, 'woocommerce');

        $listing = $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $product->variants->first()->id,
            'external_id' => '55',
            'lifecycle_status' => 'live',
        ]));

        $this->asTenant($tenant, fn () => ListingSyncState::query()->create([
            'tenant_id' => $tenant->id,
            'listing_id' => $listing->id,
            'domain' => SyncDomain::CONTENT->value,
            'desired_version' => 4,
            'synced_version' => 2,
            'status' => 'error_permanent',
            'last_error' => 'başlık çok uzun',
        ]));

        $channels = $this->actingAs($user)
            ->get("/products/{$product->id}/channels")
            ->viewData('page')['props']['channels'];

        $this->assertTrue($channels[0]['published']);
        $this->assertSame('55', $channels[0]['externalId']);
        $this->assertSame('error_permanent', $channels[0]['syncStatus']);
        $this->assertSame('başlık çok uzun', $channels[0]['lastError']);
        $this->assertTrue($channels[0]['pendingWork'], 'desired > synced iken bekleyen iş görünmeli.');
    }

    /**
     * Gönderme sonrası ekranda "bekliyor" görünür — henüz iş çalışmadı.
     */
    #[Test]
    public function newly_published_channel_shows_pending_status(): void
    {
        [$tenant, $user, $product] = $this->makeContext();

        $connection = $this->connection($tenant, 'woocommerce');

        $this->actingAs($user)->post("/products/{$product->id}/channels", [
            'connection_id' => $connection->id,
        ]);

        $channels = $this->actingAs($user)
            ->get("/products/{$product->id}/channels")
            ->viewData('page')['props']['channels'];

        $this->assertTrue($channels[0]['published'], 'Gönderilmiş kanal işaretli olmalı.');
        $this->assertSame('pending', $channels[0]['syncStatus']);
        $this->assertNull($channels[0]['externalId'], 'Kanal henüz kimlik vermedi.');
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: User, 2: Product} */
    private function makeContext(): array
    {
        $user = User::factory()->create();

        $tenant = (new CreateTenant)->run(name: 'Yayin '.uniqid(), owner: $user);

        $product = $this->asTenant($tenant, function () use ($tenant): Product {
            $product = Product::factory()->create([
                'tenant_id' => $tenant->id,
                'content_version' => 1,
            ]);

            Variant::factory()->create([
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
            ]);

            return $product->load('variants');
        });

        return [$tenant, $user, $product];
    }

    private function connection(
        Tenant $tenant,
        string $channelTypeCode,
        string $label = 'Mağaza',
        string $status = 'active',
        string $health = 'healthy',
        string $adapter = ProgrammableCatalogAdapter::class,
    ): ChannelConnection {
        $this->asSystem(function () use ($channelTypeCode, $adapter): void {
            ChannelType::query()->updateOrCreate(
                ['code' => $channelTypeCode],
                [
                    'name' => ucfirst($channelTypeCode),
                    'kind' => 'marketplace',
                    'adapter_class' => $adapter,
                    'is_active' => true,
                ],
            );
        });

        return $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_type_code' => $channelTypeCode,
            'label' => $label,
            'status' => $status,
            'health_status' => $health,
        ]));
    }
}
