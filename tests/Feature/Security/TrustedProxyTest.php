<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Plesk (nginx) → Caddy → php-fpm zincirinde https bilgisi kaybolmamalı.
 *
 * Kaybolsaydı Laravel isteği "http" sanır; https sayfada http bağlantı
 * üretir ve e-posta doğrulama gibi imzalı bağlantılar "geçersiz imza"
 * verirdi — yerelde (proxy yok) hiç görünmeyen, yalnız canlıda çıkan hata.
 */
final class TrustedProxyTest extends TestCase
{
    #[Test]
    public function forwarded_https_is_honoured(): void
    {
        Route::get('/_test/scheme', fn () => response()->json([
            'secure' => request()->isSecure(),
            'url' => url('/panel'),
        ]));

        $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => '34pazar.com',
            'X-Forwarded-Port' => '443',
        ])->get('/_test/scheme')
            ->assertJson(['secure' => true, 'url' => 'https://34pazar.com/panel']);
    }
}
