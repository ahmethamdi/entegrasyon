<?php

declare(strict_types=1);

namespace App\Support\Observability;

/**
 * Ölçülen metrikler ve uyarı eşikleri.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · Ölçülecek metrikler,
 * §12 · Dead letter (kiracı başına 10 ölü iş), §17 · P0.
 *
 * EŞİK METRİĞİN YANINDA YAŞAR. Panelde ayrı bir tabloya yazılsaydı iki
 * gerçek kaynağı doğar: biri değiştiğinde diğeri sessizce eski eşiği
 * uygular ve "kırmızı mı" sorusu iki farklı cevap alırdı.
 *
 * YÖN DE METRİĞİN PARÇASIDIR. Metriklerin çoğunda büyük değer kötüdür
 * (gecikme, hata oranı) ama hepsi öyle değil — yön sabit varsayılsaydı
 * ileride eklenecek bir "başarı oranı" metriği eşiği ters yorumlar ve
 * sağlıklı sistemi kırmızı gösterirdi.
 *
 * KAPSAM DA METRİĞİN PARÇASIDIR. §11'in eşikleri kapsam belirtir:
 * "kiracı başına > 5 adet", "kanal başına > 5 sn". Kapsam bilinmeden
 * eşik uygulanamaz — sistem geneli toplanan bir fazla satış sayısında
 * "kiracı başına 5" kuralının hiçbir anlamı yoktur.
 */
enum Metric: string
{
    // ───────────────────────────────────────────────── sistem geneli

    /** Stok gönderiminin uçtan uca gecikmesi, milisaniye (§11 · > 60 sn). */
    case INVENTORY_SYNC_LATENCY_P95 = 'inventory_sync_latency_p95';

    /** Başarısız senkron denemelerinin yüzdesi (§11 · > %5 saatlik). */
    case SYNC_ERROR_RATE = 'sync_error_rate';

    /** En eski yayınlanmamış outbox olayının yaşı, saniye (§11 · > 60 sn). */
    case OUTBOX_PUBLISH_LAG = 'outbox_publish_lag';

    /** Yayınlandığı hâlde tüketilmemiş olay sayısı (§11 · > 0). */
    case OUTBOX_CONSUME_GAP = 'outbox_consume_gap';

    /** Bekleyen en eski inbox mesajının yaşı, saniye (§11 · > 5 dk). */
    case INBOX_PROCESSING_LAG = 'inbox_processing_lag';

    /** Kurtarmaya aday bekleyen inbox mesajı sayısı (§11 · saatte > 10). */
    case INBOX_RECOVERY_BACKLOG = 'inbox_recovery_backlog';

    /** Worker'ın hiç almadığı operasyon sayısı (§11 · 5 dk üstü > 0). */
    case SYNC_DELIVERY_GAP = 'sync_delivery_gap';

    /** Sürüklenen listing'lerin kontrol edilenlere oranı (§11 · günlük > %1). */
    case DRIFT_RATE = 'drift_rate';

    // ─────────────────────────────────────────────────── kiracı başına

    /** Eksik satılan adet — negatif available'ın kendisi (§11 · > 5). */
    case OVERSOLD_UNITS = 'oversold_units';

    /** Fazla satılmış varyant sayısı (§11 · > 3). */
    case OVERSOLD_VARIANTS = 'oversold_variants';

    /** Ölü senkron operasyonu sayısı (§12 · kiracı başına > 10). */
    case DEAD_OPERATIONS = 'dead_operations';

    // ──────────────────────────────────────────────────── kanal başına

    /** Kanal çağrılarının p95 süresi, milisaniye (§11 · > 5 sn). */
    case API_LATENCY_P95 = 'api_latency_p95';

    /** Son bir saatteki 429 sayısı (§11 · saatte > 100). */
    case RATE_LIMIT_HITS = 'rate_limit_hits';

    // ──────────────────────────── V3.0 · §25 · token ve kota (üç yeni)
    //
    // ⚠️ BUNLAR YENİ BİR ARIZA BİÇİMİNİ ÖLÇER. Yukarıdaki on üç metrik
    // bir şeyin YAVAŞ veya BOZUK olduğunu görür; token ölümünde hiçbir
    // şey yavaşlamaz ve hata oranı yükselmez — bağlantı bir gün
    // SESSİZCE çalışmayı bırakır ve satıcı bunu ancak siparişler
    // kesilince fark eder.

