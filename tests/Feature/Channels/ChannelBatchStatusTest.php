<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Ciceksepeti\CiceksepetiAdapter;
use App\Domain\Channels\Adapters\Hepsiburada\HepsiburadaAdapter;
use App\Domain\Channels\Adapters\N11\N11Adapter;
use App\Domain\Channels\Adapters\Pazarama\PazaramaAdapter;
use App\Domain\Channels\Adapters\Trendyol\TrendyolAdapter;
use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\SupportsBatchStatus;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Enums\ErrorClass;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Support\BatchStatus;
use App\Support\Logging\PayloadRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kanal toplu iş sonucunu AYRIŞTIRMA — beş adapter, `SupportsBatchStatus`.
 *
 * Yanıt gövdeleri resmi belgedeki biçimle kurulur (YENI-KANALLAR-API-NOTLARI
 * §4 N11, §5 Çiçeksepeti, §6 Pazarama; HB OpenAPI
 * `StockUploadResultRepresentation`; Trendyol `getBatchRequestResult`).
 *
 * DEĞİŞMEZ KURAL — SATIR KİMLİKLE EŞLENİR: anahtar kanala giden kimliktir
 * (barkod / HB SKU / stockCode), sıra DEĞİL.
 * DEĞİŞMEZ KURAL — SÜREN İŞ BİTMİŞ SAYILMAZ, UNUTULAN İŞ RED SAYILMAZ.
 */
