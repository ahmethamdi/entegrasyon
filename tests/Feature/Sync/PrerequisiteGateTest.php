<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\OptionDefinition;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Actions\SaveAttributeMapping;
use App\Domain\Channels\Actions\SaveCategoryMapping;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Actions\LockInventoryRows;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Support\MovementKey;
use App\Domain\Sync\Actions\PublishListing;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Support\PrerequisiteGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\AssertsLedgerIntegrity;
use Tests\Support\Channels\ProgrammableCatalogAdapter;
use Tests\TestCase;

/**
 * Ön koşul kapısı — §13 · Faz 2 · "Katalog aktarımı, ön koşul kapısı,
 * onay durumu takibi". §14 · pazaryeri karmaşıklığı.
 *
 * DEĞİŞMEZ KURAL — KAPI STOK AKIŞINA DOKUNMAZ (§14'ün ana tasarım hedefi):
 *   Eksik eşleştirmede listing `blocked` olur ve içerik gönderilmez, ama
 *   stok hareketleri, `inventory_levels` ve outbox olayları HİÇ
 *   ETKİLENMEZ. Pazaryerine özgü karmaşıklığın stok çekirdeğine
 *   dokunmaması bu maddenin varlık sebebidir.
 *
 * DEĞİŞMEZ KURAL — KAPI YETENEĞE GÖRE ÇALIŞIR:
 *   Yalnızca `SupportsTaxonomy` uygulayan kanallarda. WooCommerce'te
 *   kategori serbesttir ve kapı HİÇ çalışmaz — `if ($code === 'trendyol')`
 *   yazılmaz.
 *
 * DEĞİŞMEZ KURAL — ENGEL KALICI DEĞİLDİR:
 *   Kullanıcı eşleştirmeyi tamamlayınca satır yeniden akışa girer. Engel
 *   bir CEZA değil, bir BEKLEME durumudur.
 */
