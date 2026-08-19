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
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Reconciliation\Actions\ReconcileConnection;
use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Reconciliation\Models\ReconciliationItem;
use App\Domain\Reconciliation\Models\ReconciliationRun;
use App\Domain\Reconciliation\Support\DriftHistory;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Channels\ProgrammableInventoryAdapter;
use Tests\TestCase;

/**
 * §10 · ONARIM DÖNGÜ EMNİYETİ — 3 TUR KURALI.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · VERIFY adımı, §1 · Karar 13
 * ("üç tur üst üste sürüklenmede otomatik onarım duruyor").
 *
 *   İki tur üst üste sürüklenme → kalıcı sorun, kullanıcıya bildirim
 *   Üç tur üst üste            → otomatik onarım DURDURULUR, elle inceleme
 *
 * BU EMNİYETİN VARLIK NEDENİ:
 *   Onarım sürüm kapısını ATLAR (§8) ve `desired_version`'ı ARTIRMAZ. Bu
 *   bilinçli bir karardır ama bir bedeli vardır: kanal 200 dönüp değişikliği
 *   UYGULAMIYORSA mutabakat aynı farkı her turda yeniden bulur, her turda
 *   yeni bir onarım açar ve bu SONSUZA KADAR sürer. Sıcak katmanda bu beş
 *   dakikada bir demektir — kanal kotası boşa gider, `sync_operations`
 *   şişer ve gerçek sürüklenmeler gürültüde kaybolur.
 *
 * SAYAÇ GEÇMİŞTEN TÜRETİLİR, AYRI KOLONDA TUTULMAZ:
 *   `reconciliation_items` zaten GERÇEĞİ taşıyor ve
 *   `recon_items_listing_time_idx (listing_id, checked_at DESC)` tam bu
 *   sorgu için var. Ayrı sayaç kolonu, kalem yazan her yolun onu da
 *   güncellemesini zorunlu kılardı; biri unutulduğunda iki gerçek kaynağı
 *   SESSİZCE ayrışır (bu projede `activeListingCount` ile aynı biçimde
 *   yaşandı).
 *
 * MATCHED ZİNCİRİ KIRAR:
 *   Sayılan şey ARDIŞIK sürüklenmedir, toplam değil. Araya giren bir
 *   MATCHED "sorun çözüldü" demektir; sayılmasaydı aylar önce iki kez
 *   sürüklenmiş sağlıklı bir listing bugün tek bir sürüklenmede kilitlenirdi.
 */