final class ChannelBatchStatusTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────── Trendyol

    /**
     * Satır `requestItem.barcode` ile eşlenir; FAILED sebebi taşınır,
     * SUCCESS açık başarıdır. Uç nokta ürün servisindeki batch-requests.
     */
    #[Test]
    public function trendyol_maps_rows_by_barcode(): void
    {
        Http::fake(['*' => Http::response([
            'batchRequestId' => 'BR-1',
            'status' => 'COMPLETED',
            'items' => [
                ['requestItem' => ['barcode' => 'BRK-A', 'quantity' => 3], 'status' => 'SUCCESS', 'failureReasons' => []],
                ['requestItem' => ['barcode' => 'BRK-B', 'quantity' => 1], 'status' => 'FAILED', 'failureReasons' => ['Ürün onaylı değil']],
            ],
        ], 200)]);

        $adapter = $this->trendyol();
        $status = $adapter->fetchBatchStatus('BR-1', SyncDomain::INVENTORY);

        $this->assertTrue($status->isCompleted());
        $this->assertSame([BatchStatus::OUTCOME_SUCCEEDED, null], $status->outcomeFor('BRK-A'));
        [$outcome, $failure] = $status->outcomeFor('BRK-B');
        $this->assertSame(BatchStatus::OUTCOME_FAILED, $outcome);
        $this->assertSame('Ürün onaylı değil', $failure->reason);
        $this->assertSame(ErrorClass::VALIDATION, $failure->class);
        // Yanıtta olmayan satır için hüküm UYDURULMAZ.
        $this->assertSame(BatchStatus::OUTCOME_UNKNOWN, $status->outcomeFor('BRK-C')[0]);

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET'
            && str_ends_with($r->url(), '/integration/product/sellers/123456/products/batch-requests/BR-1'));

        $this->assertSame('BR-1', $adapter->batchIdFrom(AdapterResult::success(['batch_request_id' => 'BR-1'])));
        $this->assertNull($adapter->batchIdFrom(AdapterResult::success(['pushed' => 0])));
    }

    /** Süren iş → pending; 404 → expired; durum alanı yoksa yalnız tüm satırlar bitmişse tamam. */
    #[Test]
    public function trendyol_distinguishes_pending_expired_and_statusless_completion(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['status' => 'IN_PROGRESS', 'items' => []], 200)
            ->push(['message' => 'not found'], 404)
            ->push(['items' => [['requestItem' => ['barcode' => 'X'], 'status' => 'SUCCESS']]], 200)
            ->push(['items' => [['requestItem' => ['barcode' => 'X'], 'status' => 'PROCESSING']]], 200)]);

        $adapter = $this->trendyol();

        $this->assertTrue($adapter->fetchBatchStatus('B', SyncDomain::PRICE)->isPending());
        $this->assertTrue($adapter->fetchBatchStatus('B', SyncDomain::PRICE)->isExpired());
        $this->assertTrue($adapter->fetchBatchStatus('B', SyncDomain::PRICE)->isCompleted());
        $this->assertTrue($adapter->fetchBatchStatus('B', SyncDomain::PRICE)->isPending());
    }

    // ───────────────────────────────────────────────────────── Hepsiburada

    /**
     * YALNIZ HATALAR LİSTELENİR: `hepsiburadaSku`'lu hata o satıra yazılır,
     * listelenmeyen satır başarılıdır. Stok sonucu `stock-uploads/id/{id}`.
     */
    #[Test]
    public function hepsiburada_stock_upload_lists_only_errors(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'U-1', 'status' => 'Done', 'total' => 2,
            'errors' => [['elementNo' => 0, 'hepsiburadaSku' => 'HBV-BAD', 'merchantSku' => 'BAD', 'errors' => ['Listing bulunamadı']]],
        ], 200)]);

        $adapter = $this->hepsiburada('M-1');
        $status = $adapter->fetchBatchStatus('U-1', SyncDomain::INVENTORY);

        $this->assertSame(BatchStatus::OUTCOME_FAILED, $status->outcomeFor('HBV-BAD')[0]);
        $this->assertSame('Listing bulunamadı', $status->outcomeFor('HBV-BAD')[1]->reason);
        $this->assertSame(BatchStatus::OUTCOME_SUCCEEDED, $status->outcomeFor('HBV-OK')[0]);

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET'
            && str_ends_with($r->url(), '/Listings/merchantid/M-1/stock-uploads/id/U-1'));
        $this->assertSame('U-1', $adapter->batchIdFrom(AdapterResult::success(['upload_id' => 'U-1'])));
    }

    /**
     * Fiyat sonucu `price-uploads/id/{id}`; fiyat bandı ihlali satırı
     * reddeder ve izin verilen aralık sebepte görünür.
     */
    #[Test]
    public function hepsiburada_price_validation_fails_the_row_with_the_range(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'P-1', 'status' => 'Completed', 'errors' => [],
            'priceValidations' => [[
                'hepsiburadaSku' => 'HBV-P', 'type' => 'OutOfPriceRange',
                'minPrice' => 10, 'maxPrice' => 80, 'description' => 'Fiyat bant dışında',
            ]],
        ], 200)]);

        $status = $this->hepsiburada('M-2')->fetchBatchStatus('P-1', SyncDomain::PRICE);

        [$outcome, $failure] = $status->outcomeFor('HBV-P');
        $this->assertSame(BatchStatus::OUTCOME_FAILED, $outcome);
        $this->assertStringContainsString('Fiyat bant dışında', $failure->reason);
        $this->assertStringContainsString('10 – 80', $failure->reason);

        Http::assertSent(static fn (Request $r): bool => str_ends_with($r->url(), '/Listings/merchantid/M-2/price-uploads/id/P-1'));
    }

    /**
     * Kimliksiz hata varsa listelenmeyenler başarılı SAYILMAZ; süren
     * yükleme pending, unutulan expired.
     */
    #[Test]
    public function hepsiburada_unmatched_error_cancels_the_success_inference(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['id' => 'U', 'status' => 'Done', 'errors' => [['elementNo' => 3, 'errors' => ['Hatalı satır']]]], 200)
            ->push(['id' => 'U', 'status' => 'InProgress', 'errors' => null], 200)
            ->push([], 404)]);

        $adapter = $this->hepsiburada('M-3');

        $status = $adapter->fetchBatchStatus('U', SyncDomain::INVENTORY);
        $this->assertTrue($status->isCompleted());
        $this->assertSame(['Hatalı satır'], $status->unmatched);
        $this->assertSame(BatchStatus::OUTCOME_UNKNOWN, $status->outcomeFor('HBV-ANY')[0]);

        $this->assertTrue($adapter->fetchBatchStatus('U', SyncDomain::INVENTORY)->isPending());
        $this->assertTrue($adapter->fetchBatchStatus('U', SyncDomain::INVENTORY)->isExpired());
    }

    // ────────────────────────────────────────────────────────────────── N11

    /**
     * `task-details/page-query`: gövde `taskId` SAYI + `pageable`; satır
     * `itemCode` ile eşlenir, `FAIL` sebepleri taşınır.
     */
    #[Test]
    public function n11_reads_task_details_per_sku(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 1092, 'status' => 'PROCESSED',
            'skus' => ['content' => [
                ['itemCode' => 'SC-1', 'status' => 'SUCCESS', 'reasons' => []],
                ['itemCode' => 'SC-2', 'status' => 'FAIL', 'reasons' => ['Fiyat 2 ondalık olmalı']],
            ], 'totalPages' => 1],
        ], 200)]);

        $adapter = $this->n11();
        $status = $adapter->fetchBatchStatus('1092', SyncDomain::PRICE);

        $this->assertSame(BatchStatus::OUTCOME_SUCCEEDED, $status->outcomeFor('SC-1')[0]);
        $this->assertSame('Fiyat 2 ondalık olmalı', $status->outcomeFor('SC-2')[1]->reason);

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
            && $r->url() === 'https://api.n11.com/ms/product/task-details/page-query'
            && $r->data() === ['taskId' => 1092, 'pageable' => ['page' => 0, 'size' => 1000]]
            && $r->header('appKey') === ['ANAHTAR-1']);
        $this->assertSame('1092', $adapter->batchIdFrom(AdapterResult::success(['task_id' => '1092'])));
    }

    /** `IN_QUEUE` sürüyor; `REJECT` görevdeki HER satırı aynı sebeple düşürür. */
    #[Test]
    public function n11_queue_is_pending_and_reject_fails_every_row(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['id' => 7, 'status' => 'IN_QUEUE'], 200)
            ->push(['id' => 7, 'status' => 'REJECT', 'reasons' => ['Geçersiz gövde']], 200)]);

        $adapter = $this->n11();

        $this->assertTrue($adapter->fetchBatchStatus('7', SyncDomain::INVENTORY)->isPending());

        $rejected = $adapter->fetchBatchStatus('7', SyncDomain::INVENTORY);
        $this->assertSame(BatchStatus::OUTCOME_FAILED, $rejected->outcomeFor('HERHANGI')[0]);
        $this->assertStringContainsString('Geçersiz gövde', $rejected->outcomeFor('HERHANGI')[1]->reason);
    }

    // ────────────────────────────────────────────────────────── Çiçeksepeti

    /**
     * `batch-status/{id}`: `Success`/`Warning` başarı; `Failed` + 4xxx kodu
     * KALICI (bilinmeyen stockCode), kodsuz `Failed` GEÇİCİ (teknik hata).
     */
    #[Test]
    public function ciceksepeti_classifies_failed_rows_by_code(): void
    {
        Http::fake(['*' => Http::response([
            'batchId' => 'CS-1', 'itemCount' => 4,
            'items' => [
                ['data' => ['stockCode' => 'OK-1'], 'status' => 'Success', 'failureReasons' => []],
                ['data' => ['StockCode' => 'WARN-1'], 'status' => 'Warning', 'failureReasons' => [['message' => 'Liste fiyatı yüksek', 'code' => 3001]]],
                ['data' => ['stockCode' => 'BAD-1'], 'status' => 'Failed', 'failureReasons' => [['message' => 'Girmiş olduğunuz kod bulunmamaktadır', 'code' => 4000]]],
                ['data' => ['stockCode' => 'TECH-1'], 'status' => 'Failed', 'failureReasons' => [['message' => 'Sistem hatası']]],
            ],
        ], 200)]);

        $adapter = $this->ciceksepeti();
        $status = $adapter->fetchBatchStatus('CS-1', SyncDomain::INVENTORY);

        $this->assertSame(BatchStatus::OUTCOME_SUCCEEDED, $status->outcomeFor('OK-1')[0]);
        $this->assertSame(BatchStatus::OUTCOME_SUCCEEDED, $status->outcomeFor('WARN-1')[0]);
        $this->assertSame(ErrorClass::VALIDATION, $status->outcomeFor('BAD-1')[1]->class);
        $this->assertStringContainsString('(4000)', $status->outcomeFor('BAD-1')[1]->reason);
        $this->assertSame(ErrorClass::SERVER_ERROR, $status->outcomeFor('TECH-1')[1]->class);

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET'
            && $r->url() === 'https://apis.ciceksepeti.com/api/v1/Products/batch-status/CS-1'
            && $r->header('x-api-key') === ['CS-ANAHTAR-123']);
        $this->assertSame('CS-1', $adapter->batchIdFrom(AdapterResult::success(['batch_id' => 'CS-1'])));
        $this->assertSame(4 * 3600, $adapter->batchRetentionSeconds(SyncDomain::INVENTORY));
    }

    /** Tek bir `Processing` satırı işi sürdürür; 404 iş unutuldu demektir. */
    #[Test]
    public function ciceksepeti_one_processing_row_keeps_the_batch_pending(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['batchId' => 'X', 'items' => [
                ['data' => ['stockCode' => 'A'], 'status' => 'Success'],
                ['data' => ['stockCode' => 'B'], 'status' => 'Processing'],
            ]], 200)
            ->push(['message' => 'yok'], 404)]);

        $adapter = $this->ciceksepeti();

        $this->assertTrue($adapter->fetchBatchStatus('X', SyncDomain::INVENTORY)->isPending());
        $this->assertTrue($adapter->fetchBatchStatus('X', SyncDomain::INVENTORY)->isExpired());
    }

    // ───────────────────────────────────────────────────────────── Pazarama

    /**
     * `lake-projections`: alanın alt nesnesi okunur (fiyatta `price`);
     * `0` başarı, `2` ret (sebep `operationDetail`), `5` onay bekliyor —
     * başarı DEĞİL.
     */
    #[Test]
    public function pazarama_reads_the_domain_section_and_approval_state(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            ['code' => 'BRK-1', 'price' => ['status' => 0, 'operationDetail' => null]],
            ['code' => 'BRK-2', 'price' => ['status' => 2, 'operationDetail' => 'Satış fiyatı liste fiyatından büyük']],
            ['code' => 'BRK-3', 'price' => ['status' => 5, 'operationDetail' => 'Ürün fiyat onayına gönderildi']],
        ]], 200)]);

        $adapter = $this->pazarama();
        $status = $adapter->fetchBatchStatus('GUID-1', SyncDomain::PRICE);

        $this->assertSame(BatchStatus::OUTCOME_SUCCEEDED, $status->outcomeFor('BRK-1')[0]);
        $this->assertSame('Satış fiyatı liste fiyatından büyük', $status->outcomeFor('BRK-2')[1]->reason);
        $this->assertSame(BatchStatus::OUTCOME_AWAITING_APPROVAL, $status->outcomeFor('BRK-3')[0]);
        $this->assertSame('Ürün fiyat onayına gönderildi', $status->outcomeFor('BRK-3')[1]->reason);

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET'
            && str_starts_with($r->url(), 'https://isortagimapi.pazarama.com/listing-state/batch-id/GUID-1/lake-projections')
            && str_contains($r->url(), 'page=1&pageSize=3000')
            && $r->header('Authorization') === ['Bearer TOKEN-1']);
        $this->assertSame('GUID-1', $adapter->batchIdFrom(AdapterResult::success(['batch_id' => 'GUID-1'])));
    }

    /** `3` İşleniyor → pending (zarf `data.items` da okunur); 404 → expired. */
    #[Test]
    public function pazarama_processing_row_is_pending(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['success' => true, 'data' => ['items' => [
                ['code' => 'A', 'stock' => ['status' => 0]],
                ['code' => 'B', 'stock' => ['status' => 3]],
            ]]], 200)
            ->push(['success' => true, 'data' => ['items' => [
                ['code' => 'A', 'stock' => ['status' => 0]],
                ['code' => 'B', 'stock' => ['status' => 1, 'operationDetail' => 'Ürün bulunamadı']],
            ]]], 200)
            ->push('', 404)]);

        $adapter = $this->pazarama();

        $this->assertTrue($adapter->fetchBatchStatus('G', SyncDomain::INVENTORY)->isPending());

        $done = $adapter->fetchBatchStatus('G', SyncDomain::INVENTORY);
        $this->assertSame(BatchStatus::OUTCOME_SUCCEEDED, $done->outcomeFor('A')[0]);
        $this->assertSame(BatchStatus::OUTCOME_FAILED, $done->outcomeFor('B')[0]);

        $this->assertTrue($adapter->fetchBatchStatus('G', SyncDomain::INVENTORY)->isExpired());
    }

    /** Beş kanal da yeteneği bildirir; saklama süreleri pozitif. */
    #[Test]
    public function the_five_async_channels_declare_the_capability(): void
    {
        foreach ([$this->trendyol(), $this->hepsiburada('M-9'), $this->n11(), $this->ciceksepeti(), $this->pazarama()] as $adapter) {
            $this->assertInstanceOf(SupportsBatchStatus::class, $adapter);
            $this->assertGreaterThan(0, $adapter->batchRetentionSeconds(SyncDomain::INVENTORY));
        }

        $this->assertSame(4 * 3600, $this->pazarama()->batchRetentionSeconds(SyncDomain::PRICE));
    }

    // ──────────────────────────────────────────────────────────── yardımcı

    private function trendyol(): TrendyolAdapter
    {
        return $this->build('trendyol', TrendyolAdapter::class, '123456',
            ['supplier_id' => '123456'], ['api_key' => 'anahtar', 'api_secret' => 'sifre']);
    }

    private function hepsiburada(string $merchantId): HepsiburadaAdapter
    {
        return $this->build('hepsiburada', HepsiburadaAdapter::class, $merchantId,
            [HepsiburadaAdapter::INTEGRATOR_KEY => 'firma_dev'], ['service_key' => 'ANAHTAR12345']);
    }

    private function n11(): N11Adapter
    {
        return $this->build('n11', N11Adapter::class, 'testMagaza',
            [N11Adapter::SELLER_NAME_KEY => 'testMagaza'], ['app_key' => 'ANAHTAR-1', 'app_secret' => 'SIR-GIZLI-12345']);
    }

    private function ciceksepeti(): CiceksepetiAdapter
    {
        return $this->build('ciceksepeti', CiceksepetiAdapter::class, 'cs-'.uniqid(),
            [CiceksepetiAdapter::SELLER_ID_KEY => '998877'], [CiceksepetiAdapter::API_KEY_SECRET => 'CS-ANAHTAR-123']);
    }

    private function pazarama(): PazaramaAdapter
    {
        return $this->build('pazarama', PazaramaAdapter::class, 'pz-'.uniqid(),
            [PazaramaAdapter::SELLER_NAME_KEY => 'magazam'],
            ['client_id' => 'CID-1', 'client_secret' => 'SIR-GIZLI-12345', 'access_token' => 'TOKEN-1']);
    }

    /**
     * @template T of ChannelAdapter
     *
     * @param  class-string<T>  $class
     * @param  array<string, string>  $settings
     * @param  array<string, string>  $secrets
     * @return T
     */
    private function build(string $code, string $class, string $account, array $settings, array $secrets): ChannelAdapter
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => ucfirst($code), 'kind' => 'marketplace', 'adapter_class' => $class,
                'capabilities' => [], 'rate_limit_profile' => [], 'supports_webhooks' => false, 'is_active' => true,
            ],
        ));

        $tenant = (new CreateTenant)->run(name: $code.' '.uniqid(), owner: User::factory()->create());

        return $this->asTenant($tenant, function () use ($code, $class, $account, $settings, $secrets): ChannelAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => $code,
                'external_account_id' => $account,
                'settings' => $settings,
            ]);

            app(CredentialVault::class)->store($connection, $secrets);

            return new $class(
                $connection,
                new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)),
            );
        });
    }
}
