<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Domain\Catalog\Actions\ResolveChannelPrice;
use App\Domain\Catalog\Actions\ResolvePriceConflict;
use App\Domain\Catalog\Models\PriceOverride;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\AuditLog;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Reconciliation\Actions\ReconcileConnection;
use App\Domain\Reconciliation\Enums\ItemStatus;
use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Reconciliation\Models\ReconciliationItem;
use App\Domain\Reconciliation\Models\ReconciliationRun;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\Channels\ProgrammableInventoryAdapter;
use Tests\TestCase;

/**
 * §9 · FİYAT ÇAKIŞMASI — tespit, karar ve sonuçları.
 *
 * Mimari Karar Dokümanı v2.2 · §9 (domain başına çakışma politikası),
 * §10 (mutabakat akışı), §11 (denetim kaydı), §1 · Karar 18.
 *
 * DEĞİŞMEZ KURAL — FİYATTA ÜZERİNE YAZILMAZ:
 *   Stokta fark bulununca sessizce onarılır (tek otorite biziz); fiyatta
 *   ONARIM AÇILMAZ. §9'un gerekçesi: satıcılar kanal panelinden kampanya
 *   yapıyor ve sessizce ezmek EN SIK ŞİKAYET. Bu dosyadaki en kritik
 *   iddia budur ve operasyon SAYISIYLA kanıtlanır.
 *
 * DEĞİŞMEZ KURAL — KABUL EDİLEN FİYAT BİR DAHA EZİLMEZ:
 *   Override'lı listing fiyat fan-out'undan ELENİR. Elenmeseydi satıcı
 *   "kabul ettim" der, sistem bir sonraki turda üzerine yazardı ve TÜM
 *   ÖZELLİK ANLAMSIZLAŞIRDI.
 */
