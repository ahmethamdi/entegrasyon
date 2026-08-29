<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Ebay\EbayAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CircuitBreaker;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushInventory;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\InventoryBatchBuilder;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\InventoryPushItem;
use App\Domain\Sync\Support\PricePushBatch;
use App\Domain\Sync\Support\SyncResultRecorder;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * eBay stok + fiyat — slice 4.6.
 *
 * V3.0 · §13.4 · v2.2 §7 · §8 · §9.
 *
 * ⚠️ `Http::fake()` AYNI TESTTE İKİ KEZ ÇAĞRILMAZ — her senaryo TEK
 * `fake()` kurar.
 */
final class EbayInventoryPricingTest extends TestCase
{
    use RefreshDatabase;

    /** Senaryonun kiracısı — yük kurucular kiracı bağlamı ister. */
    private Tenant $tenant;

    // ──────────────────────────────────────────────────── stok (§13.4)

    /**
     * ⚠️ HEDEF `offerId`, SKU DEĞİL (§13.4) — ve o kimlik
     * `channel_metadata`'da yaşar.
     *
     * `InventoryPushItem` `offer_id` TAŞIMAZ (`sku` ve `external_id`
     * taşır); `external_id` kullanılsaydı istek `listing_id`'yi offer
     * kimliği sanar ve var olmayan bir kaynağa giderdi.
     */
    #[Test]
    public function inventory_targets_the_offer_id_from_channel_metadata(): void
    {
        [$adapter, $listings] = $this->scenario();

        Http::fake(['*' => Http::response(['responses' => []], 200)]);

        $adapter->pushInventory($this->inventoryBatch($listings, [7]));

        Http::assertSent(static function (Request $r): bool {
            $offer = $r->data()['requests'][0]['offers'][0] ?? [];

            return ($offer['offerId'] ?? null) === 'OFFER-1'
                && ($offer['availableQuantity'] ?? null) === 7;
        });
    }

    /**
     * ⚠️ STOK YÜKÜ FİYAT ALANI TAŞIMAZ — Trendyol'un kuralı BURADA DA
     * geçerli ama SEBEBİ farklı.
     *
     * eBay uç noktası kısmi kalem kabul eder: yalnızca miktar
     * gönderilirse fiyata DOKUNULMAZ. Gönderilseydi bir STOK turu
     * satıcının kanal panelinden yaptığı kampanyayı SESSİZCE ezerdi
     * (§9: "sessizce ezmek EN SIK ŞİKAYET").
     *
     * Etsy'de durum TERSTİ — orada alanı göndermemek onu SİLMEKTİ.
     */
    #[Test]
    public function the_inventory_payload_never_carries_a_price(): void
    {
        [$adapter, $listings] = $this->scenario();

        Http::fake(['*' => Http::response(['responses' => []], 200)]);

        $adapter->pushInventory($this->inventoryBatch($listings, [7]));

        Http::assertSent(static function (Request $r): bool {
            $offer = $r->data()['requests'][0]['offers'][0] ?? [];

            return ! array_key_exists('price', $offer)
                && ! array_key_exists('pricingSummary', $offer);
        });
    }

    /**
     * ⚠️ OFFER KİMLİĞİ OLMAYAN YÜKTE SESSİZCE BAŞARILI DÖNÜLMEZ (§7).
     *
     * Dönülseydi operasyon tamamlandı sanılır, `synced_version` ilerler
     * ve satır kanalda hiçbir şey değişmemişken "senkron" görünürdü.
     */
    #[Test]
    public function a_batch_without_any_offer_id_fails_instead_of_succeeding(): void
    {
        [$adapter, $listings] = $this->scenario(offerIds: [null]);

        Http::fake(['*' => Http::response(['responses' => []], 200)]);

        $result = $adapter->pushInventory($this->inventoryBatch($listings, [7]));

        $this->assertTrue($result->failed());
        Http::assertNothingSent();
    }

    // ─────────────────────────────────────────────────── fiyat (§13.4)

