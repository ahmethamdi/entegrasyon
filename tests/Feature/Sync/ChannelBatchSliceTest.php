<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Ciceksepeti\CiceksepetiAdapter;
use App\Domain\Channels\Adapters\Pazarama\PazaramaAdapter;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Actions\ResolveChannelBatch;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Jobs\PushInventory;
use App\Domain\Sync\Jobs\PushPrices;
use App\Domain\Sync\Models\ChannelBatch;
use App\Domain\Sync\Models\ChannelBatchItem;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Support\ChannelBatchRecorder;
use App\Domain\Sync\Support\InventoryBatchBuilder;
use App\Domain\Sync\Support\PollChannelBatches;
use App\Domain\Sync\Support\PriceBatchBuilder;
use App\Domain\Sync\Support\SyncResultRecorder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ASENKRON TOPLU İŞ SONUCU — uçtan uca dilim.
 *
 * Zincir gerçek sınıflarla yürür; sahte olan yalnız HTTP katmanı:
 *   push (`PushInventory`/`PushPrices`) → gerçek adapter → kanal iş kimliği
 *   → `ChannelBatchRecorder` (saklanır) → `sync:poll-batches` turu
 *   (`PollChannelBatches` → `ResolveChannelBatch`) → gerçek adapter
 *   `fetchBatchStatus` → sync state → ürün listesinde "Sorun var".
 *
 * SINANAN KURALLAR:
 *   - Push kararı DEĞİŞMEZ: kanal kabul ettiyse operasyon tamamlanır,
 *     kimlik saklama düşse bile.
 *   - Reddedilen satır listing'de hata, geçen satır temiz.
 *   - Başarılı sonraki iş YALNIZ toplu işin yazdığı hatayı siler.
 *   - Bayat hüküm yeni değeri ezmez.
 *   - Saklama süresi dolunca yoklama bırakılır; kanal sorulmaz.
 *   - Aynı sonuç iki kez işlense de tek hüküm (error_count bir kez artar).
 *   - Başka kiracının işi görünmez, hükmü başka kiracıya yazılmaz.
 */
final class ChannelBatchSliceTest extends TestCase
{
    use RefreshDatabase;

    private const PUSH_URL = '*price-and-inventory';

    protected function setUp(): void
    {
        parent::setUp();

        // Planlama yolu iş atar; iş bu testte ELLE çağrılır.
        Queue::fake();
    }

    /**
     * PUSH → KİMLİK SAKLANDI → YOKLAMA → RET LİSTING'DE, GEÇEN TEMİZ.
     */
    #[Test]
    public function rejected_row_lands_on_the_listing_and_passed_row_stays_clean(): void
    {
        [$tenant, $user] = $this->makeTenant();
        $connection = $this->trendyol($tenant);
        $good = $this->listed($tenant, $connection, 'BRK-A');
        $bad = $this->listed($tenant, $connection, 'BRK-B');

        Http::fake([
            self::PUSH_URL => Http::response(['batchRequestId' => 'BR-1'], 200),
            '*batch-requests/BR-1' => Http::sequence()
                ->push(['status' => 'IN_PROGRESS', 'items' => []], 200)
                ->push($this->trendyolResult(['BRK-A' => null, 'BRK-B' => 'Ürün onaylı değil']), 200),
        ]);

        $opGood = $this->openInventory($tenant, $good);
        $this->openInventory($tenant, $bad);
        $this->push($tenant, $opGood);

        // PUSH KARARI DEĞİŞMEDİ: kanal kabul etti → iki operasyon da tamam.
        $this->asTenant($tenant, function (): void {
            $this->assertSame(
                [SyncOperationStatus::COMPLETED, SyncOperationStatus::COMPLETED],
                SyncOperation::query()->orderBy('created_at')->get()->pluck('status')->all(),
            );
        });

        // KİMLİK SAKLANDI: tek iş, iki satır, bekliyor.
        $batch = $this->asTenant($tenant, fn () => ChannelBatch::query()->sole());
        $this->assertSame('BR-1', $batch->external_batch_id);
        $this->assertSame(SyncDomain::INVENTORY, $batch->domain);
        $this->assertSame('pending', $batch->status);
        $this->assertSame(2, $this->asTenant($tenant, fn () => ChannelBatchItem::query()->count()));

        // İLK TUR: kanal hâlâ işliyor → satırlara dokunulmaz.
        $this->assertSame(1, app(PollChannelBatches::class)->sweep()['pending']);
        $this->assertSame('synced', $this->state($tenant, $bad)->status);

        // AYNI İŞ DAKİKADA BİRDEN SIK SORULMAZ.
        $this->assertSame(0, array_sum(app(PollChannelBatches::class)->sweep()));

        $this->travel(2)->minutes();
        $this->assertSame(1, app(PollChannelBatches::class)->sweep()['completed']);

        $badState = $this->state($tenant, $bad);
        $this->assertSame('error_permanent', $badState->status);
        $this->assertSame('Ürün onaylı değil', $badState->last_error);
        $this->assertSame(1, $badState->error_count);

        $goodState = $this->state($tenant, $good);
        $this->assertSame('synced', $goodState->status);
        $this->assertNull($goodState->last_error);

        // MEVCUT "Sorun var" GÖRÜNÜMÜ: yalnız reddedilen ürün sorunlu.
        $chips = $this->chips($user);
        $this->assertSame('problem', $chips['BRK-B']);
        $this->assertSame('live', $chips['BRK-A']);

        // SEBEP GÖRÜNÜR: ürünün kanallar ekranında stok/fiyat hatası metniyle;
        // içerik hatası alanı (gönderim) karışmaz.
        $this->assertSame('Ürün onaylı değil', $this->channelRow($tenant, $user, $bad)['stockPriceError']);
        $goodRow = $this->channelRow($tenant, $user, $good);
        $this->assertNull($goodRow['stockPriceError']);
        $this->assertNull($goodRow['lastError']);

        $batch->refresh();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(1, $batch->failed_count);

        // Bitmiş iş bir daha sorulmaz.
        $this->travel(10)->minutes();
        $this->assertSame(0, array_sum(app(PollChannelBatches::class)->sweep()));
        Http::assertSentCount(3);
    }