final class PriceConflictTest extends TestCase
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

    // ───────────────────────────────────────────────────────────── tespit

    /**
     * Kanal fiyatı farklıysa PRICE_CONFLICT — VE ONARIM AÇILMAZ.
     *
     * BU DOSYANIN EN KRİTİK TESTİ. Stok turunda aynı fark `DRIFT_DETECTED`
     * yazar ve `QueueRepair` bir operasyon açar; burada operasyon sayısı
     * SIFIR kalmalıdır. Açılsaydı satıcının kanal panelinden yaptığı
     * kampanya bir sonraki turda sessizce silinirdi.
     */
    #[Test]
    public function channel_price_difference_is_a_conflict_and_opens_no_repair(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext(price: '99.90');

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');
        $this->markPriceStale($tenant, $listing);

        ProgrammableInventoryAdapter::remotePrice('woocommerce', '10', '79.90');

        $run = $this->reconcilePrices($tenant, $connection);

        $this->assertSame(1, $run->checked_count);

        $item = $this->itemFor($tenant, $listing);

        $this->assertSame(ItemStatus::PRICE_CONFLICT->value, $item->status);
        $this->assertSame(SyncDomain::PRICE->value, $item->domain);

        // Fark KURUŞ cinsindendir: 99.90 − 79.90 = 20.00 TL = 2000 kuruş.
        $this->assertSame(2000, $item->drift_magnitude);

        // Her iki fiyat da SAKLANIR: karar bağlamı olmadan satıcı neyi
        // neye tercih ettiğini bilemez.
        $this->assertSame('99.90', $item->local_value['price']);
        $this->assertSame('79.90', $item->remote_value['price']);

        // ── EN KRİTİK İDDİA ──────────────────────────────────────────
        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => SyncOperation::query()->count()),
            'FİYAT ÇAKIŞMASINDA ONARIM AÇILMAZ (§9): açılsaydı satıcının '.
            'kanal panelinden yaptığı kampanya sessizce ezilirdi.',
        );

        // Sürüklenme SAYILMAZ da: `isDrift()` false döner ve tur sayacı
        // fiyat çakışmasını sürüklenme olarak raporlamaz.
        $this->assertSame(0, $run->drift_count);
    }

    /**
     * Aynı fiyat farklı yazımlarla gelirse ÇAKIŞMA DEĞİLDİR.
     *
     * `decimal(12,2)` PHP'ye STRING döner ve kanal "79.9" ya da "79.900"
     * yazabilir. Metin karşılaştırılsaydı her tur SAHTE bir çakışma üretir,
     * satıcı olmayan bir kararı sonsuza kadar verirdi.
     */
    #[Test]
    public function equivalent_price_written_differently_is_not_a_conflict(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext(price: '79.90');

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');
        $this->markPriceStale($tenant, $listing);

        ProgrammableInventoryAdapter::remotePrice('woocommerce', '10', '79.9');

        $this->reconcilePrices($tenant, $connection);

        $this->assertSame(ItemStatus::MATCHED->value, $this->itemFor($tenant, $listing)->status);
    }

    /**
     * Fiyat turu STOK kalemi YAZMAZ ve stok bakiyesine DOKUNMAZ.
     *
     * İki domain aynı akışı paylaşır ama ayrı defter tutar; karışsalardı
     * fiyat turu stok sürüklenmesi raporlar ve onarım açardı.
     */
    #[Test]
    public function price_round_writes_only_price_items(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext(price: '10.00');

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');
        $this->markPriceStale($tenant, $listing);

        ProgrammableInventoryAdapter::remotePrice('woocommerce', '10', '20.00');

        $this->reconcilePrices($tenant, $connection);

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => ReconciliationItem::query()
                ->where('domain', SyncDomain::INVENTORY->value)
                ->count()),
            'Fiyat turu STOK kalemi yazmaz.',
        );
    }

    /**
     * Kanal okunamazsa REMOTE_UNREACHABLE — çakışma DEĞİL.
     *
     * Fark KANITLANMAMIŞTIR ve bilinmeyen duruma karşı karar sordurmak,
     * satıcıya olmayan bir kampanyayı onaylatmak olurdu.
     */
    #[Test]
    public function unreadable_channel_is_not_a_price_conflict(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext(price: '10.00');

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');
        $this->markPriceStale($tenant, $listing);

        ProgrammableInventoryAdapter::failFetchOn('woocommerce');

        $run = $this->reconcilePrices($tenant, $connection);

        $this->assertSame('failed', $run->status);
        $this->assertSame(
            ItemStatus::REMOTE_UNREACHABLE->value,
            $this->itemFor($tenant, $listing)->status,
        );
    }

    /**
     * `PRICE_CONFLICT` sürüklenme SAYILMAZ — enum tek kaynaktır.
     *
     * Onarım kapısı `isDrift()`'tir; `true` dönseydi `ReconcileConnection`
     * içinde ayrı bir domain koşulu olmadığı için onarım DOĞRUDAN açılırdı.
     */
    #[Test]
    public function price_conflict_is_not_drift(): void
    {
        $this->assertFalse(ItemStatus::PRICE_CONFLICT->isDrift());
        $this->assertFalse(ItemStatus::REMOTE_UNREACHABLE->isDrift());
        $this->assertTrue(ItemStatus::DRIFT_DETECTED->isDrift());
    }

    // ───────────────────────────────────────────────────────────── karar

    /**
     * "Kanalınki kalsın" → override yazılır, denetim kaydı düşer,
     * resync İSTENMEZ.
     */
    #[Test]
    public function accepting_channel_price_writes_override_without_resync(): void
    {
        [$tenant, $variant, $connection, $owner] = $this->makeContext(price: '99.90', withOwner: true);

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');
        $item = $this->conflictFor($tenant, $connection, $listing, '79.90');

        $this->asTenant($tenant, fn () => app(ResolvePriceConflict::class)->run(
            $item,
            ResolvePriceConflict::ACCEPT_CHANNEL,
            $owner->id,
        ));

        $override = $this->asTenant($tenant, fn () => PriceOverride::query()
            ->where('listing_id', $listing->id)
            ->firstOrFail());

        $this->assertSame('79.90', $override->channel_price);
        // KARAR ANINDAKİ KANONİK FİYAT da saklanır: kanonik fiyat sonradan
        // değişince override'ın BAYAT olduğu ancak bununla anlaşılır.
        $this->assertSame('99.90', $override->our_price);
        $this->assertSame($owner->id, $override->accepted_by);

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => OutboxEvent::query()
                ->where('event_type', 'ListingResyncRequested')
                ->count()),
            '"Kanalınki kalsın" kanala HİÇBİR istek göndermez.',
        );

        $log = $this->asTenant($tenant, fn () => AuditLog::query()
            ->where('action', AuditAction::PRICE_CONFLICT_RESOLVED->value)
            ->firstOrFail());

        $this->assertSame($listing->id, $log->subject_id);
        $this->assertSame(ResolvePriceConflict::ACCEPT_CHANNEL, $log->changes['decision']);

        // Kalem AÇIK LİSTEDEN düşer — durum korunur, `resolved_at` damgalanır.
        $this->assertNotNull($item->fresh()->resolved_at);
        $this->assertSame(ItemStatus::PRICE_CONFLICT->value, $item->fresh()->status);
    }

    /**
     * "Bizimki gitsin" → resync olayı yazılır ve override KALMAZ.
     *
     * §9 · Karar 18: durum yazmak tek başına hiçbir iş üretmez — kanonik
     * veri değişmedi ve değişmeyen veriden yeni domain olayı doğmaz. Bu
     * yüzden `ListingResyncRequested` ZORUNLUDUR.
     */
    #[Test]
    public function pushing_our_price_requests_resync_and_clears_override(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext(price: '99.90');

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        // Önce kabul edilmiş bir override VARDIR: satıcı fikir değiştiriyor.
        $item = $this->conflictFor($tenant, $connection, $listing, '79.90');

        $this->asTenant($tenant, fn () => app(ResolvePriceConflict::class)->run(
            $item,
            ResolvePriceConflict::ACCEPT_CHANNEL,
        ));

        $second = $this->conflictFor($tenant, $connection, $listing, '79.90');

        $this->asTenant($tenant, fn () => app(ResolvePriceConflict::class)->run(
            $second,
            ResolvePriceConflict::PUSH_OURS,
        ));

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => PriceOverride::query()
                ->where('listing_id', $listing->id)
                ->count()),
            'Override KALDIRILMALIDIR: kalsaydı açılan operasyon fan-out\'ta '.
            'elenir ve talep sessizce hiçbir şey yapmazdı.',
        );

        $event = $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'ListingResyncRequested')
            ->firstOrFail());

        $this->assertSame(SyncDomain::PRICE->value, $event->payload['domain']);
        $this->assertSame('price_conflict_resolved', $event->payload['reason']);
    }

    /**
     * Çakışma OLMAYAN kalem karar KABUL ETMEZ.
     *
     * Kapı olmasaydı panelden gelen herhangi bir kalem kimliği override
     * yazdırabilir ve satıcı hiç çakışma olmayan bir listing'in fiyatını
     * kilitleyebilirdi.
     */
    #[Test]
    public function non_conflict_item_cannot_be_resolved(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext(price: '10.00');

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');

        $item = $this->asTenant($tenant, fn () => ReconciliationItem::query()->create([
            'tenant_id' => $tenant->id,
            'reconciliation_run_id' => $this->emptyRun($tenant, $connection)->id,
            'listing_id' => $listing->id,
            'domain' => SyncDomain::INVENTORY->value,
            'priority_reason' => 'recently_sold',
            'status' => ItemStatus::DRIFT_DETECTED->value,
            'checked_at' => now(),
        ]));

        $this->expectException(RuntimeException::class);

        $this->asTenant($tenant, fn () => app(ResolvePriceConflict::class)->run(
            $item,
            ResolvePriceConflict::ACCEPT_CHANNEL,
        ));
    }

    // ─────────────────────────────────────────────────────────── çözümleme

    /**
     * Override varsa geçerli fiyat KANALINKİDİR.
     */
    #[Test]
    public function override_wins_over_canonical_price(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext(price: '99.90');

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');
        $this->acceptOverride($tenant, $listing, channel: '79.90', ours: '99.90');

        $resolved = $this->asTenant($tenant, function () use ($listing) {
            $fresh = Listing::query()->with('variant')->findOrFail($listing->id);

            return app(ResolveChannelPrice::class)->run($fresh);
        });

        $this->assertSame('79.90', $resolved['price']);
        $this->assertNotNull($resolved['override']);
    }

    /**
     * KANONİK FİYAT DEĞİŞTİYSE OVERRIDE BAYATLAR ve YOK SAYILIR.
     *
     * Satıcı "89.90 kalsın, benimki 99.90'dı" dedi; sonra panelden fiyatı
     * 149.90 yaptı. O karar ARTIK BAŞKA BİR SORUYA verilmiş bir cevaptır.
     * Yok sayılmasaydı panelden yapılan zam o kanala SESSİZCE hiç gitmez ve
     * satıcı eski fiyattan satmaya devam ederdi — sürekli ve sessiz gelir
     * kaybı.
     */
    #[Test]
    public function override_goes_stale_when_canonical_price_changes(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext(price: '99.90');

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');
        $this->acceptOverride($tenant, $listing, channel: '89.90', ours: '99.90');

        // Satıcı panelden zam yaptı.
        $this->asTenant($tenant, fn () => $variant->forceFill(['price' => '149.90'])->save());

        $resolved = $this->asTenant($tenant, function () use ($listing) {
            $fresh = Listing::query()->with('variant')->findOrFail($listing->id);

            return app(ResolveChannelPrice::class)->run($fresh);
        });

        $this->assertSame('149.90', $resolved['price']);
        $this->assertNull(
            $resolved['override'],
            'Kanonik fiyat değişince override BAYATLAR: yok sayılmasaydı zam '.
            'o kanala hiç gitmezdi.',
        );
    }

    /**
     * Süresi dolmuş override yok sayılır; süresiz olan GEÇERLİDİR.
     *
     * NULL "süresiz" demektir, "süresi dolmuş" değil.
     */
    #[Test]
    public function expired_override_is_ignored_but_null_expiry_is_not(): void
    {
        [$tenant, $variant, $connection] = $this->makeContext(price: '99.90');

        $listing = $this->listing($tenant, $variant, $connection, externalId: '10');
        $override = $this->acceptOverride($tenant, $listing, channel: '79.90', ours: '99.90');

        $this->assertTrue($override->isActive());

        $this->asTenant($tenant, fn () => $override->forceFill([
            'expires_at' => now()->subDay(),
        ])->save());

        $resolved = $this->asTenant($tenant, function () use ($listing) {
            $fresh = Listing::query()->with('variant')->findOrFail($listing->id);

            return app(ResolveChannelPrice::class)->run($fresh);
        });

        $this->assertSame('99.90', $resolved['price']);
        $this->assertNull($resolved['override']);
    }

    // ───────────────────────────────────────────────────────────── yardımcı

    /** @return array{0: Tenant, 1: Variant, 2: ChannelConnection, 3?: User} */
    private function makeContext(string $price, bool $withOwner = false): array
    {
        $owner = User::factory()->create();

        $tenant = (new CreateTenant)->run(
            name: 'Fiyat '.uniqid(),
            owner: $owner,
        );

        $variant = $this->asTenant($tenant, fn () => Variant::factory()->create([
            'price' => $price,
        ]));

        $connection = $this->connection($tenant, 'woocommerce');

        return $withOwner
            ? [$tenant, $variant, $connection, $owner]
            : [$tenant, $variant, $connection];
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

    /**
     * Fiyat adayı yaratır — `stale_sync` sebebiyle.
     *
     * FİYAT TURU `recently_sold` KULLANMAZ (satış fiyatı değiştirmez), o
     * yüzden aday bekleyen bir fiyat senkronu üzerinden kurulur.
     */
    private function markPriceStale(Tenant $tenant, Listing $listing): void
    {
        // `updateOrCreate`: aynı listing için ikinci bir tur kurulabilir
        // (satıcı fikir değiştirir) ve `(listing_id, domain)` TEKİLDİR.
        // Ayrıca mutabakat turunun kendisi de bu satırı yaratır
        // (`stampObservation`), yani ikinci çağrı ONU günceller.
        $this->asTenant($tenant, fn () => ListingSyncState::query()->updateOrCreate(
            [
                'listing_id' => $listing->id,
                'domain' => SyncDomain::PRICE->value,
            ],
            [
                'tenant_id' => $tenant->id,
                'desired_version' => 2,
                'synced_version' => 1,
                'status' => 'pending',
                'error_count' => 0,
                'last_requested_at' => now()->subDays(2),
            ],
        ));
    }

    private function reconcilePrices(Tenant $tenant, ChannelConnection $connection): ReconciliationRun
    {
        return $this->asTenant($tenant, fn () => app(ReconcileConnection::class)->run(
            connection: $connection,
            scope: ReconciliationScope::WARM,
            budget: 50,
            domain: SyncDomain::PRICE,
        ));
    }

    /** Gerçek bir tur koşturarak çakışma kalemi üretir. */
    private function conflictFor(
        Tenant $tenant,
        ChannelConnection $connection,
        Listing $listing,
        string $channelPrice,
    ): ReconciliationItem {
        $this->markPriceStale($tenant, $listing);

        ProgrammableInventoryAdapter::remotePrice('woocommerce', (string) $listing->external_id, $channelPrice);

        $this->reconcilePrices($tenant, $connection);

        // İlişki KİRACI BAĞLAMI İÇİNDE yüklenir: kapanışın dışında
        // `load()` çağırmak tenant-scoped bir sorgu açar ve izolasyon
        // istisnası fırlatır.
        $item = $this->asTenant($tenant, fn () => ReconciliationItem::query()
            ->with('listing.variant')
            ->where('listing_id', $listing->id)
            ->orderByDesc('id')
            ->firstOrFail());

        $this->assertSame(ItemStatus::PRICE_CONFLICT->value, $item->status);

        return $item;
    }

    private function acceptOverride(
        Tenant $tenant,
        Listing $listing,
        string $channel,
        string $ours,
    ): PriceOverride {
        return $this->asTenant($tenant, fn () => PriceOverride::query()->create([
            'tenant_id' => $tenant->id,
            'listing_id' => $listing->id,
            'channel_price' => $channel,
            'our_price' => $ours,
            'accepted_at' => now(),
            'expires_at' => null,
        ]));
    }

    private function emptyRun(Tenant $tenant, ChannelConnection $connection): ReconciliationRun
    {
        return $this->asTenant($tenant, fn () => ReconciliationRun::query()->create([
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $connection->id,
            'scope' => ReconciliationScope::WARM->value,
            'trigger_reason' => 'test',
            'started_at' => now(),
            'status' => 'completed',
        ]));
    }

    /** SON kalem `id` üzerinden — `checked_at` saniye hassasiyetlidir. */
    private function itemFor(Tenant $tenant, Listing $listing): ReconciliationItem
    {
        return $this->asTenant($tenant, fn () => ReconciliationItem::query()
            ->where('listing_id', $listing->id)
            ->orderByDesc('id')
            ->firstOrFail());
    }
}
