<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\InventoryPushItem;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Shopify stok — slice 1.5. FAZ 1'İN EN KRİTİK PARÇASI.
 *
 * V3.0 · §06.5 · v2.2 §7 · Karar 25.
 *
 * ⚠️ DELTA MUTATION'I YASAKTIR. Shopify hem `inventorySetOnHandQuantities`
 * (mutlak) hem `inventoryAdjustQuantities` (delta) sunar. Delta daha
 * "verimli" görünür ve bu görüntü ALDATICIDIR: kaybolan veya İKİ KEZ
 * işlenen bir istek kanaldaki bakiyeyi KALICI olarak kaydırır ve fark geri
 * kazanılamaz. Mutlak değerde tekrar ZARARSIZDIR — yeniden denemenin
 * güvenli olmasının ve mutabakatın çalışabilmesinin dayanağı budur.
 *
 * ⚠️ HEDEF `inventoryItemId` — mutation variant gid'ini KABUL ETMEZ.
 * Kimlik slice 1.3'te `channel_metadata`'ya yazıldı ve burada TEK sorguyla
 * okunur; kalem başına ayrı çevrim yapılsaydı stok yolu (projenin en
 * kritik yolu) İKİ KATINA çıkardı.
 */
final class ShopifyInventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ MUTLAK DEĞER MUTATION'I KULLANILIR — delta ASLA.
     */
    #[Test]
    public function inventory_is_written_with_the_absolute_value_mutation(): void
    {
        $this->fakeSuccess();

        [$adapter, $batch] = $this->adapterWithBatch(quantity: 7);

        $adapter->pushInventory($batch);

        Http::assertSent(function ($request): bool {
            $query = (string) ($request->data()['query'] ?? '');

            return str_contains($query, 'inventorySetOnHandQuantities')
                && ! str_contains($query, 'inventoryAdjustQuantities');
        });
    }

    /**
     * Gönderilen miktar MUTLAKTIR ve kırpma TEKRARLANMAZ.
     *
     * Kırpma `OutboundQuantity::forChannel()` içinde YAPILDI; adapter onu
     * yeniden uygulasaydı kural iki yerde yaşar ve biri değiştiğinde
     * ötekinin sessizce eski kalması an meselesi olurdu.
     */
    #[Test]
    public function the_payload_carries_the_absolute_quantity(): void
    {
        $this->fakeSuccess();

        [$adapter, $batch] = $this->adapterWithBatch(quantity: 42);

        $adapter->pushInventory($batch);

        Http::assertSent(function ($request): bool {
            $set = $request->data()['variables']['input']['setQuantities'][0] ?? [];

            return ($set['quantity'] ?? null) === 42;
        });
    }

    /**
     * ⚠️ HEDEF `inventoryItemId` — VARIANT GID DEĞİL.
     *
     * Bu testin varlık sebebi: `external_id` (variant gid) gönderilseydi
     * Shopify `userErrors` döner, o hata `VALIDATION` yani KALICIDIR ve
     * listing "düzeltilemez" damgasıyla ölürdü.
     */
    #[Test]
    public function the_target_is_the_inventory_item_gid_not_the_variant_gid(): void
    {
        $this->fakeSuccess();

        [$adapter, $batch] = $this->adapterWithBatch(
            externalId: 'gid://shopify/ProductVariant/456',
            inventoryItemGid: 'gid://shopify/InventoryItem/789',
        );

        $adapter->pushInventory($batch);

        Http::assertSent(function ($request): bool {
            $set = $request->data()['variables']['input']['setQuantities'][0] ?? [];

            return ($set['inventoryItemId'] ?? null) === 'gid://shopify/InventoryItem/789'
                && ($set['inventoryItemId'] ?? null) !== 'gid://shopify/ProductVariant/456';
        });
    }

    /** Konum yüke KONULUR — stok konuma yazılır. */
    #[Test]
    public function the_payload_carries_the_location(): void
    {
        $this->fakeSuccess();

        [$adapter, $batch] = $this->adapterWithBatch();

        $adapter->pushInventory($batch);

        Http::assertSent(function ($request): bool {
            $set = $request->data()['variables']['input']['setQuantities'][0] ?? [];

            return ($set['locationId'] ?? null) === 'gid://shopify/Location/12';
        });
    }

    /**
     * ⚠️ KONUM YOKSA STOK GÖNDERİLMEZ — istisna fırlatılır.
     *
     * Sağlık kontrolü bunu bağlantı kurulurken yakalar (P1-5); buraya
     * düşmesi bağlantının SONRADAN bozulduğu anlamına gelir. Sessizce
     * varsayılan konuma yazmak, iki depolu satıcının stoğunu YANLIŞ DEPOYA
     * yazardı.
     */
    #[Test]
    public function pushing_without_a_location_throws_instead_of_guessing(): void
    {
        Http::fake();

        [$adapter, $batch] = $this->adapterWithBatch(settings: []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/konum/i');

        $adapter->pushInventory($batch);
    }

    /**
     * ⚠️ KİMLİĞİ OLMAYAN KALEM YÜKE ALINMAZ ama SESSİZCE DÜŞMEZ.
     *
     * Boş `inventoryItemId` ile giden mutation `userErrors` döner ve o hata
     * KALICIDIR. Atlanan kalem sonuç verisinde ADIYLA raporlanır — sessiz
     * kırpma yok (§13).
     */
    #[Test]
    public function an_item_without_an_inventory_item_gid_is_reported_not_silently_dropped(): void
    {
        $this->fakeSuccess();

        [$adapter, $batch] = $this->adapterWithBatchOfTwo(
            firstInventoryItemGid: 'gid://shopify/InventoryItem/789',
            secondInventoryItemGid: null,
        );

        $result = $adapter->pushInventory($batch);

        $this->assertFalse($result->failed());
        $this->assertSame(1, $result->data['pushed']);
        $this->assertContains(
            'SKU-2',
            $result->data['skipped_without_inventory_item'],
            'Atlanan kalem raporlanmadı — satıcı stoğun neden gitmediğini göremez.',
        );

        // Yalnızca kimliği olan kalem gönderildi.
        Http::assertSent(function ($request): bool {
            return count($request->data()['variables']['input']['setQuantities'] ?? []) === 1;
        });
    }

    /**
     * HİÇBİR kalemin kimliği yoksa operasyon BAŞARISIZDIR.
     *
     * Başarı dönülseydi `synced_version` ilerler ve kanalda hiçbir şey
     * değişmemişken satır "senkron" görünürdü (P0-1'in kardeşi).
     */
    #[Test]
    public function a_batch_where_no_item_has_an_identifier_fails(): void
    {
        Http::fake();

        [$adapter, $batch] = $this->adapterWithBatch(inventoryItemGid: null);

        $result = $adapter->pushInventory($batch);

        $this->assertTrue($result->failed());
        Http::assertNothingSent();
    }

    /** Boş yükte çağrı YAPILMAZ — kota boşa harcanmaz. */
    #[Test]
    public function an_empty_batch_sends_nothing(): void
    {
        Http::fake();

        [$adapter] = $this->adapterWithBatch();

        $result = $adapter->pushInventory(new InventoryPushBatch('bos', [], []));

        $this->assertFalse($result->failed());
        $this->assertSame(0, $result->data['pushed']);
        Http::assertNothingSent();
    }

    /**
     * ⚠️ P0-1 — stok yazmada `userErrors` da yakalanır.
     *
     * Yanıt HTTP 200'dür. Kontrol edilmezse `SyncResultRecorder` BAŞARI
     * yazar, `synced_version` ilerler ve KANALDAKİ STOK YANLIŞ KALIR —
     * fazla satışa doğrudan yol açar.
     */
    #[Test]
    public function user_errors_during_an_inventory_push_are_a_failure(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['inventorySetOnHandQuantities' => [
                'inventoryAdjustmentGroup' => null,
                'userErrors' => [['field' => ['input'], 'message' => 'Location not found']],
            ]],
        ], 200)]);

        [$adapter, $batch] = $this->adapterWithBatch();

        $this->expectExceptionMessageMatches('/Location not found/');

        $adapter->pushInventory($batch);
    }

    /**
     * TEK ÇAĞRIDA ÇOK KALEM — 50'lik yük 51 istek atmaz.
     *
     * Kimlik haritası TEK sorguyla okunur; kalem başına ayrı GraphQL
     * çevrimi yapılsaydı stok yolu (projenin en kritik yolu) İKİ KATINA
     * çıkardı.
     */
    #[Test]
    public function a_multi_item_batch_is_sent_in_a_single_request(): void
    {
        $this->fakeSuccess();

        [$adapter, $batch] = $this->adapterWithBatchOfTwo(
            firstInventoryItemGid: 'gid://shopify/InventoryItem/1',
            secondInventoryItemGid: 'gid://shopify/InventoryItem/2',
        );

        $adapter->pushInventory($batch);

        Http::assertSentCount(1);

        Http::assertSent(function ($request): bool {
            return count($request->data()['variables']['input']['setQuantities'] ?? []) === 2;
        });
    }

    // ─────────────────────────────────────────────────────── mutabakat okuma

    /**
     * Uzak stok TOPLU okunur ve `external_id` ile anahtarlanır.
     *
     * ⚠️ ANAHTAR VARIANT GID OLMALIDIR — mutabakat kalemi onunla eşleştirir.
     * `inventoryItemId` ile anahtarlansaydı karşılaştırma HİÇBİR listing'i
     * bulamaz ve her tur "uzak değer okunamadı" derdi.
     */
    #[Test]
    public function remote_inventory_is_keyed_by_the_variant_gid(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['nodes' => [
                ['id' => 'gid://shopify/ProductVariant/456', 'inventoryQuantity' => 13],
            ]],
        ], 200)]);

        [$adapter, , $listing] = $this->adapterWithBatch(
            externalId: 'gid://shopify/ProductVariant/456',
        );

        $snapshot = $adapter->fetchInventory([$listing]);

        $this->assertSame(13, $snapshot->quantityFor('gid://shopify/ProductVariant/456'));
    }

    /**
     * ⚠️ SİLİNMİŞ VARYANT SIFIR OLARAK OKUNMAZ — ATLANIR.
     *
     * Shopify silinmiş düğüm için `null` döner. Sıfır yazılsaydı mutabakat
     * "kanalda 0 var" sanır ve SÜRÜKLENME raporlardı; oysa satır kanalda
     * HİÇ YOKTUR ve doğru sınıflandırma `REMOTE_MISSING`'dir — o da
     * otomatik onarım AÇMAZ (v2.2 · §10).
     */
    #[Test]
    public function a_deleted_variant_is_skipped_not_read_as_zero(): void
    {
        // Shopify silinmiş düğüm için dizide `null` döndürür.
        Http::fake(['*' => Http::response(['data' => ['nodes' => [null]]], 200)]);

        [$adapter, , $listing] = $this->adapterWithBatch(
            externalId: 'gid://shopify/ProductVariant/456',
        );

        $snapshot = $adapter->fetchInventory([$listing]);

        $this->assertTrue($snapshot->isEmpty());
        $this->assertNull($snapshot->quantityFor('gid://shopify/ProductVariant/456'));
    }

    /**
     * ⚠️ STOK TAKİBİ KAPALI VARYANT DA SIFIR OKUNMAZ.
     *
     * Shopify, `inventoryItem.tracked = false` olan varyantta düğümü
     * DÖNDÜRÜR ama `inventoryQuantity` alanı `null` gelir. Kimlik dolu
     * olduğu için `null` düğüm elemesine TAKILMAZ.
     *
     * Sıfır sayılsaydı mutabakat "kanalda 0 var, bizde 7 var" der ve her
     * turda SÜRÜKLENME raporlardı; onarım 0'ı 7 yapmaya çalışır, kanal
     * takip kapalı olduğu için uygulamaz ve üç tur sonra kalem
     * `MANUAL_REVIEW`'a düşerdi — hiçbir şey bozuk olmadığı hâlde.
     *
     * Bu senaryo `null` düğüm testinden AYRIDIR ve onu mutasyonla
     * yakalayan tek testtir.
     */
    #[Test]
    public function a_variant_with_untracked_inventory_is_skipped_not_read_as_zero(): void
    {
        Http::fake(['*' => Http::response(['data' => ['nodes' => [
            ['id' => 'gid://shopify/ProductVariant/456', 'inventoryQuantity' => null],
        ]]], 200)]);

        [$adapter, , $listing] = $this->adapterWithBatch(
            externalId: 'gid://shopify/ProductVariant/456',
        );

        $snapshot = $adapter->fetchInventory([$listing]);

        $this->assertNull(
            $snapshot->quantityFor('gid://shopify/ProductVariant/456'),
            'Takibi kapalı varyant 0 okundu — her tur sahte sürüklenme raporlanır.',
        );
        $this->assertTrue($snapshot->isEmpty());
    }

    /** Kimliksiz listing sorguya girmez ve boş çağrı atılmaz. */
    #[Test]
    public function fetching_with_no_external_ids_sends_nothing(): void
    {
        Http::fake();

        [$adapter, , $listing] = $this->adapterWithBatch(externalId: null);

        $this->assertTrue($adapter->fetchInventory([$listing])->isEmpty());
        Http::assertNothingSent();
    }

    /** Yetenek `instanceof` ile okunur. */
    #[Test]
    public function the_adapter_declares_the_inventory_capability(): void
    {
        [$adapter] = $this->adapterWithBatch();

        $this->assertInstanceOf(SupportsInventory::class, $adapter);
        $this->assertSame(250, $adapter->maxInventoryBatchSize());
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function fakeSuccess(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['inventorySetOnHandQuantities' => [
                'inventoryAdjustmentGroup' => ['createdAt' => '2026-08-25T10:00:00Z'],
                'userErrors' => [],
            ]],
        ], 200)]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0: ShopifyAdapter, 1: InventoryPushBatch, 2: Listing}
     */
    private function adapterWithBatch(
        int $quantity = 5,
        ?string $externalId = 'gid://shopify/ProductVariant/456',
        ?string $inventoryItemGid = 'gid://shopify/InventoryItem/789',
        array $settings = ['location_gid' => 'gid://shopify/Location/12'],
    ): array {
        $tenant = $this->makeTenant();

        return $this->asTenant($tenant, function () use (
            $quantity, $externalId, $inventoryItemGid, $settings
        ): array {
            $connection = $this->connection($settings);

            $variant = Variant::factory()->create(['sku' => 'SKU-1']);

            $listing = Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => $variant->id,
                'external_id' => $externalId,
                'channel_metadata' => $inventoryItemGid === null
                    ? null
                    : ['inventory_item_gid' => $inventoryItemGid],
            ]);

            $batch = new InventoryPushBatch($connection->id, [
                new InventoryPushItem(
                    listingId: $listing->id,
                    externalId: (string) $externalId,
                    sku: 'SKU-1',
                    quantity: $quantity,
                    version: 1,
                ),
            ], []);

            return [$this->adapterFor($connection), $batch, $listing];
        });
    }

    /** @return array{0: ShopifyAdapter, 1: InventoryPushBatch} */
    private function adapterWithBatchOfTwo(
        ?string $firstInventoryItemGid,
        ?string $secondInventoryItemGid,
    ): array {
        $tenant = $this->makeTenant();

        return $this->asTenant($tenant, function () use (
            $firstInventoryItemGid, $secondInventoryItemGid
        ): array {
            $connection = $this->connection(['location_gid' => 'gid://shopify/Location/12']);

            $items = [];

            foreach ([
                ['SKU-1', 'gid://shopify/ProductVariant/1', $firstInventoryItemGid, 3],
                ['SKU-2', 'gid://shopify/ProductVariant/2', $secondInventoryItemGid, 9],
            ] as [$sku, $variantGid, $inventoryGid, $qty]) {
                $variant = Variant::factory()->create(['sku' => $sku]);

                $listing = Listing::factory()->create([
                    'channel_connection_id' => $connection->id,
                    'variant_id' => $variant->id,
                    'external_id' => $variantGid,
                    'channel_metadata' => $inventoryGid === null
                        ? null
                        : ['inventory_item_gid' => $inventoryGid],
                ]);

                $items[] = new InventoryPushItem(
                    listingId: $listing->id,
                    externalId: $variantGid,
                    sku: $sku,
                    quantity: $qty,
                    version: 1,
                );
            }

            return [$this->adapterFor($connection), new InventoryPushBatch($connection->id, $items, [])];
        });
    }

    /** @param array<string, mixed> $settings */
    private function connection(array $settings): ChannelConnection
    {
        $connection = ChannelConnection::factory()->create([
            'channel_type_code' => 'shopify',
            'external_account_id' => 'magaza.myshopify.com',
            'settings' => $settings,
        ]);

        app(CredentialVault::class)->store($connection, ['access_token' => 'shpat_test']);

        return $connection;
    }

    private function adapterFor(ChannelConnection $connection): ShopifyAdapter
    {
        return new ShopifyAdapter(
            $connection,
            new ChannelHttpClient(
                $connection,
                app(CredentialVault::class),
                app(PayloadRedactor::class),
            ),
        );
    }

    private function makeTenant(): Tenant
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'shopify'],
            [
                'name' => 'Shopify',
                'kind' => 'storefront',
                'adapter_class' => ShopifyAdapter::class,
                'supports_webhooks' => true,
                'is_active' => false,
            ],
        ));

        return (new CreateTenant)->run(
            name: 'Shopify Stok '.uniqid(),
            owner: User::factory()->create(),
        );
    }
}
