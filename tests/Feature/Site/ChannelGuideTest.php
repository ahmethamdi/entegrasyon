<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use App\Domain\Channels\Support\ChannelConnectForm;
use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S12 · /yardim — kanal bağlama rehberleri.
 *
 * Rehber metni bağlanma formundan okunur; test aynı metni formdan alıp
 * sayfada arar. Rehber ayrı yazılsaydı form değişince sessizce eskirdi.
 */
final class ChannelGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChannelTypeSeeder::class);
        DB::table('channel_types')->whereIn('code', ['trendyol', 'shopify', 'etsy'])->update(['is_active' => true]);
    }

    #[Test]
    public function the_guide_shows_the_same_fields_and_hints_as_the_connect_form(): void
    {
        $form = ChannelConnectForm::present('trendyol');
        $response = $this->get('/yardim/trendyol')->assertOk()->assertSee('Trendyol nasıl bağlanır');

        $response->assertSee($form['help']);
        foreach ([...$form['secretFields'], ...$form['identityFields']] as $field) {
            $response->assertSee($field['label']);
            if (($field['hint'] ?? '') !== '') {
                $response->assertSee($field['hint']);
            }
        }

        // Sırların değeri/örneği değil, yalnız adı gösterilir.
        $response->assertSee('gizli tutulur');
    }

    #[Test]
    public function only_live_channels_with_a_connect_form_get_a_guide(): void
    {
        DB::table('channel_types')->where('code', 'etsy')->update(['is_active' => false]);

        $this->get('/yardim/etsy')->assertNotFound();
        $this->get('/yardim/olmayan')->assertNotFound();
        $this->get('/yardim')->assertOk()->assertSee('/yardim/trendyol')->assertDontSee('/yardim/etsy');
    }

    /**
     * Shopify App Store sayfası yayında değilken (24 Eki: 404) rehber o
     * bağlantıyı vermez, bize yazmayı söyler.
     */
    #[Test]
    public function the_shopify_guide_does_not_link_to_an_unpublished_app_listing(): void
    {
        config(['services.shopify.listing_public' => false]);
        $this->get('/yardim/shopify')->assertOk()
            ->assertDontSee('apps.shopify.com', false)
            ->assertSee('incelemesinde');

        config(['services.shopify.listing_public' => true]);
        $this->get('/yardim/shopify')->assertSee('apps.shopify.com', false);
    }

    #[Test]
    public function guides_are_in_the_sitemap(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString(route('site.help').'</loc>', $xml);
        $this->assertStringContainsString(route('site.help.channel', 'trendyol').'</loc>', $xml);
    }
}