    /**
     * ⚠️ FİYAT YÜKÜ MİKTAR TAŞIMAZ — stok turunun AYNASI, TERS bedelle.
     *
     * Gönderilseydi bir FİYAT turu stoğu ezerdi ve ürün satışa
     * KAPANABİLİRDİ: yanlış fiyattan satış devam eder, sıfır stokta
     * satış DURUR.
     */
    #[Test]
    public function the_price_payload_never_carries_a_quantity(): void
    {
        [$adapter, $listings] = $this->scenario();

        Http::fake(['*' => Http::response(['responses' => []], 200)]);

        $adapter->pushPrices($this->priceBatch($listings, ['249.90']));

        Http::assertSent(static function (Request $r): bool {
            $offer = $r->data()['requests'][0]['offers'][0] ?? [];

            return ! array_key_exists('availableQuantity', $offer)
                && ($offer['price']['value'] ?? null) === '249.90';
        });
    }

    /**
     * ⚠️ PARA BİRİMİ MARKETPLACE'TEN GELİR, kanonik koldan DEĞİL.
     *
     * `variants.currency` varsayılanı `TRY`'dir ve `EBAY_DE`'ye TRY
     * fiyat `VALIDATION` (KALICI) demektir.
     */
    #[Test]
    public function the_price_currency_comes_from_the_marketplace(): void
    {
        [$adapter, $listings] = $this->scenario();

        Http::fake(['*' => Http::response(['responses' => []], 200)]);

        $adapter->pushPrices($this->priceBatch($listings, ['249.90']));

        Http::assertSent(static fn (Request $r): bool => ($r->data()['requests'][0]['offers'][0]['price']['currency'] ?? null) === 'EUR');
    }

    /**
     * ⚠️ BİLİNMEYEN PAZARDA UYDURMA PARA BİRİMİYLE GÖNDERİLMEZ.
     *
     * Yanlış para birimi GÖRÜNMEZ bir hatadır: kanal kabul ederse ürün
     * 199.90 EUR yerine 199.90 USD'ye satılır ve satıcı ancak siparişte
     * fark eder. Eksik fiyat GÖRÜNÜR bir hatadır.
     */
    #[Test]
    public function an_unknown_marketplace_fails_instead_of_guessing_a_currency(): void
    {
        [$adapter, $listings] = $this->scenario(marketplace: 'EBAY_MARS');

        Http::fake(['*' => Http::response(['responses' => []], 200)]);

        $result = $adapter->pushPrices($this->priceBatch($listings, ['249.90']));

        $this->assertTrue($result->failed());
        Http::assertNothingSent();
    }

    // ──────────────────────────── KISMİ BAŞARI — §13.4'ün ana maddesi

    /**
     * ⚠️ 200 GÖVDE KODU "HEPSİ GEÇTİ" DEMEK DEĞİLDİR.
     *
     * Yanıt `responses[]` döner ve HER KALEM kendi `statusCode`'unu
     * taşır. Başarısız kalemler operasyon kimliğine eşlenmezse "senkron"
     * damgası yer ve stok kanalda YANLIŞ kalır.
     */
    #[Test]
    public function a_failed_item_inside_a_two_hundred_is_reported_as_partial(): void
    {
        [$adapter, $listings] = $this->scenario(offerIds: ['OFFER-1', 'OFFER-2']);

        Http::fake(['*' => Http::response(['responses' => [
            ['offerId' => 'OFFER-1', 'statusCode' => 200],
            ['offerId' => 'OFFER-2', 'statusCode' => 400, 'errors' => [
                ['errorId' => 25713, 'message' => 'Offer bulunamadı'],
            ]],
        ]], 200)]);

        $batch = $this->inventoryBatch($listings, [7, 3]);
        $result = $adapter->pushInventory($batch);

        $this->assertTrue($result->successful, 'Kısmi başarı yine de başarılıdır.');
        $this->assertTrue($result->hasFailedOperations());

        $failedId = $batch->operations()[1]->id;

        $this->assertArrayHasKey(
            $failedId,
            $result->failedOperations,
            'Başarısız kalem OPERASYON KİMLİĞİNE eşlenmedi — o satır '
            .'"senkron" damgası yer ve stok kanalda yanlış kalırdı.',
        );

        $this->assertArrayNotHasKey($batch->operations()[0]->id, $result->failedOperations);
    }

