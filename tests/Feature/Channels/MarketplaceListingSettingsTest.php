<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Hepsiburada\Catalog\HepsiburadaCatalog;
use App\Domain\Channels\Adapters\Hepsiburada\HepsiburadaAdapter;
use App\Domain\Channels\Adapters\N11\Catalog\N11Catalog;
use App\Domain\Channels\Adapters\N11\N11Adapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/channels/{id}/settings` · N11 (KDV, teslimat şablonu, hazırlık günü)
 * ve Hepsiburada (KDV).
 *
 * 24 Eki'ye kadar üçü de panelde girilemiyordu; `N11Catalog` ürün açmayı
 * bu ayarlar olmadan reddettiği için N11'e ürün açma canlıda fiilen
 * kapalıydı. Şablon adı mağazadan SOAP `GetShipmentTemplateList` ile okunur.
 */
final class MarketplaceListingSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** Şablonlar mağazadan gelir; KDV ve hazırlık günü kapalı kümedir. */
    #[Test]
    public function the_screen_offers_store_templates_vat_and_preparing_days(): void
    {
        [$user, $connection] = $this->n11Connection();
        $this->fakeTemplates(['Standart Kargo', 'Ücretsiz Kargo'], default: 'Standart Kargo');

        $fields = collect($this->fieldsOn($user, $connection))->keyBy('key');

        $this->assertSame(['0', '1', '10', '20'], array_column($fields[N11Catalog::SETTING_VAT_RATE]['options'], 'value'));
        $this->assertSame(['Standart Kargo', 'Ücretsiz Kargo'], array_column($fields[N11Catalog::SETTING_SHIPMENT_TEMPLATE]['options'], 'value'));
        $this->assertStringContainsString('varsayılan', $fields[N11Catalog::SETTING_SHIPMENT_TEMPLATE]['options'][0]['label']);
        $this->assertContains('3', array_column($fields[N11Catalog::SETTING_PREPARING_DAY]['options'], 'value'));

        // ⚠️ Varsayılan YOK: hazırlık günü bir söz, KDV ürünün vergisi.
        $this->assertNull($fields[N11Catalog::SETTING_VAT_RATE]['value']);
        $this->assertNull($fields[N11Catalog::SETTING_PREPARING_DAY]['value']);

        Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://api.n11.com/ws/shipmentService/'
            && str_contains($r->body(), '<sch:GetShipmentTemplateListRequest>')
            && str_contains($r->body(), '<appKey>ANAHTAR-1</appKey>'));
    }

    /** Geçerli seçimler kaydedilir; kart eksik ayarı ağ çağrısı yapmadan sayar. */
    #[Test]
    public function valid_choices_are_saved_and_the_card_counts_missing_settings(): void
    {
        [$user, $connection] = $this->n11Connection();
        $this->fakeTemplates(['Standart Kargo']);

        $this->assertSame(3, $this->card($user)['settingsMissing']);

        $this->actingAs($user)->put("/channels/{$connection->id}/settings", [
            N11Catalog::SETTING_VAT_RATE => '20',
            N11Catalog::SETTING_SHIPMENT_TEMPLATE => 'Standart Kargo',
            N11Catalog::SETTING_PREPARING_DAY => '3',
        ])->assertRedirect('/channels')->assertSessionHasNoErrors();

        $settings = $this->settingsOf($connection);

        $this->assertSame('20', $settings[N11Catalog::SETTING_VAT_RATE]);
        $this->assertSame('Standart Kargo', $settings[N11Catalog::SETTING_SHIPMENT_TEMPLATE]);
        $this->assertSame('3', $settings[N11Catalog::SETTING_PREPARING_DAY]);

        Http::fake(['*' => Http::response('', 500)]);
        $this->assertSame(0, $this->card($user)['settingsMissing']);
    }

    /**
     * ⚠️ MAĞAZADA OLMAYAN ŞABLON ADI YAZILAMAZ.
     *
     * Ad elle yazılabilseydi yazım hatası ilk ilanda N11'in reddiyle çıkardı.
     */
    #[Test]
    public function a_template_name_not_in_the_store_is_rejected(): void
    {
        [$user, $connection] = $this->n11Connection();
        $this->fakeTemplates(['Standart Kargo']);

        $this->actingAs($user)->put("/channels/{$connection->id}/settings", [
            N11Catalog::SETTING_VAT_RATE => '20',
            N11Catalog::SETTING_SHIPMENT_TEMPLATE => 'Standart  Kargo',
            N11Catalog::SETTING_PREPARING_DAY => '3',
        ])->assertSessionHasErrors(N11Catalog::SETTING_SHIPMENT_TEMPLATE);

        $this->assertArrayNotHasKey(N11Catalog::SETTING_SHIPMENT_TEMPLATE, $this->settingsOf($connection));
    }

    /** KDV ve hazırlık günü liste dışı olamaz, boş bırakılamaz. */
    #[Test]
    public function vat_and_preparing_day_must_come_from_the_list(): void
    {
        [$user, $connection] = $this->n11Connection();
        $this->fakeTemplates(['Standart Kargo']);

        $this->actingAs($user)->put("/channels/{$connection->id}/settings", [
            N11Catalog::SETTING_VAT_RATE => '18',
            N11Catalog::SETTING_SHIPMENT_TEMPLATE => 'Standart Kargo',
            N11Catalog::SETTING_PREPARING_DAY => '',
        ])->assertSessionHasErrors([N11Catalog::SETTING_VAT_RATE, N11Catalog::SETTING_PREPARING_DAY]);
    }

    /**
     * ⚠️ ŞABLON OKUNAMAZSA "ŞABLON YOK" DENMEZ ve kayıt yapılmaz.
     *
     * N11 kimlik reddini HTTP 200 + `result.status=failure` ile döner;
     * boş listeye çevrilseydi satıcı mağazasında şablon olmadığını sanırdı.
     */
    #[Test]
    public function an_unreadable_template_list_blocks_saving_with_a_reason(): void
    {
        [$user, $connection] = $this->n11Connection();
        Http::fake(['api.n11.com/ws/*' => Http::response($this->envelope(
            '<result><status>failure</status><errorCode>SELLER_API.authenticationFailed</errorCode><errorMessage>Doğrulama başarısız</errorMessage></result>'
        ))]);

        $field = collect($this->fieldsOn($user, $connection))->keyBy('key')[N11Catalog::SETTING_SHIPMENT_TEMPLATE];

        $this->assertStringContainsString('okunamadı', (string) $field['optionsError']);

        $this->actingAs($user)->put("/channels/{$connection->id}/settings", [
            N11Catalog::SETTING_VAT_RATE => '20',
            N11Catalog::SETTING_SHIPMENT_TEMPLATE => 'Standart Kargo',
            N11Catalog::SETTING_PREPARING_DAY => '3',
        ])->assertSessionHasErrors(N11Catalog::SETTING_SHIPMENT_TEMPLATE);
    }

    /** Hiç şablonu olmayan mağaza nereden oluşturacağını öğrenir. */
    #[Test]
    public function a_store_without_templates_is_told_where_to_create_one(): void
    {
        [$user, $connection] = $this->n11Connection();
        $this->fakeTemplates([]);

        $field = collect($this->fieldsOn($user, $connection))->keyBy('key')[N11Catalog::SETTING_SHIPMENT_TEMPLATE];

        $this->assertStringContainsString('Teslimat Bilgileri', (string) $field['optionsError']);
    }

    /**
     * Hepsiburada'da da aynı açık vardı: KDV zorunlu, girilecek yer yoktu.
     * Ağ çağrısı YOK — seçenekler kapalı küme.
     */
    #[Test]
    public function hepsiburada_vat_rate_can_be_chosen_without_a_default(): void
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'hepsiburada'], [
            'name' => 'Hepsiburada', 'kind' => 'marketplace', 'adapter_class' => HepsiburadaAdapter::class,
            'supports_webhooks' => false, 'is_active' => true,
        ]));
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'HB Ayar '.uniqid(), owner: $user);
        $connection = $this->asTenant($tenant, fn (): ChannelConnection => ChannelConnection::factory()->create([
            'channel_type_code' => 'hepsiburada',
            'external_account_id' => 'hb-'.uniqid(),
            'status' => 'active',
            'settings' => [HepsiburadaAdapter::ENVIRONMENT_KEY => HepsiburadaAdapter::ENVIRONMENT_TEST],
        ]));
        Http::fake();

        $field = collect($this->fieldsOn($user, $connection))->keyBy('key')[HepsiburadaCatalog::SETTING_VAT_RATE];
        $this->assertNull($field['value']);
        $this->assertSame(1, $this->card($user)['settingsMissing']);

        $this->actingAs($user)->put("/channels/{$connection->id}/settings", [HepsiburadaCatalog::SETTING_VAT_RATE => '18'])
            ->assertSessionHasErrors(HepsiburadaCatalog::SETTING_VAT_RATE);
        $this->actingAs($user)->put("/channels/{$connection->id}/settings", [HepsiburadaCatalog::SETTING_VAT_RATE => '10'])
            ->assertSessionHasNoErrors();

        $this->assertSame('10', $this->settingsOf($connection)[HepsiburadaCatalog::SETTING_VAT_RATE]);
        $this->assertSame(0, $this->card($user)['settingsMissing']);
        $this->assertSame(HepsiburadaAdapter::ENVIRONMENT_TEST, $this->settingsOf($connection)[HepsiburadaAdapter::ENVIRONMENT_KEY]);
        Http::assertNothingSent();
    }

    /** @param list<string> $names */
    private function fakeTemplates(array $names, ?string $default = null): void
    {
        $items = implode('', array_map(
            static fn (string $name): string => '<shipmentTemplate><templateName>'.htmlspecialchars($name, ENT_XML1).'</templateName>'
                .'<defaultTemplate>'.($name === $default ? 'true' : 'false').'</defaultTemplate></shipmentTemplate>',
            $names,
        ));

        Http::fake(['api.n11.com/ws/*' => Http::response($this->envelope(
            '<result><status>success</status></result><shipmentTemplates>'.$items.'</shipmentTemplates>'
        ))]);
    }

    private function envelope(string $inner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Body>'
            .'<ns3:GetShipmentTemplateListResponse xmlns:ns3="http://www.n11.com/ws/schemas">'.$inner.'</ns3:GetShipmentTemplateListResponse>'
            .'</SOAP-ENV:Body></SOAP-ENV:Envelope>';
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

    /** @return array{0: User, 1: ChannelConnection} */
    private function n11Connection(): array
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(['code' => 'n11'], [
            'name' => 'N11',
            'kind' => 'marketplace',
            'adapter_class' => N11Adapter::class,
            'supports_webhooks' => false,
            'is_active' => true,
        ]));

        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'N11 Ayar '.uniqid(), owner: $user);

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'n11',
                'external_account_id' => 'n11-'.uniqid(),
                'status' => 'active',
            ]);

            app(CredentialVault::class)->store($connection, ['app_key' => 'ANAHTAR-1', 'app_secret' => 'SIR-GIZLI-12345']);

            return $connection;
        });

        return [$user, $connection];
    }
}
