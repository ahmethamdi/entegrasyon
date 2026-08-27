<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Mail\MetricAlertMail;
use App\Support\Observability\AlertKey;
use App\Support\Observability\DispatchAlerts;
use App\Support\Observability\Metric;
use App\Support\Observability\MetricScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Eşik aşımı uyarıları — §11 · §12.
 *
 * Bu testlerin koruduğu asıl kural: AYNI UYARI AYNI GÜN İKİ KEZ GİTMEZ.
 * Tekrar eden bildirim dikkati YOK EDER ve o noktadan sonra gerçek bir
 * olay da fark edilmez.
 */
final class DispatchAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    // ---------------------------------------------------------------- gönderim

    /**
     * Eşiği AŞAN metrik kiracının sahibine gider.
     *
     * `OVERSOLD_UNITS` eşiği 5; 9 aşımdır.
     */
    #[Test]
    public function a_breaching_tenant_metric_emails_the_owner(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->snapshot(Metric::OVERSOLD_UNITS, 9, MetricScope::tenant($tenant->id));

        $result = $this->dispatch();

        $this->assertSame(1, $result['sent']);

        Mail::assertSent(
            MetricAlertMail::class,
            static fn (MetricAlertMail $mail): bool => $mail->hasTo($user->email)
                && $mail->metric === Metric::OVERSOLD_UNITS,
        );
    }

    /**
     * EŞİĞE TAM DAYANAN DEĞER AŞIM DEĞİLDİR.
     *
     * §11 "büyüktür" der ve `Metric::breaches()` tek kaynaktır. Burada
     * `>=` yazılsaydı panel yeşil gösterirken e-posta giderdi ve satıcı
     * hangisine inanacağını bilemezdi.
     */
    #[Test]
    public function a_value_exactly_at_the_threshold_does_not_alert(): void
    {
        [$tenant] = $this->makeTenant();

        $this->snapshot(Metric::OVERSOLD_UNITS, 5, MetricScope::tenant($tenant->id));

        $this->assertSame(0, $this->dispatch()['sent']);

        Mail::assertNothingSent();
    }

    /** Eşiğin altındaki değer hiçbir şey üretmez. */
    #[Test]
    public function a_healthy_metric_sends_nothing(): void
    {
        [$tenant] = $this->makeTenant();

        $this->snapshot(Metric::OVERSOLD_UNITS, 1, MetricScope::tenant($tenant->id));

        $this->assertSame(0, $this->dispatch()['sent']);

        Mail::assertNothingSent();
    }

    // ------------------------------------------------- EN KRİTİK KURAL

    /**
     * AYNI UYARI AYNI GÜN İKİ KEZ GİTMEZ.
     *
     * Eşik aşımı KALICI bir durumdur ve her turda yeniden ölçülür; koruma
     * olmasaydı satıcının gelen kutusu dolar ve uyarılar okunmaz olurdu.
     */
    #[Test]
    public function the_same_alert_is_not_sent_twice_in_one_day(): void
    {
        [$tenant] = $this->makeTenant();

        $this->snapshot(Metric::OVERSOLD_UNITS, 9, MetricScope::tenant($tenant->id));

        $first = $this->dispatch();
        $second = $this->dispatch();

        $this->assertSame(1, $first['sent']);
        $this->assertSame(0, $second['sent'], 'İkinci tur GÖNDERMEMELİ.');
        $this->assertSame(1, $second['suppressed']);

        Mail::assertSentCount(1);
    }

    /**
     * ÇIPA GÜN BAŞINADIR — ertesi gün yeniden gönderilir.
     *
     * Sonsuza kadar susulsaydı satıcı ertesi gün durumu hatırlamaz ve
     * sorun sessizce yaşamaya devam ederdi.
     */
    #[Test]
    public function the_alert_is_sent_again_the_next_day(): void
    {
        [$tenant] = $this->makeTenant();

        $this->snapshot(Metric::OVERSOLD_UNITS, 9, MetricScope::tenant($tenant->id));

        $this->dispatch();

        // Dünkü kayıt — bugünkü turu bastırmamalı.
        DB::table('alert_deliveries')->update(['sent_on' => now()->subDay()->toDateString()]);

        $this->assertSame(1, $this->dispatch()['sent'], 'Ertesi gün yeniden gönderilmeli.');
    }

    /**
     * ÇIPA GÖNDERİMDEN ÖNCE YAZILIR.
     *
     * Sonra yazılsaydı iki paralel tur aynı uyarıyı iki kez gönderir ve
     * tekillik ihlali ancak e-posta gittikten SONRA fark edilirdi.
     */
    #[Test]
    public function the_claim_row_records_the_value_and_threshold(): void
    {
        [$tenant] = $this->makeTenant();

        $this->snapshot(Metric::OVERSOLD_UNITS, 9, MetricScope::tenant($tenant->id));

        $this->dispatch();

        $row = DB::table('alert_deliveries')->first();

        $this->assertSame(
            AlertKey::metricForTenant(Metric::OVERSOLD_UNITS, $tenant->id),
            $row->alert_key,
        );
        $this->assertSame($tenant->id, $row->tenant_id);
        $this->assertSame(1, (int) $row->recipient_count);
        $this->assertSame(9.0, (float) $row->observed_value);
        $this->assertSame(5.0, (float) $row->threshold_value, 'Eşik de kaydedilmeli.');
    }

    // ---------------------------------------------------------------- kapsam

    /**
     * SİSTEM UYARISI YÖNETİCİYE GİDER, kiracıya DEĞİL.
     */
    #[Test]
    public function a_system_metric_emails_the_configured_admin(): void
    {
        [, $user] = $this->makeTenant();

        config()->set('entegrasyon.alerts.admin_email', 'yonetici@example.com');

        // OUTBOX_CONSUME_GAP eşiği 0 — bir tane bile fazla.
        $this->snapshot(Metric::OUTBOX_CONSUME_GAP, 3);

        $this->assertSame(1, $this->dispatch()['sent']);

        Mail::assertSent(
            MetricAlertMail::class,
            static fn (MetricAlertMail $mail): bool => $mail->hasTo('yonetici@example.com'),
        );

        Mail::assertNotSent(
            MetricAlertMail::class,
            static fn (MetricAlertMail $mail): bool => $mail->hasTo($user->email),
        );
    }

    /**
     * YÖNETİCİ ADRESİ TANIMSIZSA SİSTEM UYARISI GÖNDERİLMEZ.
     *
     * Uydurma bir adrese göndermek ya da sessizce ilk kullanıcıya düşmek
     * uyarının YANLIŞ kişiye gitmesi demektir. Bu bilinçli bir kapıdır ve
     * çıpa da YAZILMAZ — adres tanımlanınca uyarı gidebilmeli.
     */
    #[Test]
    public function a_system_metric_without_an_admin_address_is_skipped_not_sent(): void
    {
        $this->makeTenant();

        config()->set('entegrasyon.alerts.admin_email', null);

        $this->snapshot(Metric::OUTBOX_CONSUME_GAP, 3);

        $result = $this->dispatch();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);

        Mail::assertNothingSent();

        $this->assertSame(
            0,
            DB::table('alert_deliveries')->count(),
            'Alıcısı yokken çıpa YAZILMAMALI — adres tanımlanınca gidebilmeli.',
        );
    }

    /** Bağlantı kapsamlı uyarı da yöneticiye gider — altyapı sorunudur. */
    #[Test]
    public function a_connection_metric_goes_to_the_admin(): void
    {
        [, $user] = $this->makeTenant();

        config()->set('entegrasyon.alerts.admin_email', 'yonetici@example.com');

        $this->snapshot(Metric::API_LATENCY_P95, 9_000, MetricScope::connection('01a0-baglanti'));

        $this->assertSame(1, $this->dispatch()['sent']);

        Mail::assertSent(
            MetricAlertMail::class,
            static fn (MetricAlertMail $mail): bool => $mail->hasTo('yonetici@example.com'),
        );
        Mail::assertNotSent(
            MetricAlertMail::class,
            static fn (MetricAlertMail $mail): bool => $mail->hasTo($user->email),
        );
    }

    /**
     * İKİ KİRACI AYNI METRİKTE AŞARSA İKİSİ DE UYARI ALIR.
     *
     * Anahtar kiracı kimliğini taşımasaydı ilk gönderim ikincisini
     * bastırır ve o satıcı sorunundan HİÇ haberdar olmazdı.
     */
    #[Test]
    public function each_tenant_gets_its_own_alert(): void
    {
        [$tenantA, $userA] = $this->makeTenant('A');
        [$tenantB, $userB] = $this->makeTenant('B');

        $this->snapshot(Metric::OVERSOLD_UNITS, 9, MetricScope::tenant($tenantA->id));
        $this->snapshot(Metric::OVERSOLD_UNITS, 40, MetricScope::tenant($tenantB->id));

        $this->assertSame(2, $this->dispatch()['sent']);

        Mail::assertSent(MetricAlertMail::class, static fn (MetricAlertMail $m): bool => $m->hasTo($userA->email));
        Mail::assertSent(MetricAlertMail::class, static fn (MetricAlertMail $m): bool => $m->hasTo($userB->email));
    }

    /** Kiracı uyarısı BAŞKA kiracının sahibine sızmaz. */
    #[Test]
    public function a_tenant_alert_never_leaks_to_another_tenant(): void
    {
        [$tenantA] = $this->makeTenant('A');
        [, $userB] = $this->makeTenant('B');

        $this->snapshot(Metric::OVERSOLD_UNITS, 9, MetricScope::tenant($tenantA->id));

        $this->dispatch();

        Mail::assertNotSent(
            MetricAlertMail::class,
            static fn (MetricAlertMail $mail): bool => $mail->hasTo($userB->email),
        );
    }

    // ---------------------------------------------------------------- okuma

    /**
     * SON ÖLÇÜM `id` İLE SEÇİLİR, `captured_at` İLE DEĞİL.
     *
     * `captured_at` SANİYE hassasiyetlidir; iki tur aynı damgayı
     * taşıyabilir. Düzelmiş bir metrik için uyarı göndermek satıcıyı
     * olmayan bir soruna gönderirdi.
     */
    #[Test]
    public function only_the_latest_measurement_decides(): void
    {
        [$tenant] = $this->makeTenant();

        $scope = MetricScope::tenant($tenant->id);
        $stamp = now();

        // ESKİ ölçüm aşıyor, YENİ ölçüm sağlıklı — ikisi AYNI damgada.
        $this->snapshot(Metric::OVERSOLD_UNITS, 99, $scope, $stamp);
        $this->snapshot(Metric::OVERSOLD_UNITS, 1, $scope, $stamp);

        $this->assertSame(0, $this->dispatch()['sent'], 'Düzelmiş metrik uyarı üretmemeli.');

        Mail::assertNothingSent();
    }

    /** Tanınmayan metrik satırı için uydurma eşik uygulanmaz. */
    #[Test]
    public function an_unknown_metric_row_is_ignored(): void
    {
        $this->makeTenant();

        DB::table('metric_snapshots')->insert([
            'metric' => 'kaldirilmis_metrik',
            'scope' => MetricScope::SYSTEM,
            'value' => 9_999,
            'captured_at' => now(),
        ]);

        $this->assertSame(0, $this->dispatch()['sent']);
    }

    // ---------------------------------------------------------------- alıcı

    /**
     * DAVETİ KABUL ETMEMİŞ ÜYEYE GÖNDERİLMEZ — adres doğrulanmış
     * sayılmaz ve uyarı yabancı bir gelen kutusuna düşerdi.
     */
    #[Test]
    public function a_pending_invite_does_not_receive_alerts(): void
    {
        [$tenant, $owner] = $this->makeTenant();

        $invited = User::factory()->create();

        DB::table('tenant_users')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'user_id' => $invited->id,
            'role' => 'owner',
            'invited_at' => now(),
            'accepted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->snapshot(Metric::OVERSOLD_UNITS, 9, MetricScope::tenant($tenant->id));

        $this->dispatch();

        Mail::assertSent(MetricAlertMail::class, static fn (MetricAlertMail $m): bool => $m->hasTo($owner->email));
        Mail::assertNotSent(
            MetricAlertMail::class,
            static fn (MetricAlertMail $mail): bool => $mail->hasTo($invited->email),
        );
    }

    // ---------------------------------------------------------------- sözleşme

    /**
     * ANAHTAR BİÇİMİ BEKLENEN METİNLE SINANIR.
     *
     * Yazan da okuyan da `AlertKey`'i çağırdığı için, önek değişirse ikisi
     * BİRLİKTE kayar ve davranış testleri YEŞİL KALIR — ama
     * `alert_deliveries`'teki ESKİ satırlar bir daha HİÇ bulunamaz, yani
     * tekrar koruması sessizce kaybolur ve satıcı aynı uyarıyı ikinci kez
     * alır. Bu mutasyon ilk turda GERÇEKTEN HAYATTA KALDI; test bu yüzden
     * yardımcıyı kendisiyle karşılaştırmaz, BEKLENEN METNİ yazar.
     *
     * (Geçmişte `MetricScope` üzerinde birebir aynı mutasyon yaşandı.)
     */
    #[Test]
    public function the_alert_key_format_is_a_contract(): void
    {
        $this->assertSame(
            'metric:oversold_units:tenant:01a0-kiraci',
            AlertKey::metricForTenant(Metric::OVERSOLD_UNITS, '01a0-kiraci'),
        );

        $this->assertSame(
            'metric:api_latency_p95:connection:01a0-baglanti',
            AlertKey::metricForConnection(Metric::API_LATENCY_P95, '01a0-baglanti'),
        );

        $this->assertSame(
            'metric:outbox_consume_gap:system',
            AlertKey::metricForSystem(Metric::OUTBOX_CONSUME_GAP),
        );

        $this->assertSame(
            'digest:dead_operations:tenant:01a0-kiraci',
            AlertKey::deadLetterDigest('01a0-kiraci'),
        );
    }

    /**
     * Kapsam çözümlemesi de sözleşmedir: kiracı uyarısı satıcıya,
     * bağlantı ve sistem uyarısı YÖNETİCİYE gider ve ayrım bu iki
     * metoda dayanır.
     */
    #[Test]
    public function the_scope_helpers_distinguish_tenant_from_connection(): void
    {
        $this->assertSame('01a0-k', MetricScope::tenantIdOf('tenant:01a0-k'));
        $this->assertNull(MetricScope::tenantIdOf('connection:01a0-b'));
        $this->assertNull(MetricScope::tenantIdOf('system'));

        $this->assertSame('01a0-b', MetricScope::connectionIdOf('connection:01a0-b'));
        $this->assertNull(MetricScope::connectionIdOf('tenant:01a0-k'));
    }

    // ---------------------------------------------------------------- §12

    /**
     * §12'NİN ÖLÜ İŞ EŞİĞİ AYRI BİR YOL DEĞİL, AYNI YOLDUR.
     *
     * "Kiracı başına 10'dan fazla ölü iş → e-posta" §12'nin cümlesidir ve
     * `DEAD_OPERATIONS` metriği bunu ZATEN kiracı başına ölçüyor, eşiği de
     * 10. Ayrı bir özet yolu yazılsaydı eşik İKİ YERDE yaşar ve biri
     * değiştiğinde diğeri sessizce eski değerle çalışırdı.
     */
    #[Test]
    public function the_dead_letter_threshold_from_section_twelve_alerts_the_tenant(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->assertSame(
            10.0,
            Metric::DEAD_OPERATIONS->threshold(),
            '§12 "kiracı başına 10'."'".'dan fazla" diyor.',
        );

        // 10 AŞIM DEĞİLDİR ("10'dan fazla").
        $this->snapshot(Metric::DEAD_OPERATIONS, 10, MetricScope::tenant($tenant->id));
        $this->assertSame(0, $this->dispatch()['sent']);

        $this->snapshot(Metric::DEAD_OPERATIONS, 11, MetricScope::tenant($tenant->id));
        $this->assertSame(1, $this->dispatch()['sent']);

        Mail::assertSent(
            MetricAlertMail::class,
            static fn (MetricAlertMail $mail): bool => $mail->hasTo($user->email)
                && $mail->metric === Metric::DEAD_OPERATIONS,
        );
    }

    // ---------------------------------------------------------------- yardımcı

    // ═══════════════════════════════ §25 · token uyarısı SATICIYA gider

    /**
     * ⚠️ BAĞLANTI KAPSAMLI TOKEN UYARISI SATICININ GELEN KUTUSUNA DÜŞER.
     *
     * Bu testin varlık sebebi bir MUTASYONLA bulundu: bağlantı→kiracı
     * çözümü kaldırıldığında hiçbir test kırılmadı. Oysa o çözüm
     * olmadan `connection:{id}` kapsamı kiracıyı TAŞIMAZ, alıcı listesi
     * BOŞ kalır, uyarı `skipped` sayılır ve satıcı bağlantısının
     * öldüğünü HİÇ ÖĞRENEMEZ — §25'in tam olarak önlemek için yazıldığı
     * sessiz ölüm.
     *
     * İDDİA "ALICI BULUNDU" DEĞİL, POSTANIN SATICIYA GİTTİĞİDİR:
     * yalnızca sayıya bakan bir test, e-posta yanlış kişiye gitse bile
     * yeşil kalırdı.
     */
    #[Test]
    public function a_token_alert_reaches_the_seller(): void
    {
        [$tenant, $user] = $this->makeTenant('Token');

        $connectionId = $this->connectionFor($tenant);

        $this->snapshot(
            Metric::TOKEN_EXPIRING_SOON,
            1,
            MetricScope::connection($connectionId),
        );

        $result = $this->dispatch();

        $this->assertSame(1, $result['sent']);

        Mail::assertSent(
            MetricAlertMail::class,
            fn (MetricAlertMail $mail): bool => $mail->hasTo($user->email),
        );
    }

    /**
     * ⚠️ KOTA UYARISI AYNI KAPSAMDA AMA YÖNETİCİYE GİDER.
     *
     * İkisi de `connection:{id}` kapsamlıdır; ayıran şey KAPSAM DEĞİL
     * `Metric::alertAudience()`'tır. Bu test o ayrımın gerçekten
     * uygulandığını sürer — kapsamdan türetilmeye geri dönülseydi kota
     * uyarısı satıcıya gider ve satıcı yapamayacağı bir iş için
     * uyarılırdı.
     */
    #[Test]
    public function a_quota_alert_reaches_the_admin_not_the_seller(): void
    {
        [$tenant, $user] = $this->makeTenant('Kota');

        config(['entegrasyon.alerts.admin_email' => 'yonetici@ornek.test']);

        $connectionId = $this->connectionFor($tenant);

        $this->snapshot(
            Metric::CHANNEL_DAILY_QUOTA_USED,
            95,
            MetricScope::connection($connectionId),
        );

        $this->assertSame(1, $this->dispatch()['sent']);

        Mail::assertSent(
            MetricAlertMail::class,
            fn (MetricAlertMail $mail): bool => $mail->hasTo('yonetici@ornek.test')
                && ! $mail->hasTo($user->email),
        );
    }

    /**
     * ⚠️ TOKEN UYARISI HANGİ MAĞAZA OLDUĞUNU SÖYLER.
     *
     * "Bir kanal bağlantısı" cümlesi, bağlantı uyarıları YALNIZCA
     * yöneticiye giderken yeterliydi — o kimliği panelden bulabilir.
     * §25 ile bu uyarılar SATICIYA gidiyor ve üç mağazası olan bir
     * satıcı hangisini yeniden yetkilendireceğini o cümleden
     * ÇIKARAMAZ: uyarı okunur ama eylem üretmez.
     */
    #[Test]
    public function a_token_alert_names_the_connection(): void
    {
        [$tenant] = $this->makeTenant('Ad');

        $connectionId = $this->connectionFor($tenant, label: 'Etsy Ana Mağaza');

        $this->snapshot(
            Metric::TOKEN_EXPIRING_SOON,
            1,
            MetricScope::connection($connectionId),
        );

        $this->dispatch();

        Mail::assertSent(MetricAlertMail::class, function (MetricAlertMail $mail): bool {
            $rendered = $mail->render();

            return str_contains($rendered, 'Etsy Ana Mağaza')
                // ⚠️ TAVSİYE EKRAN DEĞİL EYLEM SÖYLER: varsayılan metin
                // satıcıyı salt okunur bir ekrana gönderir ve orada
                // yapabileceği bir şey YOKTUR.
                && str_contains($rendered, 'yeniden');
        });
    }

    /** @return array{sent: int, suppressed: int, skipped: int} */
    private function dispatch(): array
    {
        return app(DispatchAlerts::class)->run();
    }

    /** Kiracıya ait bir kanal bağlantısı; kimliğini döner. */
    private function connectionFor(Tenant $tenant, string $label = 'Etsy Mağazam'): string
    {
        $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
            ['code' => 'etsy'],
            [
                'name' => 'Etsy',
                'kind' => 'marketplace',
                'adapter_class' => EtsyAdapter::class,
                'supports_webhooks' => false,
                'is_active' => true,
            ],
        ));

        return $this->asTenant($tenant, fn (): string => ChannelConnection::factory()->create([
            'channel_type_code' => 'etsy',
            'external_account_id' => 'etsy-'.uniqid(),
            'label' => $label,
            'status' => 'active',
        ])->id);
    }

    private function snapshot(Metric $metric, float $value, ?string $scope = null, mixed $at = null): void
    {
        DB::table('metric_snapshots')->insert([
            'metric' => $metric->value,
            'scope' => $scope ?? MetricScope::SYSTEM,
            'value' => $value,
            'captured_at' => $at ?? now(),
        ]);
    }

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(string $name = 'Uyarı'): array
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);

        return [$tenant, $user];
    }
}