final class PrerequisiteGateTest extends TestCase
{
    use AssertsLedgerIntegrity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        ProgrammableCatalogAdapter::reset();
    }

    protected function tearDown(): void
    {
        ProgrammableCatalogAdapter::reset();

        parent::tearDown();
    }

    // ═══════════════════════════════════════════════ kapının kendisi

    /**
     * Eşleştirme hiç yoksa kapı KAPALIDIR ve sebebi ADIYLA söyler.
     *
     * "Ön koşul sağlanmadı" demek kullanıcıya ne yapacağını söylemez;
     * hangi kategorinin eşleşmediği yazılır.
     */
    #[Test]
    public function gate_is_closed_when_the_category_is_not_mapped(): void
    {
        [$tenant] = $this->makeTenant();
        $this->makeTree();
        $connection = $this->connection($tenant, 'trendyol');

        $product = $this->product($tenant, 'kadin-elbise');

        $result = $this->asTenant($tenant, fn () => app(PrerequisiteGate::class)
            ->check($product, $connection));

        $this->assertFalse($result->satisfied());
        $this->assertStringContainsString('kadin-elbise', $result->missingCategoryReason() ?? '');
    }

    /**
     * Kategori eşleşmiş ama ZORUNLU ÖZNİTELİK eksikse kapı yine KAPALIDIR.
     *
     * Eksikler ADIYLA sayılır: satıcı "3 öznitelik eksik" cümlesiyle hangi
     * ekrana gideceğini bilemez.
     */
    #[Test]
    public function gate_is_closed_when_a_required_attribute_is_not_mapped(): void
    {
        [$tenant] = $this->makeTenant();
        [$dress] = $this->makeTree();
        $connection = $this->connection($tenant, 'trendyol');

        $this->makeAttribute($dress, 'attr-size', 'Beden', isRequired: true);
        $this->makeAttribute($dress, 'attr-color', 'Renk', isRequired: true);
        // İSTEĞE BAĞLI öznitelik kapıyı KAPATMAZ.
        $this->makeAttribute($dress, 'attr-fabric', 'Kumaş', isRequired: false);

        $product = $this->product($tenant, 'kadin-elbise');

        $size = $this->asTenant($tenant, fn () => OptionDefinition::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Beden',
        ]));

        $result = $this->asTenant($tenant, function () use ($product, $connection, $dress, $size) {
            app(SaveCategoryMapping::class)->run('kadin-elbise', $dress);
            app(SaveAttributeMapping::class)->run($size, $dress, 'attr-size');

            return app(PrerequisiteGate::class)->check($product, $connection);
        });

        $this->assertFalse($result->satisfied());
        $this->assertSame(['Renk'], $result->missingAttributes(),
            'Eksik öznitelik ADIYLA döner; isteğe bağlı olan sayılmaz.');
    }

    /**
     * Her şey eşleştiğinde kapı AÇIKTIR.
     */
    #[Test]
    public function gate_is_open_when_everything_is_mapped(): void
    {
        [$tenant] = $this->makeTenant();
        [$dress] = $this->makeTree();
        $connection = $this->connection($tenant, 'trendyol');

        $this->makeAttribute($dress, 'attr-size', 'Beden', isRequired: true);

        $product = $this->product($tenant, 'kadin-elbise');

        $result = $this->asTenant($tenant, function () use ($product, $connection, $dress, $tenant) {
            $size = OptionDefinition::query()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Beden',
            ]);

            app(SaveCategoryMapping::class)->run('kadin-elbise', $dress);
            app(SaveAttributeMapping::class)->run($size, $dress, 'attr-size');

            return app(PrerequisiteGate::class)->check($product, $connection);
        });

        $this->assertTrue($result->satisfied());
        $this->assertSame([], $result->missingAttributes());
    }

    /**
     * TAKSONOMİSİZ KANALDA KAPI HİÇ ÇALIŞMAZ.
     *
     * WooCommerce `SupportsTaxonomy` uygulamaz; kategori serbesttir ve
     * eşleştirme aranmaz. Kapı kanal koduna değil YETENEĞE bakar.
     */
    #[Test]
    public function gate_does_not_apply_to_channels_without_taxonomy(): void
    {
        [$tenant] = $this->makeTenant();
        $this->makeTree();

        // ProgrammableCatalogAdapter SupportsTaxonomy uygulamaz.
        $connection = $this->connection($tenant, 'woocommerce');

        // Hiçbir eşleştirme YOK — yine de kapı açık olmalı.
        $product = $this->product($tenant, 'kadin-elbise');

        $result = $this->asTenant($tenant, fn () => app(PrerequisiteGate::class)
            ->check($product, $connection));

        $this->assertTrue($result->satisfied(),
            'Taksonomi yeteneği olmayan kanalda ön koşul aranmaz.');
        $this->assertFalse($result->applies());
    }

    /**
     * İÇ KATEGORİSİ OLMAYAN ÜRÜN de kapıya takılır — ve sebebi ayrıdır.
     *
     * `internal_category_id` NULL ise eşleştirilecek bir şey bile yoktur;
     * "kategori eşleşmemiş" demek kullanıcıyı eşleştirme ekranında hiç
     * bulunmayan bir satırı aramaya gönderirdi.
     */
    #[Test]
    public function a_product_without_an_internal_category_is_blocked_with_its_own_reason(): void
    {
        [$tenant] = $this->makeTenant();
        $this->makeTree();
        $connection = $this->connection($tenant, 'trendyol');

        $product = $this->product($tenant, internalCategoryId: null);

        $result = $this->asTenant($tenant, fn () => app(PrerequisiteGate::class)
            ->check($product, $connection));

        $this->assertFalse($result->satisfied());
        $this->assertStringContainsString('iç kategori', mb_strtolower($result->missingCategoryReason() ?? ''),
            'Sebep "eşleşme yok" değil "iç kategori atanmamış" olmalı.');
    }

    // ═══════════════════════════════════════════════ akışa etkisi

    /**
     * ENGELLENEN LISTING `blocked` OLUR VE İŞ ATILMAZ.
     *
     * §14: `listings.lifecycle_status = 'blocked'` +
     * `listing_sync_states(CONTENT).status = 'blocked'`.
     */
    #[Test]
    public function publishing_a_product_with_missing_mappings_blocks_instead_of_sending(): void
    {
        [$tenant] = $this->makeTenant();
        $this->makeTree();
        $connection = $this->connection($tenant, 'trendyol');

        $product = $this->product($tenant, 'kadin-elbise');

        $operationIds = $this->asTenant($tenant, fn () => app(PublishListing::class)
            ->run($product, $connection));

        $this->assertSame([], $operationIds, 'Engellenen ürün için operasyon açılmaz.');

        Queue::assertNothingPushed();

        $listing = $this->asTenant($tenant, fn () => Listing::query()->firstOrFail());

        $this->assertSame('blocked', $listing->lifecycle_status);

        $state = $this->asTenant($tenant, fn () => ListingSyncState::query()
            ->where('listing_id', $listing->id)
            ->where('domain', SyncDomain::CONTENT->value)
            ->firstOrFail());

        $this->assertSame('blocked', $state->status);
        $this->assertNotNull($state->last_error, 'Sebep panelde görünmek zorunda.');
        $this->assertStringContainsString('kadin-elbise', $state->last_error);
    }

    /**
     * ENGEL STOK AKIŞINA DOKUNMAZ — §14'ün ANA TASARIM HEDEFİ.
     *
     * Bu testin kırılması, pazaryeri karmaşıklığının stok çekirdeğine
     * sızdığı anlamına gelir.
     */
    #[Test]
    public function blocking_does_not_touch_the_stock_flow(): void
    {
        [$tenant] = $this->makeTenant();
        $this->makeTree();
        $connection = $this->connection($tenant, 'trendyol');

        $product = $this->product($tenant, 'kadin-elbise', openingStock: 7);

        $variantId = $this->asTenant($tenant, fn () => $product->variants()->firstOrFail()->id);

        $before = $this->stockSnapshot($tenant, $variantId);

        $this->asTenant($tenant, fn () => app(PublishListing::class)->run($product, $connection));

        $after = $this->stockSnapshot($tenant, $variantId);

        $this->assertSame($before, $after,
            'Ön koşul kapısı hareket, bakiye, sürüm veya outbox olayı DEĞİŞTİRMEZ.');

        // Ledger eşitliği de korunmalı.
        $this->assertLedgerMatchesProjection(
            $tenant->id,
            $this->asTenant($tenant, fn () => $tenant->defaultWarehouse()->id),
            $variantId,
        );
    }

    /**
     * EŞLEŞTİRME TAMAMLANINCA SATIR YENİDEN AKIŞA GİRER.
     *
     * Engel bir CEZA değil BEKLEME durumudur; kullanıcı eksiği kapatınca
     * ürün normal yoldan gönderilir ve `blocked` damgası kalkar.
     */
    #[Test]
    public function completing_the_mapping_unblocks_the_listing(): void
    {
        [$tenant] = $this->makeTenant();
        [$dress] = $this->makeTree();
        $connection = $this->connection($tenant, 'trendyol');

        $product = $this->product($tenant, 'kadin-elbise');

        // İlk deneme: engellenir.
        $this->asTenant($tenant, fn () => app(PublishListing::class)->run($product, $connection));

        $listing = $this->asTenant($tenant, fn () => Listing::query()->firstOrFail());
        $this->assertSame('blocked', $listing->lifecycle_status);

        // Satıcı eşleştirmeyi tamamlar.
        $this->asTenant($tenant, fn () => app(SaveCategoryMapping::class)->run('kadin-elbise', $dress));

        // İkinci deneme: geçer.
        $operationIds = $this->asTenant($tenant, fn () => app(PublishListing::class)
            ->run($product, $connection));

        $this->assertNotSame([], $operationIds, 'Eksik kapanınca akış yeniden başlar.');

        $fresh = DB::table('listings')->where('id', $listing->id)->first();

        $this->assertSame('draft', $fresh->lifecycle_status,
            'Engel kalkınca satır taslağa döner; canlı işaretini kanal onayı yazar.');

        $state = $this->asTenant($tenant, fn () => ListingSyncState::query()
            ->where('listing_id', $listing->id)
            ->where('domain', SyncDomain::CONTENT->value)
            ->firstOrFail());

        $this->assertNotSame('blocked', $state->status);
    }

    /**
     * İKİNCİ KEZ ENGELLENEN ÜRÜN İKİNCİ LISTING SATIRI AÇMAZ.
     *
     * `(channel_connection_id, variant_id)` tekildir; engel yolu da bu
     * kısıta uymak zorundadır.
     */
    #[Test]
    public function blocking_twice_does_not_create_a_second_listing(): void
    {
        [$tenant] = $this->makeTenant();
        $this->makeTree();
        $connection = $this->connection($tenant, 'trendyol');

        $product = $this->product($tenant, 'kadin-elbise');

        $this->asTenant($tenant, fn () => app(PublishListing::class)->run($product, $connection));
        $this->asTenant($tenant, fn () => app(PublishListing::class)->run($product, $connection));

        $count = $this->asTenant($tenant, fn () => Listing::query()->count());

        $this->assertSame(1, $count);
    }

    /**
     * TAKSONOMİSİZ KANAL ENGELLENMEZ — uçtan uca.
     *
     * Kapının varlığı Woo akışını hiçbir şekilde değiştirmemeli.
     */
    #[Test]
    public function a_woocommerce_publish_is_unaffected_by_the_gate(): void
    {
        [$tenant] = $this->makeTenant();
        $connection = $this->connection($tenant, 'woocommerce');

        $product = $this->product($tenant, internalCategoryId: null);

        $operationIds = $this->asTenant($tenant, fn () => app(PublishListing::class)
            ->run($product, $connection));

        $this->assertNotSame([], $operationIds);

        $listing = $this->asTenant($tenant, fn () => Listing::query()->firstOrFail());

        $this->assertSame('draft', $listing->lifecycle_status,
            'Taksonomisiz kanalda ön koşul aranmaz; satır normal taslak doğar.');
    }

    /**
     * ENGELLENEN GÖNDERİM "ZATEN GÜNCEL" DEMEZ.
     *
     * `PublishListing` boş dizi döndürmesi İKİ ayrı anlama gelir: sürüm
     * kapısı eledi (zaten gönderilmiş) veya ön koşul kapısı engelledi
     * (hiç gönderilmedi). İkisi tek mesaja indirgenirse satıcı eksik
     * eşleştirmeyi "her şey yolunda" sanır ve ürününün neden kanalda
     * görünmediğini asla anlayamaz.
     *
     * (GERÇEK TARAYICI ÇALIŞTIRMASINDA BULUNDU: panel engellenen ürün
     * için "GATE-1 bu kanalda zaten güncel." diyordu.)
     */
    #[Test]
    public function a_blocked_publish_does_not_report_already_up_to_date(): void
    {
        [$tenant, $user] = $this->makeTenant();
        $this->makeTree();
        $connection = $this->connection($tenant, 'trendyol');

        $product = $this->product($tenant, 'kadin-elbise');

        $response = $this->actingAs($user)
            ->post("/products/{$product->id}/channels", ['connection_id' => $connection->id]);

        $response->assertRedirect();

        $flash = session('success').session('warning');

        $this->assertStringNotContainsString('zaten güncel', $flash,
            'Engellenen ürün "zaten güncel" DEĞİLDİR — hiç gönderilmedi.');
        $this->assertStringContainsString('ön koşul', mb_strtolower($flash),
            'Mesaj engelin sebebini söylemeli.');
    }

    // ═══════════════════════════════════════════════ kiracı izolasyonu

    /**
     * BAŞKA KİRACININ EŞLEŞTİRMESİ KAPIYI AÇMAZ.
     *
     * Eşleştirme kiracıya aittir; B'nin kararı A'nın ürününü geçiremez.
     */
    #[Test]
    public function another_tenants_mapping_does_not_open_the_gate(): void
    {
        [$tenantA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();
        [$dress] = $this->makeTree();

        $connectionA = $this->connection($tenantA, 'trendyol');

        // B kiracısı AYNI iç kategori adını eşleştirir.
        $this->asTenant($tenantB, fn () => app(SaveCategoryMapping::class)->run('kadin-elbise', $dress));

        $productA = $this->product($tenantA, 'kadin-elbise');

        $result = $this->asTenant($tenantA, fn () => app(PrerequisiteGate::class)
            ->check($productA, $connectionA));

        $this->assertFalse($result->satisfied(),
            'B kiracısının eşleştirmesi A kiracısının kapısını açmamalı.');
    }

    // ───────────────────────────────────────────────────── yardımcılar

    /**
     * Stok tarafının tam görüntüsü — hareket, bakiye, sürüm ve outbox.
     *
     * @return array<string, mixed>
     */
    private function stockSnapshot(Tenant $tenant, string $variantId): array
    {
        return $this->asSystem(fn (): array => [
            'movements' => DB::table('inventory_movements')
                ->where('variant_id', $variantId)->count(),
            'levels' => DB::table('inventory_levels')
                ->where('variant_id', $variantId)
                ->get(['on_hand', 'reserved', 'version'])
                ->map(fn ($r): array => (array) $r)
                ->all(),
            'outbox' => DB::table('outbox_events')
                ->where('tenant_id', $tenant->id)->count(),
        ]);
    }

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(): array
    {
        $user = User::factory()->create();

        $tenant = (new CreateTenant)->run(name: 'Kapi '.uniqid(), owner: $user);

        return [$tenant, $user];
    }

    private function product(
        Tenant $tenant,
        ?string $internalCategoryId = null,
        int $openingStock = 0,
    ): Product {
        return $this->asTenant($tenant, function () use ($tenant, $internalCategoryId, $openingStock): Product {
            $product = Product::factory()->create([
                'tenant_id' => $tenant->id,
                'internal_category_id' => $internalCategoryId,
                'content_version' => 1,
            ]);

            $variant = Variant::factory()->create([
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
            ]);

            // AÇILIŞ STOĞU LEDGER ÜZERİNDEN girer — doğrudan yazmak
            // on_hand = Σ on_hand_delta eşitliğini bozardı.
            if ($openingStock > 0) {
                $warehouse = $tenant->defaultWarehouse();

                app(LockInventoryRows::class)
                    ->run($warehouse->id, [$variant->id]);

                app(ApplyMovement::class)->run(
                    warehouseId: $warehouse->id,
                    variantId: $variant->id,
                    type: MovementType::IMPORT,
                    quantity: $openingStock,
                    idempotencyKey: MovementKey::import(
                        (string) new UuidV7,
                    ),
                    sourceType: 'test',
                    sourceId: $product->id,
                );
            }

            return $product->load('variants');
        });
    }

    private function connection(Tenant $tenant, string $channelTypeCode): ChannelConnection
    {
        $adapter = $channelTypeCode === 'trendyol'
            ? TrendyolAdapter::class
            : ProgrammableCatalogAdapter::class;

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
            'status' => 'active',
            'health_status' => 'healthy',
        ]));
    }

    /** @return array{0: ChannelCategory, 1: ChannelCategory} */
    private function makeTree(string $version = 'v1'): array
    {
        return $this->asSystem(function () use ($version): array {
            ChannelType::query()->updateOrCreate(
                ['code' => 'trendyol'],
                [
                    'name' => 'Trendyol',
                    'kind' => 'marketplace',
                    'adapter_class' => TrendyolAdapter::class,
                    'is_active' => true,
                ],
            );

            ChannelCategory::query()->updateOrCreate(
                ['channel_type_code' => 'trendyol', 'taxonomy_version' => $version, 'external_id' => '1'],
                ['name' => 'Giyim', 'path' => 'Giyim', 'is_leaf' => false],
            );

            $dress = ChannelCategory::query()->updateOrCreate(
                ['channel_type_code' => 'trendyol', 'taxonomy_version' => $version, 'external_id' => '11'],
                ['parent_external_id' => '1', 'name' => 'Elbise', 'path' => 'Giyim > Elbise', 'is_leaf' => true],
            );

            $shoe = ChannelCategory::query()->updateOrCreate(
                ['channel_type_code' => 'trendyol', 'taxonomy_version' => $version, 'external_id' => '12'],
                ['parent_external_id' => '1', 'name' => 'Ayakkabı', 'path' => 'Giyim > Ayakkabı', 'is_leaf' => true],
            );

            return [$dress, $shoe];
        });
    }

    private function makeAttribute(
        ChannelCategory $category,
        string $externalAttributeId,
        string $name,
        bool $isRequired = true,
    ): ChannelCategoryAttribute {
        return $this->asSystem(fn () => ChannelCategoryAttribute::query()->updateOrCreate(
            ['channel_category_id' => $category->id, 'external_attribute_id' => $externalAttributeId],
            [
                'name' => $name,
                'is_required' => $isRequired,
                'is_variant_defining' => true,
                'data_type' => 'string',
                'allowed_values' => [],
            ],
        ));
    }
}
