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
 * BUGÜN YAZILMAYAN İKİ OLAY — DÜRÜST SINIR
 * ─────────────────────────────────────────────────────────────────────
 * §11'in listesinde altı olay var; bu enum dördünü tanımlar. Eksik ikisi:
 *
 *   - "fiyat çakışması kararı" — çakışma çözümü akışı henüz YAZILMADI
 *     (§9'un üçüncü durumu; fiyat senkronu tek yönlü çalışıyor).
 *   - "kullanıcı davet ve rol değişimi" — davet akışı YAZILMADI;
 *     `tenant_users.role` yalnızca `CreateTenant` tarafından yazılıyor.
 *
 * O yollar açıldığında buraya birer değer eklenir. Şimdi tanımlamak,
 * hiçbir yerden yazılmayan ölü bir enum değeri bırakırdı ve denetim
 * ekranı olmayan bir olayı varmış gibi gösterirdi.
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

    /** Panelde gösterilecek Türkçe ad. */
    public function label(): string
    {
        return match ($this) {
            self::CHANNEL_CONNECTED => 'Kanal bağlandı',
            self::CHANNEL_CREDENTIAL_UPDATED => 'Kanal anahtarı yenilendi',
            self::STOCK_ADJUSTED => 'Stok elle düzeltildi',
            self::TENANT_CREATED => 'Hesap oluşturuldu',
        };
    }
}
