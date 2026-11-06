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

    /**
     * Tanıtım sitesinde gösterilen DÜRÜST etiket (6 Kas 2026 · site sprint 1).
     *
     * Sitede "çalışır" demek gerçek hesapta denenmemiş kanalı denenmiş gibi
     * gösteriyordu. Ayrım üç basamaklıdır ve İÇ DURUM ADI satıcıya gitmez:
     *   gerçek satıcı hesabında → "Canlıda doğrulandı"
     *   kanalın test sunucusunda → "Test ortamında doğrulandı"
     *   kod + sahte test         → "Test ediliyor" (gerçek hesapta denenmedi)
     * Kod yoksa satır kanalın kendi panelinde yapılır; başarısız gerçek
     * deneme "Şu an çalışmıyor" der.
     */
    public function publicLabel(): string
    {
        return match ($this) {
            self::PRODUCTION_VERIFIED => 'Canlıda doğrulandı',
            self::SANDBOX_VERIFIED => 'Test ortamında doğrulandı',
            self::IMPLEMENTED, self::MOCK_TESTED => 'Test ediliyor',
            self::FAILED => 'Şu an çalışmıyor',
            self::NOT_SUPPORTED, self::NOT_IMPLEMENTED => 'Kanalın panelinde',
        };
    }

    /** Etiketin tek cümlelik açıklaması — sitedeki "durumlar ne demek" listesi. */
    public function publicNote(): string
    {
        return match ($this) {
            self::PRODUCTION_VERIFIED => 'Gerçek bir satıcı hesabında uçtan uca denendi ve çalıştı.',
            self::SANDBOX_VERIFIED => 'Kanalın resmî test sunucusunda gerçek isteklerle denendi; canlı hesapta henüz denenmedi.',
            self::IMPLEMENTED, self::MOCK_TESTED => 'Kodu yazıldı ve kanalın belgesindeki örnek yanıtlarla sınandı; gerçek bir hesapta henüz denenmedi.',
            self::FAILED => 'Gerçek denemede başarısız oldu; düzelene kadar bu işi kanalın kendi panelinden yaparsın.',
            self::NOT_SUPPORTED, self::NOT_IMPLEMENTED => 'Bu iş 34Pazar\'dan yapılmıyor; kanalın kendi satıcı panelinden yaparsın.',
        };
    }

    /** Rozet tonu (CSS `badge--{ton}`): live · sandbox · testing · off. */
    public function publicTone(): string
    {
        return match ($this) {
            self::PRODUCTION_VERIFIED => 'live',
            self::SANDBOX_VERIFIED => 'sandbox',
            self::IMPLEMENTED, self::MOCK_TESTED => 'testing',
            self::FAILED, self::NOT_SUPPORTED, self::NOT_IMPLEMENTED => 'off',
        };
    }

    /** Sitedeki sıralama: yüksek = daha güçlü kanıt. Kanal özeti en ZAYIF çekirdek hücreyi alır. */
    public function evidenceRank(): int
    {
        return match ($this) {
            self::PRODUCTION_VERIFIED => 4,
            self::SANDBOX_VERIFIED => 3,
            self::MOCK_TESTED, self::IMPLEMENTED => 2,
            self::FAILED => 1,
            self::NOT_SUPPORTED, self::NOT_IMPLEMENTED => 0,
        };
    }

    /** Satıcıya "çalışır" denebilir mi (kod + en az sahte test ya da gerçek doğrulama). */
    public function works(): bool
    {
        return in_array($this, [self::IMPLEMENTED, self::MOCK_TESTED, self::SANDBOX_VERIFIED, self::PRODUCTION_VERIFIED], true);
    }
}
