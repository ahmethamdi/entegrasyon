<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Fulfillment\Actions\RequestShippingLabel;
use App\Domain\Fulfillment\Enums\FulfillmentReason;
use App\Domain\Fulfillment\Jobs\PrepareShippingLabel;
use App\Domain\Fulfillment\Models\FulfillmentBatchItem;
use App\Domain\Fulfillment\Models\ShippingLabel;
use App\Domain\Fulfillment\Support\LabelDocuments;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Orders\Enums\OrderState;
use App\Domain\Orders\Models\Order;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S2 · Trendyol ortak kargo etiketi — yaşam döngüsü ve idempotens.
 *
 * Kural: kanala etiket isteği (POST) paket başına EN FAZLA BİR KEZ gider;
 * sonucu belirsiz istek tekrarlanmaz, yalnız etiket sorulur.
 */
final class ShippingLabelTest extends TestCase
{
    use BuildsFulfillmentScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfillment();
    }

    #[Test]
    public function a_label_is_requested_once_then_fetched_and_stored_untouched(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5001');
        $label = $this->request($tenant, $order);

        $this->runLabelJob($tenant, $label->id)->assertReleased(PrepareShippingLabel::POLL_SECONDS);
        $this->assertSame(1, $this->labelPostCount);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/integration/sellers/999001/common-label/7330000005001')
            && $r['format'] === 'ZPL' && $r['boxQuantity'] === 1);
        $this->assertSame(ShippingLabel::STATUS_REQUESTED, $this->labelFor($tenant, $order)->status);

        // İkinci tur: henüz yok → bekler; üçüncü tur: hazır.
        $this->labelGets = [Http::response(['data' => []]), $this->zplResponse(self::ZPL_1)];
        $this->runLabelJob($tenant, $label->id)->assertReleased(PrepareShippingLabel::POLL_SECONDS);
        $this->runLabelJob($tenant, $label->id)->assertNotReleased();

        $ready = $this->labelFor($tenant, $order);
        $this->assertSame(ShippingLabel::STATUS_READY, $ready->status);
        $this->assertSame('ZPL', $ready->format);
        $this->assertSame('7330000005001', $ready->cargo_tracking_number);
        $this->assertSame('Trendyol Express Marketplace', $ready->cargo_provider);
        $this->assertSame([['format' => 'ZPL', 'label' => self::ZPL_1]], $ready->labels(), 'Etiketin ASLI bayt bayt saklanır.');
        $this->assertSame(hash('sha256', (string) $ready->payload), $ready->payload_sha256);
        $this->assertSame(1, $this->labelPostCount, 'Hazır olunca ikinci istek yok.');
    }

    #[Test]
    public function repeated_requests_and_repeated_jobs_never_post_twice(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5002');

        $first = $this->request($tenant, $order);
        $second = $this->request($tenant, $order);
        $this->assertSame($first->id, $second->id, 'Paket başına tek satır.');
        Queue::assertPushed(PrepareShippingLabel::class, 1);

        $this->runLabelJob($tenant, $first->id);
        $this->runLabelJob($tenant, $first->id);
        $this->runLabelJob($tenant, $first->id);

        $this->assertSame(1, $this->labelPostCount);
        $this->assertSame(1, TenantContext::runFor($tenant->id, fn () => ShippingLabel::query()->count()));
    }

    #[Test]
    public function an_existing_label_is_fetched_without_requesting_a_new_one(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5003');
        $label = $this->request($tenant, $order);
        $this->labelGets = [$this->zplResponse(self::ZPL_1)];

        $this->runLabelJob($tenant, $label->id);

        $this->assertSame(0, $this->labelPostCount, 'Panelde/öncekinde üretilmiş etiket için istek gönderilmez.');
        $this->assertSame(ShippingLabel::STATUS_READY, $this->labelFor($tenant, $order)->status);
    }

    #[Test]
    public function a_timed_out_request_is_never_resent_only_polled(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5004');
        $label = $this->request($tenant, $order);
        $this->onLabelPost = fn () => Http::failedConnection();

        $this->runLabelJob($tenant, $label->id)->assertReleased();

        $after = $this->labelFor($tenant, $order);
        $this->assertNotNull($after->requested_at, 'Sonucu belirsiz istek "gitmiş olabilir" sayılır.');
        $this->assertSame(ShippingLabel::STATUS_REQUESTED, $after->status);

        $this->onLabelPost = null;
        $this->labelGets = [Http::response(['data' => []]), $this->zplResponse(self::ZPL_1)];
        $this->runLabelJob($tenant, $label->id)->assertReleased(PrepareShippingLabel::POLL_SECONDS);
        $this->runLabelJob($tenant, $label->id);

        $this->assertSame(1, $this->labelPostCount, 'Zaman aşımından sonra POST tekrarlanmadı.');
        $this->assertSame(ShippingLabel::STATUS_READY, $this->labelFor($tenant, $order)->status);
    }

    #[Test]
    public function a_queue_retry_racing_a_claimed_request_does_not_post(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5005');
        $label = $this->request($tenant, $order);
        // Başka bir işçi isteği talep etmiş (requested_at yazılmış).
        TenantContext::runFor($tenant->id, fn () => ShippingLabel::query()->whereKey($label->id)->update(['requested_at' => now(), 'status' => 'requested']));

        $this->runLabelJob($tenant, $label->id)->assertReleased(PrepareShippingLabel::POLL_SECONDS);

        $this->assertSame(0, $this->labelPostCount);
        $this->assertSame(1, $this->labelGetCount);
    }

    #[Test]
    public function a_worker_that_loses_the_claim_race_mid_job_does_not_post(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5017');
        $label = $this->request($tenant, $order);
        // İş satırı talepsiz okudu; etiketi sorarken başka işçi talebi aldı.
        $this->labelGets = [function () use ($tenant, $label) {
            TenantContext::runFor($tenant->id, fn () => ShippingLabel::query()->whereKey($label->id)->update(['requested_at' => now(), 'status' => 'requested']));

            return Http::response(['data' => []]);
        }];

        $this->runLabelJob($tenant, $label->id)->assertReleased(PrepareShippingLabel::POLL_SECONDS);

        $this->assertSame(0, $this->labelPostCount, 'Koşullu talep yarışı kaybeden işçi istek göndermez.');
    }

    #[Test]
    public function a_definite_rejection_releases_the_claim_and_explains_why(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5006');
        $label = $this->request($tenant, $order);
        $this->onLabelPost = fn () => Http::response(['errors' => [['code' => 1001, 'type' => 'BadRequestException', 'message' => 'Please check the cargo tracking number you provided']]], 400);

        $this->runLabelJob($tenant, $label->id)->assertNotReleased();

        $failed = $this->labelFor($tenant, $order);
        $this->assertSame(ShippingLabel::STATUS_FAILED, $failed->status);
        $this->assertSame(FulfillmentReason::LABEL_CREATION_FAILED->value, $failed->reason_code);
        $this->assertNull($failed->requested_at, 'Kesin ret kanalda iz bırakmaz; açık yeniden deneme yeni istek gönderebilir.');
    }

    #[Test]
    public function an_unsupported_cargo_provider_is_never_asked_for_a_label(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5007', packageOverrides: ['cargoProviderName' => 'Yurtiçi Kargo Marketplace']);
        $label = $this->request($tenant, $order);

        $this->runLabelJob($tenant, $label->id);

        $failed = $this->labelFor($tenant, $order);
        $this->assertSame(FulfillmentReason::CARGO_PROVIDER_UNSUPPORTED->value, $failed->reason_code);
        $this->assertSame('Yurtiçi Kargo Marketplace', $failed->cargo_provider);
        $this->assertSame(0, $this->labelPostCount + $this->labelGetCount);
    }

    #[Test]
    public function an_account_without_common_label_permission_gets_a_stable_reason(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5008');
        $label = $this->request($tenant, $order);
        $this->labelGets = [Http::response(self::NOT_ALLOWED, 400)];

        $this->runLabelJob($tenant, $label->id);

        $failed = $this->labelFor($tenant, $order);
        $this->assertSame(FulfillmentReason::COMMON_LABEL_NOT_AUTHORIZED->value, $failed->reason_code, 'Kod saklanır, Türkçe metin değil.');
        $this->assertSame(0, $this->labelPostCount);
    }

    #[Test]
    public function a_label_that_never_becomes_ready_ends_as_not_ready(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5009');
        $label = $this->request($tenant, $order);
        $this->runLabelJob($tenant, $label->id);

        for ($i = 1; $i < PrepareShippingLabel::MAX_POLLS; $i++) {
            $this->runLabelJob($tenant, $label->id)->assertReleased(PrepareShippingLabel::POLL_SECONDS);
            $this->assertSame(FulfillmentReason::LABEL_NOT_READY->value, $this->labelFor($tenant, $order)->reason_code);
        }

        $this->runLabelJob($tenant, $label->id)->assertNotReleased();

        $failed = $this->labelFor($tenant, $order);
        $this->assertSame(ShippingLabel::STATUS_FAILED, $failed->status);
        $this->assertSame(FulfillmentReason::LABEL_NOT_READY->value, $failed->reason_code);
        $this->assertNotNull($failed->requested_at);
        $this->assertSame(1, $this->labelPostCount);
    }

    #[Test]
    public function a_package_cancelled_on_the_channel_is_not_labelled_even_if_our_state_is_stale(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5010', packageOverrides: ['shipmentPackageStatus' => 'Cancelled', 'status' => 'Cancelled']);
        $label = $this->request($tenant, $order);

        $this->runLabelJob($tenant, $label->id);

        $this->assertSame(FulfillmentReason::INVALID_ORDER_STATE->value, $this->labelFor($tenant, $order)->reason_code);
        $this->assertSame(0, $this->labelPostCount);
    }

    #[Test]
    public function an_invalid_canonical_state_is_rejected_without_creating_a_label(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $cancelled = $this->awaitingOrder($tenant, $connection, '5011', state: 'cancelled');
        $unknown = $this->awaitingOrder($tenant, $connection, '5012');
        TenantContext::runFor($tenant->id, fn () => Order::query()->whereKey($unknown->id)->update(['state' => null]));

        foreach ([$cancelled, $unknown->fresh()] as $order) {
            $decision = TenantContext::runFor($tenant->id, fn () => app(RequestShippingLabel::class)->run($order));
            $this->assertSame(FulfillmentBatchItem::ACTION_REQUIRED, $decision->outcome);
            $this->assertSame(FulfillmentReason::INVALID_ORDER_STATE, $decision->reason);
        }

        $this->assertNull($this->labelFor($tenant, $cancelled));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_failed_label_is_retried_only_by_an_explicit_request(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5013');
        $label = $this->request($tenant, $order);
        $this->labelGets = [Http::response(self::NOT_ALLOWED, 400)];
        $this->runLabelJob($tenant, $label->id);
        $this->assertSame(ShippingLabel::STATUS_FAILED, $this->labelFor($tenant, $order)->status);

        $this->request($tenant, $order);

        $this->assertSame(ShippingLabel::STATUS_PENDING, $this->labelFor($tenant, $order)->status);
        Queue::assertPushed(PrepareShippingLabel::class, 2);
    }

    #[Test]
    public function multiple_boxes_keep_every_original_label_and_the_zpl_bundle_is_untouched(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5014', packageOverrides: ['boxQuantity' => 2]);
        $label = $this->request($tenant, $order);
        $this->labelGets = [Http::response(['data' => []]), $this->zplResponse(self::ZPL_1, self::ZPL_2)];

        $this->runLabelJob($tenant, $label->id);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['boxQuantity'] === 2);
        $this->runLabelJob($tenant, $label->id);

        $ready = $this->labelFor($tenant, $order);
        $this->assertCount(2, $ready->labels());
        $this->assertSame(self::ZPL_1."\n".self::ZPL_2."\n", app(LabelDocuments::class)->zplBundle(collect([$ready])));
    }

    #[Test]
    public function a_pdf_label_is_kept_as_a_reference_and_excluded_from_the_zpl_bundle(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5015');
        $label = $this->request($tenant, $order);
        $this->labelGets = [Http::response(['data' => [['format' => 'PDF', 'label' => 'https://cdn.dsmcdn.com/x/etiket.pdf']]])];

        $this->runLabelJob($tenant, $label->id);

        $ready = $this->labelFor($tenant, $order);
        $this->assertSame('PDF', $ready->format);
        $this->assertSame(['https://cdn.dsmcdn.com/x/etiket.pdf'], app(LabelDocuments::class)->pdfLinks($ready));
        $this->assertSame('', app(LabelDocuments::class)->zplBundle(collect([$ready])));
    }

    #[Test]
    public function an_unknown_label_format_is_stored_but_not_marked_ready(): void
    {
        Queue::fake();
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5016');
        $label = $this->request($tenant, $order);
        $this->labelGets = [Http::response(['data' => [['format' => 'EPL', 'label' => 'N\nA50,0,0']]])];

        $this->runLabelJob($tenant, $label->id);

        $row = $this->labelFor($tenant, $order);
        $this->assertSame(FulfillmentReason::LABEL_FORMAT_UNSUPPORTED->value, $row->reason_code);
        $this->assertSame([['format' => 'EPL', 'label' => 'N\nA50,0,0']], $row->labels(), 'Asıl atılmaz.');
    }

    #[Test]
    #[DataProvider('carriers')]
    public function only_common_label_carriers_are_eligible(string $provider, bool $eligible): void
    {
        [$tenant, $connection] = $this->trendyolTenant();
        $order = $this->awaitingOrder($tenant, $connection, '5100', packageOverrides: ['cargoProviderName' => $provider, 'shipmentPackageStatus' => 'Invoiced']);

        $shipment = TenantContext::runFor($tenant->id, fn () => app(AdapterRegistry::class)->for($connection)->fetchShipment($order));

        $this->assertSame($eligible, $shipment->commonLabelEligible);
        $this->assertSame(OrderState::AWAITING_SHIPMENT, $shipment->state, 'Ham durum adapter sınırında kanoniğe çevrilir.');
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function carriers(): array
    {
        return [
            'TEX' => ['Trendyol Express Marketplace', true],
            'Aras' => ['Aras Kargo Marketplace', true],
            'Yurtiçi' => ['Yurtiçi Kargo Marketplace', false],
            'Sürat' => ['Sürat Kargo Marketplace', false],
            'benzer ad' => ['Texas Cargo', false],
            'boş' => ['', false],
        ];
    }

    private function request(Tenant $tenant, Order $order): ShippingLabel
    {
        $decision = TenantContext::runFor($tenant->id, fn () => app(RequestShippingLabel::class)->run($order->fresh()));
        $this->assertNotNull($decision->labelId);

        return $this->labelFor($tenant, $order);
    }
}