    /**
     * AYNI SONUÇ İKİ KEZ İŞLENSE DE TEK HÜKÜM — iki tur aynı bekleyen işi
     * aynı anda elinde tutsa bile ikincisi kilitte durumu yeniden okur.
     * Aynı push sonucunu iki kez saklamak da tek iş üretir.
     */
    #[Test]
    public function the_same_result_processed_twice_writes_once(): void
    {
        [$tenant] = $this->makeTenant();
        $connection = $this->trendyol($tenant);
        $bad = $this->listed($tenant, $connection, 'BRK-B');

        Http::fake([
            self::PUSH_URL => Http::response(['batchRequestId' => 'BR-1'], 200),
            '*batch-requests/BR-1' => Http::response($this->trendyolResult(['BRK-B' => 'Geçersiz barkod']), 200),
        ]);

        $op = $this->openInventory($tenant, $bad);
        $this->push($tenant, $op);

        // Aynı sonuç ikinci kez saklanır (iş yeniden koştu): çift kayıt yok.
        $this->asTenant($tenant, function () use ($connection, $op): void {
            $operation = SyncOperation::query()->findOrFail($op);
            app(ChannelBatchRecorder::class)->remember(
                app(AdapterRegistry::class)->for($connection->fresh()),
                SyncDomain::INVENTORY,
                $connection->id,
                [$operation],
                [$operation->entity_id => 'BRK-B'],
                AdapterResult::success(['batch_request_id' => 'BR-1']),
            );
            $this->assertSame(1, ChannelBatch::query()->count());
            $this->assertSame(1, ChannelBatchItem::query()->count());
        });

        // İki "tur" aynı bekleyen işi bellekte tutuyor.
        [$first, $second] = $this->asTenant($tenant, fn () => [
            ChannelBatch::query()->sole(),
            ChannelBatch::query()->sole(),
        ]);

        $resolve = app(ResolveChannelBatch::class);

        $this->assertSame('completed', $this->asTenant($tenant, fn () => $resolve->run($first)));
        $this->assertSame('skipped', $this->asTenant($tenant, fn () => $resolve->run($second)));

        $state = $this->state($tenant, $bad);
        $this->assertSame(1, $state->error_count, 'Hata İKİ kez sayılmamalı.');
        $this->assertSame(1, $this->asTenant($tenant, fn () => ChannelBatchItem::query()->where('outcome', 'failed')->count()));
    }

