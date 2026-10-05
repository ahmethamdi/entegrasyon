<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Panel dili (TR/EN): seçim → oturum → tarayıcı; varsayılan Türkçe.
 *
 * Shopify inceleme ekibi İngilizce tarayıcıyla, seçim yapmadan gelir ve
 * paneli İngilizce görmelidir; Türk satıcı hiçbir şey yapmadan Türkçe.
 */
final class PanelLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.english_panel' => true]);
    }

    /**
     * ŞALTER KAPALIYKEN PANEL HEP TÜRKÇE — çeviri bitmeden İngilizce
     * tarayıcılı satıcı yarı çevrilmiş panel görmesin.
     */
    #[Test]
    public function with_the_switch_off_the_panel_is_always_turkish(): void
    {
        config(['app.english_panel' => false]);

        $user = $this->owner();
        $user->forceFill(['locale' => 'en'])->save();

        $props = $this->propsOf($this->actingAs($user)->withHeader('Accept-Language', 'en-US')->get('/panel'));

        $this->assertSame('tr', $props['locale']);
        $this->assertFalse($props['localeSwitch']);
    }

    #[Test]
    public function the_panel_defaults_to_turkish_with_no_dictionary(): void
    {
        $props = $this->propsOf($this->actingAs($this->owner())->get('/panel'));

        $this->assertSame('tr', $props['locale']);
        $this->assertSame([], $props['translations'], 'Türkçede sözlük gönderilmez — anahtar metnin kendisi.');
    }

    #[Test]
    public function an_english_browser_gets_english_without_choosing(): void
    {
        $props = $this->propsOf($this->actingAs($this->owner())
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->get('/panel'));

        $this->assertSame('en', $props['locale']);
        $this->assertSame('Home', $props['translations']['Ana sayfa'] ?? null);
    }

    #[Test]
    public function a_browser_preferring_turkish_stays_turkish(): void
    {
        $props = $this->propsOf($this->actingAs($this->owner())
            ->withHeader('Accept-Language', 'tr-TR,tr;q=0.9,en;q=0.8')
            ->get('/panel'));

        $this->assertSame('tr', $props['locale']);
    }

    /**
     * Seçim KULLANICIYA yazılır: telefondan girince de geçerli kalmalı.
     */
    #[Test]
    public function the_choice_is_saved_on_the_user_and_beats_the_browser(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->from('/panel')->post('/locale', ['locale' => 'en'])->assertRedirect('/panel');

        $this->assertSame('en', $user->fresh()->locale);

        $props = $this->propsOf($this->actingAs($user->fresh())
            ->withHeader('Accept-Language', 'tr-TR')
            ->get('/panel'));

        $this->assertSame('en', $props['locale']);
    }

    #[Test]
    public function a_guest_can_choose_on_the_login_screen(): void
    {
        $this->post('/locale', ['locale' => 'en'])->assertRedirect();

        $this->assertSame('en', $this->propsOf($this->get('/login'))['locale']);
    }

    #[Test]
    public function an_unsupported_language_is_rejected(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->post('/locale', ['locale' => 'de'])->assertSessionHasErrors('locale');

        $this->assertNull($user->fresh()->locale);
    }

    /**
     * TANITIM SİTESİ TÜRKÇE KALIR: Türkçe metnin içinde İngilizce ay adı
     * (blog tarihi) basılmasın.
     */
    #[Test]
    public function the_marketing_site_ignores_the_browser_language(): void
    {
        $this->withHeader('Accept-Language', 'en-US')->get('/')->assertOk();

        $this->assertSame('tr', app()->getLocale());
    }

    private function owner(): User
    {
        $user = User::factory()->create();

        (new CreateTenant)->run(name: 'Dil Testi', owner: $user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function propsOf(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props'];
    }
}
