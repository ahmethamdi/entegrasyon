<?php

declare(strict_types=1);

namespace App\Support\Observability;

/**
 * Bir uyarının kimliği — tekrar gönderimi önleyen çıpanın metni.
 *
 * Mimari Karar Dokümanı v2.2 · §11, §12.
 *
 * `MetricScope` ile AYNI AİLEDEN ve aynı tuzağı taşır: biçimi hem YAZAN
 * hem OKUYAN taraf bu sınıftan alır. Önek değişirse ikisi BİRLİKTE kayar,
 * davranış testleri YEŞİL KALIR ve `alert_deliveries` içindeki eski
 * satırlar bir daha HİÇ BULUNAMAZ — yani tekrar koruması sessizce
 * kaybolur ve satıcı bir sabah aynı uyarıyı ikinci kez alır.
 *
 * Bu yüzden testler BEKLENEN METİNLE sınar, yardımcıyı kendi içinde
 * karşılaştırmaz (geçmiş bir turda `MetricScope`'ta tam bu mutasyon
 * hayatta kalmıştı).
 */
final class AlertKey
{
    /**
     * Kiracı kapsamlı metrik uyarısı.
     *
     * Kiracı kimliği anahtarın İÇİNDEDİR: iki kiracı aynı metrikte aynı
     * gün eşiği aşabilir ve ikisi de kendi uyarısını almalıdır. Yalnızca
     * metrik adına bağlansaydı ilk kiracının gönderimi ikincisininkini
     * bastırır ve o satıcı sorunundan HİÇ haberdar olmazdı.
     */
    public static function metricForTenant(Metric $metric, string $tenantId): string
    {
        return sprintf('metric:%s:tenant:%s', $metric->value, $tenantId);
    }

    /** Bağlantı kapsamlı metrik uyarısı (api gecikmesi, 429). */
    public static function metricForConnection(Metric $metric, string $connectionId): string
    {
        return sprintf('metric:%s:connection:%s', $metric->value, $connectionId);
    }

    /** Sistem geneli metrik uyarısı — yöneticiye gider. */
    public static function metricForSystem(Metric $metric): string
    {
        return sprintf('metric:%s:system', $metric->value);
    }

    /**
     * §12'nin ölü iş günlük özeti — kiracı başına.
     *
     * Metrik uyarısından AYRI bir anahtardır ve bu bilinçlidir:
     * `dead_operations` metriği eşik aşımını bildirir, günlük özet ise
     * §12'nin ayrıca istediği ÖZET'tir. Aynı anahtarı paylaşsalardı
     * biri diğerini o gün için bastırır ve satıcı ya eşik uyarısını ya
     * özeti kaybederdi.
     */
    public static function deadLetterDigest(string $tenantId): string
    {
        return sprintf('digest:dead_operations:tenant:%s', $tenantId);
    }
}
