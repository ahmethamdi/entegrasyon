<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Actions\SaveCategoryMapping;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Actions\TrackApprovalStatus;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Jobs\PushListing;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\ListingPayloadBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use App\Support\Logging\PayloadRedactor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Onay durumu takibi — §13 · Faz 2 · "Katalog aktarımı, ön koşul kapısı,
 * onay durumu takibi". §7 · SupportsApprovalWorkflow, §14 · onay süreci.
 *
 * DEĞİŞMEZ KURAL — ONAY DURUMU LIFECYCLE'DAN AYRIDIR:
 *   Ürün bizde "gönderildi" ama kanalda "beklemede" veya "reddedildi"
 *   olabilir. Panel bu farkı göstermek zorundadır, yoksa kullanıcı ürünün
 *   neden görünmediğini anlayamaz.
 *
 * DEĞİŞMEZ KURAL — ONAYSIZ LISTING'E STOK GÖNDERİLMEZ (§14):
 *   Stok fan-out'u yalnızca `lifecycle_status = 'live'` satırları hedefler.
 *   Onay bu bayrağı yönetir; STOK MANTIĞI DEĞİŞMEZ.
 *
 * DEĞİŞMEZ KURAL — RED SEBEBİ GÖSTERİLİR:
 *   "Reddedildi" tek başına kullanıcıya ne düzelteceğini söylemez.
 */
