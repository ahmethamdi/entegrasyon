<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Onay durumu ekranı — "kaç ürünüm onay bekliyor, hangileri reddedildi".
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 4 (onay durumu ekranı),
 * §14 · onay süreci, §7 · SupportsApprovalWorkflow.
 *
 * EKRANIN VARLIK SEBEBİ — TOPLU GÖRÜNÜM:
 *   Rozet ve red sebebi ürün-kanal ekranında ZATEN VARDI ama orası TEK
 *   ÜRÜN için "hangi kanallarda ne durumda" sorusunu cevaplıyor. Yüz ürün
 *   gönderen satıcı, reddedilen üçünü bulmak için yüz ürünün kanal
 *   sekmesini tek tek açmak zorundaydı: red sebebi KAYITLIYDI ve pratikte
 *   GÖRÜNMEZDİ.
 *
 * DEĞİŞMEZ KURAL — ONAY SÜRECİ OLMAYAN KANAL BU EKRANDA GÖRÜNMEZ (§7):
 *   Woo `SupportsApprovalWorkflow` uygulamaz ve orada ürün gönderilir
 *   gönderilmez yayına girer. Yetenek `instanceof` ile okunur.
 *
 * DEĞİŞMEZ KURAL — REDDEDİLEN EN ÜSTTE:
 *   `rejected` kullanıcı müdahalesi bekler ve kendiliğinden düzelmez;
 *   `pending_approval` bir bekleme durumudur. Aynı kefeye konsalardı
 *   satıcı "sistem hallediyor" sanır ve tam olarak kendisini bekleyen
 *   satırı hiç görmezdi.
 */
final class ApprovalScreenTest extends TestCase
{
    use RefreshDatabase;

    // ───────────────────────────────────────────────────────────── erişim

    #[Test]
    public function guest_cannot_reach_the_approval_screen(): void
    {
        $this->get('/approvals')->assertRedirect('/login');
    }

