<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\SyncOperation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Onboarding ilerlemesi — S11'den beri beş adım (kanal → ürün → stok →
 * fatura (isteğe bağlı) → ilk sipariş).
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 4 · "Onboarding: kayıt → kanal
 * bağla → ürün aktar → ilk senkron — 20 sa".
 *
 * Doküman adımları SAYAR ama nasıl saklanacağını söylemez; karar burada
 * alınır ve gerekçesi aşağıdadır.
 *
 * DEĞİŞMEZ KURAL — İLERLEME SAKLANMAZ, TÜRETİLİR:
 *   `tenants` tablosunda onboarding kolonu YOKTUR ve §4 de tanımlamaz.
 *   Ayrı kolon (`onboarding_step`) veya tablo açmak bu projenin iki
 *   yerleşik kararına aykırıdır: `is_dirty` generated column'dır ve
 *   `DriftHistory` sayacı ayrı kolonda tutmaz. Gerekçe birebir aynı —
 *   ayrı sayaç, adımı bitiren HER yolun onu da güncellemesini zorunlu
 *   kılar; biri unutulunca iki gerçek kaynağı SESSİZCE ayrışır.
 *
 *   Burada tuzak daha keskin: adım "bitti" diye damgalanıp veri sonradan
 *   giderse (bağlantı silinir, ürün silinir) kayıtlı ilerleme YALAN
 *   söyler. Türetilmiş ilerleme yalan söyleyemez.
 *
 * DEĞİŞMEZ KURAL — KANAL ADIMI `active` İSTER, VARLIK YETMEZ:
 *   "Sağlık kontrolü geçmeden bağlantı `active` olmaz" (§13 · faz 1.4).
 *   `pending` bir bağlantıyı bitmiş saymak kullanıcıyı ÇALIŞMAYAN bir
 *   kanala ürün göndermeye davet eder ve hepsi `AUTHENTICATION` ile
 *   KALICI hataya düşer — "aktif ama çalışmayan bağlantı en pahalı hata
 *   biçimidir".
 *
 * DEĞİŞMEZ KURAL — SENKRON ADIMI `completed` İSTER, AÇILMA YETMEZ:
 *   Operasyonun AÇILMASI bir şey kanıtlamaz. `pending` kuyrukta
 *   bekliyordur, `dead` ise tam olarak BAŞARISIZ olmuştur. İkisini de
 *   "ilk senkron tamam" saymak, ürünün temel iddiasının çalışmadığı anda
 *   kullanıcıya "kurulum bitti" demektir.
 */
