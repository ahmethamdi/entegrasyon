<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Reconciliation\Actions\ReconcileConnection;
use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Sync\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Channels\ProgrammableInventoryAdapter;
use Tests\TestCase;

/**
 * Mutabakat ekranı — sürüklenmenin kullanıcıya görünen TEK yeri.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 4 panel maddesi, §10 ·
 * Reconciliation Engine, §17 · "Panelde senkron geçmişi ve hata
 * görünürlüğü — destek yükünü belirleyen tek ekran".
 *
 * EKRANIN VARLIK SEBEBİ:
 *   Üç mutabakat katmanı da `reconciliation_items` yazıyor ve bugüne kadar
 *   HİÇBİRİ gösterilmiyordu. Sürüklenme tespiti ürünün temel iddiasıdır
 *   (§17 · "Mutabakat sıcak katmanı — ürünün temel iddiası") ama kullanıcı
 *   onu göremiyorsa iddia kanıtlanamaz: satıcı kanalda yanlış stok
 *   olduğunu ancak müşteri şikâyet edince öğrenir.
 *
 * DEĞİŞMEZ KURAL — `MANUAL_REVIEW` EN ÜSTTE VE AYRI GÖSTERİLİR:
 *   O satırlar için otomatik onarım DURMUŞTUR (§10 · 3 tur kuralı) ve
 *   kendiliğinden düzelmeyecektir. `DRIFT_DETECTED` ile aynı kefeye
 *   konsaydı satıcı "sistem hallediyor" sanır ve hiç bakmazdı — oysa tam
 *   olarak o satırlar elle müdahale bekliyor.
 *
 * DEĞİŞMEZ KURAL — YEREL VE UZAK DEĞER BİRLİKTE GÖSTERİLİR:
 *   "Sürüklenme var" demek yetmez; destek "hangi değer neydi" sorusuna
 *   cevap veremez. `local_value` HEM ham kanonik bakiyeyi HEM karşılaştırma
 *   tabanını (`expected_remote`) taşır ve ikisi FAZLA SATIŞTA AYRIŞIR —
 *   kanoniği −3 olan bir varyantta kanaldaki 0 DOĞRUDUR. Yalnızca ham
 *   değer gösterilseydi satıcı olmayan bir sürüklenme arardı.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen alanlar.
 *
 * DEĞİŞMEZ KURAL — `DB::table()` GLOBAL SCOPE'A TABİ DEĞİLDİR: ham sorguda
 *   kiracı filtresi AÇIKÇA yazılır ve TESTİ de yazılır (bu projede aynı
 *   boşluk dört ayrı turda bulundu).
 */
