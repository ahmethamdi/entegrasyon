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
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\SyncOperation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Onboarding ilerlemesi — dokümanın dört adımı.
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
     * YENİ KİRACIDA YALNIZCA KAYIT ADIMI BİTMİŞTİR.
     *
     * Kullanıcı paneldeyse kiracısı vardır — o adım tanım gereği kapalı.
     */
    #[Test]
    public function a_fresh_tenant_has_only_the_registration_step_done(): void
    {
        [, $user] = $this->makeTenant();

        $steps = $this->steps($this->actingAs($user)->get('/'));

        $this->assertSame(
            ['account' => true, 'channel' => false, 'product' => false, 'sync' => false],
            $steps,
            'Yeni kiracıda yalnızca kayıt adımı bitmiş olmalı.',
        );
    }

    /** Aktif bağlantı kanal adımını kapatır. */
    #[Test]
    public function an_active_connection_completes_the_channel_step(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->connectionFor($tenant, status: 'active');

        $steps = $this->steps($this->actingAs($user)->get('/'));

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

        $steps = $this->steps($this->actingAs($user)->get('/'));

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

        $steps = $this->steps($this->actingAs($user)->get('/'));

        $this->assertTrue($steps['product'], 'Ürün varsa ürün adımı kapanmalı.');
    }

    /** Tamamlanmış senkron operasyonu son adımı kapatır. */
    #[Test]
    public function a_completed_operation_completes_the_sync_step(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $connection = $this->connectionFor($tenant, status: 'active');

        $this->operationFor($tenant, $connection, SyncOperationStatus::COMPLETED);

        $steps = $this->steps($this->actingAs($user)->get('/'));

        $this->assertTrue($steps['sync'], 'Tamamlanan operasyon senkron adımını kapatmalı.');
    }

    /**
     * BEKLEYEN OPERASYON SENKRON ADIMINI KAPATMAZ.
     *
     * Operasyon açıldı demek kanala ULAŞTI demek değildir; iş kuyrukta
     * bekliyor olabilir.
     */
    #[Test]
    public function a_pending_operation_does_not_complete_the_sync_step(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $connection = $this->connectionFor($tenant, status: 'active');

        $this->operationFor($tenant, $connection, SyncOperationStatus::PENDING);

        $steps = $this->steps($this->actingAs($user)->get('/'));

        $this->assertFalse($steps['sync'], 'Bekleyen operasyon senkron adımını kapatmamalı.');
    }

    /**
     * ÖLÜ OPERASYON SENKRON ADIMINI KAPATMAZ — TAM TERSİNİ KANITLAR.
     *
     * `dead` operasyon "denendi ve BAŞARISIZ oldu" demektir. Kapatılsaydı
     * kullanıcıya, senkronun çalışmadığı anda "kurulum tamam" denirdi.
     */
    #[Test]
    public function a_dead_operation_does_not_complete_the_sync_step(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $connection = $this->connectionFor($tenant, status: 'active');

        $this->operationFor($tenant, $connection, SyncOperationStatus::DEAD);

        $steps = $this->steps($this->actingAs($user)->get('/'));

        $this->assertFalse($steps['sync'], 'Ölü operasyon senkron adımını kapatmamalı.');
    }

    // ---------------------------------------------------------------- görünürlük

    /** Dört adım da bitince şerit KAYBOLUR — kullanıcının kararı budur. */
    #[Test]
    public function the_banner_disappears_once_every_step_is_done(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $connection = $this->connectionFor($tenant, status: 'active');
        $this->productFor($tenant);
        $this->operationFor($tenant, $connection, SyncOperationStatus::COMPLETED);

        $onboarding = $this->onboarding($this->actingAs($user)->get('/'));

        $this->assertFalse(
            $onboarding['visible'],
            'Dört adım bitince şerit görünmemeli.',
        );
    }

    /** Tek adım bile eksikse şerit görünür. */
    #[Test]
    public function the_banner_stays_visible_while_a_step_is_missing(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->connectionFor($tenant, status: 'active');
        $this->productFor($tenant);
        // İlk senkron YOK.

        $onboarding = $this->onboarding($this->actingAs($user)->get('/'));

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

        $this->assertFalse($this->onboarding($this->actingAs($user)->get('/'))['visible']);

        // Bağlantı sağlıksızlığa düşüyor (§13 · faz 1.4: `active`'ten geri çekilir).
        TenantContext::runAsSystem(function () use ($connection): void {
            ChannelConnection::withoutGlobalScopes()
                ->whereKey($connection->id)
                ->update(['status' => 'error']);
        });

        $onboarding = $this->onboarding($this->actingAs($user)->get('/'));

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

        $onboarding = $this->onboarding($this->actingAs($user)->get('/'));

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

        $steps = $this->steps($this->actingAs($userA)->get('/'));

        $this->assertSame(
            ['account' => true, 'channel' => false, 'product' => false, 'sync' => false],
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

        foreach (['/products', '/orders', '/inventory', '/channels'] as $path) {
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
    ): SyncOperation {
        return TenantContext::runAsSystem(fn (): SyncOperation => SyncOperation::create([
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $connection->id,
            'operation_type' => 'INVENTORY_PUSH',
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
