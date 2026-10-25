<?php

declare(strict_types=1);

namespace App\Domain\Channels\Certification;

/**
 * S15 · bir kanal × yetenek hücresinin durumu.
 *
 * Kaynakları ayrıdır ve birbirinin yerine geçmez:
 *   NOT_SUPPORTED     adaptör açıkça beyan eder (`DeclaresUnsupportedCapabilities`)
 *   NOT_IMPLEMENTED   arayüz yok
 *   IMPLEMENTED       arayüz var, sahte HTTP testi bulunamadı
 *   MOCK_TESTED       test taraması (sahte HTTP) — GERÇEK hesap DEĞİL
 *   SANDBOX_VERIFIED  kanıtlı kayıt: kanalın test ortamında gerçek istekle
 *   PRODUCTION_VERIFIED kanıtlı kayıt: gerçek satıcı hesabında
 *   FAILED            kanıtlı kayıt: gerçek denemede başarısız (en yeni kayıt)
 *
 * "Test geçti" ≠ "kanal doğrulandı": MOCK_TESTED hiçbir zaman VERIFIED gibi
 * gösterilmez (protokol: mock ve gerçek hesap sonuçları ayrı raporlanır).
 */
enum CertificationStatus: string
{
    case NOT_SUPPORTED = 'NOT_SUPPORTED';
    case NOT_IMPLEMENTED = 'NOT_IMPLEMENTED';
    case IMPLEMENTED = 'IMPLEMENTED';
    case MOCK_TESTED = 'MOCK_TESTED';
    case SANDBOX_VERIFIED = 'SANDBOX_VERIFIED';
    case PRODUCTION_VERIFIED = 'PRODUCTION_VERIFIED';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::NOT_SUPPORTED => 'Kanal desteklemiyor',
            self::NOT_IMPLEMENTED => 'Yazılmadı',
            self::IMPLEMENTED => 'Kodda var, testsiz',
            self::MOCK_TESTED => 'Sahte testle sınandı',
            self::SANDBOX_VERIFIED => 'Test ortamında doğrulandı',
            self::PRODUCTION_VERIFIED => 'Gerçek hesapta doğrulandı',
            self::FAILED => 'Gerçek denemede başarısız',
        };
    }

    /** Satıcıya "çalışır" denebilir mi (kod + en az sahte test ya da gerçek doğrulama). */
    public function works(): bool
    {
        return in_array($this, [self::IMPLEMENTED, self::MOCK_TESTED, self::SANDBOX_VERIFIED, self::PRODUCTION_VERIFIED], true);
    }
}
