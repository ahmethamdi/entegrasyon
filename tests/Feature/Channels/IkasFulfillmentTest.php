<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Ikas\IkasAdapter;
use App\Domain\Channels\Adapters\Ikas\IkasGraphQLException;
use App\Domain\Channels\Adapters\Ikas\IkasQueries;
use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Sync\Enums\ErrorClass;
use App\Support\Logging\PayloadRedactor;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ikas kargo bildirimi — `fulfillOrder`.
 *
 * Her başarılı çağrı yeni paket açtığı için adapter önce siparişin
 * paketlerini okur. ikas hata oranı yüksek mağazayı engellediği için
 * reddedileceği bilinen istek hiç atılmaz.
 */
final class IkasFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    /** Açık kalemler, eşleşen firma kimliği ve numara `fulfillOrder` girdisine yazılır; paket kimliği döner. */
    #[Test]
    public function the_tracking_number_is_sent_with_the_open_lines(): void
    {
        $this->fakeIkas(fulfill: Http::response(['data' => ['fulfillOrder' => [
            'id' => 'o-1',
            'orderPackages' => [['id' => 'pkg-9', 'orderLineItemIds' => ['l-1'], 'deleted' => false, 'trackingInfo' => ['trackingNumber' => 'YK123']]],
        ]]]));

        $result = $this->push(carrier: 'mng kargo', tracking: 'YK123');

        $this->assertTrue($result->successful);
        $this->assertSame('pkg-9', $result->data['external_id'] ?? null);

        Http::assertSent(function (HttpRequest $r): bool {
            if (! str_contains((string) $r['query'], 'fulfillOrder')) {
                return false;
            }

            $input = $r['variables']['input'];

            return $r->url() === IkasQueries::GRAPHQL_URL
                && $r->hasHeader('Authorization', 'Bearer TOKEN-1')
                && $input['orderId'] === 'o-1'
                // Kargolanmış ve silinmiş kalem gönderilmez.
                && $input['lines'] === [['orderLineItemId' => 'l-1', 'quantity' => 2]]
                && $input['trackingInfoDetail'] === ['trackingNumber' => 'YK123', 'cargoCompany' => 'MNG Kargo', 'cargoCompanyId' => 'MNG_KARGO'];
        });
    }

    /**
     * ⚠️ NUMARA BİR PAKETTE ZATEN VARSA İSTEK ATILMAZ.
     *
     * Yanıtı kaybolan istek yeniden denendiğinde ikas aynı numarayla ikinci
     * paketi açardı. Karşılaştırma boşluk ve harf farkını yok sayar.
     */
    #[Test]
    public function an_already_recorded_tracking_number_is_not_sent_again(): void
    {
        $this->fakeIkas(packages: [
            ['id' => 'pkg-1', 'orderLineItemIds' => ['l-1'], 'orderPackageFulfillStatus' => 'FULFILLED', 'deleted' => false, 'trackingInfo' => ['trackingNumber' => 'YK123']],
        ]);

        $result = $this->push(tracking: ' yk 123 ');

        $this->assertTrue($result->successful);
        $this->assertTrue($result->data['already_shipped'] ?? false);
        $this->assertSame('pkg-1', $result->data['external_id'] ?? null);
        Http::assertNotSent(fn (HttpRequest $r): bool => str_contains((string) $r['query'], 'fulfillOrder'));
    }

    /** İptal edilmiş paketteki numara "zaten var" sayılmaz — o numarayla yeniden kargolamak meşru. */
    #[Test]
    public function a_cancelled_package_does_not_block_the_same_number(): void
    {
        $this->fakeIkas(packages: [
            ['id' => 'pkg-1', 'orderLineItemIds' => ['l-1'], 'orderPackageFulfillStatus' => 'CANCELLED', 'deleted' => false, 'trackingInfo' => ['trackingNumber' => 'YK123']],
        ]);

        $this->assertTrue($this->push()->successful);

        Http::assertSent(fn (HttpRequest $r): bool => str_contains((string) $r['query'], 'fulfillOrder'));
    }

    /** Sipariş okunamazsa gönderilmez — geçici hata ikinci paket demek olurdu. */
    #[Test]
    public function an_unreadable_order_stops_the_push(): void
    {
        Http::fake(['*' => Http::response(['message' => 'down'], 503)]);

        try {
            $this->push();
            $this->fail('Okunamayan sipariş yutuldu.');
        } catch (RequestException $e) {
            $this->assertSame(503, $e->response->status());
        }

        Http::assertNotSent(fn (HttpRequest $r): bool => str_contains((string) $r['query'], 'fulfillOrder'));
    }

    /** Açık kalem yoksa mutation atılmaz (reddedilir ve hata oranına yazılırdı). */
    #[Test]
    public function an_order_without_open_lines_sends_nothing(): void
    {
        $this->fakeIkas(lines: [['id' => 'l-1', 'quantity' => 1, 'status' => 'FULFILLED', 'deleted' => false]]);

        $result = $this->push();

        $this->assertTrue($result->successful);
        $this->assertTrue($result->data['already_fulfilled'] ?? false);
        Http::assertNotSent(fn (HttpRequest $r): bool => str_contains((string) $r['query'], 'fulfillOrder'));
    }

    /** Siparişin kanal kimliği yoksa istek atılmaz. */
    #[Test]
    public function an_order_without_an_ikas_id_sends_nothing(): void
    {
        Http::fake();

        $result = $this->push(orderId: null);

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        Http::assertNothingSent();
    }

    /**
     * ⚠️ İZİN HATASI (`FORBIDDEN`) KİMLİK HATASI DEĞİL, `VALIDATION`DIR.
     *
     * `AUTHENTICATION` sayılsaydı devre kesici SÜRESİZ açılır ve sipariş
     * yazma izni olmayan tek kargo bildirimi stok akışını durdururdu.
     */
    #[Test]
    public function a_forbidden_mutation_is_validation_not_authentication(): void
    {
        $this->fakeIkas(fulfill: Http::response(['errors' => [['message' => 'Not allowed', 'extensions' => ['code' => 'FORBIDDEN']]]]));

        $result = $this->push();

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        $this->assertStringContainsString('ikas panelinden', (string) $result->errorMessage);
    }

    /** HTTP 403 de aynı: `VALIDATION`. */
    #[Test]
    public function an_http_forbidden_is_validation_not_authentication(): void
    {
        $this->fakeIkas(fulfill: Http::response(['message' => 'Forbidden'], 403));

        $result = $this->push();

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
    }

    /** Süresi dolmuş token (`UNAUTHENTICATED`) dokunulmadan yükselir; onu yenileme düzeltir. */
    #[Test]
    public function an_unauthenticated_error_is_left_to_classification(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['message' => 'expired', 'extensions' => ['code' => 'UNAUTHENTICATED']]]])]);

        $this->expectException(IkasGraphQLException::class);

        $this->push();
    }

    /**
     * ⚠️ MAĞAZANIN LİSTESİNDE OLMAYAN FİRMA YALNIZ ADIYLA GİDER.
     *
     * Uydurma bir `cargoCompanyId` reddedilir ve hata oranına yazılırdı;
     * firma adı paket bilgisinde korunur.
     */
    #[Test]
    public function an_unknown_carrier_is_sent_by_name_only(): void
    {
        $this->fakeIkas();

        $this->assertTrue($this->push(carrier: 'Bizim Kurye')->successful);

        Http::assertSent(fn (HttpRequest $r): bool => str_contains((string) $r['query'], 'fulfillOrder')
            && $r['variables']['input']['trackingInfoDetail'] === ['trackingNumber' => 'YK123', 'cargoCompany' => 'Bizim Kurye']);
    }

    /** Firma listesi okunamazsa numara yine gider — liste yardımcı bilgidir. */
    #[Test]
    public function an_unreadable_carrier_list_does_not_stop_the_push(): void
    {
        $this->fakeIkas(carriers: Http::response(['errors' => [['message' => 'Cannot query field', 'extensions' => ['code' => 'GRAPHQL_VALIDATION_FAILED']]]]));

        $this->assertTrue($this->push(carrier: 'MNG Kargo')->successful);

        Http::assertSent(fn (HttpRequest $r): bool => str_contains((string) $r['query'], 'fulfillOrder')
            && $r['variables']['input']['trackingInfoDetail'] === ['trackingNumber' => 'YK123', 'cargoCompany' => 'MNG Kargo']);
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    /**
     * @param  list<array<string, mixed>>|null  $lines
     * @param  list<array<string, mixed>>  $packages
     */
    private function fakeIkas(
        ?array $lines = null,
        array $packages = [],
        ?PromiseInterface $carriers = null,
        ?PromiseInterface $fulfill = null,
    ): void {
        $lines ??= [
            ['id' => 'l-1', 'quantity' => 2, 'status' => 'UNFULFILLED', 'deleted' => false],
            ['id' => 'l-2', 'quantity' => 1, 'status' => 'FULFILLED', 'deleted' => false],
            ['id' => 'l-3', 'quantity' => 1, 'status' => 'UNFULFILLED', 'deleted' => true],
        ];

        Http::fake(function (HttpRequest $r) use ($lines, $packages, $carriers, $fulfill): PromiseInterface {
            $query = (string) $r['query'];

            return match (true) {
                str_contains($query, 'fulfillOrder') => $fulfill ?? Http::response(['data' => ['fulfillOrder' => ['id' => 'o-1', 'orderPackages' => []]]]),
                str_contains($query, 'listCargoCompany') => $carriers ?? Http::response(['data' => ['listCargoCompany' => [
                    ['id' => 'MNG_KARGO', 'name' => 'MNG Kargo'],
                    ['id' => 'YURTICI_KARGO', 'name' => 'Yurtiçi Kargo'],
                ]]]),
                str_contains($query, 'listOrder') => Http::response(['data' => ['listOrder' => ['data' => [
                    ['id' => 'o-1', 'orderLineItems' => $lines, 'orderPackages' => $packages],
                ]]]]),
                default => Http::response(['errors' => [['message' => 'beklenmeyen sorgu']]], 500),
            };
        });
    }

    private function push(?string $carrier = 'MNG Kargo', string $tracking = 'YK123', ?string $orderId = 'o-1'): AdapterResult
    {
        $order = new Order;
        $order->external_id = $orderId;

        $fulfillment = new Fulfillment;
        $fulfillment->carrier = $carrier;
        $fulfillment->tracking_number = $tracking;
        $fulfillment->setRelation('order', $order);

        return $this->adapter()->pushFulfillment($fulfillment);
    }

    private function adapter(): IkasAdapter
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'ikas'],
            ['name' => 'ikas', 'kind' => 'storefront', 'adapter_class' => IkasAdapter::class, 'supports_webhooks' => false, 'is_active' => false],
        ));

        $tenant = (new CreateTenant)->run(name: 'ikas Kargo '.uniqid(), owner: User::factory()->create());

        return $this->asTenant($tenant, function (): IkasAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'ikas',
                'external_account_id' => 'magazam',
                'settings' => [],
            ]);

            app(CredentialVault::class)->store($connection, ['client_id' => 'CID', 'client_secret' => 'CSECRET-123', 'access_token' => 'TOKEN-1']);

            return new IkasAdapter(
                $connection,
                new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)),
            );
        });
    }
}