final class RepairLoopSafetyTest extends TestCase
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

    // ---------------------------------------------------------------- REPAIRED

    /**
     * ONARIM TUTTUYSA KALEM `REPAIRED` OLUR — MATCHED değil.
     *
     * §10: "İkinci turda MATCHED gelirse reconciliation_item.status =
     * 'REPAIRED'". Ayrım denetim içindir: `MATCHED` "zaten doğruydu",
     * `REPAIRED` "bozuktu ve onarımımız TUTTU" demektir. İkisi tek duruma
     * sıkıştırılsaydı onarımın işe yarayıp yaramadığı hiçbir yerde
     * kayıtlı olmazdı ve 3 tur kuralı "ardışık sürüklenme" ile "onarılmış
     * sürüklenme"yi ayırt edemezdi.
     */
    #[Test]
    public function a_successful_repair_is_recorded_as_repaired_not_matched(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 17);
        $this->markRecentlySold($tenant, $variant);          // 17 → 16

        // Tur 1: kanal 99 → sürüklenme, onarım açılır.
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);
        $this->reconcile($tenant, $connection);

        $this->assertSame('REPAIR_QUEUED', $this->latestItem($tenant, $listing)->status);

        // Tur 2 (DOĞRULAMA): kanal artık doğru değeri döndürüyor.
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 16);
        $this->reconcile($tenant, $connection);

        $this->assertSame(
            'REPAIRED',
            $this->latestItem($tenant, $listing)->status,
            'Onarım sonrası eşleşme REPAIRED yazmalı — MATCHED "zaten doğruydu" demektir.',
        );
    }

    /**
     * ÖNCESİNDE ONARIM YOKSA EŞLEŞME `MATCHED` KALIR.
     *
     * `REPAIRED` yalnızca bir onarımın ARDINDAN gelen eşleşme için
     * anlamlıdır. Her eşleşmeye `REPAIRED` denseydi durum bilgi taşımaz
     * ve panel "kaç sürüklenme onarıldı" sorusunu yanlış cevaplardı.
     */
    #[Test]
    public function a_plain_match_without_prior_repair_stays_matched(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 7);
        $this->markRecentlySold($tenant, $variant);          // 7 → 6

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 6);
        $this->reconcile($tenant, $connection);

        $this->assertSame('MATCHED', $this->latestItem($tenant, $listing)->status);
    }

    // ---------------------------------------------------------------- sayaç

    /** Ardışık sürüklenme sayacı geçmişten türetilir. */
    #[Test]
    public function the_consecutive_drift_count_grows_with_each_unresolved_round(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 17);
        $this->markRecentlySold($tenant, $variant);

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);

        $this->assertSame(0, $this->consecutiveDrift($tenant, $listing), 'Hiç tur yokken sıfır.');

        $this->reconcile($tenant, $connection);
        $this->assertSame(1, $this->consecutiveDrift($tenant, $listing));

        $this->reconcile($tenant, $connection);
        $this->assertSame(2, $this->consecutiveDrift($tenant, $listing));
    }

    /**
     * ARAYA GİREN `MATCHED` ZİNCİRİ SIFIRLAR.
     *
     * Sayılan şey ARDIŞIK sürüklenmedir. Toplam sayılsaydı aylar önce iki
     * kez sürüklenmiş sağlıklı bir listing, bugünkü ilk sürüklenmesinde
     * doğrudan kilitlenirdi.
     */
    #[Test]
    public function a_matched_round_resets_the_consecutive_chain(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 17);
        $this->markRecentlySold($tenant, $variant);          // → 16

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);
        $this->reconcile($tenant, $connection);
        $this->reconcile($tenant, $connection);

        $this->assertSame(2, $this->consecutiveDrift($tenant, $listing));

        // Sorun çözüldü.
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 16);
        $this->reconcile($tenant, $connection);

        $this->assertSame(
            0,
            $this->consecutiveDrift($tenant, $listing),
            'Eşleşen tur zinciri KIRAR — sayaç ardışıklığı ölçer, toplamı değil.',
        );
    }

    /** Başka listing'in sürüklenmesi bu listing'in sayacına karışmaz. */
    #[Test]
    public function the_counter_is_per_listing(): void
    {
        [$tenant, $variantA, $connection] = $this->makeContext();

        $listingA = $this->listing($tenant, $variantA, $connection, externalId: '10');

        $variantB = $this->asTenant($tenant, fn () => Variant::factory()->create());
        $listingB = $this->listing($tenant, $variantB, $connection, externalId: '11');

        $this->seedStock($tenant, $variantA, 17);
        $this->seedStock($tenant, $variantB, 17);
        $this->markRecentlySold($tenant, $variantA);
        $this->markRecentlySold($tenant, $variantB);

        // A sürüklenir, B doğrudur.
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '11', 16);

        $this->reconcile($tenant, $connection);
        $this->reconcile($tenant, $connection);

        $this->assertSame(2, $this->consecutiveDrift($tenant, $listingA));
        $this->assertSame(0, $this->consecutiveDrift($tenant, $listingB));
    }

    // ---------------------------------------------------------------- emniyet

    /**
     * ÜÇÜNCÜ TURDA OTOMATİK ONARIM DURUR — EMNİYETİN TAMAMI.
     *
     * İlk iki tur onarım açar; üçüncü turda sürüklenme HÂLÂ duruyorsa
     * kanal bizim yazmamızı uygulamıyor demektir ve onarımı tekrarlamak
     * yalnızca kota harcar. Kalem yine YAZILIR (sürüklenme gerçektir ve
     * kaydedilmelidir) ama operasyon AÇILMAZ.
     */
    #[Test]
    public function the_third_consecutive_drift_stops_opening_repairs(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 17);
        $this->markRecentlySold($tenant, $variant);

        // Kanal İNATLA yanlış değeri döndürüyor — yazmamızı uygulamıyor.
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);

        $this->reconcile($tenant, $connection);
        $this->reconcile($tenant, $connection);

        $afterTwo = $this->operationCount($tenant);

        $this->assertSame(2, $afterTwo, 'İlk iki tur onarım AÇAR.');

        $third = $this->reconcile($tenant, $connection);

        $this->assertSame(
            $afterTwo,
            $this->operationCount($tenant),
            'ÜÇÜNCÜ turda otomatik onarım DURMALI — sonsuz döngü emniyeti.',
        );

        $this->assertSame(
            1,
            $third->drift_count,
            'Sürüklenme yine SAYILIR: emniyet onarımı durdurur, gerçeği gizlemez.',
        );
    }

    /**
     * DURDURULAN KALEM `MANUAL_REVIEW` İŞARETLENİR.
     *
     * `DRIFT_DETECTED` bırakılsaydı o kalem bir sonraki turda yine
     * `drift_detected` sebebiyle aday olur ve panel onu "onarım
     * bekliyor" gibi gösterirdi — oysa hiçbir onarım gelmeyecek.
     * Kullanıcı sonsuza kadar bekleyen bir satıra bakardı.
     */
    #[Test]
    public function the_stopped_item_is_flagged_for_manual_review(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 17);
        $this->markRecentlySold($tenant, $variant);

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);

        $this->reconcile($tenant, $connection);
        $this->reconcile($tenant, $connection);
        $this->reconcile($tenant, $connection);

        $this->assertSame(
            'MANUAL_REVIEW',
            $this->latestItem($tenant, $listing)->status,
            'Durdurulan kalem elle inceleme istemeli.',
        );
    }

    /**
     * DÖRDÜNCÜ VE SONRAKİ TURLAR DA ONARIM AÇMAZ.
     *
     * Emniyet bir EŞİK değil bir DURUMDUR: üçüncü turda durup dördüncüde
     * yeniden başlasaydı döngü yalnızca yavaşlar, KIRILMAZDI — ve her üç
     * turda bir onarım açan sonsuz bir döngü hâlâ sonsuz bir döngüdür.
     *
     * TUR SAYISI GEÇMİŞ PENCERESİNDEN BÜYÜK SEÇİLDİ ve bu ölçek bilinçli:
     * sayaç yalnızca son N kalemi okur (`STOP_AFTER * 5` = 10). Altı tur
     * koşulsaydı, `MANUAL_REVIEW`'ın zinciri UZATMAMASI durumunda bile ilk
     * iki `REPAIR_QUEUED` kalemi hâlâ pencerede kalır ve sayaç 2'de
     * takılı görünürdü — yani test YEŞİL kalır ve mutasyon hayatta
     * kalırdı (bu tam olarak yaşandı). Pencereden BÜYÜK bir tur sayısında
     * eski kalemler düşer; `MANUAL_REVIEW` sayılmazsa sayaç sıfıra iner ve
     * EMNİYET ÇÖKER — onarım sessizce yeniden başlar.
     *
     * Genel kural: bir pencere/limit varsa, testin ölçeği o pencereyi
     * AŞMALI; aşmazsa pencerenin kendisi testi sahte yeşil tutar.
     */
    #[Test]
    public function repairs_stay_stopped_on_later_rounds(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 17);
        $this->markRecentlySold($tenant, $variant);

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);

        // Geçmiş penceresinden BÜYÜK: eski kalemler pencereden düşer.
        $rounds = (DriftHistory::STOP_AFTER * 5) + 4;

        for ($round = 0; $round < $rounds; $round++) {
            $this->reconcile($tenant, $connection);
        }

        $this->assertSame(
            2,
            $this->operationCount($tenant),
            "{$rounds} turda da yalnızca İLK İKİ tur onarım açmalı — emniyet DURUMDUR, eşik değil.",
        );
    }

    /**
     * SORUN ÇÖZÜLÜRSE EMNİYET KENDİLİĞİNDEN KALKAR.
     *
     * Emniyet kalıcı bir ceza DEĞİLDİR. Satıcı kanal tarafındaki sorunu
     * düzeltir (yetki, ürün durumu, stok yönetimi kapalıydı) ve bir tur
     * eşleşirse zincir kırılır; sonraki bir sürüklenme yeniden onarılabilir
     * olmalıdır. Kalkmasaydı tek bir geçici kanal arızası o listing'i
     * sonsuza kadar otomatik onarımın dışına atardı.
     */
    #[Test]
    public function the_safety_lifts_once_a_round_matches_again(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 17);
        $this->markRecentlySold($tenant, $variant);          // → 16

        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);

        $this->reconcile($tenant, $connection);
        $this->reconcile($tenant, $connection);
        $this->reconcile($tenant, $connection);              // durduruldu

        $this->assertSame(2, $this->operationCount($tenant));

        // Kanal düzeldi.
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 16);
        $this->reconcile($tenant, $connection);

        // Sonra yeniden sürüklendi — bu YENİ bir sorundur.
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 55);
        $this->reconcile($tenant, $connection);

        $this->assertSame(
            3,
            $this->operationCount($tenant),
            'Zincir kırıldıktan sonra yeni sürüklenme yeniden onarılabilmeli.',
        );
    }

    /**
     * EMNİYET SAYARKEN `REMOTE_UNREACHABLE` TURLARINI SAYMAZ.
     *
     * Okunamayan kanal SÜRÜKLENME DEĞİLDİR (§10) — fark kanıtlanmamıştır.
     * Sayılsaydı üç kez arka arkaya düşen bir kanal, hiç sürüklenmemiş bir
     * listing'i otomatik onarımın dışına atardı ve altyapı arızası kalıcı
     * bir veri sorununa dönüşürdü.
     */
    #[Test]
    public function unreachable_rounds_do_not_count_toward_the_safety(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 17);
        $this->markRecentlySold($tenant, $variant);

        // Kanal üç tur boyunca okunamıyor.
        ProgrammableInventoryAdapter::failFetchOn('woocommerce');
        $this->reconcile($tenant, $connection);
        $this->reconcile($tenant, $connection);
        $this->reconcile($tenant, $connection);

        $this->assertSame(
            0,
            $this->consecutiveDrift($tenant, $listing),
            'Okunamayan tur sürüklenme DEĞİLDİR ve sayaca girmez.',
        );

        // Kanal geri geldi ve gerçekten sürüklenme var.
        ProgrammableInventoryAdapter::reset();
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);

        $this->reconcile($tenant, $connection);

        $this->assertSame(
            1,
            $this->operationCount($tenant),
            'Altyapı arızası onarımı engellememeli — bu ilk GERÇEK sürüklenmedir.',
        );
    }

    /**
     * OKUNAMAYAN TUR ZİNCİRİ KIRMAZ DA — yalnızca YOK SAYILIR.
     *
     * BU TEST BİR MUTASYONUN ARDINDAN EKLENDİ: `REMOTE_UNREACHABLE`
     * turunun zinciri KIRMASI hiçbir testi bozmuyordu. O hâlde emniyet
     * hiçbir zaman devreye giremezdi — kanalı inatla yanlış değer
     * döndüren bir entegrasyonda araya giren TEK bir ağ hatası sayacı
     * sıfırlar ve sonsuz onarım döngüsü baştan başlardı. Gerçek
     * kanallarda geçici hata kuraldır, istisna değil; yani bu mutasyon
     * emniyeti pratikte tamamen etkisiz kılardı.
     *
     * Doğru davranış üçüncü bir seçenektir: okunamayan tur ne SAYILIR
     * (fark kanıtlanmamıştır) ne de zinciri KIRAR ("düzeldi" de değildir).
     * O tur yok sayılır ve zincir kaldığı yerden devam eder.
     */
    #[Test]
    public function an_unreachable_round_does_not_reset_an_existing_chain(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext();

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $this->seedStock($tenant, $variant, 17);
        $this->markRecentlySold($tenant, $variant);

        // İki tur gerçek sürüklenme — zincir 2'ye çıkar.
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);
        $this->reconcile($tenant, $connection);
        $this->reconcile($tenant, $connection);

        $this->assertSame(2, $this->consecutiveDrift($tenant, $listing));
        $this->assertSame(2, $this->operationCount($tenant));

        // ARAYA GİREN AĞ HATASI — "düzeldi" DEĞİLDİR.
        ProgrammableInventoryAdapter::failFetchOn('woocommerce');
        $this->reconcile($tenant, $connection);

        $this->assertSame(
            2,
            $this->consecutiveDrift($tenant, $listing),
            'Okunamayan tur zinciri KIRMAMALI — ağ hatası "sorun çözüldü" demek değildir.',
        );

        // Kanal geri geldi, sürüklenme HÂLÂ duruyor: emniyet devrede olmalı.
        ProgrammableInventoryAdapter::reset();
        ProgrammableInventoryAdapter::remoteQuantity('woocommerce', '10', 99);
        $this->reconcile($tenant, $connection);

        $this->assertSame(
            2,
            $this->operationCount($tenant),
            'Emniyet devrede kalmalı — araya giren ağ hatası döngüyü yeniden başlatmamalı.',
        );

        $this->assertSame(
            'MANUAL_REVIEW',
            $this->latestItem($tenant, $listing)->status,
        );
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: Variant, 2: ChannelConnection} */
    private function makeContext(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Emniyet '.uniqid(),
            owner: User::factory()->create(),
        );

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create());

        return [$tenant, $variant, $this->connection($tenant, 'woocommerce')];
    }

    private function connection(Tenant $tenant, string $code): ChannelConnection
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => ucfirst($code),
                'kind' => 'marketplace',
                'adapter_class' => ProgrammableInventoryAdapter::class,
                'is_active' => true,
            ],
        ));

        return $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'channel_type_code' => $code,
        ]));
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
            warehouseId: $this->warehouse($tenant)->id,
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: $quantity,
            idempotencyKey: 'import:'.$variant->id,
            sourceType: 'test',
        ));
    }

    /** "Son 30 dakikada satıldı" adayı yaratır. */
    private function markRecentlySold(Tenant $tenant, Variant $variant): void
    {
        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $this->warehouse($tenant)->id,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 1,
            idempotencyKey: 'recent-sale:'.$variant->id,
            sourceType: 'test',
        ));
    }

    private function warehouse(Tenant $tenant): Warehouse
    {
        return $this->asTenant($tenant, fn () => Warehouse::query()
            ->where('is_default', true)
            ->firstOrFail());
    }

    private function reconcile(Tenant $tenant, ChannelConnection $connection): ReconciliationRun
    {
        return $this->asTenant($tenant, fn () => app(ReconcileConnection::class)->run(
            connection: $connection,
            scope: ReconciliationScope::HOT,
        ));
    }

    /**
     * Kalem sıralaması `id` ÜZERİNDEN yapılır, `checked_at` üzerinden DEĞİL.
     *
     * `checked_at` saniye hassasiyetlidir ve aynı testte arka arkaya koşan
     * turlar aynı damgayı taşır; sıra belirsiz kalır. `id` UUIDv7'dir —
     * zaman sıralı ve saniye içinde de ayırt edici.
     */
    private function latestItem(Tenant $tenant, Listing $listing): ReconciliationItem
    {
        return $this->asTenant($tenant, fn () => ReconciliationItem::query()
            ->where('listing_id', $listing->id)
            ->orderByDesc('id')
            ->firstOrFail());
    }

    private function consecutiveDrift(Tenant $tenant, Listing $listing): int
    {
        return $this->asTenant($tenant, fn (): int => app(DriftHistory::class)
            ->consecutiveDriftCount($listing->id));
    }

    private function operationCount(Tenant $tenant): int
    {
        return $this->asTenant($tenant, fn (): int => SyncOperation::query()->count());
    }
}