    /** Yakında dolacak (veya dolmuş) aktif token sayısı (§25 · > 0). */
    case TOKEN_EXPIRING_SOON = 'token_expiring_soon';

    /** Son 24 saatteki başarısız token yenileme çağrısı (§25 · > 3). */
    case TOKEN_REFRESH_FAILURES = 'token_refresh_failures';

    /** Kanalın günlük istek kotasının kullanılan yüzdesi (§25 · > %80). */
    case CHANNEL_DAILY_QUOTA_USED = 'channel_daily_quota_used';

    /**
     * Uyarı eşiği — bu değer AŞILIRSA metrik kırmızıdır.
     *
     * §11 · uyarı eşiği sütunu birebir. Değerler metriğin kendi
     * biriminde: gecikmeler MİLİSANİYE veya SANİYE, oranlar YÜZDE,
     * sayımlar adet.
     */
    public function threshold(): float
    {
        return match ($this) {
            self::INVENTORY_SYNC_LATENCY_P95 => 60_000,  // 60 sn, ms cinsinden
            self::SYNC_ERROR_RATE => 5,                  // %5
            self::OUTBOX_PUBLISH_LAG => 60,              // 60 sn
            self::OUTBOX_CONSUME_GAP => 0,               // tek bir tane bile fazla
            self::INBOX_PROCESSING_LAG => 300,           // 5 dk
            self::INBOX_RECOVERY_BACKLOG => 10,
            self::SYNC_DELIVERY_GAP => 0,
            self::DRIFT_RATE => 1,                       // %1
            self::OVERSOLD_UNITS => 5,
            self::OVERSOLD_VARIANTS => 3,
            self::DEAD_OPERATIONS => 10,
            self::API_LATENCY_P95 => 5_000,              // 5 sn, ms cinsinden
            self::RATE_LIMIT_HITS => 100,

            // §25 tablosu birebir.
            self::TOKEN_EXPIRING_SOON => 0,              // tek bir tane bile fazla
            self::TOKEN_REFRESH_FAILURES => 3,           // günde 3
            self::CHANNEL_DAILY_QUOTA_USED => 80,        // %80
        };
    }

    /**
     * Bu metriğin uyarısı KİME gider.
     *
     * ⚠️ ALICI KAPSAMDAN TÜRETİLMEZ — metriğin KENDİ özelliğidir (§25).
     *
     * v2.2'ye kadar kural basitti: kiracı kapsamlı uyarı satıcıya,
     * gerisi yöneticiye. İşliyordu çünkü bağlantı kapsamlı iki metrik
     * de (api gecikmesi, 429) ALTYAPI sorunuydu.
     *
     * Token metrikleri bu eşitliği KIRAR: kapsamları bağlantıdır ama
     * yeniden yetkilendirmeyi YALNIZCA SATICI yapabilir. Kural
     * `DispatchAlerts` içinde bir `if` olarak yazılsaydı metrikten
     * UZAKTA yaşar ve yeni bir token metriği eklendiğinde oraya
     * eklenmesi unutulurdu — eşiğin panelde yeniden tanımlanmasıyla
     * aynı hata biçimi.
     */
    public function alertAudience(): AlertAudience
    {
        return match ($this) {
            // Yapılacak iş SATICININ: fazla satışı düzeltmek, ölü işi
            // yeniden denemek, kanalı yeniden yetkilendirmek.
            self::OVERSOLD_UNITS,
            self::OVERSOLD_VARIANTS,
            self::DEAD_OPERATIONS,
            self::TOKEN_EXPIRING_SOON,
            self::TOKEN_REFRESH_FAILURES => AlertAudience::TENANT,

            // Gerisi ALTYAPI sorunudur. Kota da buradadır: aşımda
            // yapılacak şey stok itme sıklığını düşürmek ve gruplamayı
            // gözden geçirmektir (§21 · P2) — satıcının elinde düğme
            // YOKTUR.
            default => AlertAudience::ADMIN,
        };
    }

    /** Bu değer eşiği aşıyor mu — panel rozeti buradan beslenir. */
    public function breaches(float $value): bool
    {
        return $value > $this->threshold();
    }