    /**
     * BAŞKA KİRACININ ONAY SATIRI GÖRÜNMEZ.
     *
     * Satır ürün başlığı ve SKU taşır; sızıntı rakip satıcının katalogunu
     * ifşa ederdi.
     */
    #[Test]
    public function approvals_never_leak_across_tenants(): void
    {
        [$tenantA, $userA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();

        $this->pendingListing($tenantA, sku: 'BENIM-1');
        $this->pendingListing($tenantB, sku: 'BASKASININ-1');

        $rows = $this->rows($this->actingAs($userA)->get('/approvals'));

        $this->assertCount(1, $rows);
        $this->assertSame('BENIM-1', $rows[0]['sku']);
    }

    /** Özet sayıları da kiracıya kapsanır — AYRI sorgu, AYRI boşluk. */
    #[Test]
    public function the_summary_never_leaks_across_tenants(): void
    {
        [$tenantA, $userA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();

        $this->rejectedListing($tenantA, sku: 'BENIM-1', reason: 'Görsel çözünürlüğü yetersiz');
        $this->rejectedListing($tenantB, sku: 'BASKASININ-1', reason: 'x');

        $summary = $this->summary($this->actingAs($userA)->get('/approvals'));

        $this->assertSame(1, $summary['rejected']);
    }

    // ──────────────────────────────────────────────────────────── içerik

    /**
     * RED SEBEBİ ADIYLA GÖSTERİLİR.
     *
     * "Reddedildi" demek satıcıya ne yapacağını SÖYLEMEZ; sebep zaten
     * kayıtlıdır (§14) ve gösterilmemesi onu ölü veri yapardı.
     */
    #[Test]
    public function a_rejected_row_carries_its_reason(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->rejectedListing($tenant, sku: 'ELB-1', reason: 'Marka bilgisi eksik');

        $rows = $this->rows($this->actingAs($user)->get('/approvals'));

        $this->assertSame('rejected', $rows[0]['status']);
        $this->assertSame('Marka bilgisi eksik', $rows[0]['reason']);
        $this->assertSame('ELB-1', $rows[0]['sku']);
    }

    /**
     * REDDEDİLEN EN ÜSTTE — sıralama bir EYLEM sırasıdır.
     */
    #[Test]
    public function rejected_rows_are_listed_first(): void
    {
        [$tenant, $user] = $this->makeTenant();

        // Bekleyen ÖNCE yaratılır: sıralama yaratılış sırasına düşseydi
        // test kendi kurulumu sayesinde yanlışlıkla yeşil kalırdı.
        $this->pendingListing($tenant, sku: 'BEKLEYEN');
        $this->rejectedListing($tenant, sku: 'RED', reason: 'sebep');

        $rows = $this->rows($this->actingAs($user)->get('/approvals'));

        $this->assertSame('RED', $rows[0]['sku']);
        $this->assertSame('BEKLEYEN', $rows[1]['sku']);
    }

    /**
     * ÖZET İKİ DURUMU AYRI SAYAR — iki farklı eylem.
     */
    #[Test]
    public function the_summary_separates_rejected_from_pending(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->rejectedListing($tenant, sku: 'RED-1', reason: 'x');
        $this->pendingListing($tenant, sku: 'BEK-1');
        $this->pendingListing($tenant, sku: 'BEK-2');

        $summary = $this->summary($this->actingAs($user)->get('/approvals'));

        $this->assertSame(1, $summary['rejected']);
        $this->assertSame(2, $summary['pending']);
    }

    /**
     * CANLI VE TASLAK SATIRLAR BU EKRANDA YOKTUR.
     *
     * `live` zaten yayında ve onay beklemiyor; `draft`/`blocked` kanala
     * HİÇ gitmedi. Listelenselerdi ekran "onay bekleyenler" değil "tüm
     * listeler" olurdu ve gerçek bekleyenler kaybolurdu.
     */
    #[Test]
    public function live_and_draft_listings_are_not_listed(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $connection = $this->approvalConnection($tenant);

        $this->listing($tenant, $connection, sku: 'CANLI', lifecycle: 'live');
        $this->listing($tenant, $connection, sku: 'TASLAK', lifecycle: 'draft');
        $this->listing($tenant, $connection, sku: 'BLOKE', lifecycle: 'blocked');
        $this->listing($tenant, $connection, sku: 'BEKLEYEN', lifecycle: 'pending_approval');

        $rows = $this->rows($this->actingAs($user)->get('/approvals'));

        $this->assertCount(1, $rows);
        $this->assertSame('BEKLEYEN', $rows[0]['sku']);
    }

    // ──────────────────────────────────────────────────────── yetenek

    /**
     * ONAY SÜRECİ OLMAYAN KANALIN SATIRLARI GÖRÜNMEZ (§7).
     *
     * Woo `SupportsApprovalWorkflow` uygulamaz. Bir Woo listing'i
     * `pending_approval` durumuna elle sokulsa bile bu ekranda YER
     * ALMAMALIDIR: o kanalda "onay bekleme" diye bir hâl yoktur ve satıcı
     * hiç gelmeyecek bir onayı beklerdi.
     */
    #[Test]
    public function listings_of_a_channel_without_an_approval_workflow_are_excluded(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $woo = $this->wooConnection($tenant);

        $this->listing($tenant, $woo, sku: 'WOO-1', lifecycle: 'pending_approval');

        $response = $this->actingAs($user)->get('/approvals');

        $this->assertSame([], $this->rows($response));
        $this->assertSame(0, $this->summary($response)['pending']);
    }

    /**
     * ONAY SÜRECİ OLAN KANAL YOKSA EKRAN BUNU AÇIKÇA SÖYLER.
     *
     * Boş tablo göstermek satıcıya "onay bekleyen ürünüm yok" dedirtirdi;
     * doğru cevap "bu kanalda onay süreci yok"tur ve ikisi TAMAMEN FARKLI
     * şeylerdir.
     */
    #[Test]
    public function the_screen_says_when_no_channel_has_an_approval_workflow(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->wooConnection($tenant);

        $response = $this->actingAs($user)->get('/approvals');

        $this->assertFalse($this->props($response)['hasApprovalChannels']);
    }

    /** Hiç bağlantısı olmayan kiracıda ekran patlamaz. */
    #[Test]
    public function the_screen_survives_a_tenant_with_no_connections(): void
    {
        [, $user] = $this->makeTenant();

        $response = $this->actingAs($user)->get('/approvals');

        $response->assertOk();
        $this->assertFalse($this->props($response)['hasApprovalChannels']);
        $this->assertSame([], $this->rows($response));
    }

    /**
     * "SON KONTROL" DAMGASI SATIRLARLA AYNI BİÇİMDE GÖNDERİLİR.
     *
     * `DB::max()` ham kolon metni döndürür (`"2026-08-24 14:31:09"`) ve
     * tarayıcı onu YEREL saat sanar; satırlar ise `toIso8601String()`
     * kullanır. İkisi karışınca AYNI AN ekranda iki farklı saat olarak
     * görünür — GERÇEK TARAYICI ÇALIŞTIRMASINDA iki saatlik fark ölçüldü
     * (üstte 14:31, satırda 16:31).
     */
    #[Test]
    public function the_last_checked_stamp_is_sent_in_the_same_format_as_the_rows(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $listing = $this->pendingListing($tenant, sku: 'BEK-1');

        $this->asTenant($tenant, fn () => $listing->forceFill([
            'approval_checked_at' => now(),
        ])->save());

        $props = $this->props($this->actingAs($user)->get('/approvals'));

        $this->assertNotNull($props['lastCheckedAt']);
        $this->assertSame(
            $props['rows'][0]['checkedAt'],
            $props['lastCheckedAt'],
            'Üst damga ile satır damgası AYNI biçimde gönderilmeli; '.
            'aksi halde aynı an iki farklı saat olarak görünür.',
        );
    }

    // ──────────────────────────────────────────────────────── filtreler

    /** Durum filtresi listeyi daraltır. */
    #[Test]
    public function the_status_filter_narrows_the_list(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->rejectedListing($tenant, sku: 'RED-1', reason: 'x');
        $this->pendingListing($tenant, sku: 'BEK-1');

        $rows = $this->rows($this->actingAs($user)->get('/approvals?status=rejected'));

        $this->assertCount(1, $rows);
        $this->assertSame('RED-1', $rows[0]['sku']);
    }

    /**
     * ÖZET FİLTREDEN ETKİLENMEZ.
     *
     * Kullanıcı "yalnızca reddedilenler" filtresini açtığında bekleyen
     * sayısının sıfıra düşmesi, o ürünlerin kaybolduğu izlenimini verirdi.
     */
    #[Test]
    public function the_summary_ignores_the_active_filter(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->rejectedListing($tenant, sku: 'RED-1', reason: 'x');
        $this->pendingListing($tenant, sku: 'BEK-1');

        $summary = $this->summary($this->actingAs($user)->get('/approvals?status=rejected'));

        $this->assertSame(1, $summary['rejected']);
        $this->assertSame(1, $summary['pending'], 'Özet filtreden BAĞIMSIZ olmalı.');
    }

    /**
     * UYDURMA BAĞLANTI FİLTRESİ YOK SAYILIR, LİSTEYİ BOŞALTMAZ.
     *
     * Doğrulanmasaydı adres çubuğuna yazılan rastgele bir kimlik sorguyu
     * hiç eşleşmeyen bir bağlantıya çevirir ve ekran sebebini
     * söyleyemeden boş kalırdı.
     */
    #[Test]
    public function an_unknown_connection_filter_is_ignored(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->pendingListing($tenant, sku: 'BEK-1');

        $rows = $this->rows($this->actingAs($user)->get('/approvals?connection='.fake()->uuid()));

        $this->assertCount(1, $rows, 'Tanınmayan filtre YOK SAYILMALI.');
    }

    // ─────────────────────────────────────────────────────── yardımcı

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(): array
    {
        $user = User::factory()->create();

        return [(new CreateTenant)->run(name: 'Onay '.uniqid(), owner: $user), $user];
    }

    private function approvalConnection(Tenant $tenant): ChannelConnection
    {
        return $this->connection($tenant, 'trendyol', TrendyolAdapter::class);
    }

    private function wooConnection(Tenant $tenant): ChannelConnection
    {
        return $this->connection($tenant, 'woocommerce', WooCommerceAdapter::class);
    }

    private function connection(Tenant $tenant, string $code, string $adapter): ChannelConnection
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => ucfirst($code),
                'kind' => 'marketplace',
                'adapter_class' => $adapter,
                'is_active' => true,
            ],
        ));

        return $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'channel_type_code' => $code,
            'status' => 'active',
            'health_status' => 'healthy',
            'settings' => ['supplier_id' => '12345', 'base_url' => 'https://api.trendyol.com'],
        ]));
    }

    private function pendingListing(Tenant $tenant, string $sku): Listing
    {
        return $this->listing($tenant, $this->approvalConnection($tenant), $sku, 'pending_approval');
    }

    private function rejectedListing(Tenant $tenant, string $sku, string $reason): Listing
    {
        return $this->listing(
            $tenant,
            $this->approvalConnection($tenant),
            $sku,
            'rejected',
            $reason,
        );
    }

    private function listing(
        Tenant $tenant,
        ChannelConnection $connection,
        string $sku,
        string $lifecycle,
        ?string $reason = null,
    ): Listing {
        return $this->asTenant($tenant, function () use ($connection, $sku, $lifecycle, $reason): Listing {
            $product = Product::factory()->create(['title' => 'Ürün '.$sku]);
            $variant = Variant::factory()->create([
                'product_id' => $product->id,
                'sku' => $sku,
            ]);

            return Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => $variant->id,
                'external_id' => 'EXT-'.$sku,
                'lifecycle_status' => $lifecycle,
                'approval_rejection_reason' => $reason,
            ]);
        });
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