    /**
     * ⚠️ EŞLEŞTİRME `offerId` İLEDİR, SIRAYLA DEĞİL.
     *
     * eBay `responses[]` dizisini gönderdiğimiz sırada döndürmeyebilir;
     * konumla eşleştirilseydi bir kalemin hatası BAŞKA bir operasyona
     * yazılır ve İKİ satır birden yanlış olurdu.
     *
     * Yanıt bilinçli olarak TERS sırada geliyor.
     */
    #[Test]
    public function results_are_matched_by_offer_id_not_by_position(): void
    {
        [$adapter, $listings] = $this->scenario(offerIds: ['OFFER-1', 'OFFER-2']);

        Http::fake(['*' => Http::response(['responses' => [
            // ⚠️ TERS SIRA — ikinci gönderilen ÖNCE dönüyor.
            ['offerId' => 'OFFER-2', 'statusCode' => 200],
            ['offerId' => 'OFFER-1', 'statusCode' => 400, 'errors' => [
                ['errorId' => 25713, 'message' => 'Offer bulunamadı'],
            ]],
        ]], 200)]);

        $batch = $this->inventoryBatch($listings, [7, 3]);
        $result = $adapter->pushInventory($batch);

        $this->assertArrayHasKey(
            $batch->operations()[0]->id,
            $result->failedOperations,
            'Hata YANLIŞ operasyona yazıldı — konumla eşleştirme iki satırı '
            .'birden bozar.',
        );

        $this->assertArrayNotHasKey($batch->operations()[1]->id, $result->failedOperations);
    }

    /**
     * ⚠️ SEBEP ADIYLA SÖYLENİR — `/failures` ekranında GÖRÜNÜR (§12).
     *
     * "Kalem başarısız" demek satıcıya ne yapacağını söylemez.
     */
    #[Test]
    public function the_item_error_text_carries_the_channel_message(): void
    {
        [$adapter, $listings] = $this->scenario(offerIds: ['OFFER-1']);

        Http::fake(['*' => Http::response(['responses' => [
            ['offerId' => 'OFFER-1', 'statusCode' => 400, 'errors' => [
                ['errorId' => 25713, 'message' => 'Offer bulunamadı'],
            ]],
        ]], 200)]);

        $batch = $this->inventoryBatch($listings, [7]);
        $result = $adapter->pushInventory($batch);

        $text = $result->failedOperations[$batch->operations()[0]->id] ?? '';

        $this->assertStringContainsString('Offer bulunamadı', $text);
        $this->assertStringContainsString('25713', $text);
    }

    /**
     * ⚠️ KİMLİĞİ TANINMAYAN YANIT SATIRI YOK SAYILIR.
     *
     * Yükte OLMAYAN operasyona dokunmak v2.2'nin açık yasağıdır ve başka
     * bir bağlantının satırını öldürebilirdi.
     */
    #[Test]
    public function an_unknown_offer_id_in_the_response_is_ignored(): void
    {
        [$adapter, $listings] = $this->scenario(offerIds: ['OFFER-1']);

        Http::fake(['*' => Http::response(['responses' => [
            ['offerId' => 'OFFER-1', 'statusCode' => 200],
            ['offerId' => 'BASKA-BAGLANTI', 'statusCode' => 400, 'errors' => []],
        ]], 200)]);

        $result = $adapter->pushInventory($this->inventoryBatch($listings, [7]));

        $this->assertFalse(
            $result->hasFailedOperations(),
            'Yükte olmayan bir kimlik yüzünden operasyon başarısız yazıldı.',
        );
    }

