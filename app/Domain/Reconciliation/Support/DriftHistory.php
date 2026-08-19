<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Support;

use App\Domain\Reconciliation\Enums\ItemStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * §10 · Bir listing'in ARDIŞIK sürüklenme geçmişi — 3 tur kuralının tabanı.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · VERIFY adımı, §1 · Karar 13.
 *
 * SAYAÇ GEÇMİŞTEN TÜRETİLİR, AYRI KOLONDA TUTULMAZ:
 *   `reconciliation_items` zaten GERÇEĞİ taşıyor ve
 *   `recon_items_listing_time_idx (listing_id, checked_at DESC)` tam bu
 *   sorgu için var. Ayrı bir `consecutive_drift_count` kolonu, kalem yazan
 *   HER yolun onu da güncellemesini zorunlu kılardı; biri unutulduğunda iki
 *   gerçek kaynağı sessizce ayrışır ve emniyet ya hiç çalışmaz ya da
 *   sağlıklı satırları kilitler. Böyle bir kolon §4 şemasında da tanımlı
 *   değildir.
 *
 * SAYILAN ŞEY ARDIŞIKLIKTIR, TOPLAM DEĞİL:
 *   Araya giren bir eşleşme "sorun çözüldü" demektir ve zinciri KIRAR.
 *   Toplam sayılsaydı aylar önce iki kez sürüklenmiş sağlıklı bir listing
 *   bugünkü ilk sürüklenmesinde doğrudan kilitlenirdi.
 *
 * `REMOTE_UNREACHABLE` ZİNCİRE GİRMEZ VE ONU KIRMAZ:
 *   Okunamayan kanal sürüklenme DEĞİLDİR (fark kanıtlanmamıştır) ama
 *   "düzeldi" de değildir. Sayılsaydı üç kez arka arkaya düşen bir kanal,
 *   hiç sürüklenmemiş bir listing'i otomatik onarımın dışına atardı;
 *   zinciri kırsaydı gerçek bir sonsuz döngü, araya giren tek bir ağ
 *   hatasıyla yeniden başlardı. Doğru davranış: o turu YOK SAYMAK.
 *
 * `DB::table()` GLOBAL SCOPE'A TABİ DEĞİLDİR — kiracı filtresi AÇIKÇA
 * yazılır. BUGÜN İKİNCİ SAVUNMA HATTIDIR ve bu bilinçlidir: sorgu zaten
 * `listing_id` ile daraltılıyor, `reconciliation_items.listing_id` →
 * `listings.id` FK'sı var ve bir listing TEK kiracıya aittir; yani
 * filtresiz sorgu da tek kiracının kalemlerini döndürür. Kaldırılması
 * davranışla sınanamaz (mutasyon hayatta kalır ve KALMALI) — sahte test
 * YAZILMADI. Filtre, ileride sayacın listing yerine varyant veya bağlantı
 * üzerinden sorulması hâlinde tek gerçek savunma olacağı için duruyor.
 */
final class DriftHistory
{
    /**
     * §10 · üçüncü ardışık sürüklenmede otomatik onarım DURUR.
     *
     * İki tur onarım açar; üçüncüde sürüklenme hâlâ duruyorsa kanal bizim
     * yazmamızı uygulamıyor demektir ve tekrarlamak yalnızca kota harcar.
     */
    public const STOP_AFTER = 2;

    /**
     * Zinciri KIRAN durumlar — "sorun çözüldü" anlamına gelenler.
     *
     * `REPAIRED` de buradadır: onarımın TUTTUĞU turdur ve tanım gereği
     * eşleşmedir.
     */
    private const RESOLVING = [
        ItemStatus::MATCHED->value,
        ItemStatus::REPAIRED->value,
    ];

    /** Zinciri UZATAN durumlar — sürüklenme kanıtlanmış olanlar. */
    private const DRIFTING = [
        ItemStatus::DRIFT_DETECTED->value,
        ItemStatus::REPAIR_QUEUED->value,
        ItemStatus::MANUAL_REVIEW->value,
    ];

    /**
     * Bu listing için kesintisiz süren sürüklenme turu sayısı.
     *
     * Geçmiş EN YENİDEN ESKİYE taranır ve ilk çözücü durumda durulur.
     * Sıralama `id` üzerindendir: `checked_at` SANİYE hassasiyetlidir ve
     * aynı saniyede yazılan iki kalemde sıra belirsiz kalır — sayaç o
     * durumda yanlış okur. `id` UUIDv7'dir, zaman sıralı ve saniye içinde
     * de ayırt edicidir.
     */
    public function consecutiveDriftCount(string $listingId): int
    {
        $statuses = DB::table('reconciliation_items')
            ->where('tenant_id', TenantContext::idOrFail())
            ->where('listing_id', $listingId)
            ->orderByDesc('id')
            // Sınır emniyet eşiğinin birkaç katı: daha eskisini okumak
            // sonucu DEĞİŞTİREMEZ (zincir çoktan kırılmıştır) ve uzun
            // geçmişi olan listing'lerde boşuna satır taşırdı.
            ->limit(self::STOP_AFTER * 5)
            ->pluck('status');

        $count = 0;

        foreach ($statuses as $status) {
            if (in_array($status, self::RESOLVING, true)) {
                break;
            }

            if (in_array($status, self::DRIFTING, true)) {
                $count++;

                continue;
            }

            // REMOTE_UNREACHABLE ve REMOTE_MISSING: ne sayılır ne zinciri
            // kırar. Fark kanıtlanmamıştır ama "düzeldi" de denemez.
        }

        return $count;
    }

    /**
     * Bu listing için otomatik onarım hâlâ açılabilir mi?
     *
     * Emniyet bir EŞİK DEĞİL BİR DURUMDUR: üçüncü turda durup dördüncüde
     * yeniden başlasaydı döngü yalnızca yavaşlar, KIRILMAZDI — her üç
     * turda bir onarım açan sonsuz bir döngü hâlâ sonsuz bir döngüdür.
     */
    public function autoRepairAllowed(string $listingId): bool
    {
        return $this->consecutiveDriftCount($listingId) < self::STOP_AFTER;
    }

    /**
     * Bu listing'in ÖNCEKİ turu bir onarım bekliyor muydu?
     *
     * `MATCHED` ile `REPAIRED` ayrımı buradan gelir: eşleşme bir onarımın
     * ARDINDAN geldiyse onarım TUTMUŞ demektir (§10). Ayrım denetim
     * içindir — ikisi tek duruma sıkıştırılsaydı onarımın işe yarayıp
     * yaramadığı hiçbir yerde kayıtlı olmazdı.
     */
    public function awaitingRepairVerification(string $listingId): bool
    {
        $previous = DB::table('reconciliation_items')
            ->where('tenant_id', TenantContext::idOrFail())
            ->where('listing_id', $listingId)
            ->orderByDesc('id')
            ->value('status');

        return $previous === ItemStatus::REPAIR_QUEUED->value;
    }
}
