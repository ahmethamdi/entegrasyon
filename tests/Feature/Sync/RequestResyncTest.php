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
use App\Domain\Messaging\Consumers\ListingResyncRequestedConsumer;
use App\Domain\Messaging\Jobs\ConsumeOutboxEvent;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Actions\RequestResync;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T10 · Kalıcı hata düzeltilince resync üretilir (§18 · P1).
 *
 * Mimari Karar Dokümanı v2.2 · §9 · error_permanent durumundan çıkış,
 * §1 · Karar 18, §18 · T10.
 *
 * DEĞİŞMEZ KURAL — DURUM DEĞİŞİKLİĞİ TEK BAŞINA HİÇBİR İŞ ÜRETMEZ.
 * `error_permanent → pending` yazmak yeterli olsaydı, kanonik veri o arada
 * değişmediği için yeni bir domain olayı DOĞMAZ ve hiçbir şey kanala
 * gitmezdi. Satır panelde "bekliyor" görünür, sonsuza kadar bekler ve
 * kullanıcı neden hiçbir şey olmadığını anlamaz. Bu yüzden her çıkış geçişi
 * AYNI TRANSACTION içinde bir `ListingResyncRequested` olayı yazar.
 *
 * Ayrıca `error_permanent` mutabakatta ASLA aday değildir
 * (`CandidateSelector`) — yani bu geçiş, satırı akışa geri sokan TEK yoldur.
 */
