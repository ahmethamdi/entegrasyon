<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Adapters\Etsy\EtsyAuth;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Etsy kargo bildirimi — `createReceiptShipment`.
 *
 * Panelden girilen takip numarası Etsy siparişine yazılır. Etsy her
 * başarılı çağrıda alıcıya e-posta gönderdiği için tekrar zararsız
 * DEĞİLDİR; adapter önce siparişi okur.
 */
final class EtsyFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private const RECEIPT_URL = 'https://openapi.etsy.com/v3/application/shops/777/receipts/4193927841';

    private const TRACKING_URL = self::RECEIPT_URL.'/tracking';

    /** Numara ve firma doğru uca, iki kimlik başlığıyla gider; kargo kimliği döner. */
    #[Test]
    public function the_tracking_code_is_posted_to_the_receipt(): void
    {
        Http::fake([
            self::TRACKING_URL => Http::response($this->receipt([['receipt_shipping_id' => 991, 'tracking_code' => 'YK123', 'carrier_name' => 'DHL']]), 200),
            self::RECEIPT_URL => Http::response($this->receipt([]), 200),
        ]);

        $result = $this->push(carrier: 'DHL', tracking: 'YK123');

        $this->assertTrue($result->successful);
        $this->assertSame('991', $result->data['external_id'] ?? null);

        Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'POST'
            && $r->url() === self::TRACKING_URL
            && $r['tracking_code'] === 'YK123'
            && $r['carrier_name'] === 'DHL'
            && $r->hasHeader('x-api-key', 'key-abc:sir-xyz')
            && $r->hasHeader('Authorization', 'Bearer 12345.token'));
    }

    /**
     * ⚠️ NUMARA SİPARİŞTE ZATEN VARSA İSTEK ATILMAZ.
     *
     * Yanıtı kaybolan istek yeniden denendiğinde Etsy ikinci kargo kaydını
     * açar ve alıcı aynı numarayla İKİNCİ e-postayı alırdı. Karşılaştırma
     * boşluk ve harf farkını yok sayar.
     */
    #[Test]
    public function an_already_recorded_tracking_code_is_not_posted_again(): void
    {
        Http::fake([
            self::TRACKING_URL => Http::response([], 500),
            self::RECEIPT_URL => Http::response($this->receipt([['receipt_shipping_id' => 991, 'tracking_code' => 'YK123', 'carrier_name' => 'other']]), 200),
        ]);

        $result = $this->push(carrier: 'Yurtiçi Kargo', tracking: ' yk 123 ');

        $this->assertTrue($result->successful);
        $this->assertTrue($result->data['already_shipped'] ?? false);
        $this->assertSame('991', $result->data['external_id'] ?? null);
        Http::assertNotSent(fn (HttpRequest $r): bool => $r->method() === 'POST');
    }

    /**
     * ⚠️ SİPARİŞ OKUNAMAZSA GÖNDERİLMEZ.
     *
     * Okuma hatası "kargo yok" sayılsaydı geçici bir aksaklık alıcıya ikinci
     * e-posta demek olurdu. İstisna yükselir, iş yeniden dener.
     */
    #[Test]
    public function an_unreadable_receipt_stops_the_push(): void
    {
        Http::fake([
            self::TRACKING_URL => Http::response($this->receipt([]), 200),
            self::RECEIPT_URL => Http::response(['error' => 'down'], 503),
        ]);

        try {
            $this->push();
            $this->fail('Okunamayan sipariş yutuldu.');
        } catch (RequestException $e) {
            $this->assertSame(503, $e->response->status());
        }

        Http::assertNotSent(fn (HttpRequest $r): bool => $r->method() === 'POST');
    }

    /**
     * ⚠️ `transactions_w` EKLENMEDEN YETKİLENDİRİLMİŞ BAĞLANTI İSTEK ATMAZ.
     *
     * Etsy 403 dönerdi ve satıcı "kanal reddetti" görürdü; oysa çözüm
     * bizim ekranımızda ("İzin ver"). Mesaj oraya yönlendirir.
     */
    #[Test]
    public function a_connection_without_the_write_scope_sends_nothing(): void
    {
        Http::fake();

        $result = $this->push(grantedScopes: null);

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        $this->assertStringContainsString('İzin ver', (string) $result->errorMessage);
        Http::assertNothingSent();
    }

    /**
     * ⚠️ 403 KİMLİK HATASI DEĞİL, `VALIDATION`DIR.
     *
     * Etsy Preferred Partner bölgelerinde onaysız uygulamaya geçerli anahtarla
     * bile 403 döner. `AUTHENTICATION` sayılsaydı devre kesici SÜRESİZ açılır
     * ve tek kargo bildirimi bağlantının stok/sipariş akışını durdururdu.
     */
    #[Test]
    public function a_forbidden_tracking_call_is_validation_not_authentication(): void
    {
        Http::fake([
            self::TRACKING_URL => Http::response(['error' => 'Forbidden'], 403),
            self::RECEIPT_URL => Http::response($this->receipt([]), 200),
        ]);

        $result = $this->push();

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        $this->assertStringContainsString('Etsy panelinden', (string) $result->errorMessage);
    }

    /**
     * ⚠️ ETSY'NİN TANIMADIĞI FİRMA `other` İLE YENİDEN GİDER.
     *
     * Yerel firma adları Etsy listesinde büyük olasılıkla yok; reddedilip
     * bırakılsaydı Türkiye'den gönderen her satıcının bildirimi düşerdi.
     * Firma adı alıcı notunda korunur.
     */
    #[Test]
    public function an_unknown_carrier_is_retried_as_other(): void
    {
        Http::fake([
            self::TRACKING_URL => Http::sequence()
                ->push(['error' => 'Invalid carrier_name'], 400)
                ->push($this->receipt([['receipt_shipping_id' => 992, 'tracking_code' => 'YK123', 'carrier_name' => 'other']]), 200),
            self::RECEIPT_URL => Http::response($this->receipt([]), 200),
        ]);

        $result = $this->push(carrier: 'Yurtiçi Kargo', tracking: 'YK123');

        $this->assertTrue($result->successful);
        $this->assertSame('992', $result->data['external_id'] ?? null);

        Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'POST'
            && $r['carrier_name'] === 'other'
            && $r['tracking_code'] === 'YK123'
            && str_contains((string) $r['note_to_buyer'], 'Yurtiçi Kargo'));
    }

    /** Firmayla ilgisiz 400 yeniden denenmez — istisna sınıflandırmaya gider. */
    #[Test]
    public function an_unrelated_bad_request_is_not_retried(): void
    {
        Http::fake([
            self::TRACKING_URL => Http::response(['error' => 'tracking_code too long'], 400),
            self::RECEIPT_URL => Http::response($this->receipt([]), 200),
        ]);

        try {
            $this->push(carrier: 'DHL');
            $this->fail('İlgisiz 400 yutuldu.');
        } catch (RequestException $e) {
            $this->assertSame(400, $e->response->status());
        }

        Http::assertSentCount(2);
    }

    /** Firma boşsa `other` gider (numara Etsy'de yine görünür). */
    #[Test]
    public function a_missing_carrier_is_sent_as_other(): void
    {
        Http::fake([
            self::TRACKING_URL => Http::response($this->receipt([['receipt_shipping_id' => 993, 'tracking_code' => 'YK123']]), 200),
            self::RECEIPT_URL => Http::response($this->receipt([]), 200),
        ]);

        $this->assertTrue($this->push(carrier: null)->successful);

        Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'POST' && $r['carrier_name'] === 'other');
    }

    /** Siparişin kanal kimliği yoksa istek atılmaz. */
    #[Test]
    public function an_order_without_a_receipt_id_sends_nothing(): void
    {
        Http::fake();

        $result = $this->push(receiptId: null);

        $this->assertTrue($result->failed());
        $this->assertSame(ErrorClass::VALIDATION, $result->errorClass);
        Http::assertNothingSent();
    }

    /** Callback'te yazılan tam liste eksik izin bırakmaz; eski bağlantıda yalnız kargo izni eksiktir. */
    #[Test]
    public function missing_scopes_follow_the_granted_list(): void
    {
        $this->assertSame([], $this->adapter(EtsyAuth::SCOPES)->missingAuthorizationScopes());
        $this->assertSame(['transactions_w'], $this->adapter(null)->missingAuthorizationScopes());
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    /**
     * @param  list<array<string, mixed>>  $shipments
     * @return array<string, mixed>
     */
    private function receipt(array $shipments): array
    {
        return [
            'receipt_id' => 4193927841,
            'status' => 'Paid',
            'is_shipped' => $shipments !== [],
            'shipments' => $shipments,
        ];
    }

    /** @param  list<string>|null  $grantedScopes */
    private function push(
        ?string $carrier = 'DHL',
        string $tracking = 'YK123',
        ?string $receiptId = '4193927841',
        ?array $grantedScopes = EtsyAuth::SCOPES,
    ): AdapterResult {
        $adapter = $this->adapter($grantedScopes);

        $order = new Order;
        $order->external_id = $receiptId;

        $fulfillment = new Fulfillment;
        $fulfillment->carrier = $carrier;
        $fulfillment->tracking_number = $tracking;
        $fulfillment->setRelation('order', $order);

        return $adapter->pushFulfillment($fulfillment);
    }

    /** @param  list<string>|null  $grantedScopes */
    private function adapter(?array $grantedScopes): EtsyAdapter
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'etsy'],
            ['name' => 'Etsy', 'kind' => 'marketplace', 'adapter_class' => EtsyAdapter::class, 'supports_webhooks' => false, 'is_active' => false],
        ));

        config(['services.etsy.keystring' => 'key-abc', 'services.etsy.shared_secret' => 'sir-xyz']);

        $tenant = (new CreateTenant)->run(name: 'Etsy Kargo '.uniqid(), owner: User::factory()->create());

        return $this->asTenant($tenant, function () use ($grantedScopes): EtsyAdapter {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'etsy',
                'external_account_id' => 'etsy-shop-'.uniqid(),
                'status' => 'active',
                'settings' => array_filter([
                    EtsyAdapter::SHOP_ID_KEY => '777',
                    EtsyAuth::GRANTED_SCOPES_KEY => $grantedScopes,
                ], static fn (mixed $v): bool => $v !== null),
            ]);

            app(CredentialVault::class)->store($connection, ['access_token' => '12345.token', 'refresh_token' => '12345.refresh']);

            return new EtsyAdapter(
                $connection,
                new ChannelHttpClient($connection, app(CredentialVault::class), app(PayloadRedactor::class)),
            );
        });
    }
}
