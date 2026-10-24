<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use App\Domain\Channels\Adapters\Amazon\AmazonAdapter;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 24 Eki 2026 açılış denetimi · uygulama kaydı eksik kanal SUNULMAZ.
 *
 * Canlıda Amazon ve OTTO uygulama anahtarları boştu: site iki kanalı "her
 * pakette" diye listeliyor, bağlanma isteği `AmazonApp::clientId()`
 * istisnasıyla 500'e düşüyordu. Etsy anahtarı doluydu ama Etsy'de etkin
 * değildi ("API key not active") — `CHANNELS_WITHHELD` ile saklanır.
 */
final class ChannelOfferingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChannelTypeSeeder::class);
        DB::table('channel_types')->whereIn('code', ['trendyol', 'amazon', 'etsy'])->update(['is_active' => true]);
        config(['services.amazon' => ['client_id' => null, 'client_secret' => null, 'application_id' => null]]);
        config(['services.etsy' => ['keystring' => 'k', 'shared_secret' => 's']]);
        config(['entegrasyon.channels_withheld' => []]);
    }

    #[Test]
    public function a_channel_without_its_app_registration_is_not_on_the_site(): void
    {
        $this->get('/entegrasyonlar/amazon')->assertNotFound();
        $this->get('/yardim/amazon')->assertNotFound();
        $this->get('/entegrasyonlar')->assertOk()->assertDontSee('/entegrasyonlar/amazon');
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/entegrasyonlar/amazon');

        $this->get('/entegrasyonlar/trendyol')->assertOk();
    }

    #[Test]
    public function it_comes_back_by_itself_once_the_app_is_registered(): void
    {
        config(['services.amazon' => ['client_id' => 'amzn1.cid', 'client_secret' => 'sec', 'application_id' => 'amzn1.sp.app']]);

        $this->get('/entegrasyonlar/amazon')->assertOk();
        $this->get('/entegrasyonlar')->assertOk()->assertSee('/entegrasyonlar/amazon');
    }

    #[Test]
    public function a_withheld_channel_is_hidden_even_with_keys(): void
    {
        $this->get('/entegrasyonlar/etsy')->assertOk();

        config(['entegrasyon.channels_withheld' => ['etsy']]);

        $this->get('/entegrasyonlar/etsy')->assertNotFound();
        $this->get('/entegrasyonlar')->assertOk()->assertDontSee('/entegrasyonlar/etsy');
    }

    #[Test]
    public function the_connect_form_neither_offers_nor_accepts_it(): void
    {
        $user = User::factory()->create();
        (new CreateTenant)->run(name: 'Sunum '.uniqid(), owner: $user);

        $this->actingAs($user)->get('/channels/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('channelTypes', fn ($types): bool => collect($types)->pluck('code')->doesntContain('amazon')
                && collect($types)->pluck('code')->contains('trendyol')));

        // Doğrudan POST da kapıda Türkçe reddedilir — 500 değil.
        $this->actingAs($user)->post('/channels', ['channel_type_code' => 'amazon', 'label' => 'A'])
            ->assertSessionHasErrors('channel_type_code');

        $this->assertSame(AmazonAdapter::class, ChannelType::query()->whereKey('amazon')->value('adapter_class'));
    }
}