final class RequestResyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Planlamayı sınıyoruz; gerçek worker'ı `sync` sürücü taklit etmez ve
        // iş sınırındaki bağlam temizliği çağıranın bağlamını da götürür.
        Queue::fake();
    }

    /**
     * T10 · KALICI HATA DÜZELTİLİNCE RESYNC ÜRETİLİR.
     *
     * Doküman §18'in T10 senaryosu birebir: kullanıcı eksik özniteliği
     * tamamladı, **kanonik ürün verisi DEĞİŞMEDİ** ve tam bu yüzden durumu
     * ellemek yetmez.
     */
    #[Test]
    public function error_permanent_recovery_emits_resync_event(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->setState($tenant, $listing, status: 'error_permanent', lastError: 'Missing required attribute');

        $this->asTenant($tenant, fn () => app(RequestResync::class)->run(
            $listing,
            SyncDomain::CONTENT,
            'taxonomy_prerequisite_fixed',
        ));

        // (1) Durum pending'e döndü.
        $this->assertSame('pending', $this->stateFor($tenant, $listing)->status);

        // (2) Durum değişikliği TEK BAŞINA yetmez: olay yazıldı mı?
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $listing->id,
            'event_type' => 'ListingResyncRequested',
        ]);

        // (3) Tüketici çalışınca operasyon oluşuyor.
        $event = $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'ListingResyncRequested')
            ->firstOrFail());

        $this->asTenant($tenant, fn () => app(ListingResyncRequestedConsumer::class)->handle($event));

        $count = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('entity_id', $listing->id)
            ->where('operation_type', SyncDomain::CONTENT->operationType())
            ->count());

        $this->assertSame(1, $count, 'tüketici operasyon açmadı — resync hiçbir iş üretmedi.');
        $this->assertNotNull($event->fresh()->consumed_at);
    }

    /**
     * ESKİ HATA METNİ TEMİZLENİR ve SAYAÇ SIFIRLANIR.
     *
     * Eski metin kalsaydı panel çözülmüş bir sorunu göstermeye devam eder ve
     * kullanıcı düzeltmesinin işe yaramadığını sanardı.
     */
    #[Test]
    public function it_clears_the_stale_error_and_resets_the_counter(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->setState(
            $tenant,
            $listing,
            status: 'error_permanent',
            lastError: 'Missing required attribute: Renk',
            errorCount: 4,
        );

        $this->asTenant($tenant, fn () => app(RequestResync::class)->run(
            $listing,
            SyncDomain::CONTENT,
            'taxonomy_prerequisite_fixed',
        ));

        $state = $this->stateFor($tenant, $listing);

        $this->assertNull($state->last_error, 'eski hata metni kaldı — panel çözülmüş sorunu gösterir.');
        $this->assertSame(0, $state->error_count);
    }

    /**
     * `desired_version` ARTIRILMAZ.
     *
     * Artırılsaydı sürüm kapısı sonraki GERÇEK değişikliği "bayat" sayardı;
     * bu projede ön koşul kapısında tam bu tuzak yaşandı. Kanonik veri
     * değişmedi, dolayısıyla istenen sürüm de değişmez.
     */
    #[Test]
    public function it_does_not_bump_the_desired_version(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->setState($tenant, $listing, status: 'error_permanent', desired: 5, synced: 5);

        $this->asTenant($tenant, fn () => app(RequestResync::class)->run(
            $listing,
            SyncDomain::CONTENT,
            'manual_retry',
        ));

        $state = $this->stateFor($tenant, $listing);

        $this->assertSame(5, $state->desired_version, 'desired_version arttı — sonraki gerçek değişiklik bayat sayılır.');
        $this->assertSame(5, $state->synced_version, 'synced_version geriye alındı — o alan GERÇEĞİ taşır.');
    }

    /**
     * ZATEN GÖNDERİLMİŞ SÜRÜMDE DE İŞ ÜRETİLİR — SÜRÜM KAPISI ELEMEZ.
     *
     * BU TESTİN VARLIK NEDENİ: listing v5'te BAŞARIYLA gönderilmiş
     * (`synced_version = 5`) ama sonradan kalıcı hataya düşmüşse, dokümanın
     * gösterdiği gibi NORMAL_SYNC niyetiyle mevcut sürüm geçirilirse
     * `OpenSyncOperation`'ın kapısı `synced_version >= eventVersion` ile
     * operasyonu SESSİZCE ELER: kullanıcı "yeniden dene" der ve HİÇBİR ŞEY
     * OLMAZ. Bu, projede tekrar tekrar ısıran hata biçiminin aynısıdır.
     *
     * Çözüm REPAIR niyetidir: kapı ATLANIR ve `desired_version` ARTIRILMAZ —
     * ikisi de mutabakatın onarım yolundaki mevcut kurallardır.
     */
    #[Test]
    public function it_creates_work_even_when_the_current_version_was_already_synced(): void
    {
        [$tenant, $listing] = $this->makeListing();

        // Kapının eleyeceği en zor durum: istenen = gönderilen = mevcut sürüm.
        $this->setState($tenant, $listing, status: 'error_permanent', desired: 5, synced: 5);

        $this->asTenant($tenant, fn () => app(RequestResync::class)->run(
            $listing,
            SyncDomain::CONTENT,
            'credential_reauthorized',
        ));

        $event = $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'ListingResyncRequested')
            ->firstOrFail());

        $this->asTenant($tenant, fn () => app(ListingResyncRequestedConsumer::class)->handle($event));

        $count = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('entity_id', $listing->id)
            ->count());

        $this->assertSame(
            1,
            $count,
            'sürüm kapısı resync operasyonunu eledi — kullanıcının "yeniden dene"si sessizce hiçbir şey yapmaz.',
        );
    }

    /**
     * AYNI OLAY İKİ KEZ TÜKETİLSE TEK OPERASYON OLUŞUR.
     *
     * Olay çökme senaryosunda iki kez yayınlanabilir. Çıpa olay kimliğidir;
     * taşımasaydı iki tüketim aynı anahtarı üretir veya (daha kötüsü)
     * mutabakatın onarım anahtarıyla çakışırdı.
     */
    #[Test]
    public function consuming_the_same_event_twice_yields_one_operation(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->setState($tenant, $listing, status: 'error_permanent', desired: 5, synced: 5);

        $this->asTenant($tenant, fn () => app(RequestResync::class)->run(
            $listing,
            SyncDomain::CONTENT,
            'manual_retry',
        ));

        $event = $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'ListingResyncRequested')
            ->firstOrFail());

        $this->asTenant($tenant, function () use ($event): void {
            app(ListingResyncRequestedConsumer::class)->handle($event);
            // İkinci tur: consumed damgası erken çıkışı sağlar, ama damga
            // atılmasa bile anahtar tekilliği tek operasyon garantiler.
            app(ListingResyncRequestedConsumer::class)->handle($event->fresh());
        });

        $count = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('entity_id', $listing->id)
            ->count());

        $this->assertSame(1, $count, 'ikinci tüketim ikinci operasyon açtı.');
    }

    /**
     * İKİ AYRI RESYNC TALEBİ İKİ AYRI OPERASYON ÜRETİR — ÇIPA BUNU SAĞLAR.
     *
     * Gerçek senaryo: satıcı eksik eşleştirmeyi tamamlar ve "yeniden dene"
     * der; gönderim BAŞKA bir sebeple yine kalıcı hataya düşer; satıcı onu da
     * düzeltip TEKRAR dener. Aynı listing, aynı kanonik sürüm, İKİ MEŞRU
     * TALEP.
     *
     * REPAIR sürüm kapısını atladığı için ayırt etmenin tek yolu anahtardır.
     * Çıpa anahtara girmezse iki talep de `...:repair:` (boş kimlik) anahtarına
     * düşer, `insertOrIgnore` ikincisini SESSİZCE yutar ve satıcının ikinci
     * düzeltmesi hiç gönderilmez (mutasyonla bulundu).
     */
    #[Test]
    public function two_separate_resync_requests_produce_two_operations(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->setState($tenant, $listing, status: 'error_permanent', desired: 5, synced: 5);

        // Birinci talep — eksik öznitelik tamamlandı.
        $first = $this->asTenant($tenant, fn () => app(RequestResync::class)->run(
            $listing,
            SyncDomain::CONTENT,
            'taxonomy_prerequisite_fixed',
        ));
        $this->asTenant($tenant, fn () => app(ListingResyncRequestedConsumer::class)->handle($first));

        // Gönderim başka bir sebeple yine kalıcı hataya düştü.
        $this->setState($tenant, $listing, status: 'error_permanent', lastError: 'Invalid barcode', desired: 5, synced: 5);

        // İkinci talep — o hata da düzeltildi. AYNI listing, AYNI sürüm.
        $second = $this->asTenant($tenant, fn () => app(RequestResync::class)->run(
            $listing,
            SyncDomain::CONTENT,
            'content_corrected',
        ));
        $this->asTenant($tenant, fn () => app(ListingResyncRequestedConsumer::class)->handle($second));

        $count = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('entity_id', $listing->id)
            ->count());

        $this->assertSame(
            2,
            $count,
            'ikinci resync talebi sessizce yutuldu — çıpa anahtara girmiyor ve '.
            'satıcının ikinci düzeltmesi hiç gönderilmez.',
        );
    }

    /**
     * CANLI OLMAYAN LISTING İŞ ÜRETMEZ AMA OLAY CONSUMED DAMGALANIR.
     *
     * Damgalanmazsa seviye 1 bütünlük taraması olayı kayıp sanar ve sonsuza
     * kadar yeniden yayınlar.
     */
    #[Test]
    public function a_non_live_listing_plans_nothing_but_the_event_is_still_consumed(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->asTenant($tenant, fn () => $listing->forceFill(['lifecycle_status' => 'draft'])->save());
        $this->setState($tenant, $listing, status: 'error_permanent');

        $this->asTenant($tenant, fn () => app(RequestResync::class)->run(
            $listing,
            SyncDomain::CONTENT,
            'manual_retry',
        ));

        $event = $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'ListingResyncRequested')
            ->firstOrFail());

        $this->asTenant($tenant, fn () => app(ListingResyncRequestedConsumer::class)->handle($event));

        $count = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('entity_id', $listing->id)
            ->count());

        $this->assertSame(0, $count, 'canlı olmayan listing için operasyon açıldı.');
        $this->assertNotNull(
            $event->fresh()->consumed_at,
            'olay damgalanmadı — seviye 1 taraması onu sonsuza kadar yeniden yayınlar.',
        );
    }

    /**
     * OLAY GERÇEK TESLİM YOLUNDAN GEÇER — `ConsumeOutboxEvent` ONU YÖNLENDİRİR.
     *
     * BU TESTİN VARLIK NEDENİ: diğer testler tüketiciyi DOĞRUDAN çağırıyor ve
     * bu yüzden hepsi, tüketicinin `ConsumeOutboxEvent` içindeki `match`
     * dalına hiç bağlanmadığı bir dünyada da yeşil kalır (mutasyonla
     * bulundu). Dal yoksa olay "tanınmayan tür" sayılır, SESSİZCE consumed
     * damgalanır ve kullanıcının düzeltmesi hiçbir iş üretmez: durum
     * `pending` görünür, kanala hiçbir şey gitmez. Bu projede tam bu biçimde
     * iki ölümcül hata bulundu ("sınıfın var olması onu kimsenin çağırdığı
     * anlamına gelmez").
     */
    #[Test]
    public function the_event_is_routed_by_the_real_outbox_consumer_job(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->setState($tenant, $listing, status: 'error_permanent', desired: 5, synced: 5);

        $event = $this->asTenant($tenant, fn () => app(RequestResync::class)->run(
            $listing,
            SyncDomain::CONTENT,
            'manual_retry',
        ));

        // Gerçek yol: relay olayı yayınlar ve bu işi atar.
        (new ConsumeOutboxEvent($tenant->id, $event->id))->handle();

        $count = $this->asTenant($tenant, fn () => SyncOperation::query()
            ->where('entity_id', $listing->id)
            ->where('operation_type', SyncDomain::CONTENT->operationType())
            ->count());

        $this->assertSame(
            1,
            $count,
            'ConsumeOutboxEvent resync olayını yönlendirmedi — olay "tanınmayan tür" '.
            'sayılıp sessizce yutuldu ve kullanıcının düzeltmesi hiçbir iş üretmedi.',
        );
    }

    /**
     * OLAY YÜKÜ SEBEBİ TAŞIR.
     *
     * Tek generic olay tipi kullanılır (ayrı taksonomi kurulmaz, §9); sebep
     * ayrımı yükte yaşar ve "bu resync neden istendi" sorusu ancak oradan
     * cevaplanır.
     */
    #[Test]
    public function the_payload_carries_the_reason(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->setState($tenant, $listing, status: 'error_permanent');

        $this->asTenant($tenant, fn () => app(RequestResync::class)->run(
            $listing,
            SyncDomain::CONTENT,
            'content_corrected',
        ));

        $event = $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'ListingResyncRequested')
            ->firstOrFail());

        $this->assertSame('content_corrected', $event->payload['reason']);
        $this->assertSame($listing->id, $event->payload['listing_id']);
        $this->assertSame(SyncDomain::CONTENT->value, $event->payload['domain']);
    }

    /**
     * KALICI HATADA OLMAYAN SATIR DA RESYNC EDİLEBİLİR.
     *
     * Action durumu ön koşul olarak SORMAZ: "yeniden dene" geçici hatada da,
     * takılı kalmış bekleyen satırda da meşru bir taleptir. Kapı koymak
     * kullanıcının elindeki tek kurtarma düğmesini keyfi biçimde kilitlerdi.
     */
    #[Test]
    public function it_also_works_for_a_row_that_is_not_in_permanent_error(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->setState($tenant, $listing, status: 'error_transient', lastError: '429 Too Many Requests');

        $this->asTenant($tenant, fn () => app(RequestResync::class)->run(
            $listing,
            SyncDomain::CONTENT,
            'manual_retry',
        ));

        $this->assertSame('pending', $this->stateFor($tenant, $listing)->status);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $listing->id,
            'event_type' => 'ListingResyncRequested',
        ]);
    }

    /**
     * SYNC STATE SATIRI YOKSA YARATILIR.
     *
     * Hiç senkronlanmamış listing tam da kullanıcının "yeniden dene"
     * demek isteyeceği satırdır; satır yok diye sessizce çıkmak talebi
     * yutardı.
     */
    #[Test]
    public function a_missing_sync_state_row_is_created(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->assertNull($this->stateFor($tenant, $listing));

        $this->asTenant($tenant, fn () => app(RequestResync::class)->run(
            $listing,
            SyncDomain::CONTENT,
            'manual_retry',
        ));

        $this->assertSame('pending', $this->stateFor($tenant, $listing)->status);
    }

    /**
     * OLAY VE DURUM AYNI TRANSACTION'DA YAZILIR.
     *
     * Ayrı olsalardı araya düşen hata iki yönde de bozuk durum bırakırdı:
     * durum pending ama olay yok (satır sonsuza kadar bekler) veya olay var
     * ama durum error_permanent (iş üretilir, panel hâlâ hata gösterir).
     */
    #[Test]
    public function the_state_change_and_the_event_share_one_transaction(): void
    {
        [$tenant, $listing] = $this->makeListing();

        $this->setState($tenant, $listing, status: 'error_permanent');

        $this->asTenant($tenant, fn () => app(RequestResync::class)->run(
            $listing,
            SyncDomain::CONTENT,
            'manual_retry',
        ));

        // İkisi de var: ham satırdan okunur (Eloquent kimlik haritası
        // kalıcılık testinde yanıltır).
        $status = $this->asTenant($tenant, fn () => DB::table('listing_sync_states')
            ->where('listing_id', $listing->id)
            ->value('status'));

        $events = DB::table('outbox_events')
            ->where('aggregate_id', $listing->id)
            ->where('event_type', 'ListingResyncRequested')
            ->count();

        $this->assertSame('pending', $status);
        $this->assertSame(1, $events);
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: Listing} */
    private function makeListing(): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Resync '.uniqid(),
            owner: User::factory()->create(),
        );

        $listing = $this->asTenant($tenant, function () {
            $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
                ['code' => 'woocommerce'],
                [
                    'name' => 'WooCommerce',
                    'kind' => 'storefront',
                    'adapter_class' => 'App\\Domain\\Channels\\Adapters\\WooCommerceAdapter',
                    'is_active' => true,
                ],
            ));

            $product = Product::factory()->create(['content_version' => 5]);

            return Listing::factory()->create([
                'channel_connection_id' => ChannelConnection::factory()->create()->id,
                'variant_id' => Variant::factory()->create(['product_id' => $product->id])->id,
                'lifecycle_status' => 'live',
            ]);
        });

        return [$tenant, $listing];
    }

    private function setState(
        Tenant $tenant,
        Listing $listing,
        string $status,
        ?string $lastError = null,
        int $errorCount = 0,
        int $desired = 0,
        int $synced = 0,
    ): void {
        $this->asTenant($tenant, fn () => ListingSyncState::query()->updateOrCreate(
            [
                'listing_id' => $listing->id,
                'domain' => SyncDomain::CONTENT->value,
            ],
            [
                'tenant_id' => $tenant->id,
                'status' => $status,
                'last_error' => $lastError,
                'error_count' => $errorCount,
                'desired_version' => $desired,
                'synced_version' => $synced,
            ],
        ));
    }

    private function stateFor(Tenant $tenant, Listing $listing): ?ListingSyncState
    {
        return $this->asTenant($tenant, fn () => ListingSyncState::query()
            ->where('listing_id', $listing->id)
            ->where('domain', SyncDomain::CONTENT->value)
            ->first());
    }
}
