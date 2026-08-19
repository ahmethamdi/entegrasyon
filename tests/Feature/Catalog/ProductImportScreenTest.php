<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Jobs\ImportProductsJob;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImport;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Toplu içe aktarma ekranı ve kuyruk işi.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · "Toplu içe aktarma
 * (Excel/CSV)", §15 · kuyruk tablosu (`listing:bulk`, background havuz).
 *
 * DEĞİŞMEZ KURAL — İŞLEME KUYRUKTA YAPILIR, İSTEKTE DEĞİL:
 *   500 satırlık bir dosya HTTP isteğinde işlenirse istek zaman aşımına
 *   uğrar, kullanıcı yenilemeye basar ve aynı dosya İKİ KEZ işlenir.
 *   Yükleme yalnızca dosyayı kaydeder ve işi kuyruğa atar.
 *
 * DEĞİŞMEZ KURAL — KUYRUK `listing:bulk`:
 *   §15 tablosunda tanımlı ve background havuzunda. Uydurma bir ad işin
 *   Redis'te sonsuza kadar beklemesi demektir ve HİÇBİR HATA GÖRÜNMEZ.
 *   Ayrıca `reconciliation` kuyruğuyla havuz PAYLAŞMAZ: toplu içe aktarma
 *   yeni müşteri kurulumunun tam ortasıdır ve mutabakat turlarını
 *   atlatırsa ürünün temel iddiası tam o anda çalışmaz hâle gelir.
 *
 * DEĞİŞMEZ KURAL — DURUM SATIRI KULLANICIYA AİTTİR:
 *   İş asenkron olduğu için kullanıcı sonucu ancak kayıttan öğrenebilir.
 *   `product_imports` satırı yoksa yükleme "kayboldu" görünür.
 */
