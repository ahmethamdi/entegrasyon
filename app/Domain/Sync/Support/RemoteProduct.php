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
        /**
         * Kanaldaki görsel adresleri, SIRALI (ilki ana görsel). Varyantın
         * kendi görseli varsa başta gelir. Kanal adresi herkese açıktır ve
         * öteki kanallar onu doğrudan indirebilir (A15).
         *
         * @var list<string>
         */
        public array $images = [],
        public array $raw = [],
        /**
         * Ürünün kanaldaki ADRESİ — içe aktarma bununla `Listing` bağı kurar.
         *
         * Anahtarlar `PushListing::adoptRemoteIdentity`'nin yazdıklarıyla
         * aynıdır: `external_id` (zorunlu), `external_parent_id`,
         * `external_url`, `channel_metadata`. Bağ kurulmasaydı ürün kanaldan
         * GELDİĞİ hâlde kanala bağlı görünmez; satıcı onu kanala "eklediğinde"
         * SKU araması tutmazsa kanalda KOPYA ürün yaratılırdı.
         *
         * Boş dizi = adapter adres vermiyor; bağ kurulmaz, SKU üretilmez.
         *
         * @var array<string, mixed>
         */
        public array $listingIdentity = [],
        /**
         * Fiyatın para birimi (ISO 4217). Null = kanal bildirmiyor; içe
         * aktarma varsayılanı (TRY) kullanır. Shopify mağazası USD ise
         * fiyat USD'dir — TRY yazılsaydı 729,95 $ ürün 729,95 ₺ görünür
         * ve TL kanallarına çevrilmeden giderdi.
         */
        public ?string $currency = null,
        /**
         * Varyantın seçenek değerleri, SIRALI: `['Renk' => 'Kırmızı', 'Beden' => 'M']`.
         *
         * ADR-003: aynı `external_parent_id` altındaki varyantlar TEK ürün
         * altında toplanır ve birbirinden bu değerlerle ayrılır. Trendyol'a
         * gönderimde öznitelik eşlemesi (`variant_options`) bunlardan
         * beslenir; yazılmasaydı kardeş varyantlar kanalda ayırt edilemezdi.
         *
         * Boş dizi = kanal seçenek bildirmiyor ya da ürün tek varyantlı
         * (Shopify "Title / Default Title" burada YER ALMAZ).
         *
         * @var array<string, string>
         */
        public array $options = [],
    ) {}

    /**
     * Kanaldaki ürün grubunun kimliği (Shopify ürün gid'i, Trendyol
     * `contentId`, Etsy `listing_id`…). Yoksa null — ürün gruplanmaz.
     */
    public function groupId(): ?string
    {
        $parent = $this->listingIdentity['external_parent_id'] ?? null;

        return is_string($parent) && trim($parent) !== '' ? $parent : null;
    }

    /**
     * Seçenek değerleri tek satır: "Kırmızı / M". Seçenek yoksa null.
     */
    public function optionLabel(): ?string
    {
        return $this->options === [] ? null : implode(' / ', array_values($this->options));
    }

    /**
     * SKU'su olan ürün doğrudan içe aktarılır.
     */
    public function hasSku(): bool
    {
        return $this->sku !== null && trim($this->sku) !== '';
    }

    /**
     * SKU'su yoksa YALNIZCA kanal adresi biliniyorsa içe aktarılır.
     *
     * O durumda SKU üretilir (`autoSku`) ve eşleşme SKU ile değil
     * `Listing` bağıyla yürür: siparişler kanal varyant kimliğiyle,
     * stok o bağın adresiyle gider. Adres yoksa üretilen SKU hiçbir
     * kanal kaydına bağlanamaz — sipariş eşleşmez, gönderim kopya
     * ürün yaratırdı; o ürün atlanır ve sebebiyle raporlanır.
     */
    public function isImportable(): bool
    {
        return $this->hasSku() || $this->autoSku('X') !== null;
    }

    /**
     * Kanal kimliğinin sayısal kuyruğundan SKU: `SHO-48213…`.
     *
     * Kimlik kanalda değişmez ve bağlantı içinde tekildir; yeniden içe
     * aktarma aynı SKU'yu üretir. Satıcı panelde değiştirebilir — eşleşme
     * SKU'ya değil bağa dayanır.
     */
    public function autoSku(string $prefix): ?string
    {
        $externalId = (string) ($this->listingIdentity['external_id'] ?? '');

        if (preg_match('/(\d+)$/', $externalId, $match) !== 1) {
            return null;
        }

        return strtoupper($prefix).'-'.$match[1];
    }
}
