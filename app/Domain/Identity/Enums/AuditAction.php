<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Denetim olayı türü — §11'in ALTI olayı.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · "Denetim kaydı".
 *
 * §11 kapsamı dar tutar ve gerekçesini de yazar: "Her satır değişikliğini
 * kaydetmek gereksiz; bu altı olay anlaşmazlık çıktığında sorulan sorular."
 * Enum bu yüzden AÇIK bir listedir ve genişletilirken aynı ölçüt uygulanır —
 * "bu olay bir anlaşmazlıkta sorulur mu?"
 *
 * DEĞER METİN OLARAK SAKLANIR ve kolon `string`'dir: enum'dan bir değer
 * kaldırılsa bile eski kayıtlar okunabilir kalmalıdır. Denetim izi kod
 * refactor'ıyla ÖLMEZ.
 *
 * ─────────────────────────────────────────────────────────────────────
 * BUGÜN YAZILMAYAN TEK OLAY — DÜRÜST SINIR
 * ─────────────────────────────────────────────────────────────────────
 * §11'in listesinde altı olay var; bu enum beşini tanımlar. Eksik olan:
 *
 *   - "kullanıcı davet ve rol değişimi" — davet akışı YAZILMADI;
 *     `tenant_users.role` yalnızca `CreateTenant` tarafından yazılıyor.
 *
 * O yol açıldığında buraya bir değer eklenir. Şimdi tanımlamak, hiçbir
 * yerden yazılmayan ölü bir enum değeri bırakırdı ve denetim ekranı
 * olmayan bir olayı varmış gibi gösterirdi.
 *
 * "Fiyat çakışması kararı" ARTIK YAZILIYOR (§9 · PRICE politikası) ve
 * aşağıda kendi değerini taşıyor.
 *
 * "Kanal bağlantısı SİLME" de aynı sebeple yok: silme yolu yazılmadı —
 * bağlantı silinmez, işaretlenir (§13 · faz 1.4).
 */
enum AuditAction: string
{
    /** Yeni kanal bağlantısı kuruldu. */
    case CHANNEL_CONNECTED = 'channel.connected';

    /**
     * Var olan bağlantının kimlik bilgisi yenilendi.
     *
     * BAĞLANTI KURMADAN AYRIDIR ve ayrı olması §11'in isteğidir
     * ("kimlik bilgisi güncelleme" listede kendi maddesi). Anahtar
     * yenileme bir güven olayıdır: "bu mağazaya kim, ne zaman yeni
     * anahtar verdi" sorusu bağlantının ne zaman kurulduğundan
     * bağımsız olarak sorulur.
     */
    case CHANNEL_CREDENTIAL_UPDATED = 'channel.credential_updated';

    /** Panelden elle stok düzeltmesi yapıldı. */
    case STOCK_ADJUSTED = 'stock.adjusted';

    /** Kiracı yaratıldı — ilk sahip ve varsayılan depo ile birlikte. */
    case TENANT_CREATED = 'tenant.created';

    /**
     * Fiyat çakışmasında satıcı karar verdi (§9 · PRICE, §11).
     *
     * İKİ YÖNÜ DE AYNI OLAY TAŞIR ve fark YÜKTE yaşar (`decision`):
     * "kanalınkini kabul et" ve "bizimkini gönder". Ayrı olaylar
     * yazılsaydı "bu listing'de kim ne zaman ne karar verdi" sorusu iki
     * ayrı taksonomiden okunurdu; oysa sorulan tek soru vardır ve
     * anlaşmazlıkta o sorulur.
     *
     * Yükte İKİ FİYAT DA taşınır: karar bağlamı olmadan denetim izi
     * "satıcı bir şey seçti" demekten öteye geçmez.
     */
    case PRICE_CONFLICT_RESOLVED = 'price.conflict_resolved';

    /**
     * Satıcı bir kanal için ayrı fiyat girdi ya da kaldırdı. Yükte eski ve
     * yeni değer para birimiyle taşınır: "Etsy'de neden $12.90" sorusunun
     * cevabı buradan okunur.
     */
    case CHANNEL_PRICE_SET = 'price.channel_set';

    /** Bağlantının fiyat kuralı (kanal farkı, yuvarlama, zarar koruması) değişti. */
    case CHANNEL_PRICE_RULE_SET = 'price.rule_set';

    /**
     * Platform yöneticisi kiracıya planı elle atadı (müşteriye özel plan ya
     * da ödeme dışı bir anlaşma). Yükte eski ve yeni plan + bitiş tarihi:
     * "bu müşteri neden bu limitlerde" sorusunun cevabı buradan okunur.
     */
    case PLAN_ASSIGNED_BY_ADMIN = 'billing.plan_assigned';

    /** Panelde gösterilecek Türkçe ad. */
    public function label(): string
    {
        // Çalışma anında çevrilir; anahtar Türkçe metnin kendisidir.
        return __(match ($this) {
            self::CHANNEL_CONNECTED => 'Kanal bağlandı',
            self::CHANNEL_CREDENTIAL_UPDATED => 'Kanal anahtarı yenilendi',
            self::STOCK_ADJUSTED => 'Stok elle düzeltildi',
            self::TENANT_CREATED => 'Hesap oluşturuldu',
            self::PRICE_CONFLICT_RESOLVED => 'Fiyat çakışması çözüldü',
            self::CHANNEL_PRICE_SET => 'Kanal fiyatı değişti',
            self::CHANNEL_PRICE_RULE_SET => 'Kanal fiyat kuralı değişti',
            self::PLAN_ASSIGNED_BY_ADMIN => 'Plan yönetici tarafından atandı',
        });
    }
}