final class ReconciliationScreenTest extends TestCase
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

    // ---------------------------------------------------------------- erişim

    /** Misafir mutabakat ekranını göremez. */
    #[Test]
    public function guest_cannot_reach_the_reconciliation_screen(): void
    {
        $this->get('/reconciliation')->assertRedirect('/login');
    }

    /**
     * BAŞKA KİRACININ SÜRÜKLENMESİ LİSTEDE GÖRÜNMEZ.
     *
     * Kalem, listing kimliği ve stok değerleri taşır; sızıntı rakip
     * satıcının stok seviyesini ifşa ederdi.
     */
    #[Test]
    public function drift_never_leaks_across_tenants(): void
    {
        [$tenantA, $userA, $variantA, $connectionA] = $this->makeContext();
        [$tenantB, , $variantB, $connectionB] = $this->makeContext();

        $this->driftFor($tenantA, $variantA, $connectionA, externalId: '10', remote: 99);
        $this->driftFor($tenantB, $variantB, $connectionB, externalId: '20', remote: 88);

        $rows = $this->rows($this->actingAs($userA)->get('/reconciliation'));

        $this->assertCount(1, $rows, 'Yalnızca kendi kiracısının sürüklenmesi görünmeli.');
        $this->assertSame('SKU-10', $rows[0]['sku']);
    }

    /** Özet sayıları da kiracıya kapsanır — AYRI bir sorgu, AYRI bir boşluk. */
    #[Test]
    public function the_summary_counts_never_leak_across_tenants(): void
    {
        [$tenantA, $userA, $variantA, $connectionA] = $this->makeContext();
        [$tenantB, , $variantB, $connectionB] = $this->makeContext();

        $this->driftFor($tenantA, $variantA, $connectionA, externalId: '10', remote: 99);
        $this->driftFor($tenantB, $variantB, $connectionB, externalId: '20', remote: 88);

        $summary = $this->summary($this->actingAs($userA)->get('/reconciliation'));

        $this->assertSame(1, $summary['drift'], 'Özet başka kiracının sürüklenmesini saymamalı.');
    }

    // ---------------------------------------------------------------- içerik

    /**
     * SÜRÜKLENME YEREL VE UZAK DEĞERİYLE BİRLİKTE GÖSTERİLİR.
     *
     * Destek "hangi değer neydi" sorusunu ancak ikisini birden görerek
     * cevaplayabilir.
     */
    #[Test]
    public function a_drift_row_shows_both_sides_of_the_comparison(): void
    {
        [$tenant, $user, $variant, $connection] = $this->makeContext();

        // Kanonik 17 → satış → 16; kanal 99 diyor.
        $this->driftFor($tenant, $variant, $connection, externalId: '10', onHand: 17, remote: 99);

        $rows = $this->rows($this->actingAs($user)->get('/reconciliation'));

        $this->assertCount(1, $rows);

        $this->assertSame(16, $rows[0]['expected_remote'], 'Beklenen uzak değer gösterilmeli.');
        $this->assertSame(99, $rows[0]['observed_remote'], 'Gözlenen uzak değer gösterilmeli.');
        $this->assertSame(83, $rows[0]['drift_magnitude']);
        $this->assertSame('SKU-10', $rows[0]['sku']);
    }

    /**
     * FAZLA SATIŞTA HAM KANONİK DE GÖSTERİLİR — İKİSİ AYRIŞIR.
     *
     * Kanonik bakiye −3 ise kanala giden değer `max(-3, 0)` yani 0'dır ve
     * kanaldaki 0 DOĞRUDUR (§10 · karşılaştırma giden değerle yapılır).
     * Ekran yalnızca ham kanoniği gösterseydi satıcı "kanalda 0 var ama
     * bende −3" diye olmayan bir sürüklenme arardı; yalnızca kırpılmış
     * değeri gösterseydi bu kez fazla satışı hiç göremezdi.
     */
    #[Test]
    public function an_oversold_row_shows_the_raw_canonical_alongside_the_outbound_value(): void
    {
        [$tenant, $user, $variant, $connection] = $this->makeContext();

        // 2 stok, 5 satış → kanonik −3, giden değer 0. Kanal 7 diyor: sürüklenme.
        $this->driftFor($tenant, $variant, $connection, externalId: '10', onHand: 2, sold: 5, remote: 7);

        $rows = $this->rows($this->actingAs($user)->get('/reconciliation'));

        $this->assertSame(-3, $rows[0]['available'], 'HAM kanonik bakiye kırpılmadan gösterilmeli.');
        $this->assertSame(0, $rows[0]['expected_remote'], 'Karşılaştırma tabanı KIRPILMIŞ değerdir.');
        $this->assertTrue($rows[0]['oversold'], 'Fazla satış açıkça işaretlenmeli.');
    }

    /** Sebep ve durum satırda görünür — "neden bakıldı" ve "ne oldu". */
    #[Test]
    public function each_row_carries_its_reason_and_status(): void
    {
        [$tenant, $user, $variant, $connection] = $this->makeContext();

        $this->driftFor($tenant, $variant, $connection, externalId: '10', remote: 99);

        $rows = $this->rows($this->actingAs($user)->get('/reconciliation'));

        $this->assertSame('recently_sold', $rows[0]['reason']);
        $this->assertSame('REPAIR_QUEUED', $rows[0]['status']);
    }

    /**
     * ÇÖZÜLMÜŞ SATIRLAR VARSAYILAN LİSTEDE YER TUTMAZ.
     *
     * Ekran EYLEM GEREKTİREN satırlar içindir. `MATCHED` ve `REPAIRED`
     * varsayılan listeyi doldursaydı gerçek sürüklenmeler binlerce
     * "her şey yolunda" satırının arasında kaybolurdu — bu ekranın
     * varlık sebebinin tam tersi.
     */
    #[Test]
    public function resolved_rows_are_not_in_the_default_list(): void
    {
        [$tenant, $user, $variant, $connection] = $this->makeContext();

        // Sürüklenme, sonra onarım TUTTU → REPAIRED.
        $this->driftFor($tenant, $variant, $connection, externalId: '10', onHand: 17, remote: 99);
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 16);
        $this->reconcile($tenant, $connection);

        $rows = $this->rows($this->actingAs($user)->get('/reconciliation'));

        $this->assertSame([], $rows, 'Onarılmış satır varsayılan listede yer tutmamalı.');
    }

    /** Geçmiş filtresi çözülmüş satırları da gösterir — denetim izi. */
    #[Test]
    public function the_history_filter_reveals_resolved_rows(): void
    {
        [$tenant, $user, $variant, $connection] = $this->makeContext();

        $this->driftFor($tenant, $variant, $connection, externalId: '10', onHand: 17, remote: 99);
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 16);
        $this->reconcile($tenant, $connection);

        $rows = $this->rows($this->actingAs($user)->get('/reconciliation?filter=all'));

        $statuses = array_column($rows, 'status');

        $this->assertContains('REPAIRED', $statuses, 'Onarımın TUTTUĞU geçmişte görünmeli.');
    }

    // ---------------------------------------------------------------- MANUAL_REVIEW

    /**
     * ELLE İNCELEME BEKLEYEN SATIR EN ÜSTTE GÖSTERİLİR.
     *
     * O satırda otomatik onarım DURMUŞTUR (§10 · 3 tur kuralı) ve
     * kendiliğinden düzelmeyecektir. Sıradan bir sürüklenmenin altında
     * kalsaydı satıcı "sistem hallediyor" sanır ve tam olarak müdahale
     * bekleyen satırı hiç görmezdi.
     *
     * TAZE SATIR ÖNCE YARATILIR ve bu SIRALAMA BİLİNÇLİDİR: kalem
     * kimlikleri UUIDv7 — ZAMAN SIRALI — olduğundan sıralama
     * uygulanmazsa satırlar yaratılış sırasında gelir. Takılı satır önce
     * yaratılsaydı zaten başta olurdu ve sıralamanın tamamen
     * kaldırılması testi KIRMAZDI (bu tam olarak yaşandı: `no_sorting`
     * mutasyonu hayatta kaldı).
     */
    #[Test]
    public function items_awaiting_manual_review_are_listed_first(): void
    {
        // makeContext SKU-10'u yaratır; taze sürüklenme ONUN üzerinden
        // gider ve ÖNCE oluşur — sıralama yoksa listede başta kalır.
        [$tenant, $user, $freshVariant, $connection] = $this->makeContext();

        $this->driftFor($tenant, $freshVariant, $connection, externalId: '11', onHand: 20, remote: 50);

        // Bu listing üç tur üst üste sürüklenir → MANUAL_REVIEW.
        $stuckVariant = $this->asTenant($tenant, fn () => Variant::factory()->create(['sku' => 'SKU-99']));
        $this->listing($tenant, $stuckVariant, $connection, externalId: '99');
        $this->seedStock($tenant, $stuckVariant, 17);
        $this->sell($tenant, $stuckVariant);
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '99', 99);

        $this->reconcile($tenant, $connection);
        $this->reconcile($tenant, $connection);
        $this->reconcile($tenant, $connection);

        $rows = $this->rows($this->actingAs($user)->get('/reconciliation'));

        $this->assertCount(2, $rows, 'İki satır da listede olmalı.');

        $this->assertSame(
            'MANUAL_REVIEW',
            $rows[0]['status'],
            'Elle inceleme bekleyen satır EN ÜSTTE olmalı — otomatik onarım orada DURDU.',
        );

        $this->assertSame(
            'SKU-99',
            $rows[0]['sku'],
            'Takılı satır SONRA yaratıldı; başa gelmesi ancak SIRALAMAYLA olur.',
        );
    }

    /** Özet elle inceleme sayısını AYRI verir — rozet bundan beslenir. */
    #[Test]
    public function the_summary_separates_manual_review_from_ordinary_drift(): void
    {
        [$tenant, $user, $variant, $connection] = $this->makeContext();

        $this->driftFor($tenant, $variant, $connection, externalId: '10', onHand: 17, remote: 99);
        $this->reconcile($tenant, $connection);
        $this->reconcile($tenant, $connection);

        $summary = $this->summary($this->actingAs($user)->get('/reconciliation'));

        $this->assertSame(1, $summary['manual_review']);
        $this->assertSame(
            0,
            $summary['drift'],
            'Durdurulan satır SIRADAN sürüklenme sayılmamalı — ikisi farklı eylem ister.',
        );
    }

    /**
     * `REMOTE_UNREACHABLE` SÜRÜKLENME OLARAK SAYILMAZ.
     *
     * Okunamayan kanal altyapı sorunudur ve fark KANITLANMAMIŞTIR (§10).
     * Sürüklenme sayılsaydı satıcı olmayan bir veri sorununu kovalar ve
     * gerçek sürüklenmeler o gürültüde kaybolurdu.
     */
    #[Test]
    public function unreachable_rows_are_not_counted_as_drift(): void
    {
        [$tenant, $user, $variant, $connection] = $this->makeContext();

        $this->listing($tenant, $variant, $connection, externalId: '10');
        $this->seedStock($tenant, $variant, 10);
        $this->sell($tenant, $variant);

        ProgrammableInventoryAdapter::failFetchOn('woocommerce');
        $this->reconcile($tenant, $connection);

        $summary = $this->summary($this->actingAs($user)->get('/reconciliation'));

        $this->assertSame(0, $summary['drift'], 'Okunamayan kanal sürüklenme DEĞİLDİR.');
        $this->assertSame(1, $summary['unreachable'], 'Ama AYRI olarak görünmeli — sessizce yutulmaz.');
    }

    // ---------------------------------------------------------------- son tur

    /** Ekran son mutabakat turunun ne zaman koştuğunu söyler. */
    #[Test]
    public function the_screen_reports_when_reconciliation_last_ran(): void
    {
        [$tenant, $user, $variant, $connection] = $this->makeContext();

        $this->driftFor($tenant, $variant, $connection, externalId: '10', remote: 99);

        $props = $this->props($this->actingAs($user)->get('/reconciliation'));

        $this->assertNotNull(
            $props['last_run'],
            'Son tur bilinmezse kullanıcı listenin ne kadar taze olduğunu bilemez.',
        );
        $this->assertSame('hot', $props['last_run']['scope']);
    }

    /**
     * HİÇ TUR KOŞMAMIŞSA EKRAN ÇÖKMEZ.
     *
     * Yeni kiracıda hiç mutabakat turu yoktur ve "son tur" NULL'dur; ekran
     * boş durumu göstermeli, hata vermemeli.
     */
    #[Test]
    public function the_screen_survives_a_tenant_with_no_runs_at_all(): void
    {
        [, $user] = $this->makeContext();

        $response = $this->actingAs($user)->get('/reconciliation');

        $response->assertOk();

        $this->assertSame([], $this->rows($response));
        $this->assertNull($this->props($response)['last_run']);
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: User, 2: Variant, 3: ChannelConnection} */
    private function makeContext(): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Mutabakat '.uniqid(), owner: $user);

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create(['sku' => 'SKU-10']));

        return [$tenant, $user, $variant, $this->connection($tenant)];
    }

    private function connection(Tenant $tenant): ChannelConnection
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'marketplace',
                'adapter_class' => ProgrammableInventoryAdapter::class,
                'is_active' => true,
            ],
        ));

        return $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'channel_type_code' => 'woocommerce',
        ]));
    }

    /**
     * GERÇEK MUTABAKAT YOLUNDAN bir sürüklenme üretir.
     *
     * Kalemi elle yazmak `status`, `local_value` ve `priority_reason`
     * alanlarını UYDURMAK demekti; ekran o zaman gerçek veriyi değil testin
     * varsayımını doğrulardı.
     */
    private function driftFor(
        Tenant $tenant,
        Variant $variant,
        ChannelConnection $connection,
        string $externalId,
        int $remote,
        int $onHand = 10,
        int $sold = 1,
    ): void {
        $this->listing($tenant, $variant, $connection, $externalId);
        $this->seedStock($tenant, $variant, $onHand);
        $this->sell($tenant, $variant, $sold);

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', $externalId, $remote);

        $this->reconcile($tenant, $connection);
    }

    private function listing(
        Tenant $tenant,
        Variant $variant,
        ChannelConnection $connection,
        string $externalId,
    ): Listing {
        return $this->asTenant($tenant, fn () => Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'external_id' => $externalId,
            'lifecycle_status' => 'live',
        ]));
    }

    /** Açılış stoğu LEDGER üzerinden. */
    private function seedStock(Tenant $tenant, Variant $variant, int $quantity): void
    {
        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $this->warehouseId($tenant),
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: $quantity,
            idempotencyKey: 'import:'.$variant->id,
            sourceType: 'test',
        ));
    }

    /** Satış hem stoğu düşürür hem `recently_sold` adayı yaratır. */
    private function sell(Tenant $tenant, Variant $variant, int $quantity = 1): void
    {
        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $this->warehouseId($tenant),
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: $quantity,
            idempotencyKey: 'sale:'.$variant->id,
            sourceType: 'test',
        ));
    }

    private function warehouseId(Tenant $tenant): string
    {
        return $this->asTenant($tenant, fn (): string => $tenant->defaultWarehouse()->id);
    }

    private function reconcile(Tenant $tenant, ChannelConnection $connection): void
    {
        $this->asTenant($tenant, fn () => app(ReconcileConnection::class)->run(
            connection: $connection,
            scope: ReconciliationScope::HOT,
        ));
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    /** @return list<array<string, mixed>> */
    private function rows(TestResponse $response): array
    {
        return $this->props($response)['rows'];
    }

    /** @return array<string, int> */
    private function summary(TestResponse $response): array
    {
        return $this->props($response)['summary'];
    }
}
