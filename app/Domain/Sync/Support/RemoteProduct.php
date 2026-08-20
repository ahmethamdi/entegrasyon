<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Kanalda bulunan, HENÜZ BİZDE OLMAYABİLECEK bir ürün.
 *
 * Mimari Karar Dokümanı v2.2 · §7 (SupportsCatalogImport), §13 · Faz 3 ·
 * madde 5 ("kanaldan ürün çekme").
 *
 * NEDEN `RemoteListing` DEĞİL:
 *   `RemoteListing` bir `Listing` satırının kanaldaki YANSIMASIDIR ve
 *   çıpası `externalId`'dir; mutabakat onu "benim gönderdiğim şey orada
 *   duruyor mu" diye sorar. Burada henüz `Listing` satırı YOKTUR — içe
 *   aktarmanın amacı tam da onu yaratmaktır. Bu nesnenin çıpası bu yüzden
 *   `sku`'dur: kanonik katalog SKU ile anahtarlanır (`UNIQUE(tenant_id,
 *   sku)`) ve içe aktarma "bu ürün bende var mı" sorusunu ancak SKU ile
 *   cevaplayabilir.
 *
 * SKU BOŞ OLABİLİR ve bu gerçek bir vakadır: WooCommerce'te SKU zorunlu
 * DEĞİLDİR ve satıcının kataloğunda SKU'suz ürünler bulunur. Bu nesne onu
 * REDDETMEZ — ayıklama içe aktarma action'ının işidir ve reddedilen satır
 * kullanıcıya SEBEBİYLE raporlanır. Burada reddetseydik ürün sessizce
 * kaybolur ve satıcı "50 ürünüm vardı, 47'si geldi" derdi.
 *
 * @property-read array<string, mixed> $raw
 */
final readonly class RemoteProduct
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $externalId,
        public ?string $sku = null,
        public ?string $title = null,
        public ?string $price = null,
        public ?int $quantity = null,
        public ?string $description = null,
        public ?string $brand = null,
        public ?string $barcode = null,
        public ?string $status = null,
        public array $raw = [],
    ) {}

    /**
     * SKU'su olmayan ürün içe aktarılamaz.
     *
     * Kanonik katalogda kimlik SKU'dur; uydurmak (örneğin kanal kimliğini
     * SKU yapmak) satıcının kendi SKU'suyla aynı ürünü sonradan yüklemesi
     * hâlinde KOPYA ürün üretirdi ve iki satır ayrı ayrı senkronlanırdı.
     */
    public function isImportable(): bool
    {
        return $this->sku !== null && trim($this->sku) !== '';
    }
}