final class ProductImportScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    // ---------------------------------------------------------------- erişim

    /** Misafir içe aktarma ekranını göremez. */
    #[Test]
    public function guest_cannot_reach_the_import_screen(): void
    {
        $this->get('/products/import')->assertRedirect('/login');
    }

    /** Misafir dosya yükleyemez. */
    #[Test]
    public function guest_cannot_upload_a_file(): void
    {
        $this->post('/products/import', [
            'file' => UploadedFile::fake()->createWithContent('urunler.csv', "sku\nX"),
        ])->assertRedirect('/login');
    }

    // ---------------------------------------------------------------- yükleme

    /**
     * YÜKLEME İŞİ KUYRUĞA ATAR, İSTEKTE İŞLEMEZ.
     *
     * 500 satırlık dosya istekte işlenseydi zaman aşımı olur, kullanıcı
     * yenilerdi ve aynı dosya iki kez işlenirdi.
     */
    #[Test]
    public function uploading_queues_a_job_instead_of_importing_inline(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->upload($user, "sku,baslik,fiyat,stok\nQ-1,Ürün,10.00,1")
            ->assertRedirect();

        Queue::assertPushed(ImportProductsJob::class);

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => Product::query()->count()),
            'İstek sırasında ürün YAZILMAMALI — iş kuyrukta çalışır.',
        );
    }

    /**
     * İŞ `listing:bulk` KUYRUĞUNA GİDER.
     *
     * §15 tablosunda tanımlı ad. Uydurma bir ad işin Redis'te sonsuza
     * kadar beklemesi demektir ve hiçbir hata görünmez.
     */
    #[Test]
    public function the_job_goes_to_the_bulk_queue(): void
    {
        [, $user] = $this->makeTenant();

        $this->upload($user, "sku,baslik,fiyat,stok\nQ-2,Ürün,10.00,1");

        Queue::assertPushed(
            ImportProductsJob::class,
            static fn (ImportProductsJob $job): bool => $job->queue === 'listing:bulk',
        );
    }

    /** Kuyruk adı Horizon yapılandırmasıyla BİREBİR aynıdır. */
    #[Test]
    public function the_bulk_queue_is_one_horizon_actually_listens_to(): void
    {
        $queues = [];

        foreach ((array) config('horizon.defaults') as $pool) {
            foreach ((array) ($pool['queue'] ?? []) as $queue) {
                $queues[] = $queue;
            }
        }

        $this->assertContains(
            'listing:bulk',
            $queues,
            'Horizon bu kuyruğu dinlemiyorsa iş sonsuza kadar bekler ve hata görünmez.',
        );
    }

    /**
     * `reconciliation` İLE HAVUZ PAYLAŞMAZ (§15 · değişmez kural).
     *
     * Toplu içe aktarma yeni müşteri kurulumunun tam ortasıdır ve arka
     * plan havuzunu doldurur; mutabakat turlarını atlatırsa ürünün temel
     * iddiası tam o anda çalışmaz hâle gelir.
     */
    #[Test]
    public function the_bulk_queue_does_not_share_a_pool_with_reconciliation(): void
    {
        foreach ((array) config('horizon.defaults') as $name => $pool) {
            $queues = (array) ($pool['queue'] ?? []);

            if (! in_array('listing:bulk', $queues, true)) {
                continue;
            }

            $this->assertNotContains(
                'reconciliation',
                $queues,
                "{$name} havuzu ikisini birden dinliyor — toplu içe aktarma mutabakatı atlatır.",
            );
        }
    }

    /** Yükleme bir durum satırı açar — kullanıcı sonucu oradan öğrenir. */
    #[Test]
    public function uploading_opens_a_status_row(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->upload($user, "sku,baslik,fiyat,stok\nS-1,Ürün,10.00,1");

        $import = $this->asTenant($tenant, fn () => ProductImport::query()->firstOrFail());

        $this->assertSame('pending', $import->status);
        $this->assertSame('urunler.csv', $import->filename);
    }

    /** CSV olmayan dosya reddedilir. */
    #[Test]
    public function a_non_csv_upload_is_rejected(): void
    {
        [, $user] = $this->makeTenant();

        $this->actingAs($user)
            ->post('/products/import', [
                'file' => UploadedFile::fake()->create('resim.png', 10, 'image/png'),
            ])
            ->assertSessionHasErrors('file');

        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------------- iş

    /**
     * İŞ KİRACI BAĞLAMINI KENDİ KURAR.
     *
     * `Queue::looping` kancası her iş sınırında bağlamı temizler; `handle()`
     * her koşulda bağlamsız başlar. Bağlam yükte taşınır ve başta kurulur.
     */
    #[Test]
    public function the_job_establishes_its_own_tenant_context(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->upload($user, "sku,baslik,fiyat,stok\nJOB-1,Ürün,10.00,4");

        $import = $this->asTenant($tenant, fn () => ProductImport::query()->firstOrFail());

        // Bağlam DIŞINDA çalıştırılır — gerçek worker da böyle çağırır.
        (new ImportProductsJob($tenant->id, $import->id))->handle();

        $this->assertSame(
            1,
            $this->asTenant($tenant, fn (): int => Product::query()->count()),
            'İş bağlamı kendi kurmalı ve ürünü yazmalı.',
        );
    }

    /** İş bitince durum satırı sonucu taşır. */
    #[Test]
    public function the_job_records_its_outcome_on_the_status_row(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->upload($user, <<<'CSV'
        sku,baslik,fiyat,stok
        R-1,İlk,10.00,1
        ,Bozuk,20.00,2
        CSV);

        $import = $this->asTenant($tenant, fn () => ProductImport::query()->firstOrFail());

        (new ImportProductsJob($tenant->id, $import->id))->handle();

        $fresh = $this->asTenant($tenant, fn () => ProductImport::query()->findOrFail($import->id));

        $this->assertSame('completed', $fresh->status);
        $this->assertSame(1, $fresh->created_count);
        $this->assertSame(0, $fresh->updated_count);
        $this->assertCount(1, $fresh->errors ?? []);
    }

    /**
     * ZORUNLU KOLON EKSİKSE DURUM `failed` OLUR.
     *
     * "0 ürün yazıldı" ile "dosyan hiç işlenmedi" farklı şeylerdir;
     * ikincisinde kullanıcının yapması gereken belli bir iş vardır.
     */
    #[Test]
    public function a_file_with_a_missing_column_is_marked_failed(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->upload($user, "sku,baslik,stok\nX-1,Ürün,1");

        $import = $this->asTenant($tenant, fn () => ProductImport::query()->firstOrFail());

        (new ImportProductsJob($tenant->id, $import->id))->handle();

        $fresh = $this->asTenant($tenant, fn () => ProductImport::query()->findOrFail($import->id));

        $this->assertSame('failed', $fresh->status);
        $this->assertNotNull($fresh->last_error);
    }

    // ---------------------------------------------------------------- ekran

    /** Ekran geçmiş yüklemeleri listeler. */
    #[Test]
    public function the_screen_lists_past_imports(): void
    {
        [, $user] = $this->makeTenant();

        $this->upload($user, "sku,baslik,fiyat,stok\nL-1,Ürün,10.00,1");

        $rows = $this->rows($this->actingAs($user)->get('/products/import'));

        $this->assertCount(1, $rows);
        $this->assertSame('urunler.csv', $rows[0]['filename']);
    }

    /** BAŞKA KİRACININ yüklemesi görünmez. */
    #[Test]
    public function imports_never_leak_across_tenants(): void
    {
        [, $userA] = $this->makeTenant('A');
        [, $userB] = $this->makeTenant('B');

        $this->upload($userB, "sku,baslik,fiyat,stok\nB-1,Ürün,10.00,1");

        $rows = $this->rows($this->actingAs($userA)->get('/products/import'));

        $this->assertSame([], $rows, 'Başka kiracının yüklemesi görünmemeli.');
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(string $name = 'İçe aktarma'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);

        return [$tenant, $user];
    }

    private function upload(User $user, string $csv): TestResponse
    {
        return $this->actingAs($user)->post('/products/import', [
            'file' => UploadedFile::fake()->createWithContent('urunler.csv', $csv),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props']['rows'];
    }
}