    /** Hepsi geçtiyse sonuç DÜZ başarıdır — kısmi değil. */
    #[Test]
    public function an_all_successful_batch_is_not_partial(): void
    {
        [$adapter, $listings] = $this->scenario(offerIds: ['OFFER-1', 'OFFER-2']);

        Http::fake(['*' => Http::response(['responses' => [
            ['offerId' => 'OFFER-1', 'statusCode' => 200],
            ['offerId' => 'OFFER-2', 'statusCode' => 204],
        ]], 200)]);

        $result = $adapter->pushInventory($this->inventoryBatch($listings, [7, 3]));

        $this->assertTrue($result->successful);
        $this->assertFalse($result->hasFailedOperations());
        $this->assertSame(2, $result->data['pushed'] ?? null);
    }

    // ─────────────────── GERÇEK İŞ · kısmi başarı operasyona yazılır

    /**
     * ⚠️ "YAZILDI" ≠ "ÇAĞRILIYOR" — kısmi başarı GERÇEK `PushInventory`
     * işiyle sürülür.
     *
     * Adapter'ı doğrudan çağıran testler eşleştirmenin DOĞRU olduğunu
     * kanıtlar; çekirdeğin onu KULLANDIĞINI kanıtlamaz.
     *
     * ⚠️ AYIRT EDİCİ İŞARET İKİ SATIRIN AYRIŞMASIDIR: biri `COMPLETED`
     * ve sürümü İLERLEMİŞ, öteki `DEAD` ve sürümü İLERLEMEMİŞ. Yalnızca
     * "biri başarısız" iddia edilseydi, tümünü başarısız yazan bir
     * mutasyon da yeşil kalırdı.
     */
    #[Test]
    public function the_real_job_completes_the_good_item_and_kills_the_failed_one(): void
    {
        [, $listings, $tenant] = $this->scenario(offerIds: ['OFFER-1', 'OFFER-2'], withTenant: true);

        Http::fake(['*' => Http::response(['responses' => [
            ['offerId' => 'OFFER-1', 'statusCode' => 200],
            ['offerId' => 'OFFER-2', 'statusCode' => 400, 'errors' => [
                ['errorId' => 25713, 'message' => 'Offer bulunamadı'],
            ]],
        ]], 200)]);

        $operations = $this->runInventoryJob($tenant, $listings);

        $good = $this->asTenant($tenant, fn (): ?SyncOperation => $operations[0]->fresh());
        $bad = $this->asTenant($tenant, fn (): ?SyncOperation => $operations[1]->fresh());

        $this->assertSame(
            SyncOperationStatus::COMPLETED,
            $good?->status,
            'Geçen kalem tamamlanmadı — kalıcı hatalı TEK bir kalem '
            .'partinin tamamını öldürüyor.',
        );

        $this->assertSame(
            SyncOperationStatus::DEAD,
            $bad?->status,
            'Başarısız kalem ASILI kaldı — `retrying` durumunda ve '
            .'`attempt_count > 0` ile hiçbir tarama onu kurtaramaz, '
            .'`/failures` ekranında da GÖRÜNMEZ.',
        );
    }

    /**
     * ⚠️ BAŞARISIZ KALEMİN SÜRÜMÜ İLERLEMEZ.
     *
     * İlerleseydi satır "senkron" görünür, mutabakat farkı göremez ve
     * stok kanalda kalıcı olarak yanlış kalırdı — kısmi başarı
     * ayrımının VARLIK SEBEBİ tam olarak budur.
     */
    #[Test]
    public function the_failed_item_does_not_advance_its_synced_version(): void
    {
        [, $listings, $tenant] = $this->scenario(offerIds: ['OFFER-1', 'OFFER-2'], withTenant: true);

        Http::fake(['*' => Http::response(['responses' => [
            ['offerId' => 'OFFER-1', 'statusCode' => 200],
            ['offerId' => 'OFFER-2', 'statusCode' => 400, 'errors' => []],
        ]], 200)]);

        $this->runInventoryJob($tenant, $listings);

        $states = $this->asTenant($tenant, fn (): array => [
            $this->syncedVersion($listings[0]),
            $this->syncedVersion($listings[1]),
        ]);

        $this->assertSame(1, $states[0], 'Geçen kalemin sürümü ilerlemedi.');
        $this->assertSame(
            0,
            $states[1],
            'Başarısız kalemin sürümü İLERLEDİ — satır "senkron" görünür '
            .'ve mutabakat gerçek farkı göremezdi.',
        );
    }