final class OnboardingProgressTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- adımlar

    /**
     * YENİ KİRACIDA HİÇBİR ADIM BİTMEMİŞTİR — S11 sırasıyla beş adım.
     *
     * "Kayıt" adımı S11'de düştü: paneldeki kullanıcı için tanım gereği
     * bitmiştir ve bilgi taşımıyordu.
     */
    #[Test]
    public function a_fresh_tenant_has_five_open_steps_in_order(): void
    {
        [, $user] = $this->makeTenant();

        $onboarding = $this->onboarding($this->actingAs($user)->get('/panel'));

        $this->assertSame(
            ['channel' => false, 'product' => false, 'stock' => false, 'invoice' => false, 'order' => false],
            $onboarding['steps'],
        );
        $this->assertSame(['invoice'], $onboarding['optional']);
        $this->assertSame('channel', $onboarding['next']);
    }

    /** Aktif bağlantı kanal adımını kapatır. */
    #[Test]
    public function an_active_connection_completes_the_channel_step(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->connectionFor($tenant, status: 'active');

        $steps = $this->steps($this->actingAs($user)->get('/panel'));

        $this->assertTrue($steps['channel'], 'Aktif bağlantı kanal adımını kapatmalı.');
    }

    /**
     * SAĞLIKSIZ / BEKLEYEN BAĞLANTI KANAL ADIMINI KAPATMAZ.
     *
     * Bu maddenin en pahalı hatası: `pending` bağlantı kanalla HİÇ
     * konuşamamıştır. Adım kapatılsaydı kullanıcı ürün göndermeye başlar
     * ve hepsi kalıcı hataya düşerdi.
     */
    #[Test]
    public function a_pending_connection_does_not_complete_the_channel_step(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->connectionFor($tenant, status: 'pending');

        $steps = $this->steps($this->actingAs($user)->get('/panel'));

        $this->assertFalse(
            $steps['channel'],
            'Sağlık kontrolünden geçmemiş bağlantı adımı kapatmamalı.',
        );
    }

    /** Ürün adımı en az bir ürünle kapanır. */
    #[Test]
    public function a_product_completes_the_product_step(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->productFor($tenant);

        $steps = $this->steps($this->actingAs($user)->get('/panel'));

        $this->assertTrue($steps['product'], 'Ürün varsa ürün adımı kapanmalı.');
    }

    /** Tamamlanmış stok gönderimi stok adımını kapatır. */
    #[Test]
    public function a_completed_operation_completes_the_stock_step(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $connection = $this->connectionFor($tenant, status: 'active');

        $this->operationFor($tenant, $connection, SyncOperationStatus::COMPLETED);

        $steps = $this->steps($this->actingAs($user)->get('/panel'));

        $this->assertTrue($steps['stock'], 'Tamamlanan stok gönderimi stok adımını kapatmalı.');
    }

    /**
     * BEKLEYEN OPERASYON SENKRON ADIMINI KAPATMAZ.
     *
     * Operasyon açıldı demek kanala ULAŞTI demek değildir; iş kuyrukta
     * bekliyor olabilir.
     */
    #[Test]
    public function a_pending_operation_does_not_complete_the_stock_step(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $connection = $this->connectionFor($tenant, status: 'active');

        $this->operationFor($tenant, $connection, SyncOperationStatus::PENDING);

        $steps = $this->steps($this->actingAs($user)->get('/panel'));

        $this->assertFalse($steps['stock'], 'Bekleyen operasyon stok adımını kapatmamalı.');
    }

    /**
     * ÖLÜ OPERASYON SENKRON ADIMINI KAPATMAZ — TAM TERSİNİ KANITLAR.
     *
     * `dead` operasyon "denendi ve BAŞARISIZ oldu" demektir. Kapatılsaydı
     * kullanıcıya, senkronun çalışmadığı anda "kurulum tamam" denirdi.
     */
    #[Test]
    public function a_dead_operation_does_not_complete_the_stock_step(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $connection = $this->connectionFor($tenant, status: 'active');

        $this->operationFor($tenant, $connection, SyncOperationStatus::DEAD);

        $steps = $this->steps($this->actingAs($user)->get('/panel'));

        $this->assertFalse($steps['stock'], 'Ölü operasyon stok adımını kapatmamalı.');
    }

    /**
     * İÇERİK GÖNDERİMİ STOK ADIMINI KAPATMAZ.
     *
     * Ürün kanalda açılmış ama stoğu hiç gitmemiş olabilir; ürünün temel
     * iddiası stoktur.
     */
    #[Test]
    public function a_completed_content_push_does_not_complete_the_stock_step(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $connection = $this->connectionFor($tenant, status: 'active');

        $this->operationFor($tenant, $connection, SyncOperationStatus::COMPLETED, type: 'CONTENT_PUSH');

        $this->assertFalse($this->steps($this->actingAs($user)->get('/panel'))['stock']);
    }

    /** İlk sipariş son adımı kapatır — kartın "bitti" ölçütü. */
    #[Test]
    public function an_order_completes_the_order_step(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->orderFor($tenant, $this->connectionFor($tenant, status: 'active'));

        $this->assertTrue($this->steps($this->actingAs($user)->get('/panel'))['order']);
    }

    /**
     * FATURA ADIMI ANCAK KULLANILABİLİR HESAPLA KAPANIR — firma seçilmemiş
     * Paraşüt bağlantısı fatura kesemez.
     */
    #[Test]
    public function only_a_usable_invoice_account_completes_the_invoice_step(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->invoiceAccountFor($tenant, companyId: null);
        $this->assertFalse($this->steps($this->actingAs($user)->get('/panel'))['invoice']);

        $this->invoiceAccountFor($tenant, companyId: '12345');
        $this->assertTrue($this->steps($this->actingAs($user)->get('/panel'))['invoice']);
    }

    // ---------------------------------------------------------------- görünürlük

    /**
     * Zorunlu adımlar bitince şerit KAYBOLUR — kullanıcının kararı budur.
     * İsteğe bağlı fatura adımı açık kalsa bile.
     */
    #[Test]
    public function the_banner_disappears_once_every_required_step_is_done(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $connection = $this->connectionFor($tenant, status: 'active');
        $this->productFor($tenant);
        $this->operationFor($tenant, $connection, SyncOperationStatus::COMPLETED);
        $this->orderFor($tenant, $connection);

        $onboarding = $this->onboarding($this->actingAs($user)->get('/panel'));

        $this->assertFalse($onboarding['steps']['invoice']);
        $this->assertFalse(
            $onboarding['visible'],
            'Zorunlu adımlar bitince şerit görünmemeli; fatura isteğe bağlı.',
        );
        $this->assertNull($onboarding['next']);
    }

    /**
     * İSTEĞE BAĞLI ADIM "SIRADAKİ" OLMAZ — fatura kesmeyen satıcı ilk
     * siparişe yönlendirilir.
     */
    #[Test]
    public function the_optional_step_is_never_the_next_step(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $connection = $this->connectionFor($tenant, status: 'active');
        $this->productFor($tenant);
        $this->operationFor($tenant, $connection, SyncOperationStatus::COMPLETED);

        $this->assertSame('order', $this->onboarding($this->actingAs($user)->get('/panel'))['next']);
    }

    /** Tek adım bile eksikse şerit görünür. */
    #[Test]
    public function the_banner_stays_visible_while_a_step_is_missing(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->connectionFor($tenant, status: 'active');
        $this->productFor($tenant);
        // İlk senkron YOK.

        $onboarding = $this->onboarding($this->actingAs($user)->get('/panel'));

        $this->assertTrue($onboarding['visible'], 'Eksik adım varken şerit görünmeli.');
    }

    /**
     * ŞERİT VERİ SİLİNİNCE GERİ GELİR — türetilmiş durumun doğal sonucu
     * ve KASITLI davranış. Kiracının gerçekten kanalı yoktur.
     */
    #[Test]
    public function the_banner_returns_when_the_underlying_data_goes_away(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $connection = $this->connectionFor($tenant, status: 'active');
        $this->productFor($tenant);
        $this->operationFor($tenant, $connection, SyncOperationStatus::COMPLETED);
        $this->orderFor($tenant, $connection);

        $this->assertFalse($this->onboarding($this->actingAs($user)->get('/panel'))['visible']);

        // Bağlantı sağlıksızlığa düşüyor (§13 · faz 1.4: `active`'ten geri çekilir).
        TenantContext::runAsSystem(function () use ($connection): void {
            ChannelConnection::withoutGlobalScopes()
                ->whereKey($connection->id)
                ->update(['status' => 'error']);
        });

        $onboarding = $this->onboarding($this->actingAs($user)->get('/panel'));

        $this->assertTrue(
            $onboarding['visible'],
            'Bağlantı aktiflikten çıkınca şerit geri gelmeli.',
        );
    }

    /** Sıradaki adım açıkça bildirilir — kullanıcı nereye gideceğini bilmeli. */
    #[Test]
    public function the_next_step_is_named(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->connectionFor($tenant, status: 'active');

        $onboarding = $this->onboarding($this->actingAs($user)->get('/panel'));

        $this->assertSame(
            'product',
            $onboarding['next'],
            'Kanal bitmişse sıradaki adım ürün olmalı.',
        );
    }

    // ---------------------------------------------------------------- izolasyon

    /**
     * BAŞKA KİRACININ VERİSİ ADIMI KAPATMAZ.
     *
     * Kapatsaydı yeni kiracı, hiç kanal bağlamadan "kanal bağlandı"
     * görürdü ve onboarding hiçbir işe yaramazdı.
     */
    #[Test]
    public function progress_never_leaks_across_tenants(): void
    {
        [, $userA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();

        $connectionB = $this->connectionFor($tenantB, status: 'active');
        $this->productFor($tenantB);
        $this->operationFor($tenantB, $connectionB, SyncOperationStatus::COMPLETED);
        $this->orderFor($tenantB, $connectionB);
        $this->invoiceAccountFor($tenantB, companyId: '777');

        $steps = $this->steps($this->actingAs($userA)->get('/panel'));

        $this->assertSame(
            ['channel' => false, 'product' => false, 'stock' => false, 'invoice' => false, 'order' => false],
            $steps,
            'Başka kiracının verisi adımları kapatmamalı.',
        );
    }

    /** İlerleme HER panel ekranında paylaşılır, yalnızca özet ekranında değil. */
    #[Test]
    public function progress_is_shared_on_every_panel_screen(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->connectionFor($tenant, status: 'active');

        foreach (['/products', '/orders', '/channels'] as $path) {
            $onboarding = $this->onboarding($this->actingAs($user)->get($path));

            $this->assertTrue(
                $onboarding['visible'],
                "Şerit {$path} ekranında da paylaşılmalı.",
            );
        }
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(): array
    {
        $user = User::factory()->create();

        $tenant = (new CreateTenant)->run(name: 'Test Şirket', owner: $user);

        return [$tenant, $user];
    }

    private function connectionFor(Tenant $tenant, string $status): ChannelConnection
    {
        TenantContext::runAsSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'marketplace',
                'adapter_class' => WooCommerceAdapter::class,
                'is_active' => true,
            ],
        ));

        return TenantContext::runAsSystem(fn (): ChannelConnection => ChannelConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => $status,
            'health_status' => $status === 'active' ? 'healthy' : 'unknown',
        ]));
    }

    private function productFor(Tenant $tenant): Product
    {
        return TenantContext::runAsSystem(fn (): Product => Product::factory()->create([
            'tenant_id' => $tenant->id,
        ]));
    }

    private function operationFor(
        Tenant $tenant,
        ChannelConnection $connection,
        SyncOperationStatus $status,
        string $type = 'INVENTORY_PUSH',
    ): SyncOperation {
        return TenantContext::runAsSystem(fn (): SyncOperation => SyncOperation::create([
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $connection->id,
            'operation_type' => $type,
            'intent' => SyncIntent::NORMAL_SYNC,
            'entity_type' => 'listing',
            'entity_id' => Str::uuid7()->toString(),
            'entity_version' => 1,
            'idempotency_key' => 'onboarding-test-'.Str::random(12),
            'status' => $status,
            'attempt_count' => $status === SyncOperationStatus::COMPLETED ? 1 : 0,
            'priority' => 100,
        ]));
    }

    private function orderFor(Tenant $tenant, ChannelConnection $connection): void
    {
        DB::table('orders')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $connection->id,
            'external_id' => 'onboarding-'.Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function invoiceAccountFor(Tenant $tenant, ?string $companyId): void
    {
        TenantContext::runAsSystem(fn () => InvoiceAccount::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'provider' => 'parasut'],
            ['status' => InvoiceAccount::STATUS_CONNECTED, 'company_id' => $companyId, 'credentials' => ['access_token' => 'x']],
        ));
    }

    // ------------------------------------------------ paylaşılan kiracı prop'u

    /**
     * KİRACI ADI ÖZET DIŞINDAKİ EKRANLARDA DA PAYLAŞILIR.
     *
     * GERÇEK ÇALIŞTIRMADA BULUNDU: başlıktaki kiracı adı YALNIZCA özet
     * ekranında görünüyordu, diğer on iki ekranda BOŞTU.
     *
     * Sebep `onboarding` prop'unun kapanışla çözdüğü tuzağın AYNISIDIR:
     * `share()` `web` grubunda çalışır, `tenant` ara katmanı ise ROTA
     * seviyesinde — yani `share()` bağlam kurulmadan ÖNCE çağrılır ve
     * kapanış DIŞINDA okunan `$request->attributes->get('tenant')` her
     * zaman null döner.
     *
     * ÖZET EKRANI BU HATAYI MASKELİYORDU: `DashboardController` kendi
     * `tenant` prop'unu gönderiyor ve paylaşılanı EZİYOR. Bu yüzden test
     * ÖZET DIŞINDA bir ekranda koşmak ZORUNDADIR — `/` üzerinde yazılsaydı
     * paylaşılan prop hiç okunmaz ve test hatayı GÖREMEZDİ.
     */
    #[Test]
    public function the_tenant_name_is_shared_on_screens_other_than_the_dashboard(): void
    {
        [, $user] = $this->makeTenant();

        $response = $this->actingAs($user)->get('/channels');
        $response->assertOk();

        $tenant = $response->viewData('page')['props']['tenant'];

        $this->assertNotNull($tenant, 'Kiracı prop\'u özet dışındaki ekranlarda da dolu olmalı.');
        $this->assertSame(
            'Test Şirket',
            $tenant['name'],
            'Başlıkta gösterilen kiracı adı her panel ekranında paylaşılmalı.',
        );
    }

    /** Kiracı bağlamı olmayan ekranda prop null kalır — istisna fırlatmaz. */
    #[Test]
    public function the_tenant_prop_is_null_without_tenant_context(): void
    {
        $response = $this->get('/login');
        $response->assertOk();

        $this->assertNull(
            $response->viewData('page')['props']['tenant'],
            'Kiracısız ekranda kiracı prop\'u null olmalı.',
        );
    }

    /** @return array<string, bool> */
    private function steps(TestResponse $response): array
    {
        return $this->onboarding($response)['steps'];
    }

    /** @return array<string, mixed> */
    private function onboarding(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props']['onboarding'];
    }
}
