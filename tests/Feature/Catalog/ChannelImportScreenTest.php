<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Jobs\ImportProductsFromChannelJob;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImport;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Support\RemoteProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Channels\ProgrammableCatalogAdapter;
use Tests\Support\Channels\ProgrammableImportAdapter;
use Tests\TestCase;

/**
 * Kanaldan ürün çekme ekranı ve kuyruk işi — §13 · Faz 3 · madde 5.
 *
 * DEĞİŞMEZ KURAL — TUR KUYRUKTA ÇALIŞIR: kanal turu 50 sayfaya kadar HTTP
 * isteği yapar; istekte işlenseydi zaman aşımına uğrar ve kullanıcı
 * yenilemeye basınca katalog İKİ KEZ çekilirdi.
 *
 * DEĞİŞMEZ KURAL — DESTEKLEMEYEN KANAL LİSTEDE GÖRÜNMEZ: yetenek
 * `instanceof` ile okunur (§7), kanal adı kontrol edilmez.
 */
final class ChannelImportScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        ProgrammableImportAdapter::reset();
        ProgrammableCatalogAdapter::reset();
    }

    // ---------------------------------------------------------------- ekran

    #[Test]
    public function the_screen_lists_connections_that_support_importing(): void
    {
        [, $user] = $this->makeTenantWithConnection();

        $connections = $this->connections($this->actingAs($user)->get('/products/import'));

        $this->assertCount(1, $connections);
        $this->assertSame('WooCommerce', $connections[0]['channel']);
    }

    /**
     * YETENEK `instanceof` İLE OKUNUR: içe aktarmayı desteklemeyen kanal
     * listede HİÇ görünmez. Görünseydi satıcı seçer, tur açılır ve her
     * seferinde "desteklenmiyor" hatası alırdı.
     */
    #[Test]
    public function a_channel_without_the_capability_is_not_offered(): void
    {
        [, $user] = $this->makeTenantWithConnection(ProgrammableCatalogAdapter::class);

        $this->assertSame(
            [],
            $this->connections($this->actingAs($user)->get('/products/import')),
            'Desteklemeyen kanal seçeneklerde olmamalı.',
        );
    }

    /** Sağlık kontrolünü geçmemiş bağlantıya iş atılmaz. */
    #[Test]
    public function an_inactive_connection_is_not_offered(): void
    {
        [$tenant, $user] = $this->makeTenantWithConnection();

        $this->asTenant($tenant, function (): void {
            ChannelConnection::query()->firstOrFail()->forceFill(['status' => 'pending'])->save();
        });

        $this->assertSame(
            [],
            $this->connections($this->actingAs($user)->get('/products/import')),
            'Aktif olmayan bağlantı seçeneklerde olmamalı.',
        );
    }

    #[Test]
    public function connections_never_leak_across_tenants(): void
    {
        $this->makeTenantWithConnection();
        [, $userB] = $this->makeTenant('B');

        $this->assertSame(
            [],
            $this->connections($this->actingAs($userB)->get('/products/import')),
            'Başka kiracının bağlantısı görünmemeli.',
        );
    }

    #[Test]
    public function guests_cannot_reach_the_screen(): void
    {
        $this->get('/products/import')->assertRedirect('/login');
    }

    // ---------------------------------------------------------------- tetikleme

    /**
     * İSTEK ÜRÜN YAZMAZ, KUYRUĞA İŞ ATAR — gerekçe sınıf başlığında.
     */
    #[Test]
    public function starting_an_import_queues_a_job_instead_of_running_inline(): void
    {
        [$tenant, $user] = $this->makeTenantWithConnection();

        $connectionId = $this->asTenant($tenant, fn () => ChannelConnection::query()->firstOrFail()->id);

        $this->actingAs($user)
            ->post('/products/import/channel', ['connection_id' => $connectionId])
            ->assertRedirect();

        Queue::assertPushed(ImportProductsFromChannelJob::class);

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => Product::query()->count()),
            'İstek sırasında ürün YAZILMAMALI.',
        );
    }

    /**
     * KUYRUK ADI `listing:bulk` (§15). Uydurma bir ad işin Redis'te
     * sonsuza kadar beklemesi demektir ve hiçbir hata görünmez.
     */
    #[Test]
    public function the_job_goes_to_the_bulk_queue(): void
    {
        [$tenant, $user] = $this->makeTenantWithConnection();

        $connectionId = $this->asTenant($tenant, fn () => ChannelConnection::query()->firstOrFail()->id);

        $this->actingAs($user)->post('/products/import/channel', ['connection_id' => $connectionId]);

        Queue::assertPushed(
            ImportProductsFromChannelJob::class,
            static fn (ImportProductsFromChannelJob $job): bool => $job->queue === 'listing:bulk',
        );
    }

    /** Durum satırı kaynağı ve bağlantıyı taşır; `payload` NULL'dur. */
    #[Test]
    public function the_status_row_records_the_channel_source(): void
    {
        [$tenant, $user] = $this->makeTenantWithConnection();

        $connectionId = $this->asTenant($tenant, fn () => ChannelConnection::query()->firstOrFail()->id);

        $this->actingAs($user)->post('/products/import/channel', ['connection_id' => $connectionId]);

        $import = $this->asTenant($tenant, fn () => ProductImport::query()->firstOrFail());

        $this->assertSame('channel', $import->source);
        $this->assertSame($connectionId, $import->channel_connection_id);
        $this->assertNull($import->payload, 'Kanal turunda CSV gövdesi YOKTUR.');
    }

    /**
     * Başka kiracının bağlantı kimliği gönderilirse tur AÇILMAZ.
     *
     * Yetkilendirme kimliğin tahmin edilemezliğine DAYANDIRILMAZ.
     */
    #[Test]
    public function another_tenants_connection_cannot_be_used(): void
    {
        [$tenantA] = $this->makeTenantWithConnection();
        [, $userB] = $this->makeTenant('B');

        $foreignId = $this->asTenant($tenantA, fn () => ChannelConnection::query()->firstOrFail()->id);

        $this->actingAs($userB)
            ->post('/products/import/channel', ['connection_id' => $foreignId])
            ->assertSessionHasErrors('connection_id');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_channel_without_the_capability_is_rejected_on_submit(): void
    {
        [$tenant, $user] = $this->makeTenantWithConnection(ProgrammableCatalogAdapter::class);

        $connectionId = $this->asTenant($tenant, fn () => ChannelConnection::query()->firstOrFail()->id);

        $this->actingAs($user)
            ->post('/products/import/channel', ['connection_id' => $connectionId])
            ->assertSessionHasErrors('connection_id');

        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------------- iş

    #[Test]
    public function the_job_records_its_outcome_on_the_status_row(): void
    {
        [$tenant, $user] = $this->makeTenantWithConnection();

        ProgrammableImportAdapter::returns('woocommerce', [
            new RemoteProduct(externalId: '1', sku: 'İŞ-1', title: 'Ürün', price: '10.00', quantity: 4),
            new RemoteProduct(externalId: '2', sku: null, title: 'SKU yok'),
        ]);

        $connectionId = $this->asTenant($tenant, fn () => ChannelConnection::query()->firstOrFail()->id);

        $this->actingAs($user)->post('/products/import/channel', ['connection_id' => $connectionId]);

        $import = $this->asTenant($tenant, fn () => ProductImport::query()->firstOrFail());

        (new ImportProductsFromChannelJob($tenant->id, $import->id))->handle();

        $fresh = $this->asTenant($tenant, fn () => ProductImport::query()->findOrFail($import->id));

        $this->assertSame('completed', $fresh->status);
        $this->assertSame(1, $fresh->created_count);
        $this->assertSame(1, $fresh->skipped_count, 'SKU\'suz ürün ATLANAN olarak sayılmalı.');
        $this->assertCount(1, $fresh->errors ?? []);
    }

    /**
     * Bağlantı silinmişse tur `failed` olur — `running` kalsaydı satır
     * sonsuza kadar "işleniyor" görünürdü.
     */
    #[Test]
    public function a_missing_connection_fails_the_run_instead_of_hanging(): void
    {
        [$tenant, $user] = $this->makeTenantWithConnection();

        $connectionId = $this->asTenant($tenant, fn () => ChannelConnection::query()->firstOrFail()->id);

        $this->actingAs($user)->post('/products/import/channel', ['connection_id' => $connectionId]);

        $import = $this->asTenant($tenant, fn () => ProductImport::query()->firstOrFail());

        $this->asTenant($tenant, function () use ($connectionId): void {
            ChannelConnection::query()->findOrFail($connectionId)->delete();
        });

        (new ImportProductsFromChannelJob($tenant->id, $import->id))->handle();

        $fresh = $this->asTenant($tenant, fn () => ProductImport::query()->findOrFail($import->id));

        $this->assertSame('failed', $fresh->status);
        $this->assertNotNull($fresh->last_error);
    }

    // ---------------------------------------------------------------- yardımcı

    /** @return list<array<string, mixed>> */
    private function connections(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props']['connections'];
    }

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(string $name = 'Kanal içe aktarma'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);

        return [$tenant, $user];
    }

    /** @return array{0: Tenant, 1: User} */
    private function makeTenantWithConnection(string $adapter = ProgrammableImportAdapter::class): array
    {
        [$tenant, $user] = $this->makeTenant();

        $this->asSystem(function () use ($adapter): void {
            ChannelType::query()->updateOrCreate(
                ['code' => 'woocommerce'],
                [
                    'name' => 'WooCommerce',
                    'kind' => 'marketplace',
                    'adapter_class' => $adapter,
                    'is_active' => true,
                ],
            );
        });

        $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_type_code' => 'woocommerce',
            'status' => 'active',
        ]));

        return [$tenant, $user];
    }
}