    /**
     * ⚠️ KISMİ BAŞARIDA DEVRE KESİCİ AÇILMAZ.
     *
     * Kanal cevap verdi ve çağrıların çoğu geçti — altyapı SAĞLIKLIDIR.
     * Başarısızlık KALEM seviyesindedir ve devreyi açmak, çalışan bir
     * kanalı kapatmak olurdu.
     */
    #[Test]
    public function a_partial_result_does_not_open_the_circuit_breaker(): void
    {
        [, $listings, $tenant] = $this->scenario(offerIds: ['OFFER-1', 'OFFER-2'], withTenant: true);

        Http::fake(['*' => Http::response(['responses' => [
            ['offerId' => 'OFFER-1', 'statusCode' => 200],
            ['offerId' => 'OFFER-2', 'statusCode' => 400, 'errors' => []],
        ]], 200)]);

        $this->runInventoryJob($tenant, $listings);

        $connectionId = $this->asTenant($tenant, fn (): string => (string) $listings[0]->fresh()->channel_connection_id);

        $this->assertTrue(
            app(CircuitBreaker::class)->allows($connectionId),
            'Kalem hatası devreyi açtı — çalışan bir kanal kapatıldı.',
        );
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    private function syncedVersion(Listing $listing): int
    {
        return (int) ListingSyncState::query()
            ->where('listing_id', $listing->id)
            ->where('domain', SyncDomain::INVENTORY->value)
            ->value('synced_version');
    }

    /**
     * Gerçek `PushInventory` işini yürütür.
     *
     * @param  list<Listing>  $listings
     * @return list<SyncOperation>
     */
    private function runInventoryJob(Tenant $tenant, array $listings): array
    {
        $operations = [];

        foreach ($listings as $listing) {
            $operations[] = $this->asTenant($tenant, fn (): SyncOperation => app(OpenSyncOperation::class)->run(
                listing: $listing,
                domain: SyncDomain::INVENTORY,
                eventVersion: 1,
                intent: SyncIntent::NORMAL_SYNC,
            ));
        }

        (new PushInventory($operations[0]->id, $tenant->id))->handle(
            app(InventoryBatchBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );

        return $operations;
    }

    /**
     * @param  list<Listing>  $listings
     * @param  list<int>  $quantities
     */
    private function inventoryBatch(array $listings, array $quantities): InventoryPushBatch
    {
        return $this->asTenant(
            $this->tenant,
            fn (): InventoryPushBatch => $this->buildInventoryBatch($listings, $quantities),
        );
    }

    /**
     * @param  list<Listing>  $listings
     * @param  list<int>  $quantities
     */
    private function buildInventoryBatch(array $listings, array $quantities): InventoryPushBatch
    {
        $items = [];
        $operations = [];

        foreach ($listings as $index => $listing) {
            $items[] = new InventoryPushItem(
                listingId: (string) $listing->id,
                externalId: (string) $listing->external_id,
                sku: (string) $listing->variant?->sku,
                quantity: $quantities[$index] ?? 0,
                version: 1,
            );

            $operations[] = $this->operationFor($listing, SyncDomain::INVENTORY);
        }

        return new InventoryPushBatch(
            channelConnectionId: (string) $listings[0]->channel_connection_id,
            items: $items,
            operations: $operations,
        );
    }

    /**
     * @param  list<Listing>  $listings
     * @param  list<string>  $prices
     */
    private function priceBatch(array $listings, array $prices): PricePushBatch
    {
        return $this->asTenant(
            $this->tenant,
            fn (): PricePushBatch => $this->buildPriceBatch($listings, $prices),
        );
    }

    /**
     * @param  list<Listing>  $listings
     * @param  list<string>  $prices
     */
    private function buildPriceBatch(array $listings, array $prices): PricePushBatch
    {
        $items = [];
        $operations = [];

        foreach ($listings as $index => $listing) {
            $items[] = [
                'listing_id' => (string) $listing->id,
                'external_id' => (string) $listing->external_id,
                'price' => $prices[$index] ?? '0.00',
                'version' => 1,
            ];

            $operations[] = $this->operationFor($listing, SyncDomain::PRICE);
        }

        return new PricePushBatch(
            channelConnectionId: (string) $listings[0]->channel_connection_id,
            items: $items,
            operations: $operations,
        );
    }

    private function operationFor(Listing $listing, SyncDomain $domain): SyncOperation
    {
        return app(OpenSyncOperation::class)->run(
            listing: $listing,
            domain: $domain,
            eventVersion: 1,
            intent: SyncIntent::NORMAL_SYNC,
        );
    }

    /**
     * @param  list<string|null>  $offerIds
     * @return array{0: EbayAdapter, 1: list<Listing>, 2: Tenant}
     */
    private function scenario(
        array $offerIds = ['OFFER-1'],
        string $marketplace = 'EBAY_DE',
        bool $withTenant = false,
    ): array {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'ebay'],
            [
                'name' => 'eBay',
                'kind' => 'marketplace',
                'adapter_class' => EbayAdapter::class,
                'supports_webhooks' => false,
                'is_active' => false,
            ],
        ));

