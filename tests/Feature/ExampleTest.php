<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Uygulama iskeleti ayakta mı — HTTP katmanı ve varlık derlemesi.
 *
 * Laravel'in kurulumdan gelen testi `GET /` için 200 bekliyordu; panel
 * kimlik doğrulamaya bağlandıktan sonra o varsayım geçersiz kaldı ve
 * misafir artık giriş ekranına yönlendiriliyor.
 *
 * Test SİLİNMEDİ çünkü CI'da Vite varlıklarının gerçekten derlendiğini
 * doğrulayan tek yer burası: giriş ekranı da Blade üzerinden render edilir
 * ve manifest yoksa istisna fırlatır (`public/build` .gitignore'dadır).
 */
final class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /** Misafir panel yerine giriş ekranına yönlendirilir. */
    #[Test]
    public function guest_is_redirected_from_the_panel_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    /**
     * Giriş ekranı render EDİLİR — Vite manifest'i okunabiliyor demektir.
     */
    #[Test]
    public function login_screen_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Entegrasyon', escape: false);
    }
}
