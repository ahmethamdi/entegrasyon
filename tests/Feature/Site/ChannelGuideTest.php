<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use App\Domain\Channels\Adapters\Shopify\ShopifyAuth;
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
     * Shopify rehberi App Store bağlantısı VERMEZ (Ref 140131 reddi; ADR-012):
     * satıcı kendi uygulamasını açar. Rehber adımları ve formun istediği
     * izin listesini aynen gösterir — listing bayrağı ne olursa olsun.
     */
    #[Test]
    public function the_shopify_guide_shows_the_own_app_steps_and_no_app_store_link(): void
    {
        foreach ([false, true] as $public) {
            config(['services.shopify.listing_public' => $public]);

            $this->get('/yardim/shopify')->assertOk()
                ->assertDontSee('apps.shopify.com', false)
                ->assertSee('Dev Dashboard')
                ->assertSee(ShopifyAuth::DEFAULT_SCOPES)
                ->assertSee('Client secret')
                ->assertSee('stok ve fiyat gönderimi açık başlar');
        }
    }

    #[Test]
    public function guides_are_in_the_sitemap(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString(route('site.help').'</loc>', $xml);
        $this->assertStringContainsString(route('site.help.channel', 'trendyol').'</loc>', $xml);
    }
}