final class ApprovalStatusTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════ adapter

    /**
     * Onay durumu kanaldan TOPLU okunur.
     *
     * Listing başına ayrı istek, 500 ürünlü bir katalogda 500 istek
     * demektir ve kotayı anlamsızca tüketir.
     */
    #[Test]
    public function approval_status_is_fetched_in_one_batch(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [
                ['barcode' => 'SKU-1', 'approved' => true, 'onSale' => true],
                ['barcode' => 'SKU-2', 'approved' => false, 'onSale' => false,
                    'rejectReasonDetails' => [['reason' => 'Görsel çözünürlüğü yetersiz']]],
            ],
        ], 200)]);

        [$tenant] = $this->makeTenant();
        $connection = $this->connection($tenant);

        $listings = $this->asTenant($tenant, fn () => [
            $this->listing($tenant, $connection, 'SKU-1', externalId: 'SKU-1'),
            $this->listing($tenant, $connection, 'SKU-2', externalId: 'SKU-2'),
        ]);

        $batch = $this->adapter($connection)->fetchApprovalStatus($listings);

        $this->assertSame('approved', $batch->statusFor('SKU-1')['status']);
        $this->assertSame('rejected', $batch->statusFor('SKU-2')['status']);
        $this->assertSame('Görsel çözünürlüğü yetersiz', $batch->statusFor('SKU-2')['reason']);

        // TEK istek: listing başına ayrı çağrı yapılmadı.
        Http::assertSentCount(1);
    }

    /**
     * Onaylanmış ama SATIŞA KAPALI ürün "approved" sayılmaz.
     *
     * Trendyol'da `approved: true` + `onSale: false` mümkündür (satıcı
     * kapatmış veya stok yok). Bu satır kanalda GÖRÜNMEZ; "onaylandı"
     * demek kullanıcıya ürünün yayında olduğunu düşündürürdü.
     */
    #[Test]
    public function approved_but_not_on_sale_is_reported_separately(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [
                ['barcode' => 'SKU-1', 'approved' => true, 'onSale' => false],
            ],
        ], 200)]);

        [$tenant] = $this->makeTenant();
        $connection = $this->connection($tenant);

        $listings = $this->asTenant($tenant, fn () => [
            $this->listing($tenant, $connection, 'SKU-1', externalId: 'SKU-1'),
        ]);

        $batch = $this->adapter($connection)->fetchApprovalStatus($listings);

        $this->assertSame('inactive', $batch->statusFor('SKU-1')['status']);
    }

    /**
     * KANALDA HİÇ GÖRÜNMEYEN listing "beklemede" sayılır, "reddedildi" değil.
     *
     * Trendyol yeni gönderilen ürünü listeye hemen koymaz. Yokluğu red
     * saymak, satıcıyı var olmayan bir hatayı düzeltmeye gönderirdi.
     */
    #[Test]
    public function a_listing_missing_from_the_response_is_pending_not_rejected(): void
    {
        Http::fake(['*' => Http::response(['content' => []], 200)]);

        [$tenant] = $this->makeTenant();
        $connection = $this->connection($tenant);

        $listings = $this->asTenant($tenant, fn () => [
            $this->listing($tenant, $connection, 'SKU-1', externalId: 'SKU-1'),
        ]);

        $batch = $this->adapter($connection)->fetchApprovalStatus($listings);

        $this->assertNull($batch->statusFor('SKU-1'),
            'Yanıtta olmayan listing için durum UYDURULMAZ.');
    }

    /**
     * BAŞARISIZ YANIT SESSİZCE BOŞ SONUCA DÖNÜŞMEZ.
     *
     * Taksonomide aynı hata yaşandı: `json()` bir 500 gövdesinde de dizi
     * döndürür. Boş sonuç "hiçbiri onaylanmadı" diye yorumlanır ve tüm
     * listing'ler haksız yere beklemede kalırdı.
     */
    #[Test]
    public function a_failed_response_raises_instead_of_returning_empty(): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        [$tenant] = $this->makeTenant();
        $connection = $this->connection($tenant);

        $listings = $this->asTenant($tenant, fn () => [
            $this->listing($tenant, $connection, 'SKU-1', externalId: 'SKU-1'),
        ]);

        $this->expectException(\Throwable::class);

        $this->adapter($connection)->fetchApprovalStatus($listings);
    }

    /**
     * KİMLİĞİ OLMAYAN LISTING İSTEĞE HİÇ GİRMEZ.
     *
     * `external_id` NULL ise ürün kanala hiç gitmemiştir; onayını sormak
     * boşuna istektir. Hiç kimlik yoksa çağrı da yapılmaz.
     */
    #[Test]
    public function listings_without_an_external_id_are_not_queried(): void
    {
        Http::fake(['*' => Http::response(['content' => []], 200)]);

        [$tenant] = $this->makeTenant();
        $connection = $this->connection($tenant);

        $listings = $this->asTenant($tenant, fn () => [
            $this->listing($tenant, $connection, 'SKU-1', externalId: null),
        ]);

        $batch = $this->adapter($connection)->fetchApprovalStatus($listings);

        $this->assertSame([], $batch->statusesByExternalId);

        Http::assertNothingSent();
    }

    // ═══════════════════════════════════════════════ gönderim sonrası

    /**
     * ONAY SÜRECİ OLAN KANALDA GÖNDERİM `live` DEĞİL `pending_approval` YAZAR.
     *
     * Doğrudan canlı işaretlenseydi henüz yayında olmayan satır fan-out
     * hedefi olur ve her stok turunda hata alırdı; üstelik panel "yayında"
     * derken ürün kanalda görünmezdi.
     */
    #[Test]
    public function pushing_to_an_approval_channel_marks_pending_approval_not_live(): void
    {
        [$tenant] = $this->makeTenant();
        $connection = $this->connection($tenant);

        // Ön koşul: kategori eşleştirmesi olmadan mapper haklı olarak
        // istisna fırlatır ve gönderim hiç kanala ulaşmaz.
        $dress = $this->asSystem(fn () => ChannelCategory::query()->updateOrCreate(
            ['channel_type_code' => 'trendyol', 'taxonomy_version' => 'v1', 'external_id' => '11'],
            ['name' => 'Elbise', 'path' => 'Giyim > Elbise', 'is_leaf' => true],
        ));

        $listing = $this->asTenant($tenant, function () use ($tenant, $connection, $dress): Listing {
            $listing = $this->listing(
                $tenant, $connection, 'SKU-1', externalId: null, lifecycle: 'draft',
            );

            $listing->variant->product->forceFill([
                'internal_category_id' => 'kadin-elbise',
            ])->save();

            app(SaveCategoryMapping::class)->run('kadin-elbise', $dress);

            return $listing;
        });

        // GERÇEK İŞ çalıştırılır: özel metoda reflection ile girmek
        // davranışı değil implementasyonu sınardı.
        $operation = $this->asTenant($tenant, fn () => app(OpenSyncOperation::class)->run(
            listing: $listing,
            domain: SyncDomain::CONTENT,
            eventVersion: 1,
        ));

        // Kanal ürünü kabul eder ve kimlik döner.
        Http::fake(['*' => Http::response(['barcode' => 'SKU-1', 'batchRequestId' => 'b-1'], 200)]);

        $this->asTenant($tenant, fn () => app(PushListing::class, [
            'operationId' => $operation->id,
            'tenantId' => $tenant->id,
        ])->handle(
            app(ListingPayloadBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        ));

        $raw = DB::table('listings')->where('id', $listing->id)->first();

        // Gönderim gerçekten başarılı olmalı; sessiz bir hata bu testi
        // yanlış sebeple kırmızıya döndürürdü.
        $attempt = DB::table('sync_attempts')->orderByDesc('id')->first();
        $this->assertNotNull($attempt, 'Gönderim denemesi açılmalıydı.');
        $this->assertNull($attempt->error_message ?? null,
            'Gönderim hata aldı: '.($attempt->error_message ?? ''));

        $this->assertSame('pending_approval', $raw->lifecycle_status);
        $this->assertNull($raw->listed_at,
            'Yayına giriş tarihi ancak gerçekten yayındayken yazılır.');
    }

    // ═══════════════════════════════════════════════ durum yazımı

    /**
     * ONAY LISTING'İ CANLI YAPAR.
     *
     * Canlı işareti stok fan-out'unun hedef filtresidir: onaylanmadan
     * canlı yapılsaydı kanalda yayında olmayan ürüne stok gönderilir ve
     * her tur hata alırdı.
     */
    #[Test]
    public function approval_marks_the_listing_live(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['barcode' => 'SKU-1', 'approved' => true, 'onSale' => true]],
        ], 200)]);

        [$tenant] = $this->makeTenant();
        $connection = $this->connection($tenant);

        $listing = $this->asTenant($tenant, fn () => $this->listing(
            $tenant, $connection, 'SKU-1', externalId: 'SKU-1', lifecycle: 'pending_approval',
        ));

        $this->asTenant($tenant, fn () => app(TrackApprovalStatus::class)->run($connection));

        // KALICILIK: ham satırı oku — Eloquent kimlik haritası yanıltır.
        $raw = DB::table('listings')->where('id', $listing->id)->first();

        $this->assertSame('live', $raw->lifecycle_status);
        $this->assertNull($raw->approval_rejection_reason);
        $this->assertNotNull($raw->approval_checked_at);
    }

    /**
     * RED LISTING'İ CANLI YAPMAZ VE SEBEBİ YAZAR.
     *
     * Reddedilen ürün kanalda yoktur; canlı işaretlemek ona stok
     * göndermeye çalışmak demekti.
     */
    #[Test]
    public function rejection_records_the_reason_and_does_not_go_live(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [[
                'barcode' => 'SKU-1',
                'approved' => false,
                'onSale' => false,
                'rejectReasonDetails' => [['reason' => 'Marka onayı yok']],
            ]],
        ], 200)]);

        [$tenant] = $this->makeTenant();
        $connection = $this->connection($tenant);

        $listing = $this->asTenant($tenant, fn () => $this->listing(
            $tenant, $connection, 'SKU-1', externalId: 'SKU-1', lifecycle: 'pending_approval',
        ));

        $this->asTenant($tenant, fn () => app(TrackApprovalStatus::class)->run($connection));

        $raw = DB::table('listings')->where('id', $listing->id)->first();

        $this->assertSame('rejected', $raw->lifecycle_status);
        $this->assertSame('Marka onayı yok', $raw->approval_rejection_reason);
    }

    /**
     * REDDEDİLEN ÜRÜN SONRADAN ONAYLANIRSA CANLI OLUR VE SEBEP TEMİZLENİR.
     *
     * Satıcı eksiği düzeltip yeniden gönderir; eski red sebebi kalsaydı
     * panel çalışan bir üründe hâlâ hata gösterirdi.
     */
    #[Test]
    public function a_previously_rejected_listing_can_become_live_and_the_reason_is_cleared(): void
    {
        [$tenant] = $this->makeTenant();
        $connection = $this->connection($tenant);

        $listing = $this->asTenant($tenant, fn () => $this->listing(
            $tenant, $connection, 'SKU-1',
            externalId: 'SKU-1',
            lifecycle: 'rejected',
            rejectionReason: 'Marka onayı yok',
        ));

        Http::fake(['*' => Http::response([
            'content' => [['barcode' => 'SKU-1', 'approved' => true, 'onSale' => true]],
        ], 200)]);

        $this->asTenant($tenant, fn () => app(TrackApprovalStatus::class)->run($connection));

        $raw = DB::table('listings')->where('id', $listing->id)->first();

        $this->assertSame('live', $raw->lifecycle_status);
        $this->assertNull($raw->approval_rejection_reason, 'Eski red sebebi TEMİZLENİR.');
    }

    /**
     * KANALDA GÖRÜNMEYEN SATIRA DOKUNULMAZ — reddedilmiş SAYILMAZ.
     *
     * Adapter yanıtta olmayan kimlik için `null` döner; action bunu red
     * sanarsa satıcı henüz sıraya bile girmemiş ürününü "reddedildi"
     * olarak görür ve var olmayan bir hatayı düzeltmeye çalışır.
     *
     * (Bu testin YOKLUĞU mutasyonla bulundu: `null` durumunu "rejected"
     * saymak hiçbir testi kırmıyordu — adapter testi yalnızca batch'i
     * sınıyordu, action'ın onu NASIL ele aldığını değil.)
     */
    #[Test]
    public function a_listing_missing_from_the_response_keeps_its_status(): void
    {
        Http::fake(['*' => Http::response(['content' => []], 200)]);

        [$tenant] = $this->makeTenant();
        $connection = $this->connection($tenant);

        $listing = $this->asTenant($tenant, fn () => $this->listing(
            $tenant, $connection, 'SKU-1', externalId: 'SKU-1', lifecycle: 'pending_approval',
        ));

        $result = $this->asTenant($tenant, fn () => app(TrackApprovalStatus::class)->run($connection));

        $raw = DB::table('listings')->where('id', $listing->id)->first();

        $this->assertSame('pending_approval', $raw->lifecycle_status,
            'Yanıtta olmayan satır REDDEDİLMİŞ sayılmaz.');
        $this->assertNull($raw->approval_rejection_reason);
        $this->assertNull($raw->approval_checked_at,
            'Dokunulmayan satır damgalanmaz da: damgalansaydı "kontrol edildi" görünürdü.');
        $this->assertSame(0, $result->rejected);
    }

    /**
     * ENGELLENMİŞ LISTING ONAY TAKİBİNE GİRMEZ.
     *
     * `blocked` satır kanala hiç gitmedi; onayını sormak anlamsızdır ve
     * yanıtta bulunmadığı için durumu da değişmemeli.
     */
    #[Test]
    public function blocked_listings_are_not_tracked(): void
    {
        Http::fake(['*' => Http::response(['content' => []], 200)]);

        [$tenant] = $this->makeTenant();
        $connection = $this->connection($tenant);

        $listing = $this->asTenant($tenant, fn () => $this->listing(
            $tenant, $connection, 'SKU-1', externalId: null, lifecycle: 'blocked',
        ));

        $this->asTenant($tenant, fn () => app(TrackApprovalStatus::class)->run($connection));

        $raw = DB::table('listings')->where('id', $listing->id)->first();

        $this->assertSame('blocked', $raw->lifecycle_status);

        Http::assertNothingSent();
    }

    /**
     * TAKSONOMİSİZ KANALDA ONAY TAKİBİ ÇALIŞMAZ.
     *
     * Woo `SupportsApprovalWorkflow` uygulamaz; orada onay süreci yoktur
     * ve ürün gönderilir gönderilmez yayına girer.
     */
    #[Test]
    public function a_channel_without_approval_workflow_is_skipped(): void
    {
        Http::fake();

        [$tenant] = $this->makeTenant();
        $connection = $this->connection($tenant, 'woocommerce');

        $result = $this->asTenant($tenant, fn () => app(TrackApprovalStatus::class)->run($connection));

        $this->assertFalse($result->supported);

        Http::assertNothingSent();
    }

    /**
     * ONAY DURUMU STOK AKIŞINA DOKUNMAZ.
     *
     * §14'ün ana hedefi: onay süreci yalnızca `lifecycle_status`
     * yönetir; hareket, bakiye veya outbox olayı üretmez.
     */
    #[Test]
    public function approval_tracking_does_not_touch_the_stock_flow(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['barcode' => 'SKU-1', 'approved' => true, 'onSale' => true]],
        ], 200)]);

        [$tenant] = $this->makeTenant();
        $connection = $this->connection($tenant);

        $this->asTenant($tenant, fn () => $this->listing(
            $tenant, $connection, 'SKU-1', externalId: 'SKU-1', lifecycle: 'pending_approval',
        ));

        $before = $this->asSystem(fn (): array => [
            'movements' => DB::table('inventory_movements')->count(),
            'levels' => DB::table('inventory_levels')->count(),
            'outbox' => DB::table('outbox_events')->count(),
        ]);

        $this->asTenant($tenant, fn () => app(TrackApprovalStatus::class)->run($connection));

        $after = $this->asSystem(fn (): array => [
            'movements' => DB::table('inventory_movements')->count(),
            'levels' => DB::table('inventory_levels')->count(),
            'outbox' => DB::table('outbox_events')->count(),
        ]);

        $this->assertSame($before, $after);
    }

    /**
     * BAŞKA KİRACININ LISTING'İ BU TURA GİRMEZ.
     *
     * Tur bağlantı başınadır ve bağlantı kiracıya aittir; yine de sorgu
     * kiracı scope'u altında çalışmalıdır.
     */
    #[Test]
    public function another_tenants_listing_is_not_included(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['barcode' => 'SKU-B', 'approved' => true, 'onSale' => true]],
        ], 200)]);

        [$tenantA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();

        $connectionA = $this->connection($tenantA);
        $connectionB = $this->connection($tenantB, 'trendyol', account: 'supplier-b');

        $listingB = $this->asTenant($tenantB, fn () => $this->listing(
            $tenantB, $connectionB, 'SKU-B', externalId: 'SKU-B', lifecycle: 'pending_approval',
        ));

        $this->asTenant($tenantA, fn () => app(TrackApprovalStatus::class)->run($connectionA));

        $raw = DB::table('listings')->where('id', $listingB->id)->first();

        $this->assertSame('pending_approval', $raw->lifecycle_status,
            'A kiracısının turu B kiracısının satırını değiştirmemeli.');
    }

    // ═══════════════════════════════════════════════ komut ve zamanlama

    /**
     * Komut kayıtlı — kayıt olmadan zamanlayıcı onu bulamaz.
     *
     * Domain komutları OTOMATİK KEŞFEDİLMEZ; `bootstrap/app.php` içinde
     * açıkça kaydedilir. Bu boşluk projede daha önce `inbox:recover` ile
     * yaşandı: komut kusursuzdu, testleri yeşildi ve hiç çalışmıyordu.
     */
    #[Test]
    public function approval_command_is_registered(): void
    {
        $this->assertArrayHasKey('approval:track', Artisan::all());
    }

    /**
     * Komut zamanlanmış — kayıt ve zamanlama İKİ AYRI koşuldur.
     *
     * Saatlik: Trendyol'un onay süreci saatler sürer ve dakikalık yoklama
     * kotayı tüketip hiçbir şey kazandırmaz.
     */
    #[Test]
    public function approval_tracking_is_scheduled_hourly(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'approval:track'));

        $this->assertCount(1, $events, 'approval:track zamanlanmalı.');
        $this->assertSame('0 * * * *', $events->first()->expression, 'Saatlik olmalı.');
    }

    // ───────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(): array
    {
        $user = User::factory()->create();

        $tenant = (new CreateTenant)->run(name: 'Onay '.uniqid(), owner: $user);

        return [$tenant, $user];
    }

    private function adapter(ChannelConnection $connection): TrendyolAdapter
    {
        // Bağlam DIŞINDA kurulabilmeli: kimlik bilgisi runAsSystem ile okunur.
        $connection->loadMissing('channelType');

        return new TrendyolAdapter(
            connection: $connection,
            client: new ChannelHttpClient(
                connection: $connection,
                vault: app(CredentialVault::class),
                redactor: app(PayloadRedactor::class),
            ),
        );
    }

    private function connection(
        Tenant $tenant,
        string $channelTypeCode = 'trendyol',
        string $account = 'supplier-a',
    ): ChannelConnection {
        $adapter = $channelTypeCode === 'trendyol'
            ? TrendyolAdapter::class
            : WooCommerceAdapter::class;

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

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_type_code' => $channelTypeCode,
            'external_account_id' => $account,
            'status' => 'active',
            'health_status' => 'healthy',
            'settings' => ['supplier_id' => '12345', 'base_url' => 'https://api.trendyol.com'],
        ]));

        TenantContext::runAsSystem(fn () => app(CredentialVault::class)->store(
            $connection,
            ['api_key' => 'k', 'api_secret' => 's'],
        ));

        return $connection->fresh(['channelType']);
    }

    private function listing(
        Tenant $tenant,
        ChannelConnection $connection,
        string $sku,
        ?string $externalId,
        string $lifecycle = 'draft',
        ?string $rejectionReason = null,
    ): Listing {
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'sku' => $sku,
        ]);

        $variant = Variant::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'sku' => $sku,
        ]);

        return Listing::query()->create([
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'external_id' => $externalId,
            'lifecycle_status' => $lifecycle,
            'approval_rejection_reason' => $rejectionReason,
        ]);
    }
}
