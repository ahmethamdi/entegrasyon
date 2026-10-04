<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Stripe webhook'u — abonelik durumunun TEK gerçek kaynağı.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Inbox HTTP katmanı, §11 · Güvenlik,
 * §13 · Faz 4.
 *
 * DEĞİŞMEZ KURAL — İMZA HAM GÖVDE ÜZERİNDEN, AYRIŞTIRMADAN ÖNCE:
 *   JSON ayrıştırıp yeniden serileştirmek baytları değiştirir (anahtar
 *   sırası, boşluk, sayı biçimi) ve imza tutmaz. Kanal webhook'larıyla
 *   AYNI kural; burada bedeli daha ağırdır çünkü doğrulanmamış bir
 *   webhook ÜCRETSİZ ABONELİK açmak demektir.
 *
 * DEĞİŞMEZ KURAL — İMZASIZ İSTEK HİÇBİR ŞEY YAZMAZ:
 *   Kanal tarafında imzasız istek 401 alır ve KAYIT YAPILMAZ. Burada da
 *   öyle: geçersiz imzayla gelen bir `checkout.session.completed`
 *   kabul edilseydi, herkes kendine `pro` abonelik açabilirdi.
 *
 * DEĞİŞMEZ KURAL — TEKRAR İKİNCİ ABONELİK AÇMAZ:
 *   Stripe olayları EN AZ BİR KEZ gönderilir (at-least-once) ve aynı
 *   olay tekrar gelebilir. Çıpa `subscriptions.external_ref` kısmi
 *   tekilliğidir; ikinci satır açılsaydı kiracı iki planda görünür ve
 *   `UNIQUE(tenant_id) WHERE aktif` kısıtı da ihlal edilirdi.
 *
 * DEĞİŞMEZ KURAL — BİLİNMEYEN OLAY TÜRÜ SESSİZCE 2xx ALIR:
 *   Stripe onlarca olay türü gönderir ve tanımadığımız için hata
 *   dönmek, Stripe'ın uç noktayı "bozuk" sayıp yeniden denemesine ve
 *   sonunda webhook'u DEVRE DIŞI bırakmasına yol açar — o noktadan
 *   sonra GERÇEK ödemeler de gelmez.
 */
