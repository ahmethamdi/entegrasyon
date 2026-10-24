<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 24 Eki 2026 açılış denetimi · hata sayfaları Türkçe ve markalı.
 * Canlıda Laravel'in İngilizce "Not Found" sayfası görüldü.
 */
final class ErrorPageTest extends TestCase
{
    #[Test]
    public function a_missing_page_is_turkish_and_branded(): void
    {
        $this->get('/boyle-bir-sayfa-yok')
            ->assertNotFound()
            ->assertSee('Aradığın sayfa burada değil.')
            ->assertSee('/images/34pazar-logo.png', false)
            ->assertSee('wa.me/4917676798125', false)
            ->assertSee('<meta name="robots" content="noindex">', false)
            ->assertDontSee('Not Found');
    }
}
