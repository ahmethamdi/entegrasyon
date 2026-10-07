<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\ChannelConnectForm;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/channels/{id}/settings` · bağlantı sonrası kanal ayarları.
 *
 * 7 Eki'ye kadar Etsy beyanları ve kargo profili yalnızca sunucuda elle
 * yazılabiliyordu; canlıdaki ilk ilan da eksik bir Printful profiliyle
 * açıldı ve YAYINLANIRKEN 400 aldı. Bu ekran ikisini de kapatır.
 */
final class ChannelSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.etsy.keystring' => 'anahtar', 'services.etsy.shared_secret' => 'sir']);
    }

    /**
     * ⚠️ YAYINLANAMAYAN PROFİL GÖRÜNÜR AMA SEÇİLEMEZ.
     *
     * Canlıdaki Printful profillerinde posta kodu ve teslim süresi yoktu;
     * Etsy taslağı kabul etti, yayınlarken 400 verdi. Gizlenseydi satıcı
     * Etsy'de gördüğü profili burada bulamazdı.
     */
    #[Test]
    public function an_unpublishable_shipping_profile_is_listed_but_not_usable(): void
    {
        [$user, $connection] = $this->etsyConnection();
        $this->fakeEtsy();

        $fields = collect($this->fieldsOn($user, $connection))->keyBy('key');
        $options = collect($fields[EtsyAdapter::SHIPPING_PROFILE_KEY]['options'])->keyBy('value');

        $this->assertTrue($options['270501328789']['usable']);
        $this->assertFalse($options['118563544830']['usable']);
        $this->assertStringContainsString('posta kodu', (string) $options['118563544830']['note']);
    }

    /** Hazırlık profilleri ve beyan seçenekleri gelir. */
    #[Test]
    public function the_screen_offers_declarations_and_processing_profiles(): void
    {
        [$user, $connection] = $this->etsyConnection();
        $this->fakeEtsy();

        $fields = collect($this->fieldsOn($user, $connection))->keyBy('key');

        $this->assertSame(
            ['i_did', 'collective', 'someone_else'],
            array_column($fields[EtsyAdapter::WHO_MADE_KEY]['options'], 'value'),
        );
        $this->assertContains('2020_2026', array_column($fields[EtsyAdapter::WHEN_MADE_KEY]['options'], 'value'));
        $this->assertSame('1404873261637', $fields[EtsyAdapter::READINESS_KEY]['options'][0]['value']);
        $this->assertFalse($fields[EtsyAdapter::READINESS_KEY]['required']);
    }

    /** "1900s" on yıldır, "1800s" yüzyıl — Etsy listesinde "1910s" de var. */
    #[Test]
    public function decade_and_century_labels_follow_etsy_meaning(): void
    {
        [$user, $connection] = $this->etsyConnection();
        $this->fakeEtsy();

        $labels = collect(
            collect($this->fieldsOn($user, $connection))->keyBy('key')[EtsyAdapter::WHEN_MADE_KEY]['options']
        )->pluck('label', 'value');

        $this->assertSame('1900–1909', $labels['1900s']);
        $this->assertSame('1800–1899', $labels['1800s']);
        $this->assertSame('2020–2026', $labels['2020_2026']);
    }

    /** Geçerli seçim kaydedilir, eksik sayacı sıfırlanır. */
    #[Test]
    public function valid_choices_are_saved(): void
    {
        [$user, $connection] = $this->etsyConnection();
        $this->fakeEtsy();

        $this->assertSame(3, $this->card($user)['settingsMissing']);

        $this->actingAs($user)->put("/channels/{$connection->id}/settings", [
            EtsyAdapter::WHO_MADE_KEY => 'i_did',
            EtsyAdapter::WHEN_MADE_KEY => 'made_to_order',
            EtsyAdapter::SHIPPING_PROFILE_KEY => '270501328789',
            EtsyAdapter::READINESS_KEY => '',
        ])->assertRedirect('/channels')->assertSessionHasNoErrors();

        $settings = $this->settingsOf($connection);

        $this->assertSame('270501328789', $settings[EtsyAdapter::SHIPPING_PROFILE_KEY]);
        $this->assertSame('i_did', $settings[EtsyAdapter::WHO_MADE_KEY]);
        $this->assertArrayNotHasKey(EtsyAdapter::READINESS_KEY, $settings);
        $this->assertSame(0, $this->card($user)['settingsMissing']);
    }

    /**
     * ⚠️ YAYINLANAMAYAN PROFİL KAYDEDİLMEZ — istek elle gönderilse bile.
     */
    #[Test]
    public function an_unusable_profile_is_rejected(): void
    {
        [$user, $connection] = $this->etsyConnection();
        $this->fakeEtsy();

        $this->actingAs($user)->put("/channels/{$connection->id}/settings", [
            EtsyAdapter::WHO_MADE_KEY => 'i_did',
            EtsyAdapter::WHEN_MADE_KEY => 'made_to_order',
            EtsyAdapter::SHIPPING_PROFILE_KEY => '118563544830',
        ])->assertSessionHasErrors(EtsyAdapter::SHIPPING_PROFILE_KEY);

        $this->assertArrayNotHasKey(EtsyAdapter::SHIPPING_PROFILE_KEY, $this->settingsOf($connection));
    }

    /** Zorunlu ayar boş bırakılamaz; liste dışı değer yazılamaz. */
    #[Test]
    public function required_and_unknown_values_are_rejected(): void
    {
        [$user, $connection] = $this->etsyConnection();
        $this->fakeEtsy();

        $this->actingAs($user)->put("/channels/{$connection->id}/settings", [
            EtsyAdapter::WHO_MADE_KEY => 'uydurma',
            EtsyAdapter::WHEN_MADE_KEY => '',
            EtsyAdapter::SHIPPING_PROFILE_KEY => '270501328789',
        ])->assertSessionHasErrors([EtsyAdapter::WHO_MADE_KEY, EtsyAdapter::WHEN_MADE_KEY]);

        $this->assertArrayNotHasKey(EtsyAdapter::SHIPPING_PROFILE_KEY, $this->settingsOf($connection));
    }

    /**
     * ⚠️ YALNIZCA TANIMLI ANAHTARLAR YAZILIR — `shop_id` ezilemez.
     */
    #[Test]
    public function undeclared_keys_cannot_be_written(): void
    {
        [$user, $connection] = $this->etsyConnection();
        $this->fakeEtsy();

        $this->actingAs($user)->put("/channels/{$connection->id}/settings", [
            EtsyAdapter::WHO_MADE_KEY => 'i_did',
            EtsyAdapter::WHEN_MADE_KEY => 'made_to_order',
            EtsyAdapter::SHIPPING_PROFILE_KEY => '270501328789',
            EtsyAdapter::SHOP_ID_KEY => '999',
        ])->assertSessionHasNoErrors();

        $this->assertSame('26418816', $this->settingsOf($connection)[EtsyAdapter::SHOP_ID_KEY]);
    }

    /**
     * ⚠️ SEÇENEKLER OKUNAMAZSA KAYIT YAPILMAZ ve neden söylenir.
     *
     * Boş liste "mağazada profil yok" ile "Etsy'ye ulaşılamadı"yı
     * karıştırırdı; doğrulanamayan değeri yazmak da 400'ü ilk ilana
     * ertelemek olurdu.
     */
    #[Test]
    public function an_unreachable_channel_blocks_saving_with_a_reason(): void
    {
        [$user, $connection] = $this->etsyConnection();
        Http::fake(['*' => Http::response(['error' => 'down'], 503)]);

        $fields = collect($this->fieldsOn($user, $connection))->keyBy('key');
        $this->assertNotNull($fields[EtsyAdapter::SHIPPING_PROFILE_KEY]['optionsError']);

        $this->actingAs($user)->put("/channels/{$connection->id}/settings", [
            EtsyAdapter::WHO_MADE_KEY => 'i_did',
            EtsyAdapter::WHEN_MADE_KEY => 'made_to_order',
            EtsyAdapter::SHIPPING_PROFILE_KEY => '270501328789',
        ])->assertSessionHasErrors(EtsyAdapter::SHIPPING_PROFILE_KEY);
    }

    /** Başka kiracının bağlantısı 404. */
    #[Test]
    public function another_tenants_connection_is_not_found(): void
    {
        [, $connection] = $this->etsyConnection();
        [$stranger] = $this->etsyConnection();
        $this->fakeEtsy();

        $this->actingAs($stranger)->get("/channels/{$connection->id}/settings")->assertNotFound();
        $this->actingAs($stranger)->put("/channels/{$connection->id}/settings", [])->assertNotFound();
    }

    /** Ayar bildirmeyen kanalda ekran yok, kartta düğme yok. */
    #[Test]
    public function a_channel_without_settings_has_no_screen(): void
    {
        $this->channelType('woocommerce', WooCommerceAdapter::class);
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Woo '.uniqid(), owner: $user);

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => ChannelConnection::factory()->create([
            'channel_type_code' => 'woocommerce',
            'external_account_id' => 'woo-'.uniqid(),
            'status' => 'active',
        ]));

        $this->actingAs($user)->get("/channels/{$connection->id}/settings")->assertNotFound();
        $this->assertFalse($this->card($user)['hasSettings']);
    }

    /**
     * ⚠️ İZİN VERİLMEMİŞ OAUTH BAĞLANTISI "İzin ver" GÖSTERİR.
     *
     * "Tekrar dene" aynı yetkisiz isteği tekrarlardı; kart satıcıyı
     * kanalın izin ekranına götürmeli.
     */
    #[Test]
    public function an_unauthorized_oauth_connection_offers_authorization(): void
    {
        [$user, $connection] = $this->etsyConnection(
            account: ChannelConnectForm::PENDING_ACCOUNT_PREFIX.'abc',
        );

        $this->assertSame("/channels/{$connection->id}/etsy/authorize", $this->card($user)['authorizeUrl']);
    }

    /** İzinli bağlantıda "İzin ver" çıkmaz. */
    #[Test]
    public function an_authorized_connection_has_no_authorize_button(): void
    {
        [$user] = $this->etsyConnection();

        $this->assertNull($this->card($user)['authorizeUrl']);
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    /** Canlıda 7 Eki görülen iki profil biçimi + tek hazırlık profili. */
    private function fakeEtsy(): void
    {
        Http::fake([
            '*/shipping-profiles*' => Http::response(['count' => 2, 'results' => [
                [
                    'shipping_profile_id' => 270501328789,
                    'title' => 'DHL',
                    'origin_country_iso' => 'DE',
                    'origin_postal_code' => '41468',
                    'shipping_profile_destinations' => [
                        ['destination_country_iso' => 'DE', 'shipping_carrier_id' => 4, 'mail_class' => 'express_standard_parcel_germany', 'min_delivery_days' => null, 'max_delivery_days' => null],
                    ],
                ],
                [
                    'shipping_profile_id' => 118563544830,
                    'title' => 'Printful: Hoodies',
                    'origin_country_iso' => 'US',
                    'origin_postal_code' => null,
                    'shipping_profile_destinations' => [
                        ['destination_country_iso' => '', 'shipping_carrier_id' => 0, 'mail_class' => null, 'min_delivery_days' => null, 'max_delivery_days' => null],
                    ],
                ],
            ]], 200),
            '*/readiness-state-definitions*' => Http::response(['count' => 1, 'results' => [
                ['readiness_state_id' => 1404873261637, 'readiness_state' => 'made_to_order', 'processing_days_display_label' => '1-3 days'],
            ]], 200),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function fieldsOn(User $user, ChannelConnection $connection): array
    {
        return $this->actingAs($user)
            ->get("/channels/{$connection->id}/settings")
            ->assertOk()
            ->viewData('page')['props']['fields'];
    }

    /** @return array<string, mixed> */
    private function card(User $user): array
    {
        return (array) ($this->actingAs($user)->get('/channels')->viewData('page')['props']['connections'][0] ?? []);
    }

    /** @return array<string, mixed> */
    private function settingsOf(ChannelConnection $connection): array
    {
        return (array) $this->asSystem(fn () => ChannelConnection::query()->findOrFail($connection->id)->settings);
    }

    private function channelType(string $code, string $adapter): void
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => $code], [
            'name' => ucfirst($code),
            'kind' => 'marketplace',
            'adapter_class' => $adapter,
            'supports_webhooks' => false,
            'is_active' => true,
        ]));
    }

    /** @return array{0: User, 1: ChannelConnection} */
    private function etsyConnection(?string $account = null): array
    {
        $this->channelType('etsy', EtsyAdapter::class);

        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Etsy Ayar '.uniqid(), owner: $user);

        $connection = $this->asTenant($tenant, function () use ($account): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'etsy',
                'external_account_id' => $account ?? '26418816-'.uniqid(),
                'status' => 'active',
                'settings' => [EtsyAdapter::SHOP_ID_KEY => '26418816'],
            ]);

            app(CredentialVault::class)->store($connection, [
                'access_token' => '12345.token',
                'refresh_token' => '12345.refresh',
            ], expiresAt: now()->addHour(), refreshExpiresAt: now()->addDays(90));

            return $connection;
        });

        return [$user, $connection];
    }
}