        $tenant = $this->tenant = (new CreateTenant)->run(
            name: 'eBay '.uniqid(),
            owner: User::factory()->create(),
        );

        [$adapter, $listings] = $this->asTenant($tenant, function () use ($offerIds, $marketplace): array {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ebay',
                'external_account_id' => 'ebay-seller-'.uniqid(),
                'status' => 'active',
                'settings' => [
                    'merchant_location_key' => 'WAREHOUSE-1',
                    'marketplace_id' => $marketplace,
                    'fulfillment_policy_id' => 'FP-1',
                    'payment_policy_id' => 'PP-1',
                    'return_policy_id' => 'RP-1',
                ],
            ]);

            app(CredentialVault::class)->store($connection, [
                'client_id' => 'app-id',
                'client_secret' => 'cert-id',
                'access_token' => 'gecerli-access',
                'refresh_token' => 'gecerli-refresh',
            ]);

            $listings = [];

            foreach ($offerIds as $index => $offerId) {
                $variant = Variant::factory()->create([
                    'sku' => 'SKU-'.($index + 1),
                    'price' => '199.90',
                    'currency' => 'TRY',
                ]);

                // ⚠️ AÇILIŞ STOĞU LEDGER ÜZERİNDEN GİRER (§4). Doğrudan
                // `inventory_levels` yazmak `on_hand = Σ on_hand_delta`
                // eşitliğini bozar. Stok satırı YOKSA
                // `InventoryBatchBuilder` kalemi YÜKE ALMAZ ve iş
                // `nothing_to_push` ile sessizce kapanır — bu test o
                // yüzden HİÇBİR ŞEY ölçmüyordu (ölçüldü: `SENT: []`).
                app(ApplyMovement::class)->run(
                    warehouseId: Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                    variantId: $variant->id,
                    type: MovementType::IMPORT,
                    quantity: 50,
                    idempotencyKey: 'import:'.$variant->id,
                    sourceType: 'test',
                );

                $listings[] = Listing::factory()->create([
                    'channel_connection_id' => $connection->id,
                    'variant_id' => $variant->id,
                    'external_id' => 'ITEM-'.($index + 1),
                    'lifecycle_status' => 'live',
                    'listed_at' => now(),
                    'channel_metadata' => $offerId === null ? null : ['offer_id' => $offerId],
                ]);
            }

            return [
                new EbayAdapter($connection, new ChannelHttpClient(
                    $connection,
                    app(CredentialVault::class),
                    app(PayloadRedactor::class),
                )),
                $listings,
            ];
        });

        unset($withTenant);

        return [$adapter, $listings, $tenant];
    }
}
