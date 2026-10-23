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
 *
 * TEK İSTİSNA — AYLIK FATURA (`invoices_monthly`, S6): sayım `invoices`
 * tablosunun kendisinden yapılır, çünkü fatura satırı RESMÎ BELGENİN
 * kaydıdır ve hiçbir akışta SİLİNMEZ (GİB'de iptal ayrı süreçtir; satır
 * kalır). Kaybolmayan bir kayıttan dönemsel sayım geriye dönük de doğru
 * üretilir — `usage_records`'un çözdüğü sorun burada yoktur. Sipariş veya
 * senkron kotası eklenirse bu gerekçe GEÇERLİ DEĞİLDİR.
 */
enum QuotaMetric: string
{
    /** Kiracının sahip olduğu ürün sayısı. */
    case PRODUCTS = 'products';

    /** Kiracının bağladığı kanal bağlantısı sayısı. */
    case CHANNELS = 'channels';

    /**
     * Bu takvim ayında (İstanbul saati) açılan fatura sayısı — DÖNEMSEL.
     * Başarısız (hiç kesilmemiş) fatura sayılmaz; yeniden deneme aynı
     * satırı kullanır, yeni hak harcamaz.
     */
    case INVOICES = 'invoices_monthly';

    /** S7 · ekip üyesi + bekleyen davet (sahip dahil). */
    case USERS = 'users';

    /**
     * S8 · bu takvim ayında yapılan AI öneri çağrısı — DÖNEMSEL. Yapılamayan
     * çağrı (ağ/sağlayıcı hatası) sayılmaz; kurallardan geçmeyen yanıt
     * sayılır (jeton harcandı).
     */
    case AI_SUGGESTIONS = 'ai_suggestions_monthly';

    /** Kullanıcıya gösterilen ad — hata mesajında geçer. */
    public function label(): string
    {
        // Çalışma anında çevrilir; anahtar Türkçe metnin kendisidir.
        return __(match ($this) {
            self::PRODUCTS => 'ürün',
            self::CHANNELS => 'kanal bağlantısı',
            self::INVOICES => 'aylık fatura',
            self::USERS => 'kullanıcı',
            self::AI_SUGGESTIONS => 'aylık AI önerisi',
        });
    }

    /**
     * Kota aşıldığında kullanıcıya ne yapacağını söyleyen tavsiye.
     *
     * Sayı tek başına yol göstermez — ölü mektup ekranının ve uyarı
     * e-postalarının "değer + tavsiye" kuralının aynısı.
     */
    public function advice(): string
    {
        return __(match ($this) {
            self::PRODUCTS => 'Daha fazla ürün eklemek için planını yükselt.',
            self::CHANNELS => 'Daha fazla kanal bağlamak için planını yükselt.',
            self::INVOICES => 'Bu ay daha fazla fatura kesmek için planını yükselt.',
            self::USERS => 'Daha fazla kişi davet etmek için planını yükselt.',
            self::AI_SUGGESTIONS => 'Bu ay daha fazla AI önerisi almak için planını yükselt.',
        });
    }

    /**
     * Kota dolduğunda satıcıya gösterilen tam cümle — panel dilinde.
     *
     * `QuotaExceededException` mesajını kurulurken üretir ve parçaları
     * birleştirir; çevrilmiş etiket Türkçe kalıba girince cümle iki dilli
     * olurdu. Ekrana giden metin bu yüzden TEK anahtarla kurulur.
     */
    public function exceededMessage(int $current, int $limit): string
    {
        return __(match ($this) {
            self::PRODUCTS => 'Ürün kotan doldu (:current/:limit). Daha fazla ürün eklemek için planını yükselt.',
            self::CHANNELS => 'Kanal bağlantısı kotan doldu (:current/:limit). Daha fazla kanal bağlamak için planını yükselt.',
            self::INVOICES => 'Bu ayki fatura kotan doldu (:current/:limit). Daha fazla fatura kesmek için planını yükselt.',
            self::USERS => 'Kullanıcı kotan doldu (:current/:limit). Daha fazla kişi davet etmek için planını yükselt.',
            self::AI_SUGGESTIONS => 'Bu ayki AI öneri hakkın doldu (:current/:limit). Daha fazla öneri için planını yükselt.',
        }, ['current' => $current, 'limit' => $limit]);
    }
}
