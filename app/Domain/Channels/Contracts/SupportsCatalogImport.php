<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Sync\Support\RemoteProductPage;

/**
 * Kanaldaki kataloğu toplu okuma yeteneği — "kanaldan ürün çekme".
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · madde 5.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN SEKİZİNCİ ARAYÜZ — §7'DEN BİLİNÇLİ SAPMA
 * ─────────────────────────────────────────────────────────────────────
 * §13 bu maddeyi Faz 3'te İSTİYOR ama §7'nin yedi yetenek arayüzünün
 * hiçbirinde karşılığı YOK. `SupportsCatalog`'un iki okuma metodu da
 * YEREL BİR KAYITTAN yola çıkar:
 *
 *   findExistingListing(Variant $v)  → "benim varyantım kanalda var mı?"
 *   fetchListing(Listing $l)         → "benim satırımın kanaldaki hâli?"
 *
 * İçe aktarma bunun TERSİNİ sorar: "kanalda ne var ki bende YOK?" Girdi
 * olarak yerel kayıt yoktur — `fetchListing` bu iş için kullanılamaz,
 * çünkü elde `Listing` satırı olmasını şart koşar ve içe aktarmanın amacı
 * tam da o satırın henüz bulunmamasıdır.
 *
 * `SupportsCatalog`'A EKLENMEDİ, çünkü Trendyol `SupportsCatalog` uygular
 * ama toplu ürün listelemesi orada AYRI bir uç noktadır ve bu tur kapsam
 * dışıdır. Eklenseydi Trendyol o metodu ya istisna fırlatarak ya da boş
 * dönerek uygulamak zorunda kalırdı; ikincisi §7'nin açık yasağıdır
 * ("yazılmamış yetenek SESSİZCE BAŞARILI DÖNMEZ"), birincisi ise panelin
 * yeteneği ayırt etmesini imkânsız kılardı — kullanıcıya "içe aktar"
 * düğmesi gösterilir, düğme her seferinde hata verirdi.
 *
 * Ayrı arayüz olunca yetenek `instanceof` ile okunur (§7 · değişmez
 * kural) ve panel yalnızca gerçekten destekleyen bağlantıyı listeler.
 *
 * §7'nin "SupportsProductMatching YOK — tek metot için ayrı arayüz açma"
 * notuyla ÇELİŞMEZ: orada reddedilen metot (`findExistingListing`)
 * `SupportsCatalog`'a doğal olarak aitti ve onu uygulayan her kanalda
 * anlamlıydı. Buradaki metot ise kanalların bir kısmında HİÇ YOKTUR —
 * yani gerçekten ayrı bir yetenektir.
 */
interface SupportsCatalogImport
{
    /**
     * Kanaldaki ürünleri sayfa sayfa okur. YEREL KAYIT GEREKTİRMEZ.
     *
     * İmleç OPAKTIR: `null` ile başlanır, dönen `nextCursor` bir sonraki
     * çağrıya olduğu gibi verilir. Çekirdek biçimini yorumlamaz.
     */
    public function fetchProductPage(?string $cursor = null): RemoteProductPage;

    /**
     * Tek turda çekilecek EN FAZLA sayfa sayısı.
     *
     * ÜST SINIR ZORUNLUDUR: `hasMore` bozuk bir kanalda sonsuza kadar
     * `true` dönebilir ve tur kotayı yakıp worker'ı süresiz meşgul
     * ederdi. `maxInventoryBatchSize()` ile aynı gerekçe — sınırı kanalı
     * tanıyan adapter söyler.
     */
    public function maxImportPages(): int;
}