    /**
     * BAŞARILI SONRAKİ İŞ TOPLU İŞİN YAZDIĞI HATAYI SİLER — başka yoldan
     * gelen hatayı SİLMEZ.
     *
     * Gerçek yol: satıcı ürünü kanalda düzeltir ve "yeniden dene"ye basar
     * (resync = aynı sürümle REPAIR). Push'un kendisi hatayı silemez —
     * `advanceSyncState`'in sürüm kapısı aynı sürümde ilerlemez — bu yüzden
     * temizlik toplu iş hükmüne kalır.
     */
    #[Test]
    public function a_later_successful_batch_clears_only_the_batch_error(): void
    {
        [$tenant, $user] = $this->makeTenant();
        $connection = $this->trendyol($tenant);
        $fixed = $this->listed($tenant, $connection, 'BRK-F');
        $foreign = $this->listed($tenant, $connection, 'BRK-X');

        Http::fake([
            self::PUSH_URL => Http::sequence()
                ->push(['batchRequestId' => 'BR-1'], 200)
                ->push(['batchRequestId' => 'BR-2'], 200),
            '*batch-requests/BR-1' => Http::response($this->trendyolResult(['BRK-F' => 'Ürün onaylı değil', 'BRK-X' => null]), 200),
            '*batch-requests/BR-2' => Http::response($this->trendyolResult(['BRK-F' => null, 'BRK-X' => null]), 200),
        ]);

        // İlk push: iki satır aynı işte (BR-1) — F reddedildi, X geçti.
        $opFixed = $this->openInventory($tenant, $fixed);
        $this->openInventory($tenant, $foreign);
        $this->push($tenant, $opFixed);
        app(PollChannelBatches::class)->sweep();

        $this->assertSame('error_permanent', $this->state($tenant, $fixed)->status);

        // Başka yoldan gelmiş hata (toplu iş YAZMADI).
        $this->asTenant($tenant, fn () => $this->state($tenant, $foreign)->forceFill([
            'status' => 'error_permanent', 'last_error' => 'Elle yazılmış başka hata', 'error_count' => 1,
        ])->save());

        // Satıcı "yeniden dene": aynı sürümle REPAIR, ikisi de aynı push'ta gider.
        $repairFixed = $this->openInventory($tenant, $fixed, intent: SyncIntent::REPAIR);
        $this->openInventory($tenant, $foreign, intent: SyncIntent::REPAIR);
        $this->push($tenant, $repairFixed);
        $this->assertSame('error_permanent', $this->state($tenant, $fixed)->status, 'Push aynı sürümde hatayı silemez.');

        $this->travel(2)->minutes();
        app(PollChannelBatches::class)->sweep();

        $fixedState = $this->state($tenant, $fixed);
        $this->assertSame('synced', $fixedState->status);
        $this->assertNull($fixedState->last_error);
        $this->assertSame(0, $fixedState->error_count);

        $foreignState = $this->state($tenant, $foreign);
        $this->assertSame('error_permanent', $foreignState->status, 'Toplu iş YAZMADIĞI hatayı silmemeli.');
        $this->assertSame('Elle yazılmış başka hata', $foreignState->last_error);

        $this->assertSame('live', $this->chips($user)['BRK-F']);
    }

    /**
     * BAYAT HÜKÜM YENİ DEĞERİ EZMEZ — sürüm 1'in reddi, sürüm 2 istendikten
     * sonra gelirse satıra yazılmaz.
     */
    #[Test]
    public function a_stale_verdict_does_not_overwrite_a_newer_value(): void
    {
        [$tenant] = $this->makeTenant();
        $connection = $this->trendyol($tenant);
        $listing = $this->listed($tenant, $connection, 'BRK-S');

        Http::fake([
            self::PUSH_URL => Http::response(['batchRequestId' => 'BR-1'], 200),
            '*batch-requests/BR-1' => Http::response($this->trendyolResult(['BRK-S' => 'Eski sürüm reddi']), 200),
        ]);

        $this->push($tenant, $this->openInventory($tenant, $listing, version: 1));

        // Sürüm 2 istendi (henüz gönderilmedi).
        $this->openInventory($tenant, $listing, version: 2);

        app(PollChannelBatches::class)->sweep();

        $state = $this->state($tenant, $listing);
        $this->assertSame('pending', $state->status);
        $this->assertNull($state->last_error);

        // Satır hükmü kayıtlı (denetim izi) ama listing'e yazılmadı.
        $this->assertSame('failed', $this->asTenant($tenant, fn () => ChannelBatchItem::query()->sole()->outcome));
    }