final class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('entegrasyon.stripe.webhook_secret', self::SECRET);
        config()->set('entegrasyon.stripe.secret', 'sk_test_dummy');
    }

    // ---------------------------------------------------------------- güvenlik

    /**
     * İMZASIZ İSTEK REDDEDİLİR VE HİÇBİR ŞEY YAZILMAZ.
     *
     * Bu maddenin en pahalı hatası: kabul edilseydi herkes kendine
     * ücretsiz `pro` abonelik açabilirdi.
     */
    #[Test]
    public function an_unsigned_request_is_rejected_and_writes_nothing(): void
    {
        [$tenant] = $this->context();

        $response = $this->postJson('/webhooks/stripe', $this->checkoutPayload($tenant));

        $response->assertStatus(400);

        $this->assertSame(0, $this->subscriptionCount());
    }

    /** Yanlış sırla imzalanmış istek de reddedilir. */
    #[Test]
    public function a_request_signed_with_the_wrong_secret_is_rejected(): void
    {
        [$tenant] = $this->context();

        $payload = $this->checkoutPayload($tenant);

        $response = $this->call(
            'POST',
            '/webhooks/stripe',
            server: $this->signedHeaders($payload, secret: 'whsec_baska_sir'),
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(400);

        $this->assertSame(0, $this->subscriptionCount());
    }

    /**
     * BAYAT ZAMAN DAMGASI REDDEDİLİR — tekrar saldırısına karşı.
     *
     * İmza geçerli olsa bile eski bir isteğin sonsuza kadar yeniden
     * oynatılabilmesi, iptal edilmiş bir aboneliği geri açardı.
     */
    #[Test]
    public function a_replayed_old_request_is_rejected(): void
    {
        [$tenant] = $this->context();

        $payload = $this->checkoutPayload($tenant);

        $response = $this->call(
            'POST',
            '/webhooks/stripe',
            server: $this->signedHeaders($payload, timestamp: time() - 3600),
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(400);

        $this->assertSame(0, $this->subscriptionCount());
    }

    // ---------------------------------------------------------------- abonelik açma

    /** Geçerli imzayla gelen tamamlanmış ödeme aboneliği AÇAR. */
    #[Test]
    public function a_completed_checkout_creates_an_active_subscription(): void
    {
        [$tenant] = $this->context();

        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();

        $subscription = $this->firstSubscription();

        $this->assertNotNull($subscription);
        $this->assertSame('active', $subscription->status);
        $this->assertSame('pro', $subscription->plan_code);
        $this->assertSame('sub_TEST123', $subscription->external_ref);
        $this->assertSame($tenant->id, $subscription->tenant_id);
    }

    /**
     * AYNI OLAY İKİ KEZ GELİRSE İKİNCİ ABONELİK AÇILMAZ.
     *
     * Stripe olayları EN AZ BİR KEZ gönderilir; bu testin varlık sebebi
     * odur.
     */
    #[Test]
    public function a_repeated_event_never_creates_a_second_subscription(): void
    {
        [$tenant] = $this->context();

        $payload = $this->checkoutPayload($tenant);

        $this->sendSigned($payload)->assertOk();
        $this->sendSigned($payload)->assertOk();

        $this->assertSame(1, $this->subscriptionCount(), 'Tekrar ikinci satır AÇMAMALI.');
    }

    /** Kiracı kimliği taşımayan olay abonelik açmaz ama 2xx döner. */
    #[Test]
    public function an_event_without_a_tenant_reference_is_ignored(): void
    {
        $this->context();

        $payload = $this->checkoutPayload(tenant: null);

        $this->sendSigned($payload)->assertOk();

        $this->assertSame(0, $this->subscriptionCount());
    }

    /** Tanınmayan plan kodu abonelik açmaz — uydurma plan yaratılmaz. */
    #[Test]
    public function an_unknown_plan_code_is_ignored(): void
    {
        [$tenant] = $this->context();

        $payload = $this->checkoutPayload($tenant, planCode: 'olmayan-plan');

        $this->sendSigned($payload)->assertOk();

        $this->assertSame(0, $this->subscriptionCount());
    }

    // ---------------------------------------------------------------- iptal ve yenileme

    /** İptal olayı aboneliği `cancelled` yapar ve SİLMEZ. */
    #[Test]
    public function a_deletion_event_cancels_the_subscription_without_deleting_it(): void
    {
        [$tenant] = $this->context();

        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();

        $this->sendSigned($this->subscriptionEvent(
            type: 'customer.subscription.deleted',
            status: 'canceled',
        ))->assertOk();

        $subscription = $this->firstSubscription();

        $this->assertSame('cancelled', $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
        $this->assertSame(1, $this->subscriptionCount(), 'İptal satırı SİLMEMELİ.');
    }

    /**
     * `updated` OLAYIYLA GELEN `canceled` DURUMU DA İPTAL SAYILIR.
     *
     * Stripe iptali İKİ yoldan bildirebilir: `subscription.deleted`
     * olayı veya `subscription.updated` + `status: canceled`. İkincisi
     * eşlenmeseydi iptal edilmiş abonelik AKTİF kalır ve kota vermeye
     * devam ederdi — mutasyonla bulundu (ilk yazımda bu boşluk vardı:
     * silme testi `deleted` bayrağını kullandığı için eşleme tablosuna
     * hiç uğramıyordu).
     */
    #[Test]
    public function an_update_event_carrying_canceled_status_also_cancels(): void
    {
        [$tenant] = $this->context();

        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();

        $this->sendSigned($this->subscriptionEvent(
            type: 'customer.subscription.updated',
            status: 'canceled',      // Stripe'ın yazımı — tek `l`
        ))->assertOk();

        $subscription = $this->firstSubscription();

        $this->assertSame('cancelled', $subscription->status);
        $this->assertFalse($subscription->grantsQuota(), 'İptal edilen abonelik kota VERMEMELİ.');
    }

    /** Deneme süresi olayı `trialing` olarak yazılır ve kota VERİR. */
    #[Test]
    public function a_trialing_event_grants_quota(): void
    {
        [$tenant] = $this->context();

        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();

        $this->sendSigned($this->subscriptionEvent(
            type: 'customer.subscription.updated',
            status: 'trialing',
        ))->assertOk();

        $subscription = $this->firstSubscription();

        $this->assertSame('trialing', $subscription->status);
        $this->assertTrue($subscription->grantsQuota());
    }

    /**
     * TANINMAYAN DURUM `past_due`'YA DÜŞER — `active`'e DEĞİL.
     *
     * Stripe yeni bir durum eklerse ve biz onu bilmiyorsak, güvenli
     * taraf kotayı VERMEMEKTİR: bilinmeyen bir durumda ücretli
     * limitleri açık tutmak, ödeme alınmadan kaynak kullandırırdı.
     */
    #[Test]
    public function an_unknown_status_falls_back_to_past_due(): void
    {
        [$tenant] = $this->context();

        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();

        $this->sendSigned($this->subscriptionEvent(
            type: 'customer.subscription.updated',
            status: 'bilinmeyen_yeni_durum',
        ))->assertOk();

        $subscription = $this->firstSubscription();

        $this->assertSame('past_due', $subscription->status);
        $this->assertFalse($subscription->grantsQuota());
    }

    /** Ödeme alınamazsa abonelik `past_due` olur ve kota VERMEZ. */
    #[Test]
    public function a_past_due_subscription_stops_granting_quota(): void
    {
        [$tenant] = $this->context();

        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();

        $this->sendSigned($this->subscriptionEvent(
            type: 'customer.subscription.updated',
            status: 'past_due',
        ))->assertOk();

        $subscription = $this->firstSubscription();

        $this->assertSame('past_due', $subscription->status);
        $this->assertFalse($subscription->grantsQuota(), 'Ödenmemiş abonelik kota VERMEMELİ.');
    }

    /** Dönem sonu güncellenir — yenileme tarihini webhook taşır. */
    #[Test]
    public function the_period_end_is_updated_from_the_event(): void
    {
        [$tenant] = $this->context();

        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();

        $periodEnd = time() + 86400 * 30;

        $this->sendSigned($this->subscriptionEvent(
            type: 'customer.subscription.updated',
            status: 'active',
            periodEnd: $periodEnd,
        ))->assertOk();

        $this->assertSame(
            $periodEnd,
            $this->firstSubscription()->current_period_end->getTimestamp(),
        );
    }

    // ---------------------------------------------------------------- dayanıklılık

    /**
     * BİLİNMEYEN OLAY TÜRÜ 2xx ALIR.
     *
     * Hata dönseydi Stripe uç noktayı bozuk sayıp yeniden dener ve
     * sonunda webhook'u DEVRE DIŞI bırakırdı — o noktadan sonra GERÇEK
     * ödemeler de gelmez.
     */
    #[Test]
    public function an_unknown_event_type_is_acknowledged(): void
    {
        $this->context();

        $this->sendSigned([
            'id' => 'evt_unknown',
            'type' => 'invoice.upcoming',
            'data' => ['object' => []],
        ])->assertOk();

        $this->assertSame(0, $this->subscriptionCount());
    }

    /** Var olmayan aboneliğe ait güncelleme sessizce yutulur. */
    #[Test]
    public function an_update_for_an_unknown_subscription_is_acknowledged(): void
    {
        $this->context();

        $this->sendSigned($this->subscriptionEvent(
            type: 'customer.subscription.updated',
            status: 'active',
            externalRef: 'sub_HIC_YOK',
        ))->assertOk();

        $this->assertSame(0, $this->subscriptionCount());
    }

    // ---------------------------------------------------------------- çift abonelik (B1)

    /**
     * İKİNCİ ÖDEME ESKİ ABONELİĞİ STRIPE'TA DA KESER.
     *
     * Önceden yalnız yerel satır "cancelled" yapılıyordu; Stripe'taki eski
     * abonelik kesilmeye devam eder ve satıcı her ay İKİ KEZ öderdi.
     */
    #[Test]
    public function a_second_checkout_cancels_the_previous_subscription_at_stripe(): void
    {
        [$tenant] = $this->context();
        $this->makePlan('business', 999);

        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();

        $second = $this->checkoutPayload($tenant, 'business');
        $second['id'] = 'evt_checkout_2';
        $second['data']['object']['subscription'] = 'sub_YENI';
        $this->sendSigned($second)->assertOk();

        $this->assertSame(['sub_TEST123'], $this->payments->cancellations);

        $statuses = TenantContext::runAsSystem(fn (): array => Subscription::withoutGlobalScopes()
            ->pluck('status', 'external_ref')->all());
        $this->assertSame(['sub_TEST123' => 'cancelled', 'sub_YENI' => 'active'], $statuses);
    }

    /** `past_due` da kesilir: kota vermez ama Stripe onu tahsil etmeye devam eder. */
    #[Test]
    public function a_past_due_previous_subscription_is_also_cancelled(): void
    {
        [$tenant] = $this->context();

        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();
        $this->sendSigned($this->subscriptionEvent('customer.subscription.updated', 'past_due'))->assertOk();

        $second = $this->checkoutPayload($tenant);
        $second['id'] = 'evt_checkout_2';
        $second['data']['object']['subscription'] = 'sub_YENI';
        $this->sendSigned($second)->assertOk();

        $this->assertSame(['sub_TEST123'], $this->payments->cancellations);
    }

    /** Aynı olayın tekrarı kendi aboneliğini İPTAL ETMEZ. */
    #[Test]
    public function a_repeated_checkout_never_cancels_its_own_subscription(): void
    {
        [$tenant] = $this->context();

        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();
        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();

        $this->assertSame([], $this->payments->cancellations);
    }

    /** Stripe iptali başarısızsa yeni abonelik GERİ ALINMAZ ve uç nokta 2xx döner. */
    #[Test]
    public function a_failed_provider_cancellation_keeps_the_new_subscription(): void
    {
        [$tenant] = $this->context();

        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();

        $this->payments->failNextCall = true;

        $second = $this->checkoutPayload($tenant);
        $second['id'] = 'evt_checkout_2';
        $second['data']['object']['subscription'] = 'sub_YENI';
        $this->sendSigned($second)->assertOk();

        $active = TenantContext::runAsSystem(fn () => Subscription::withoutGlobalScopes()
            ->where('status', 'active')->value('external_ref'));
        $this->assertSame('sub_YENI', $active);
    }

    /** Plan değişikliği abonelik güncellemesinin metadata'sından yazılır. */
    #[Test]
    public function a_plan_change_is_applied_from_the_subscription_metadata(): void
    {
        [$tenant] = $this->context();
        $this->makePlan('business', 999);

        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();

        $event = $this->subscriptionEvent('customer.subscription.updated', 'active');
        $event['data']['object']['metadata'] = ['tenant_id' => $tenant->id, 'plan_code' => 'business'];
        $this->sendSigned($event)->assertOk();

        $this->assertSame('business', $this->firstSubscription()?->plan_code);
        $this->assertSame('business', TenantContext::runAsSystem(
            fn () => Tenant::query()->whereKey($tenant->id)->value('plan_code'),
        ));
        $this->assertSame(1, $this->subscriptionCount());
    }

    /** Bilinmeyen plan kodu planı EZMEZ. */
    #[Test]
    public function an_unknown_plan_in_the_metadata_keeps_the_current_plan(): void
    {
        [$tenant] = $this->context();

        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();

        $event = $this->subscriptionEvent('customer.subscription.updated', 'active');
        $event['data']['object']['metadata'] = ['plan_code' => 'hic-yok'];
        $this->sendSigned($event)->assertOk();

        $this->assertSame('pro', $this->firstSubscription()?->plan_code);
    }

    /** Yeni API sürümü (basil): dönem sonu kalemde gelir ve yine yazılır. */
    #[Test]
    public function the_period_end_is_read_from_the_item_in_newer_api_versions(): void
    {
        [$tenant] = $this->context();

        $this->sendSigned($this->checkoutPayload($tenant))->assertOk();

        $periodEnd = time() + 30 * 86400;
        $event = $this->subscriptionEvent('customer.subscription.updated', 'active');
        unset($event['data']['object']['current_period_end']);
        $event['data']['object']['items'] = ['data' => [['current_period_end' => $periodEnd]]];
        $this->sendSigned($event)->assertOk();

        $this->assertSame($periodEnd, $this->firstSubscription()?->current_period_end?->getTimestamp());
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: Tenant, 1: User} */
    private function context(): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Ödeme Testi', owner: $user);

        Plan::create([
            'code' => 'pro',
            'name' => 'Pro',
            'price_monthly' => 499,
            'limits' => ['products' => 5000, 'channels' => 5],
        ]);

        return [$tenant, $user];
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(?Tenant $tenant, string $planCode = 'pro'): array
    {
        return [
            'id' => 'evt_checkout_1',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_1',
                    'subscription' => 'sub_TEST123',
                    'customer' => 'cus_TEST123',
                    // Kiracı ve plan METADATA ile taşınır — Stripe bizim
                    // kimliklerimizi bilmez.
                    'metadata' => array_filter([
                        'tenant_id' => $tenant?->id,
                        'plan_code' => $planCode,
                    ]),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function subscriptionEvent(
        string $type,
        string $status,
        ?int $periodEnd = null,
        string $externalRef = 'sub_TEST123',
    ): array {
        return [
            'id' => 'evt_'.$type.'_'.$status,
            'type' => $type,
            'data' => [
                'object' => [
                    'id' => $externalRef,
                    'status' => $status,
                    'current_period_end' => $periodEnd ?? (time() + 86400),
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function sendSigned(array $payload): TestResponse
    {
        return $this->call(
            'POST',
            '/webhooks/stripe',
            server: $this->signedHeaders($payload),
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Stripe imza başlığını üretir — GERÇEK algoritmayla.
     *
     * Biçim: `t={timestamp},v1={hmac}` ve imzalanan metin
     * `{timestamp}.{ham gövde}`. Testin sahte bir doğrulayıcı yerine
     * gerçek imzayı üretmesi kritik: aksi halde doğrulamanın kendisi
     * hiç sınanmazdı.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function signedHeaders(
        array $payload,
        ?string $secret = null,
        ?int $timestamp = null,
    ): array {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= time();

        $signature = hash_hmac(
            'sha256',
            $timestamp.'.'.$body,
            $secret ?? self::SECRET,
        );

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => sprintf('t=%d,v1=%s', $timestamp, $signature),
        ];
    }

    private function makePlan(string $code, int $price): void
    {
        Plan::create([
            'code' => $code,
            'name' => ucfirst($code),
            'price_monthly' => $price,
            'limits' => [],
        ]);
    }

    private function subscriptionCount(): int
    {
        return TenantContext::runAsSystem(
            fn (): int => Subscription::withoutGlobalScopes()->count(),
        );
    }

    private function firstSubscription(): ?Subscription
    {
        return TenantContext::runAsSystem(
            fn (): ?Subscription => Subscription::withoutGlobalScopes()->first(),
        );
    }
}
