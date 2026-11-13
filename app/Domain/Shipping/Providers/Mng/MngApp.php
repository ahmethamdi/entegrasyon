<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Providers\Mng;

/**
 * 34Pazar'ın MNG API Zone uygulaması (IBM API Connect `Client Id`/`Secret`).
 *
 * Satıcının değil PLATFORMUN kimliğidir: API Zone'da hesap açılır, uygulama
 * oluşturulur ve Identity / Standard Command / Barcode Command / Standard
 * Query / CBS Info ürünlerine abone olunur. Test (sandbox) ve canlı ayrı
 * hesaplardır → ayrı anahtar. Tanımlı değilse MNG satıcıya HİÇ sunulmaz
 * (`AmazonApp`/`OttoApp`/`EtsyApp::configured` deseni).
 */
final class MngApp
{
    /** @return array{id: string, secret: string}|null */
    public static function credentials(string $environment): ?array
    {
        $prefix = $environment === 'test' ? 'shipping.mng.test_client_' : 'shipping.mng.client_';
        $id = trim((string) config($prefix.'id', ''));
        $secret = trim((string) config($prefix.'secret', ''));

        return $id !== '' && $secret !== '' ? ['id' => $id, 'secret' => $secret] : null;
    }

    /** En az bir ortamın anahtarı varsa firma sunulabilir. */
    public static function configured(): bool
    {
        return self::credentials('live') !== null || self::credentials('test') !== null;
    }
}
