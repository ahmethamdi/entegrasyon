<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Kota metrikleri — planın neyi sınırladığı.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · plans.limits (JSONB), §13 · Faz 4.
 *
 * KULLANICI KARARI (20 Ağustos 2026): iki kota — ürün sayısı ve kanal
 * bağlantısı sayısı. İkisi de sektör standardıdır, müşterinin anlaması
 * kolaydır ve ANLIK sayımdır.
 *
 * DEĞİŞMEZ KURAL — DEĞER `plans.limits` ANAHTARIDIR ve SÖZLEŞMEDİR.
 * Kalıcı veriye (JSONB) yazılan biçim budur; değiştirilirse eski plan
 * satırları eski anahtarı taşımaya devam eder, yeni kod onları BULAMAZ
 * ve limit "tanımsız" sayılarak SINIRSIZ olur — yani kota sessizce
 * kalkar. Bu yüzden `PlanLimitContractTest` anahtarları BEKLENEN METİNLE
 * sınar (aynı tuzak `MetricScope` ve `AlertKey` üzerinde iki kez yaşandı).
 *
 * DÖNEMSEL KOTA (sipariş/senkron sayısı) BU LİSTEDE YOKTUR ve eklenirse
 * `usage_records` de yazılmalıdır: anlık `COUNT` dönemsel kullanımı
 * cevaplayamaz ve §4 o tabloyu tam bunun için tanımlar ("fiyatlandırma
 * verisi geriye dönük üretilemez").
 */
enum QuotaMetric: string
{
    /** Kiracının sahip olduğu ürün sayısı. */
    case PRODUCTS = 'products';

    /** Kiracının bağladığı kanal bağlantısı sayısı. */
    case CHANNELS = 'channels';

    /** Kullanıcıya gösterilen ad — hata mesajında geçer. */
    public function label(): string
    {
        return match ($this) {
            self::PRODUCTS => 'ürün',
            self::CHANNELS => 'kanal bağlantısı',
        };
    }

    /**
     * Kota aşıldığında kullanıcıya ne yapacağını söyleyen tavsiye.
     *
     * Sayı tek başına yol göstermez — ölü mektup ekranının ve uyarı
     * e-postalarının "değer + tavsiye" kuralının aynısı.
     */
    public function advice(): string
    {
        return match ($this) {
            self::PRODUCTS => 'Daha fazla ürün eklemek için planını yükselt.',
            self::CHANNELS => 'Daha fazla kanal bağlamak için planını yükselt.',
        };
    }
}
