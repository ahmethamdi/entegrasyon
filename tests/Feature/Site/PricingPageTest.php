<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use App\Domain\Billing\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ayrıntılı fiyat sayfası (24 Ekim 2026) — /fiyatlar ve /en/pricing.
 *
 * SÖZLEŞME — FİYAT VE SINIR VERİTABANINDAN GELİR: sayfadaki her paket
 * fiyatı ve sınırı `PlanSeeder`'ın yazdığı satırla aynı olmalı. Elle
 * yazılmış bir rakam panelde ödenen fiyattan ayrışırdı.
 *
 * SÖZLEŞME — YALNIZ VERİLEN SÖZ YAZILIR: kurulum yardımı ve iade yalnız
 * ücretli paketlerde; ücretsiz paket kartında bu sözler YOK. KDV hakkında
 * hiçbir şey yazılmaz (kullanıcı kararı, vergi durumu kesinleşmedi).
 */
final class PricingPageTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function urls(): array
    {
        return ['tr' => ['/fiyatlar'], 'en' => ['/en/pricing']];
    }

    #[Test]
    #[DataProvider('urls')]
    public function every_public_plan_price_and_limit_matches_the_database(string $url): void
    {
        (new PlanSeeder)->run();

        $html = $this->get($url)->assertOk()->getContent();
        $plans = Plan::query()->where('is_public', true)->get();

        $this->assertGreaterThanOrEqual(3, $plans->count());

        foreach ($plans as $plan) {
            $amount = (float) $plan->price_monthly;
            $this->assertStringContainsString(e($plan->name), $html);

            if ($amount > 0) {
                $formatted = number_format($amount, floor($amount) === $amount ? 0 : 2, ',', '.').' ₺';
                $this->assertStringContainsString($formatted, $html, "{$plan->code} fiyatı sayfada yok");
            }

            foreach (['products', 'channels'] as $key) {
                $value = $plan->limits[$key] ?? null;
                if ($value !== null) {
                    $this->assertStringContainsString(number_format($value, 0, ',', '.'), $html, "{$plan->code} {$key} sınırı sayfada yok");
                }
            }
        }
    }

    /** Fiyat değişirse sayfa yeni fiyatı gösterir — sabit metin değil. */
    #[Test]
    public function a_changed_price_shows_up_without_template_edits(): void
    {
        (new PlanSeeder)->run();
        Plan::query()->where('code', 'pro')->update(['price_monthly' => '1777.00']);

        $this->get('/fiyatlar')->assertSee('1.777 ₺')->assertDontSee('1.499 ₺');
    }

    /** Kurulum ve iade sözü ücretsiz paket kartında yok, ücretli kartlarda var. */
    #[Test]
    public function setup_and_refund_promises_only_on_paid_plan_cards(): void
    {
        (new PlanSeeder)->run();

        $html = $this->get('/fiyatlar')->assertOk()->getContent();
        preg_match_all('#<li class="plan-card[^"]*">(.*?)</li>\s*(?=<li class="plan-card|</ul>)#s', $html, $cards);

        $this->assertCount(Plan::query()->where('is_public', true)->count(), $cards[1]);

        foreach ($cards[1] as $card) {
            $free = str_contains($card, 'Kart bilgisi gerekmez');
            $this->assertSame(! $free, str_contains($card, 'Kurulum ve ilk ürün aktarımı birlikte, ücretsiz'));
            $this->assertSame(! $free, str_contains($card, 'gün içinde tam iade'));
            $this->assertStringContainsString('WhatsApp ve e-posta destek', $card);
        }
    }

    #[Test]
    public function support_refund_and_company_facts_come_from_config(): void
    {
        (new PlanSeeder)->run();
        config(['site.support_hours' => '08:30–19:30', 'site.refund_days' => 21]);

        $html = $this->get('/fiyatlar')->getContent();
        preg_match_all('#<li class="plan-card[^"]*">(.*?)</li>\s*(?=<li class="plan-card|</ul>)#s', $html, $cards);
        $this->assertNotEmpty($cards[1]);
        foreach ($cards[1] as $card) {
            // Kart da saati ayardan okur — sayfanın başka yerindeki metin yetmez.
            $this->assertStringContainsString('08:30–19:30', $card);
        }

        $this->get('/fiyatlar')
            ->assertSee('wa.me/'.config('site.whatsapp'), false)
            ->assertSee('08:30–19:30')
            ->assertSee('21 gün içinde tam iade')
            ->assertSee('Korschenbroich')
            ->assertDontSee('KDV');
    }

    #[Test]
    public function english_page_is_english_and_linked_both_ways(): void
    {
        (new PlanSeeder)->run();

        $this->get('/en/pricing')
            ->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertSee('Monthly plans. Every channel on every plan.')
            ->assertSee('hreflang="tr"', false)
            ->assertDontSee('Fiyat soruları');

        $this->get('/fiyatlar')->assertSee(route('site.en.pricing'), false);
        $this->assertStringContainsString('/en/pricing</loc>', $this->get('/sitemap.xml')->getContent());
    }
}
