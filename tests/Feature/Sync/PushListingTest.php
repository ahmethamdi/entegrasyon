<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushListing;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncAttempt;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\ContentHasher;
use App\Domain\Sync\Support\ListingPayloadBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Channels\ProgrammableCatalogAdapter;
use Tests\Support\Channels\ProgrammableInventoryAdapter;
use Tests\TestCase;

/**
 * PushListing — ürün içeriğini kanala gönderen iş (§13 · faz 1.5).
 *
 * Mimari Karar Dokümanı v2.2 · §8 · sync operation modeli, §7 · adapter
 * kuralları, §12 · iş tarafı.
 *
 * DEĞİŞMEZ KURAL — İŞ ORKESTRASYONDUR:
 *   Yükü ListingPayloadBuilder, kanal konuşmasını adapter, durum yazımını
 *   SyncResultRecorder, yeniden deneme kararını RetryPolicy yapar.
 *
 * DEĞİŞMEZ KURAL — CREATE Mİ UPDATE Mİ SORUSU external_id İLE CEVAPLANIR:
 *   external_id NULL ise ürün kanalda yok → create. Doluysa update. Ama
 *   create'ten ÖNCE findExistingListing() sorulur: kanalda aynı SKU zaten
 *   varsa yeniden yaratmak KOPYA listeleme üretir ve geri alınamaz.
 *
 * DEĞİŞMEZ KURAL — GRUPLAMA YOK:
 *   Stoktan farklı olarak içerik yükü listing başınadır; Woo'da ürün
 *   uç noktası tekil çalışır. Her operasyon kendi çağrısını yapar.
 */