    /**
     * SAKLAMA SÜRESİ DOLUNCA VAZGEÇER — kanal sorulmaz, satıra hüküm yazılmaz.
     */
    #[Test]
    public function the_poll_gives_up_after_the_channel_retention(): void
    {
        [$tenant] = $this->makeTenant();
        $connection = $this->trendyol($tenant);
        $listing = $this->listed($tenant, $connection, 'BRK-E');

        Http::fake([
            self::PUSH_URL => Http::response(['batchRequestId' => 'BR-1'], 200),
            '*batch-requests/*' => Http::response(['status' => 'IN_PROGRESS', 'items' => []], 200),
        ]);

        $this->push($tenant, $this->openInventory($tenant, $listing));

        $this->assertSame(1, app(PollChannelBatches::class)->sweep()['pending']);

        // Trendyol saklama süresi 4 saat.
        $this->travel(4 * 60 + 1)->minutes();
        $this->assertSame(1, app(PollChannelBatches::class)->sweep()['expired']);

        $batch = $this->asTenant($tenant, fn () => ChannelBatch::query()->sole());
        $this->assertSame('expired', $batch->status);
        $this->assertSame('expired', $this->asTenant($tenant, fn () => ChannelBatchItem::query()->sole()->outcome));
        $this->assertSame('synced', $this->state($tenant, $listing)->status);

        // Süre dolduktan sonra kanal BİR KEZ DAHA sorulmadı: push + 1 yoklama.
        Http::assertSentCount(2);

        // Bırakılan iş bir daha seçilmez.
        $this->travel(10)->minutes();
        $this->assertSame(0, array_sum(app(PollChannelBatches::class)->sweep()));
    }

    /**
     * BAŞKA KİRACININ İŞİ GÖRÜNMEZ — tur her işi kendi kiracısında işler.
     */
    #[Test]
    public function another_tenants_batch_is_invisible_and_resolved_in_its_own_tenant(): void
    {
        [$tenantA] = $this->makeTenant('A');
        [$tenantB] = $this->makeTenant('B');
        $listingA = $this->listed($tenantA, $this->trendyol($tenantA, '111'), 'BRK-1');
        $listingB = $this->listed($tenantB, $this->trendyol($tenantB, '222'), 'BRK-1');

        Http::fake([
            '*sellers/111/products/price-and-inventory' => Http::response(['batchRequestId' => 'BR-A'], 200),
            '*sellers/222/products/price-and-inventory' => Http::response(['batchRequestId' => 'BR-B'], 200),
            '*sellers/111/products/batch-requests/BR-A' => Http::response($this->trendyolResult(['BRK-1' => null]), 200),
            '*sellers/222/products/batch-requests/BR-B' => Http::response($this->trendyolResult(['BRK-1' => 'B kiracısının reddi']), 200),
        ]);

        $this->push($tenantA, $this->openInventory($tenantA, $listingA));
        $this->push($tenantB, $this->openInventory($tenantB, $listingB));

        // Kiracı A yalnız kendi işini görür.
        $this->assertSame(['BR-A'], $this->asTenant($tenantA, fn () => ChannelBatch::query()->pluck('external_batch_id')->all()));
        $this->assertSame(1, $this->asTenant($tenantA, fn () => ChannelBatchItem::query()->count()));

        $this->assertSame(2, app(PollChannelBatches::class)->sweep()['completed']);

        // Aynı barkod iki kiracıda: B'nin reddi A'ya yazılmadı.
        $this->assertSame('synced', $this->state($tenantA, $listingA)->status);
        $this->assertSame('error_permanent', $this->state($tenantB, $listingB)->status);
        $this->assertSame('B kiracısının reddi', $this->state($tenantB, $listingB)->last_error);
    }

    /**
     * KİMLİK SAKLAMA DÜŞERSE PUSH KARARI DEĞİŞMEZ — saklama bir yan iştir.
     */
    #[Test]
    public function a_failing_batch_store_does_not_change_the_push_outcome(): void
    {
        [$tenant] = $this->makeTenant();
        $connection = $this->trendyol($tenant);
        $listing = $this->listed($tenant, $connection, 'BRK-P');

        Http::fake([self::PUSH_URL => Http::response(['batchRequestId' => 'BR-1'], 200)]);

        Schema::drop('channel_batch_items');

        $op = $this->openInventory($tenant, $listing);
        $this->push($tenant, $op);

        $this->asTenant($tenant, function () use ($op): void {
            $operation = SyncOperation::query()->findOrFail($op);
            $this->assertSame(SyncOperationStatus::COMPLETED, $operation->status);
            $this->assertSame(1, $operation->attempt_count);
            $this->assertSame(0, ChannelBatch::query()->count(), 'Yarım iş kaydı kalmamalı.');
        });

        $this->assertSame('synced', $this->state($tenant, $listing)->status);
    }

