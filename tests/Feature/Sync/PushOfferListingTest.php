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
use App\Domain\Sync\Jobs\PushOfferListing;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\ContentPushDispatcher;
use App\Domain\Sync\Support\ListingPayloadBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Channels\ProgrammableCatalogAdapter;
use Tests\Support\Channels\ProgrammableOfferAdapter;
use Tests\TestCase;

/**
 * Çok adımlı yayın zinciri — slice 4.3.
 *
 * V3.0 · §03 · Delta 1 · §13.1 · §13.2.
 *
 * ═════════════════════════════════════════════════════════════════════
 * BU TESTİN ASIL İDDİASI: ARA BAŞARISIZLIK KURTARILIR
 * ═════════════════════════════════════════════════════════════════════
 * Delta 1'in var olma sebebi budur ve başka hiçbir test onu sürmez.
 * Zincir "üç çağrı yapıldı" diye değil, "ikinci tur BAŞTAN BAŞLAMADI"
 * diye doğrudur.
 */
final class PushOfferListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        ProgrammableOfferAdapter::reset();
        ProgrammableCatalogAdapter::reset();
    }

    protected function tearDown(): void
    {
        ProgrammableOfferAdapter::reset();
        ProgrammableCatalogAdapter::reset();

        parent::tearDown();
    }

    // ───────────────────────────────────────────────────── mutlu yol

    /**
     * Taslak listing üç adımdan geçer ve CANLI olur.
     *
     * İKİ KİMLİK DE KALICIDIR (§13.1): `offer_id` stok/fiyat yazma
     * hedefidir ve `channel_metadata`'da yaşar; `listing_id` satıcının
     * kanalda GÖRDÜĞÜ ilandır ve `external_id` olur.
     */
    #[Test]
    public function a_draft_listing_walks_the_whole_chain_and_becomes_live(): void
    {
        [$tenant, $variant] = $this->makeContext();
        $listing = $this->draftListing($tenant, $variant);

        ProgrammableOfferAdapter::succeed(offerId: 'OFFER-77', listingId: 'ITEM-99');

        $operation = $this->openOperation($tenant, $listing, version: 1);
        $this->runJob($tenant, $operation->id);

        $this->assertSame(
            ['inventory_item', 'offer', 'publish'],
            ProgrammableOfferAdapter::calls(),
            'Zincir üç adımı SIRAYLA yürütmeli.',
        );

        $fresh = $this->asTenant($tenant, fn () => $listing->fresh());

        $this->assertSame('ITEM-99', $fresh->external_id, '`external_id` = listing_id olmalı.');
        $this->assertSame(
            'OFFER-77',
            $fresh->channel_metadata['offer_id'] ?? null,
            '`offer_id` kalıcı değil — stok/fiyat yazma hedefi kaybolur.',
        );
        $this->assertTrue($fresh->isLive());
        $this->assertNotNull($fresh->listed_at);

        $this->assertSame(
            SyncOperationStatus::COMPLETED,
            $this->asTenant($tenant, fn () => $operation->fresh())->status,
        );

        $state = $this->stateFor($tenant, $listing->id);
        $this->assertSame(1, $state->synced_version);
        $this->assertFalse($state->is_dirty);
    }

    // ═══════════════════════════════ ARA BAŞARISIZLIK — Delta 1'in özü

    /**
     * ⚠️ PUBLISH DÜŞSE BİLE `offer_id` KALICIDIR (§13.2).
     *
     * Bu, Delta 1'in TEK CÜMLELİK gerekçesidir. v2.2 kuralı
     * "başarısızlıkta kimlik YAZILMAZ" der ve o kural `external_id` için
     * doğrudur — ama `offer_id` KAYBOLURSA sonraki tur ikinci bir offer
     * yaratır ve kanal `25002` (duplicate) döner: KALICI hata, listing
     * "düzeltilemez" damgasıyla ölür.
     *
     * ⚠️ AYIRT EDİCİ İŞARET `external_id`'NİN BOŞ KALMASIDIR. Yalnızca
     * `offer_id` iddia edilseydi, zincirin publish adımını hiç
     * çağırmayan bozuk bir uygulama da testi GEÇERDİ.
     */
    #[Test]
    public function a_failed_publish_still_persists_the_offer_id(): void
    {
        [$tenant, $variant] = $this->makeContext();
        $listing = $this->draftListing($tenant, $variant);

        ProgrammableOfferAdapter::succeed(offerId: 'OFFER-42');
        ProgrammableOfferAdapter::failAt('publish', ErrorClass::RATE_LIMITED);

        $operation = $this->openOperation($tenant, $listing, version: 1);
        $this->runJob($tenant, $operation->id);

        $fresh = $this->asTenant($tenant, fn () => $listing->fresh());

        $this->assertSame(
            'OFFER-42',
            $fresh->channel_metadata['offer_id'] ?? null,
            'Ara başarısızlıkta `offer_id` KAYBOLDU — sonraki tur ikinci bir '
            .'offer yaratır ve kanal 25002 duplicate döner (KALICI hata).',
        );

        $this->assertNull(
            $fresh->external_id,
            'Yayın BAŞARISIZ olmasına rağmen `external_id` yazıldı — satır '
            .'yayınlanmış görünür ve fan-out hedefi olur.',
        );

        $this->assertFalse($fresh->isLive(), 'Yayınlanmamış satır CANLI olmamalı.');
    }

    /**
     * ⚠️ İKİNCİ TUR BAŞTAN BAŞLAMAZ — `offer_id` VARSA PUBLISH'TEN DEVAM.
     *
     * `POST /offer` ikinci kez çağrılsaydı eBay `25002` döndürürdü.
     * Bu testin ölçtüğü şey ÇAĞRI SIRASIDIR: envanter kalemi yine
     * çağrılır (PUT idempotenttir ve içerik güncellemesi oradan gider),
     * ama offer YARATILMAZ — GÜNCELLENİR ve zincir publish'e ulaşır.
     */
    #[Test]
    public function the_second_run_resumes_from_publish_instead_of_starting_over(): void
    {
        [$tenant, $variant] = $this->makeContext();
        $listing = $this->draftListing($tenant, $variant);

        // ① Birinci tur: publish düşer, `offer_id` yazılır.
        ProgrammableOfferAdapter::succeed(offerId: 'OFFER-42');
        ProgrammableOfferAdapter::failAt('publish');

        $first = $this->openOperation($tenant, $listing, version: 1);
        $this->runJob($tenant, $first->id);

        // ② İkinci tur: kanal düzeldi.
        ProgrammableOfferAdapter::reset();
        ProgrammableOfferAdapter::succeed(offerId: 'OFFER-42', listingId: 'ITEM-99');

        $this->runJob($tenant, $first->id);

        $this->assertContains(
            'publish',
            ProgrammableOfferAdapter::calls(),
            'İkinci tur yayına ULAŞMADI.',
        );

        $fresh = $this->asTenant($tenant, fn () => $listing->fresh());

        $this->assertSame('ITEM-99', $fresh->external_id);
        $this->assertTrue($fresh->isLive());
        $this->assertSame('OFFER-42', $fresh->channel_metadata['offer_id'] ?? null);
    }

    /**
     * ⚠️ YAYINLANMIŞ İLANDA PUBLISH TEKRAR ÇAĞRILMAZ.
     *
     * Çağrılsaydı eBay yayında olan bir offer için hata döndürür ve HER
     * içerik güncellemesi başarısız olurdu — oysa offer adımı zaten
     * yayındaki ilanı güncelledi.
     */
    #[Test]
    public function publishing_is_skipped_for_an_already_published_listing(): void
    {
        [$tenant, $variant] = $this->makeContext();
        $listing = $this->liveListing($tenant, $variant, externalId: 'ITEM-99', offerId: 'OFFER-42');

        ProgrammableOfferAdapter::succeed();

        $operation = $this->openOperation($tenant, $listing, version: 2);
        $this->runJob($tenant, $operation->id);

        $this->assertSame(
            ['inventory_item', 'offer'],
            ProgrammableOfferAdapter::calls(),
            'Yayındaki ilan için publish TEKRAR çağrıldı — kanal hata döndürür '
            .'ve her içerik güncellemesi başarısız olurdu.',
        );
    }

    /**
     * ⚠️ İLK ADIM DÜŞERSE OFFER HİÇ AÇILMAZ.
     *
     * Açılsaydı envanter kalemi olmayan bir offer yaratılır ve kanal
     * `VALIDATION` döndürürdü — üstelik o `offer_id` kalıcı olarak
     * yazılıp yanlış bir kurtarma noktası bırakırdı.
     */
    #[Test]
    public function a_failed_inventory_item_never_opens_an_offer(): void
    {
        [$tenant, $variant] = $this->makeContext();
        $listing = $this->draftListing($tenant, $variant);

        ProgrammableOfferAdapter::succeed();
        ProgrammableOfferAdapter::failAt('inventory_item');

        $operation = $this->openOperation($tenant, $listing, version: 1);
        $this->runJob($tenant, $operation->id);

        $this->assertSame(['inventory_item'], ProgrammableOfferAdapter::calls());

        $fresh = $this->asTenant($tenant, fn () => $listing->fresh());

        $this->assertNull($fresh->channel_metadata['offer_id'] ?? null);
        $this->assertNull($fresh->external_id);
    }

    /**
     * ⚠️ `channel_metadata` BİRLEŞTİRİLİR, EZİLMEZ.
     *
     * Zincir metadata'yı İKİ FARKLI adımda yazabilir ve bir kanal
     * envanter adımında da kimlik döndürebilir (§07: kanallar tek kimlik
     * döndürmez). Ezilseydi ikinci yazım birincisini götürür ve
     * `offer_id` — yani KURTARMA ÇIPASI — kaybolurdu.
     *
     * ⚠️ BU TEST MUTASYONLA EKLENDİ. Ezme mutasyonu ilk turda HAYATTA
     * KALDI çünkü hiçbir test satıra ÖNCEDEN metadata koymuyordu:
     * tek yazım varken "birleştirme" ile "ezme" AYNI sonucu verir.
     */
    #[Test]
    public function existing_channel_metadata_survives_the_chain(): void
    {
        [$tenant, $variant] = $this->makeContext();

        // Satır önceki turdan bir kimlik taşıyor.
        $listing = $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => $this->connection()->id,
            'variant_id' => $variant->id,
            'external_id' => null,
            'lifecycle_status' => 'draft',
            'listed_at' => null,
            'channel_metadata' => ['merchant_location_key' => 'WAREHOUSE-1'],
        ]));

        ProgrammableOfferAdapter::succeed(offerId: 'OFFER-42', listingId: 'ITEM-99');

        $operation = $this->openOperation($tenant, $listing, version: 1);
        $this->runJob($tenant, $operation->id);

        $metadata = $this->asTenant($tenant, fn () => $listing->fresh())->channel_metadata;

        $this->assertSame(
            'WAREHOUSE-1',
            $metadata['merchant_location_key'] ?? null,
            'Var olan `channel_metadata` EZİLDİ — zincirin ikinci yazımı '
            .'birincisini götürürse kurtarma çıpası kaybolur.',
        );

        $this->assertSame('OFFER-42', $metadata['offer_id'] ?? null);
    }

    /**
     * ⚠️ ENVANTER KALEMİ HER TURDA ÇAĞRILIR ve bu DOĞRUDUR.
     *
     * PUT idempotenttir (§13.1) ve içerik güncellemesi ORADAN gider.
     * "Zaten yazıldı" diye atlanabilseydi başlık/açıklama değişiklikleri
     * kanala HİÇ ulaşmazdı — `content_version` artmış olmasına rağmen
     * panel "senkron" gösterirdi.
     */
    #[Test]
    public function the_inventory_item_is_written_on_every_run(): void
    {
        [$tenant, $variant] = $this->makeContext();
        $listing = $this->liveListing($tenant, $variant, externalId: 'ITEM-99', offerId: 'OFFER-42');

        ProgrammableOfferAdapter::succeed();

        $operation = $this->openOperation($tenant, $listing, version: 3);
        $this->runJob($tenant, $operation->id);

        $this->assertContains('inventory_item', ProgrammableOfferAdapter::calls());
    }

    // ────────────────────────────────────────── hata ve yetenek kapıları

    /**
     * Kalıcı hata operasyonu ÖLDÜRÜR — `RetryPolicy` kararı.
     */
    #[Test]
    public function a_permanent_failure_marks_the_operation_dead(): void
    {
        [$tenant, $variant] = $this->makeContext();
        $listing = $this->draftListing($tenant, $variant);

        ProgrammableOfferAdapter::succeed();
        ProgrammableOfferAdapter::failAt('offer', ErrorClass::VALIDATION);

        $operation = $this->openOperation($tenant, $listing, version: 1);
        $this->runJob($tenant, $operation->id);

        $this->assertSame(
            SyncOperationStatus::DEAD,
            $this->asTenant($tenant, fn () => $operation->fresh())->status,
        );
    }

    /**
     * ⚠️ YETENEĞİ OLMAYAN KANAL SESSİZCE BAŞARILI DÖNMEZ — ATLANIR.
     *
     * `AdapterResult::success()` dönseydi operasyon tamamlandı sanılır,
     * `synced_version` ilerler ve kanalda hiçbir şey değişmemişken satır
     * "senkron" görünürdü.
     *
     * ⚠️ AYIRT EDİCİ İŞARET DENEME SAYISIDIR, SÜRÜM DEĞİL — ve bu ayrım
     * MUTASYONLA bulundu. Kapıyı kaldıran mutasyon HAYATTA KALDI: kapı
     * olmadan `TypeError` fırlıyor, `catch (Throwable)` onu yakalıyor ve
     * operasyon HATA yoluna giriyor; sürüm yine ilerlemediği için zayıf
     * iddia yeşil kalıyordu.
     *
     * Fark önemlidir: ATLAMA deneme AÇMAZ (`attempt_count = 0` kalır ve
     * seviye 2 taramasının "worker hiç çalışmadı" anlamı korunur), HATA
     * ise deneme açar ve operasyonu yeniden denemeye sokar — kanal o
     * yeteneği HİÇ kazanmayacakken.
     *
     * `SyncOperationStatus::SKIPPED` DİYE BİR DURUM YOKTUR: atlama
     * `COMPLETED` yazar (`SyncResultRecorder::recordSkipped`) ve bu,
     * `PushListing`'in de davranışıdır. Duruma bakan bir iddia bu yüzden
     * ayırt edici DEĞİLDİR.
     */
    #[Test]
    public function a_channel_without_the_capability_is_skipped(): void
    {
        [$tenant, $variant] = $this->makeContext();

        // Katalog adapter'ı `SupportsOfferLifecycle` UYGULAMAZ.
        $listing = $this->draftListing($tenant, $variant, adapter: ProgrammableCatalogAdapter::class);

        $operation = $this->openOperation($tenant, $listing, version: 1);
        $this->runJob($tenant, $operation->id);

        $this->assertSame([], ProgrammableOfferAdapter::calls());

        $fresh = $this->asTenant($tenant, fn () => $operation->fresh());

        $this->assertSame(
            0,
            $fresh->attempt_count,
            'Atlanan operasyon DENEME AÇMAMALI — açılsaydı kanal o yeteneği '
            .'hiç kazanmayacakken satır yeniden denemeye girerdi.',
        );

        $this->assertNull(
            $fresh->last_error_class,
            'Yetenek eksikliği bir HATA değildir; hata olarak yazılsaydı '
            .'`/failures` ekranı düzeltilemeyecek bir satırla dolardı.',
        );
    }

    /**
     * İş kendi kiracı bağlamını kurar (§11 · P0).
     *
     * Gerçek worker'da `Queue::looping` kancası her iş sınırında bağlamı
     * TEMİZLER; iş bağlamsız başlar ve ilk tenant-scoped sorgu istisna
     * fırlatırdı.
     */
    #[Test]
    public function the_job_establishes_its_own_tenant_context(): void
    {
        [$tenant, $variant] = $this->makeContext();
        $listing = $this->draftListing($tenant, $variant);

        ProgrammableOfferAdapter::succeed();

        $operation = $this->openOperation($tenant, $listing, version: 1);

        // Bağlam KURULMADAN çağrılır — iş kendi kurmalı.
        (new PushOfferListing($operation->id, $tenant->id))->handle(
            app(ListingPayloadBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );

        $this->assertTrue($this->asTenant($tenant, fn () => $listing->fresh())->isLive());
    }

    // ──────────────────────────── ContentPushDispatcher · iş SEÇİMİ

    /**
     * ⚠️ ÇOK ADIMLI KANAL `PushOfferListing` ALIR, `PushListing` DEĞİL.
     *
     * Karar `ContentPushDispatcher` içinde TEK KAYNAKTIR ve iki yerden
     * çağrılır (panelden gönderim + yeniden deneme). Kopyalansaydı biri
     * güncellenip öteki eski kalırdı ve sonuç SESSİZ olurdu: eBay
     * listing'i `PushListing`'e düşer, o iş `SupportsCatalog` bulamayınca
     * operasyonu ATLAR ve satır "denendi" görünürken kanala HİÇ gitmez.
     */
    #[Test]
    public function an_offer_lifecycle_channel_receives_the_chain_job(): void
    {
        Queue::fake();

        [$tenant, $variant] = $this->makeContext();
        $listing = $this->draftListing($tenant, $variant);
        $operation = $this->openOperation($tenant, $listing, version: 1);

        $connection = $this->asTenant($tenant, fn () => $listing->fresh()->connection);

        $this->asTenant($tenant, fn () => app(ContentPushDispatcher::class)
            ->dispatch($operation->id, $tenant->id, $connection));

        Queue::assertPushed(PushOfferListing::class);
        Queue::assertNotPushed(PushListing::class);
    }

    /** Tek çağrılık kanal ESKİ işi almaya devam eder — regresyon kapısı. */
    #[Test]
    public function a_single_call_channel_still_receives_the_plain_job(): void
    {
        Queue::fake();

        [$tenant, $variant] = $this->makeContext();
        $listing = $this->draftListing($tenant, $variant, adapter: ProgrammableCatalogAdapter::class);
        $operation = $this->openOperation($tenant, $listing, version: 1);

        $connection = $this->asTenant($tenant, fn () => $listing->fresh()->connection);

        $this->asTenant($tenant, fn () => app(ContentPushDispatcher::class)
            ->dispatch($operation->id, $tenant->id, $connection));

        Queue::assertPushed(PushListing::class);
        Queue::assertNotPushed(PushOfferListing::class);
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: Variant} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Offer '.uniqid(),
            owner: User::factory()->create(),
        );

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        return [$tenant, $variant];
    }

    private function draftListing(
        Tenant $tenant,
        Variant $variant,
        string $adapter = ProgrammableOfferAdapter::class,
    ): Listing {
        return $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => $this->connection($adapter)->id,
            'variant_id' => $variant->id,
            'external_id' => null,
            'lifecycle_status' => 'draft',
            'listed_at' => null,
        ]));
    }

    private function liveListing(
        Tenant $tenant,
        Variant $variant,
        string $externalId,
        string $offerId,
    ): Listing {
        return $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => $this->connection()->id,
            'variant_id' => $variant->id,
            'external_id' => $externalId,
            'lifecycle_status' => 'live',
            'listed_at' => now(),
            'channel_metadata' => ['offer_id' => $offerId],
        ]));
    }

    private function connection(string $adapter = ProgrammableOfferAdapter::class): ChannelConnection
    {
        $this->asSystem(function () use ($adapter): void {
            ChannelType::query()->updateOrCreate(
                ['code' => 'ebay'],
                [
                    'name' => 'eBay',
                    'kind' => 'marketplace',
                    'adapter_class' => $adapter,
                    'supports_webhooks' => false,
                    'is_active' => true,
                ],
            );
        });

        return ChannelConnection::factory()->create(['channel_type_code' => 'ebay']);
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

    private function runJob(Tenant $tenant, string $operationId): void
    {
        (new PushOfferListing($operationId, $tenant->id))->handle(
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
