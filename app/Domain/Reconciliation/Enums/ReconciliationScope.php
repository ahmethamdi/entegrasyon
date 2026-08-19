<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Enums;

/**
 * §10 · üç mutabakat katmanı — sıklık, kapsam ve bütçe.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · bütçe tablosu, §15 · zamanlanmış işler.
 *
 *   KATMAN   SIKLIK    KAPSAM                                   BÜTÇE
 *   Sıcak    5 dakika  Son 30 dk satış · geçici hata ·          ≤ 50 / bağlantı
 *                      1 saattir bekleyen
 *   Ilık     Saatlik   Son 24 saat satış · yüksek devirli ·     ≤ 300 / bağlantı
 *                      24 saattir bakılmamış
 *   Soğuk    Günlük    Rastgele örneklem — uzun kuyruk          Aktif listing'lerin
 *                                                               %2'si, üst sınır 500
 *
 * TAM KATALOG TARAMASI HİÇBİR KATMANDA YOKTUR. Üç katman da
 * önceliklendirilmiş: en pahalı sürüklenme en sık, en ucuzu en seyrek
 * kontrol edilir.
 *
 * SICAK VE ILIK AYNI DÖRT ADAY SORGUSUNU KULLANIR, yalnızca PENCERELER
 * genişler. Soğuk katman ise TAMAMEN FARKLI bir soru sorar ve bu yüzden
 * o dört sorgunun hiçbirini çalıştırmaz — ayrıntı `usesReasonQueries()`
 * gerekçesinde.
 *
 * PENCERELER KATMANDAN GELİR, SORGUYA GÖMÜLMEZ: gömülü olsaydı ılık
 * katman sıcak katmanın sorgusunu kopyalayarak yazılır ve iki kopya
 * zamanla ayrışırdı.
 */
enum ReconciliationScope: string
{
    /** 5 dakikada bir — son 30 dk satış, geçici hata, 1 saattir bekleyen. */
    case HOT = 'hot';

    /** Saatlik — son 24 saat satış, 24 saattir bakılmamış. */
    case WARM = 'warm';

    /** Günlük — rastgele örneklem, uzun kuyruk. */
    case COLD = 'cold';

    /**
     * "Son X içinde satış oldu" penceresi (PostgreSQL interval metni).
     *
     * Sıcak katman DAR bakar (30 dk): amacı taze satışın yarattığı
     * sürüklenmeyi hızla yakalamak. Ilık katman GENİŞ bakar (24 saat):
     * amacı sıcak katmanın bütçesine sığmamış ya da o pencerenin dışında
     * kalmış satırları toplamak.
     */
    public function soldWithin(): string
    {
        return match ($this) {
            self::HOT => '30 minutes',
            self::WARM, self::COLD => '24 hours',
        };
    }

    /**
     * "Bu kadar süredir bekliyor" eşiği — `stale_sync` adayı (§10).
     *
     * Sıcak katmanda 1 saat: bir saattir gönderilmemiş satır zaten
     * sorunludur. Ilık katmanda 24 saat: sıcak katman onu her beş dakikada
     * bir zaten görüyor; ılık katmanın aynı eşiği kullanması bütçesini
     * sıcak katmanın çoktan baktığı satırlarla doldururdu.
     */
    public function pendingFor(): string
    {
        return match ($this) {
            self::HOT => '1 hour',
            self::WARM, self::COLD => '24 hours',
        };
    }

    /**
     * "Sürüklenme bulunmuş, doğrulama turu bekliyor" penceresi.
     *
     * Doğrulama AYRI turda yapılır (§10): onarımdan hemen sonra okumak hem
     * kota yer hem de pazaryerlerinde stok saniyeler sonra yansıdığı için
     * yanlış sonuç verir. Pencere 24 saat — dokümanın kendi değeri.
     */
    public function driftWithin(): string
    {
        return '24 hours';
    }

    /**
     * Bağlantı başına listing bütçesi (§10 · bütçe tablosu).
     *
     * SOĞUK KATMANIN BÜTÇESİ ORANSALDIR ve bu sabit yalnızca ÜST SINIRDIR:
     * "aktif listing'lerin %2'si, üst sınır 500". Hesap
     * `SampledCandidates::budgetFor()` içinde yapılır — 50 listing'i olan
     * bir bağlantıda sabit 500 kullanmak, günlük turun katalogun TAMAMINI
     * okuması demek olurdu ve "tam katalog taraması hiçbir katmanda yok"
     * kuralını sessizce çiğnerdi.
     */
    public function budget(): int
    {
        return match ($this) {
            self::HOT => 50,
            self::WARM => 300,
            self::COLD => 500,
        };
    }

    /**
     * Soğuk katmanın oransal bütçesi — aktif listing'lerin yüzdesi.
     */
    public function samplePercent(): int
    {
        return 2;
    }

    /**
     * Bu katman DÖRT SEBEP SORGUSUNU mu kullanır, yoksa ÖRNEKLEM mi?
     *
     * SOĞUK KATMAN DÖRT SORGUYU ÇALIŞTIRMAZ. Dokümanın KAPSAM sütunu soğuk
     * için tek şey diyor: "Rastgele örneklem — uzun kuyruk". Uzun kuyruk
     * tam olarak o dört sebebin HİÇBİRİNE takılmayan satırdır: satılmıyor,
     * hata almamış, bekleyen işi yok, sürüklenme geçmişi yok. Sessizce
     * sürüklenen ve hiçbir tetikleyicisi olmayan satır YALNIZCA burada
     * yakalanır.
     *
     * Dört sorgu soğukta da koşsaydı soğuk katman ılık katmanın günlük bir
     * kopyası olurdu: 500'lük bütçenin çoğunu ılık turun bir saat önce
     * zaten baktığı satırlar yer ve uzun kuyruk yine hiç görülmezdi.
     */
    public function usesReasonQueries(): bool
    {
        return $this !== self::COLD;
    }
}