    /**
     * FİYAT YOLU + SINIFLAR: Pazarama "onaya gönderildi" BAŞARI DEĞİL ama
     * HATA DA DEĞİL (geçici, error_count artmaz → "Bekliyor"); Çiçeksepeti
     * teknik hatası geçici (error_count artar → mutabakat adayı).
     */
    #[Test]
    public function approval_wait_and_technical_failure_are_transient(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $pazarama = $this->connectionFor($tenant, 'pazarama', PazaramaAdapter::class, 'pz-1',
            [PazaramaAdapter::SELLER_NAME_KEY => 'magazam'],
            ['client_id' => 'CID-1', 'client_secret' => 'SIR-GIZLI-12345', 'access_token' => 'TOKEN-1']);
        $cicek = $this->connectionFor($tenant, 'ciceksepeti', CiceksepetiAdapter::class, 'cs-1',
            [CiceksepetiAdapter::SELLER_ID_KEY => '998877'], [CiceksepetiAdapter::API_KEY_SECRET => 'CS-ANAHTAR-123']);

        $priced = $this->listed($tenant, $pazarama, 'PZ-1');
        $stocked = $this->listed($tenant, $cicek, 'CS-1');

        Http::fake([
            '*updatePrice-v2' => Http::response(['success' => true, 'data' => 'G-1'], 200),
            '*lake-projections*' => Http::response(['success' => true, 'data' => [
                ['code' => 'PZ-1', 'price' => ['status' => 5, 'operationDetail' => 'Ürün fiyat onayına gönderildi']],
            ]], 200),
            '*Products/price-and-stock' => Http::response(['batchId' => 'CS-B'], 200),
            '*batch-status/CS-B' => Http::response(['batchId' => 'CS-B', 'items' => [
                ['data' => ['stockCode' => 'CS-1'], 'status' => 'Failed', 'failureReasons' => [['message' => 'Sistem hatası']]],
            ]], 200),
        ]);

        $priceOp = $this->asTenant($tenant, fn () => app(OpenSyncOperation::class)->run($priced, SyncDomain::PRICE, 1)->id);
        (new PushPrices($priceOp, $tenant->id))->handle(app(PriceBatchBuilder::class), app(SyncResultRecorder::class), app(AdapterRegistry::class));
        $this->push($tenant, $this->openInventory($tenant, $stocked));

        $batches = $this->asTenant($tenant, fn () => ChannelBatch::query()->orderBy('domain')->get(['domain', 'external_batch_id']));
        $this->assertSame([['INVENTORY', 'CS-B'], ['PRICE', 'G-1']], $batches->map(fn ($b) => [$b->domain->value, $b->external_batch_id])->all());

        app(PollChannelBatches::class)->sweep();

        $approval = $this->state($tenant, $priced, SyncDomain::PRICE);
        $this->assertSame('error_transient', $approval->status);
        $this->assertSame('Ürün fiyat onayına gönderildi', $approval->last_error);
        $this->assertSame(0, $approval->error_count, 'Onay beklemek hata sayılmaz.');

        $technical = $this->state($tenant, $stocked);
        $this->assertSame('error_transient', $technical->status);
        $this->assertSame(1, $technical->error_count);

        $chips = $this->chips($user);
        $this->assertSame('pending', $chips['PZ-1']);
        $this->assertSame('pending', $chips['CS-1']);

        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), '/listing-state/batch-id/G-1/lake-projections'));
    }

    /** Tur ZAMANLANMIŞ ve komut KAYITLI — yoksa red sessiz kalır. */
    #[Test]
    public function the_poll_is_scheduled_every_five_minutes(): void
    {
        $this->assertArrayHasKey('sync:poll-batches', app(Kernel::class)->all());

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'sync:poll-batches'));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    // ──────────────────────────────────────────────────────── yardımcı

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(string $name = 'Toplu'): array
    {
        $user = User::factory()->create();

        return [(new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user), $user];
    }

    private function trendyol(Tenant $tenant, string $supplierId = '123456'): ChannelConnection
    {
        return $this->connectionFor($tenant, 'trendyol', TrendyolAdapter::class, $supplierId,
            ['supplier_id' => $supplierId], ['api_key' => 'anahtar', 'api_secret' => 'sifre']);
    }

    /**
     * @param  array<string, string>  $settings
     * @param  array<string, string>  $secrets
     */
    private function connectionFor(Tenant $tenant, string $code, string $class, string $account, array $settings, array $secrets): ChannelConnection
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => ucfirst($code), 'kind' => 'marketplace', 'adapter_class' => $class,
                'capabilities' => [], 'rate_limit_profile' => ['requests_per_second' => 5, 'burst_capacity' => 10],
                'supports_webhooks' => false, 'is_active' => true,
            ],
        ));

        return $this->asTenant($tenant, function () use ($code, $account, $settings, $secrets): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => $code,
                'external_account_id' => $account,
                'status' => 'active',
                'settings' => $settings,
            ]);

            app(CredentialVault::class)->store($connection, $secrets);

            return $connection;
        });
    }

    /** Stoklu varyant + canlı listing; ürün SKU'su = barkod (çip anahtarı). */
    private function listed(Tenant $tenant, ChannelConnection $connection, string $barcode): Listing
    {
        return $this->asTenant($tenant, function () use ($tenant, $connection, $barcode): Listing {
            $variant = Variant::factory()->create(['sku' => $barcode, 'barcode' => $barcode, 'price' => '100.00', 'currency' => 'TRY']);
            $variant->product->forceFill(['sku' => $barcode, 'title' => 'Ürün '.$barcode])->save();

            app(ApplyMovement::class)->run(
                warehouseId: $tenant->defaultWarehouse()->id,
                variantId: $variant->id,
                type: MovementType::IMPORT,
                quantity: 5,
                idempotencyKey: 'import:'.$variant->id,
                sourceType: 'test',
            );

            return Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => $variant->id,
                'external_id' => $barcode,
                'lifecycle_status' => 'live',
            ]);
        });
    }

    private function openInventory(Tenant $tenant, Listing $listing, int $version = 1, SyncIntent $intent = SyncIntent::NORMAL_SYNC): string
    {
        return $this->asTenant($tenant, function () use ($listing, $version, $intent): string {
            $operation = app(OpenSyncOperation::class)->run(
                listing: $listing,
                domain: SyncDomain::INVENTORY,
                eventVersion: $version,
                intent: $intent,
                resyncAnchor: $intent === SyncIntent::REPAIR ? 'resync-'.uniqid() : null,
            );

            $this->assertNotNull($operation);

            return $operation->id;
        });
    }

    /** İş worker'daki gibi koşar — bağlamı kendisi kurar. */
    private function push(Tenant $tenant, string $operationId): void
    {
        (new PushInventory($operationId, $tenant->id))->handle(
            app(InventoryBatchBuilder::class),
            app(SyncResultRecorder::class),
            app(AdapterRegistry::class),
        );
    }

    private function state(Tenant $tenant, Listing $listing, SyncDomain $domain = SyncDomain::INVENTORY): ListingSyncState
    {
        return $this->asTenant($tenant, fn (): ListingSyncState => ListingSyncState::query()
            ->where('listing_id', $listing->id)
            ->where('domain', $domain->value)
            ->sole());
    }

    /**
     * Ürünün kanallar ekranındaki (ilk) kanal satırı.
     *
     * @return array<string, mixed>
     */
    private function channelRow(Tenant $tenant, User $user, Listing $listing): array
    {
        $productId = $this->asTenant($tenant, fn () => $listing->variant()->firstOrFail()->product_id);

        $response = $this->actingAs($user)->get('/products/'.$productId.'/channels');
        $response->assertOk();

        return $response->viewData('page')['props']['channels'][0];
    }

    /**
     * Ürün listesindeki kanal çipleri — SKU → durum (mevcut ekran).
     *
     * @return array<string, string>
     */
    private function chips(User $user): array
    {
        $response = $this->actingAs($user)->get('/products');
        $response->assertOk();

        $chips = [];

        foreach ($response->viewData('page')['props']['rows'] as $row) {
            $chips[$row['sku']] = $row['channels'][0]['state'] ?? 'none';
        }

        return $chips;
    }

    /**
     * Trendyol `getBatchRequestResult` gövdesi — barkod → sebep (null = SUCCESS).
     *
     * @param  array<string, string|null>  $rows
     * @return array<string, mixed>
     */
    private function trendyolResult(array $rows): array
    {
        $items = [];

        foreach ($rows as $barcode => $reason) {
            $items[] = [
                'requestItem' => ['barcode' => $barcode, 'quantity' => 5],
                'status' => $reason === null ? 'SUCCESS' : 'FAILED',
                'failureReasons' => $reason === null ? [] : [$reason],
            ];
        }

        return ['status' => 'COMPLETED', 'items' => $items, 'failedItemCount' => count(array_filter($rows))];
    }
}
