<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 24 Eki 2026 açılış denetimi · destek sözü her yerde aynı.
 *
 * Fiyat sayfası "her gün WhatsApp + e-posta" diyordu; iletişim sayfası ve
 * alt bilgi ise telefon araması (tel:) gösteriyor, WhatsApp hiç geçmiyordu.
 */
final class SupportChannelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function contact_page_footer_and_top_bar_offer_whatsapp_not_a_phone_call(): void
    {
        $wa = 'https://wa.me/'.config('site.whatsapp');

        $contact = $this->get('/iletisim')->assertOk();
        $contact->assertSee('<h2 class="heading-3 mt-4">WhatsApp</h2>', false)->assertSee(config('site.support_hours'));
        $this->assertGreaterThanOrEqual(3, substr_count($contact->getContent(), $wa), 'İletişim kartı, alt bilgi ve üst çubuk');
        $contact->assertDontSee('href="tel:', false);
        // Tek tırnaklı @section metnindeki kesme işareti Blade'i kırıp yönergeyi sayfaya döküyordu.
        $contact->assertDontSee('@section(', false)->assertDontSee('@push(', false);

        $this->get('/')->assertOk()->assertSee($wa, false)->assertDontSee('href="tel:', false);
    }
}