final class PushListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Kuyruk SAHTE: sync sürücü işi DERHAL çalıştırır ve kuyruk kancaları
        // her iş sınırında kiracı bağlamını temizler. Testler işleri KENDİ
        // çağırır (runJob) — worker'daki gibi ayrı iş örneğinde.
        Queue::fake();

        ProgrammableCatalogAdapter::reset();
    }

    protected function tearDown(): void
    {
        ProgrammableCatalogAdapter::reset();

        parent::tearDown();
    }

    /**
     * Taslak listing kanala gönderilir: create çağrılır, external_id yazılır,
     * satır CANLI olur ve sync state ilerler.
     */
    #[Test]
    public function draft_listing_is_created_on_the_channel_and_becomes_live(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->draftListing($tenant, $variant, 'woocommerce');

        ProgrammableCatalogAdapter::succeedOn('woocommerce', externalId: '4242');

        $operation = $this->openOperation($tenant, $listing, version: 1);

        $this->runJob($tenant, $operation->id);

        $calls = ProgrammableCatalogAdapter::callsFor('woocommerce');

        $this->assertCount(1, $calls, 'Kanala tek çağrı gitmeli.');
        $this->assertSame('create', $calls[0]['op'], 'external_id yokken create çağrılmalı.');
        $this->assertSame($variant->sku, $calls[0]['sku']);

        $fresh = $this->asTenant($tenant, fn () => $listing->fresh());

        $this->assertSame('4242', $fresh->external_id, 'Kanaldan dönen kimlik yazılmalı.');
        $this->assertTrue($fresh->isLive(), 'Gönderilen listing CANLI olmalı.');
        $this->assertNotNull($fresh->listed_at);
        $this->assertSame('https://example.test/p/4242', $fresh->external_url);

        $this->assertSame(
            SyncOperationStatus::COMPLETED,
            $this->asTenant($tenant, fn () => $operation->fresh())->status,
        );

        $state = $this->stateFor($tenant, $listing->id);

        $this->assertSame(1, $state->synced_version);
        $this->assertSame('synced', $state->status);
        $this->assertFalse($state->is_dirty);
    }

    /**
     * external_id doluysa UPDATE çağrılır — ürün yeniden YARATILMAZ.
     *
     * Yaratmak kanalda ikinci bir ürün açardı; yorumlar, sıralama ve SEO
     * geçmişi ilk üründe kalır ve satıcı iki kopya arasında bölünür.
     */
    #[Test]
    public function listing_with_external_id_is_updated_not_recreated(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->liveListing($tenant, $variant, 'woocommerce', externalId: '77');

        ProgrammableCatalogAdapter::succeedOn('woocommerce');

        $operation = $this->openOperation($tenant, $listing, version: 5);

        $this->runJob($tenant, $operation->id);

        $calls = ProgrammableCatalogAdapter::callsFor('woocommerce');

        $this->assertCount(1, $calls);
        $this->assertSame('update', $calls[0]['op'], 'external_id doluyken update çağrılmalı.');
        $this->assertSame('77', $calls[0]['externalId']);

        $this->assertSame(
            '77',
            $this->asTenant($tenant, fn () => $listing->fresh())->external_id,
            'Güncelleme external_id’yi değiştirmemeli.',
        );

        $this->assertSame(5, $this->stateFor($tenant, $listing->id)->synced_version);
    }

    /**
     * KOPYA LİSTELEME KORUMASI — kanalda aynı SKU varsa yeniden YARATILMAZ.
     *
     * findExistingListing() eşleşirse bulunan external_id benimsenir ve
     * update yoluna girilir. Bu adım olmadan mevcut ürünler yeniden yaratılır
     * ve kanalda kopya listeler oluşur (§7 · SupportsCatalog).
     */
    #[Test]
    public function existing_remote_product_is_adopted_instead_of_duplicated(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->draftListing($tenant, $variant, 'woocommerce');

        ProgrammableCatalogAdapter::succeedOn('woocommerce', externalId: '999');
        ProgrammableCatalogAdapter::alreadyHas('woocommerce', $variant->sku, externalId: '31');

        $operation = $this->openOperation($tenant, $listing, version: 1);

        $this->runJob($tenant, $operation->id);

        $calls = ProgrammableCatalogAdapter::callsFor('woocommerce');

        $this->assertCount(1, $calls, 'Tek çağrı: benimsenen ürün güncellenmeli.');
        $this->assertSame('update', $calls[0]['op'], 'Kanalda var olan ürün yeniden YARATILMAMALI.');

        $fresh = $this->asTenant($tenant, fn () => $listing->fresh());

        $this->assertSame('31', $fresh->external_id, 'Kanalda bulunan kimlik benimsenmeli.');
        $this->assertTrue($fresh->isLive());
    }

    /**
     * Geçici hata: operasyon retrying kalır, listing TASLAK kalır.
     *
     * external_id yazılmamalıdır — kanal ürünü yaratmadı; yazmak sonraki
     * turda update çağırtır ve var olmayan ürüne 404 aldırırdı.
     */
    #[Test]
    public function transient_failure_keeps_listing_draft_and_operation_retrying(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->draftListing($tenant, $variant, 'woocommerce');

        ProgrammableCatalogAdapter::failOn('woocommerce', ErrorClass::RATE_LIMITED, '429 Too Many Requests');

        $operation = $this->openOperation($tenant, $listing, version: 1);

        $this->runJob($tenant, $operation->id);

        $fresh = $this->asTenant($tenant, fn () => $listing->fresh());

        $this->assertNull($fresh->external_id, 'Başarısız çağrıdan sonra external_id yazılmamalı.');
        $this->assertFalse($fresh->isLive(), 'Gönderilemeyen listing taslak kalmalı.');

        $this->assertSame(
            SyncOperationStatus::RETRYING,
            $this->asTenant($tenant, fn () => $operation->fresh())->status,
        );

        $state = $this->stateFor($tenant, $listing->id);

        $this->assertSame('error_transient', $state->status);
        $this->assertSame(0, $state->synced_version);
        $this->assertTrue($state->is_dirty);
    }

    /**
     * Kalıcı hata: operasyon ölür, sync state error_permanent olur.
     *
     * VALIDATION kullanıcı müdahalesi bekler; yeniden denemek bütçe israfıdır.
     */
    #[Test]
    public function permanent_failure_kills_the_operation(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->draftListing($tenant, $variant, 'woocommerce');

        ProgrammableCatalogAdapter::failOn('woocommerce', ErrorClass::VALIDATION, 'başlık boş olamaz');

        $operation = $this->openOperation($tenant, $listing, version: 1);

        $this->runJob($tenant, $operation->id);

        $this->assertSame(
            SyncOperationStatus::DEAD,
            $this->asTenant($tenant, fn () => $operation->fresh())->status,
        );

        $state = $this->stateFor($tenant, $listing->id);

        $this->assertSame('error_permanent', $state->status);
        $this->assertNotNull($state->last_error);
    }

    /**
     * Kanal katalog yeteneğini desteklemiyorsa DENEME AÇILMAZ.
     *
     * Yetenek `instanceof` ile okunur; panelde tip kontrolü yazılmaz (§7).
     */
    #[Test]
    public function channel_without_catalog_capability_is_skipped_without_attempt(): void
    {
        [$tenant, $variant] = $this->makeContext();

        // Bu bağlantı yalnızca SupportsInventory uygular.
        $listing = $this->draftListing($tenant, $variant, 'stokonly', adapter: ProgrammableInventoryAdapter::class);

        $operation = $this->openOperation($tenant, $listing, version: 1);

        $this->runJob($tenant, $operation->id);

        $this->assertSame(
            SyncOperationStatus::COMPLETED,
            $this->asTenant($tenant, fn () => $operation->fresh())->status,
        );

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn () => $operation->fresh())->attempt_count,
            'Yetenek yoksa deneme açılmaz — seviye 2 taramasının anlamı korunur.',
        );

        $this->assertSame(0, $this->asTenant($tenant, fn () => SyncAttempt::query()->count()));
    }

    /**
     * Superseded operasyon GÖNDERİLMEZ — bayat içerik yeniyi ezmez.
     */
    #[Test]
    public function superseded_operation_is_not_pushed(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->liveListing($tenant, $variant, 'woocommerce', externalId: '12');

        ProgrammableCatalogAdapter::succeedOn('woocommerce');

        $stale = $this->openOperation($tenant, $listing, version: 3);

        // Kuyrukta beklerken yeni sürüm istendi → eski operasyon superseded.
        $this->openOperation($tenant, $listing, version: 4);

        $this->assertSame(
            SyncOperationStatus::SUPERSEDED,
            $this->asTenant($tenant, fn () => $stale->fresh())->status,
        );

        $this->runJob($tenant, $stale->id);

        $this->assertSame([], ProgrammableCatalogAdapter::callsFor('woocommerce'),
            'Bayat operasyon kanala hiç gitmemeli.');

        $this->assertSame(0, $this->asTenant($tenant, fn () => SyncAttempt::query()->count()),
            'Superseded operasyon için deneme AÇILMAZ.');
    }

    /**
     * İÇERİK HASH'İ — desired_hash operasyon açılırken yazılır, synced_hash
     * başarıdan sonra. İkisi eşitse gönderilecek bir şey kalmamıştır (§9).
     */
    #[Test]
    public function content_hash_is_recorded_on_both_sides_after_success(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->liveListing($tenant, $variant, 'woocommerce', externalId: '8');

        ProgrammableCatalogAdapter::succeedOn('woocommerce');

        $operation = $this->openOperation($tenant, $listing, version: 2);

        $before = $this->stateFor($tenant, $listing->id);

        $this->assertNotNull($before->desired_hash, 'Operasyon açılırken desired_hash yazılmalı.');
        $this->assertNull($before->synced_hash, 'Gönderilmeden synced_hash yazılmamalı.');

        $this->runJob($tenant, $operation->id);

        $after = $this->stateFor($tenant, $listing->id);

        $this->assertSame(
            $before->desired_hash,
            $after->synced_hash,
            'Başarıdan sonra gönderilen hash istenen hash ile aynı olmalı.',
        );
    }

    /**
     * Hash İÇERİKTEN türer: başlık değişince hash DEĞİŞİR, değişmeyince aynı kalır.
     *
     * Hash rastgele veya zaman tabanlı olsaydı her turda farklı çıkar ve
     * "gönderilecek bir şey var mı" sorusu hep evet cevabı verirdi.
     */
    #[Test]
    public function content_hash_changes_only_when_content_changes(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->liveListing($tenant, $variant, 'woocommerce', externalId: '8');

        $hasher = app(ContentHasher::class);
        $builder = app(ListingPayloadBuilder::class);

        $first = $this->asTenant($tenant, fn (): string => $hasher->hash(
            $builder->build($listing->fresh(), version: 1)
        ));

        $second = $this->asTenant($tenant, fn (): string => $hasher->hash(
            $builder->build($listing->fresh(), version: 1)
        ));

        $this->assertSame($first, $second, 'Aynı içerik aynı hash üretmeli.');

        $this->asTenant($tenant, fn () => $variant->product->forceFill([
            'title' => 'Yeni Başlık',
        ])->save());

        $third = $this->asTenant($tenant, fn (): string => $hasher->hash(
            $builder->build($listing->fresh(), version: 1)
        ));

        $this->assertNotSame($first, $third, 'İçerik değişince hash değişmeli.');
    }

    /**
     * Hash SÜRÜMDEN türemez — aynı içerik farklı sürümde aynı hash'i verir.
     *
     * Sürüm ve hash iki ayrı soruya cevap verir: sürüm "hangi olay", hash
     * "hangi içerik". Hash sürümü içerseydi içerik değişmeden yapılan her
     * yeniden gönderim satırı kirli gösterirdi ve mutabakat gerçek
     * sürüklenmeyi gürültüde kaybederdi.
     */
    #[Test]
    public function content_hash_does_not_depend_on_version(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->liveListing($tenant, $variant, 'woocommerce', externalId: '8');

        $hasher = app(ContentHasher::class);
        $builder = app(ListingPayloadBuilder::class);

        $atOne = $this->asTenant($tenant, fn (): string => $hasher->hash(
            $builder->build($listing->fresh(), version: 1)
        ));

        $atNine = $this->asTenant($tenant, fn (): string => $hasher->hash(
            $builder->build($listing->fresh(), version: 9)
        ));

        $this->assertSame($atOne, $atNine, 'Hash sürümden bağımsız olmalı.');
    }

    /**
     * Deneme kaydı YAZILIR — başarıda da hatada da.
     *
     * Kayıt olmazsa operasyon "hiç denenmedi" görünür ve seviye 2 taraması
     * onu yanlışlıkla toplar.
     */
    #[Test]
    public function attempt_is_recorded_for_the_operation(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->liveListing($tenant, $variant, 'woocommerce', externalId: '5');

        ProgrammableCatalogAdapter::succeedOn('woocommerce');

        $operation = $this->openOperation($tenant, $listing, version: 1);

        $this->runJob($tenant, $operation->id);

        $attempts = $this->asTenant($tenant, fn () => SyncAttempt::query()->get());

        $this->assertCount(1, $attempts);
        $this->assertSame('success', $attempts[0]->outcome);
        $this->assertSame(1, $attempts[0]->attempt_number);
        $this->assertNotNull($attempts[0]->finished_at);

        $this->assertSame(1, $this->asTenant($tenant, fn () => $operation->fresh())->attempt_count);
    }

    /**
     * ÇAPRAZ KANAL İZOLASYONU — bir kanalın hatası diğerini etkilemez.
     *
     * Aynı varyant iki kanalda listelenmişse her listing kendi operasyonunu,
     * kendi durumunu ve kendi hatasını taşır.
     */
    #[Test]
    public function failure_on_one_channel_does_not_affect_the_other(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $woo = $this->draftListing($tenant, $variant, 'woocommerce');
        $trendyol = $this->draftListing($tenant, $variant, 'trendyol');

        ProgrammableCatalogAdapter::succeedOn('woocommerce', externalId: '10');
        ProgrammableCatalogAdapter::failOn('trendyol', ErrorClass::RATE_LIMITED);

        $wooOperation = $this->openOperation($tenant, $woo, version: 1);
        $trendyolOperation = $this->openOperation($tenant, $trendyol, version: 1);

        $this->runJob($tenant, $wooOperation->id);
        $this->runJob($tenant, $trendyolOperation->id);

        $this->assertSame(
            SyncOperationStatus::COMPLETED,
            $this->asTenant($tenant, fn () => $wooOperation->fresh())->status,
        );
        $this->assertSame(
            SyncOperationStatus::RETRYING,
            $this->asTenant($tenant, fn () => $trendyolOperation->fresh())->status,
        );

        $this->assertSame('synced', $this->stateFor($tenant, $woo->id)->status);
        $this->assertSame('error_transient', $this->stateFor($tenant, $trendyol->id)->status);

        $this->assertTrue($this->asTenant($tenant, fn () => $woo->fresh())->isLive());
        $this->assertFalse($this->asTenant($tenant, fn () => $trendyol->fresh())->isLive());
    }

    /**
     * İŞ KENDİ KİRACI BAĞLAMINI KURAR — worker'da bağlam YOKTUR.
     *
     * Bu iş controller'dan doğrudan atılır; `PushInventory` gibi bir
     * `TenantAwareJob` (ConsumeOutboxEvent) içinden çağrılmaz. Gerçek
     * worker'da `Queue::looping` kancası her iş sınırında bağlamı temizler,
     * bu yüzden handle() bağlamsız başlar ve ilk tenant-scoped sorgu istisna
     * fırlatır — P0 izolasyon korumasının DOĞRU davranışı.
     *
     * Bağlamı işin KENDİSİ kurmak zorundadır. Diğer testler `asTenant()`
     * sarmalayıcısı içinde koştuğu için bu boşluğu göremez; burada bağlam
     * BİLEREK temizlenir.
     *
     * (Bu boşluk tarayıcı doğrulamasında bulundu: testler yeşilken gerçek
     * worker'da iş "Kiracı bağlamı olmadan sorgulanamaz" ile düştü.)
     */
    #[Test]
    public function job_establishes_its_own_tenant_context(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->liveListing($tenant, $variant, 'woocommerce', externalId: '61');

        ProgrammableCatalogAdapter::succeedOn('woocommerce');

        $operation = $this->openOperation($tenant, $listing, version: 1);

        // Worker'daki gibi: BAĞLAM YOK.
        TenantContext::clear();

        (new PushListing($operation->id, $tenant->id))->handle(
            app(ListingPayloadBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );

        $this->assertCount(
            1,
            ProgrammableCatalogAdapter::callsFor('woocommerce'),
            'Bağlamsız worker’da iş kanala gitmeli.',
        );

        $this->assertSame(
            SyncOperationStatus::COMPLETED,
            $this->asTenant($tenant, fn () => $operation->fresh())->status,
        );

        // Bağlam İŞ BİTİNCE BIRAKILIR: sonraki işe sızarsa kiracı A'nın
        // bağlamıyla kiracı B'nin verisi yazılırdı.
        $this->assertFalse(
            TenantContext::hasTenant(),
            'İş bittiğinde bağlam bırakılmalı — sonraki işe sızmamalı.',
        );
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: Variant} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Listing '.uniqid(),
            owner: User::factory()->create(),
        );

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        return [$tenant, $variant];
    }

    private function draftListing(
        Tenant $tenant,
        Variant $variant,
        string $channelTypeCode,
        string $adapter = ProgrammableCatalogAdapter::class,
    ): Listing {
        return $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => $this->connection($channelTypeCode, $adapter)->id,
            'variant_id' => $variant->id,
            'external_id' => null,
            'lifecycle_status' => 'draft',
            'listed_at' => null,
        ]));
    }

    private function liveListing(
        Tenant $tenant,
        Variant $variant,
        string $channelTypeCode,
        string $externalId,
    ): Listing {
        return $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => $this->connection($channelTypeCode)->id,
            'variant_id' => $variant->id,
            'external_id' => $externalId,
            'lifecycle_status' => 'live',
            'listed_at' => now(),
        ]));
    }

    private function connection(
        string $channelTypeCode,
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

        return ChannelConnection::factory()->create(['channel_type_code' => $channelTypeCode]);
    }

    private function openOperation(Tenant $tenant, Listing $listing, int $version): SyncOperation
    {
        return $this->asTenant($tenant, fn () => app(OpenSyncOperation::class)->run(
            listing: $listing,
            domain: SyncDomain::CONTENT,
            eventVersion: $version,
            intent: SyncIntent::NORMAL_SYNC,
        ));
    }

    // ───────────────────────────────── V3.0 · çok kimlikli kanallar (§07)

    /**
     * ÜST ÜRÜN VE KANALA ÖZGÜ KİMLİKLER DE YAZILIR.
     *
     * V3.0 · §03 · Delta 2 · §07.
     *
     * Bazı kanallar TEK kimlik döndürmez: Shopify variant + product +
     * inventory item, Etsy product + listing + offering, eBay listing +
     * offer taşır ve ikisi de KALICIDIR.
     *
     * `inventory_item_gid` STOK YAZMA HEDEFİDİR — Shopify'ın stok
     * mutation'ı variant gid'i KABUL ETMEZ. Yazılmazsa her stok itmesi ek
     * bir GraphQL sorgusu gerektirir ve kritik yolu İKİ KATINA çıkarır.
     *
     * YAZIM KANAL-AGNOSTİKTİR: adapter hangi kimlikleri döndüreceğini
     * bilir, çekirdek yalnızca taşır. `if ($channel === 'shopify')`
     * YAZILMAZ.
     */
    #[Test]
    public function channel_specific_identifiers_are_persisted(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->draftListing($tenant, $variant, 'woocommerce');

        ProgrammableCatalogAdapter::succeedOn('woocommerce', externalId: 'gid://shopify/ProductVariant/456');
        ProgrammableCatalogAdapter::alsoReturns('woocommerce', [
            'external_parent_id' => 'gid://shopify/Product/123',
            'channel_metadata' => ['inventory_item_gid' => 'gid://shopify/InventoryItem/789'],
        ]);

        $operation = $this->openOperation($tenant, $listing, version: 1);

        $this->runJob($tenant, $operation->id);

        $fresh = $this->asTenant($tenant, fn () => $listing->fresh());

        $this->assertSame('gid://shopify/Product/123', $fresh->external_parent_id);
        $this->assertSame(
            'gid://shopify/InventoryItem/789',
            $fresh->channel_metadata['inventory_item_gid'] ?? null,
            'channel_metadata yazılmadı — stok yazma hedefi kaybolur.',
        );
    }

    /**
     * ⚠️ `channel_metadata` BİRLEŞTİRİLİR, EZİLMEZ.
     *
     * eBay'in üç adımlı yayını `offer_id`'yi ilk adımda, `listing_id`'yi
     * üçüncüde yazar (§13.2). Ezilseydi ara başarısızlıktan sonraki tur
     * `offer_id`'yi KAYBEDER, ikinci bir offer yaratır ve eBay `25002`
     * duplicate döndürürdü — KALICI hata, listing "düzeltilemez"
     * damgasıyla ölür.
     */
    #[Test]
    public function channel_metadata_is_merged_not_replaced(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->draftListing($tenant, $variant, 'woocommerce');

        // Önceki turdan kalan kimlik — eBay senaryosunda `offer_id`.
        $this->asTenant($tenant, fn () => $listing->forceFill([
            'channel_metadata' => ['offer_id' => '8912345'],
        ])->save());

        ProgrammableCatalogAdapter::succeedOn('woocommerce', externalId: '4242');
        ProgrammableCatalogAdapter::alsoReturns('woocommerce', [
            'channel_metadata' => ['listing_id' => '110123456789'],
        ]);

        $operation = $this->openOperation($tenant, $listing, version: 1);

        $this->runJob($tenant, $operation->id);

        $metadata = $this->asTenant($tenant, fn () => $listing->fresh()->channel_metadata);

        $this->assertSame('8912345', $metadata['offer_id'] ?? null,
            'Önceki kimlik EZİLDİ — eBay\'de ikinci offer yaratılır ve 25002 alınır.');
        $this->assertSame('110123456789', $metadata['listing_id'] ?? null);
    }

    /**
     * Kanal ek kimlik döndürmezse mevcut değerlere DOKUNULMAZ.
     *
     * Woo ve Trendyol tek kimlik taşır; `external_parent_id` ve
     * `channel_metadata` onlarda NULL kalır ve kalmaya devam etmelidir.
     */
    #[Test]
    public function channels_returning_a_single_identifier_leave_the_extras_null(): void
    {
        [$tenant, $variant] = $this->makeContext();

        $listing = $this->draftListing($tenant, $variant, 'woocommerce');

        ProgrammableCatalogAdapter::succeedOn('woocommerce', externalId: '4242');

        $operation = $this->openOperation($tenant, $listing, version: 1);

        $this->runJob($tenant, $operation->id);

        $fresh = $this->asTenant($tenant, fn () => $listing->fresh());

        $this->assertNull($fresh->external_parent_id);
        $this->assertNull($fresh->channel_metadata);
    }

    /**
     * İşi worker'daki gibi çalıştırır — BAĞLAM SARMALAYICISI YOK.
     *
     * İş kendi kiracı bağlamını kurar ve bitişte bırakır; `asTenant()` ile
     * sarmak gerçek worker'ı taklit etmez ve işin `finally`'si çağıranın
     * bağlamını da temizlerdi.
     */
    private function runJob(Tenant $tenant, string $operationId): void
    {
        (new PushListing($operationId, $tenant->id))->handle(
            app(ListingPayloadBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );
    }

    private function stateFor(Tenant $tenant, string $listingId): ListingSyncState
    {
        return $this->asTenant($tenant, fn () => ListingSyncState::query()
            ->where('listing_id', $listingId)
            ->where('domain', SyncDomain::CONTENT->value)
            ->firstOrFail());
    }
}