    /**
     * Değer eşiğe DAYANMIŞ mı — aşmamış ama bir adım ötede.
     *
     * "5 / eşik 5" aşım DEĞİLDİR (§11 "büyüktür" der) ve kırmızı
     * gösterilmesi yanlış olurdu; ama sessizce sıradan göstermek de
     * satıcıyı bir adım ötede olduğundan habersiz bırakır. Gerçek
     * çalıştırmada tam bu durum görüldü.
     *
     * SIFIR EŞİKLİ METRİKLERDE UYARI YOKTUR: eşiği sıfır olanlarda
     * (`outbox_consume_gap`, `sync_delivery_gap`) sıfırın altı yoktur ve
     * "yaklaşıyor" demek her sağlıklı ölçümü sarıya boyardı.
     */
    public function nearThreshold(float $value): bool
    {
        $threshold = $this->threshold();

        return $threshold > 0 && $value >= $threshold * 0.8;
    }

    /** Metriğin ölçüldüğü kapsam türü — eşik ancak bu bilinirse uygulanır. */
    public function scopeKind(): MetricScopeKind
    {
        return match ($this) {
            self::OVERSOLD_UNITS,
            self::OVERSOLD_VARIANTS,
            self::DEAD_OPERATIONS => MetricScopeKind::TENANT,

            self::API_LATENCY_P95,
            self::RATE_LIMIT_HITS,

            // §25: üçü de BAĞLANTI başınadır. Sistem geneli toplansaydı
            // tek bir ölmek üzere olan token yüz bağlantılık kurulumda
            // gürültüde kaybolur ve "hangi bağlantı" sorusu cevapsız
            // kalırdı — oysa eylem tam olarak o bağlantıya özgüdür.
            self::TOKEN_EXPIRING_SOON,
            self::TOKEN_REFRESH_FAILURES,
            self::CHANNEL_DAILY_QUOTA_USED => MetricScopeKind::CONNECTION,

            default => MetricScopeKind::SYSTEM,
        };
    }

    /** Değerin birimi — panel biçimlendirmesi buradan okur. */
    public function unit(): MetricUnit
    {
        return match ($this) {
            self::INVENTORY_SYNC_LATENCY_P95,
            self::API_LATENCY_P95 => MetricUnit::MILLISECONDS,

            self::OUTBOX_PUBLISH_LAG,
            self::INBOX_PROCESSING_LAG => MetricUnit::SECONDS,

            self::SYNC_ERROR_RATE,
            self::DRIFT_RATE,

            // Kota YÜZDEDİR, sayım değil: ham "7.842" satıcıya hiçbir
            // şey söylemez — tavanı bilmeden o sayı iyi mi kötü mü
            // belli olmaz.
            self::CHANNEL_DAILY_QUOTA_USED => MetricUnit::PERCENT,

            default => MetricUnit::COUNT,
        };
    }

    /** Panelde gösterilen ad — satıcının dilinde, iç kavram değil. */
    public function label(): string
    {
        return match ($this) {
            self::INVENTORY_SYNC_LATENCY_P95 => 'Stok senkron gecikmesi (p95)',
            self::SYNC_ERROR_RATE => 'Senkron hata oranı',
            self::OUTBOX_PUBLISH_LAG => 'Yayın birikmesi',
            self::OUTBOX_CONSUME_GAP => 'Tüketilmemiş olay',
            self::INBOX_PROCESSING_LAG => 'Gelen mesaj gecikmesi',
            self::INBOX_RECOVERY_BACKLOG => 'Kurtarma bekleyen mesaj',
            self::SYNC_DELIVERY_GAP => 'Worker almadı',
            self::DRIFT_RATE => 'Sürüklenme oranı',
            self::OVERSOLD_UNITS => 'Fazla satış (adet)',
            self::OVERSOLD_VARIANTS => 'Fazla satılan ürün',
            self::DEAD_OPERATIONS => 'Başarısız işlem',
            self::API_LATENCY_P95 => 'Kanal yanıt süresi (p95)',
            self::RATE_LIMIT_HITS => 'Hız sınırı isabeti',
            self::TOKEN_EXPIRING_SOON => 'Süresi dolan yetki',
            self::TOKEN_REFRESH_FAILURES => 'Yetki yenileme hatası',
            self::CHANNEL_DAILY_QUOTA_USED => 'Günlük kota kullanımı',
        };
    }
}
