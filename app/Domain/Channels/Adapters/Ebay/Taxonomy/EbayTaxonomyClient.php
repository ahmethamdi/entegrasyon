<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ebay\Taxonomy;

use App\Domain\Channels\Adapters\Ebay\EbayEndpoints;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Sync\Support\CategoryTreeSnapshot;
use DateTimeImmutable;
use RuntimeException;

/**
 * eBay Taxonomy API istemcisi — ağacı çeker ve düzleştirir.
 *
 * V3.0 · §13.5 · v2.2 §14 · §19 (`Taxonomy/ TaxonomyClient`).
 *
 * ─────────────────────────────────────────────────────────────────────
 * TRENDYOL/ETSY KALIBI GEÇERLİ — ama ÜÇ NOKTADA AYRIŞIR
 * ─────────────────────────────────────────────────────────────────────
 * Ağaç kanalın GERÇEĞİDİR (`channel_categories`, KİRACISIZ); eşleştirme
 * satıcının KARARIDIR (`category_mappings`, kiracıya ait). Ağaç
 * düzleştirilir ve `parent_external_id` taşıyan düz liste saklanır.
 *
 * Farklar:
 *   1. **AĞAÇ KİMLİĞİ ÖNCE SORULUR** — eBay'de ağaca doğrudan
 *      erişilmez; `marketplace_id` → `categoryTreeId` çevrimi AYRI bir
 *      uç noktadır ve İKİ çağrı gerekir.
 *   2. **SÜRÜM KANALDAN GELİR** — eBay `categoryTreeVersion` YAYIMLAR;
 *      Etsy/Trendyol'da parmak izi ağacın içeriğinden ÜRETİLİYORDU.
 *   3. **YAPRAK BAYRAĞI GÖVDEDE VAR** (`leafCategoryTreeNode`) — ama
 *      yine de TÜRETİLİR, aşağıdaki nota bak.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ AĞAÇ MARKETPLACE BAŞINADIR — §13.5'İN ANA KURALI
 * ─────────────────────────────────────────────────────────────────────
 * `EBAY_US` ve `EBAY_DE` FARKLI ağaçlar taşır ve kategori kimlikleri
 * ÖRTÜŞÜR. Sürüm marketplace kimliğini İÇERMEZSE ABD ağacıyla
 * eşleştirilen bir kategori Almanya'ya gönderilir, eBay `VALIDATION`
 * döner ve o hata KALICIDIR — listing "düzeltilemez" damgasıyla ölür.
 *
 * Tekillik `(channel_type_code, taxonomy_version, external_id)`
 * olduğu için sürüm ayracı İKİ pazarın ağacını da AYNI tabloda güvenle
 * barındırır; marketplace sürüme girmeseydi iki ağaç birbirini EZERDİ.
 */
final class EbayTaxonomyClient
{
    /**
     * Çözülmüş ağaç kimliği — ÖRNEK BAŞINA önbelleklenir.
     *
     * ⚠️ ÖNBELLEK OLMADAN HER YAPRAK İKİ İSTEK EDER. `SyncTaxonomy`
     * `fetchCategoryAttributes()`'ı YAPRAK BAŞINA çağırır ve eBay
     * ağacı ON BİNLERCE yaprak taşır; kimlik her seferinde yeniden
     * sorulsaydı tur kotayı (~5.000/gün/uç nokta, §21) İKİ KATINA
     * çıkarır ve gün ortasında 429'a çarpardı.
     *
     * ⚠️ ÖNBELLEK ÖRNEK BAŞINADIR, STATİK DEĞİL. Statik olsaydı
     * `AdapterRegistry`'nin "her çağrıda yeni örnek" kuralı delinir ve
     * kiracı A'nın marketplace ağacı kiracı B'nin turunda kullanılırdı
     * — adapter'ın paylaşılamaz olmasıyla aynı gerekçe.
     */
    private ?string $treeId = null;

    public function __construct(
        private readonly ChannelHttpClient $client,
        private readonly string $marketplaceId,
        private readonly bool $sandbox = false,
    ) {}

    /**
     * Kategori ağacını çeker, düzleştirir ve sürümler.
     *
     * ⚠️ İKİ ÇAĞRI GEREKİR ve sırası ZORUNLUDUR: önce marketplace'in
     * varsayılan ağaç kimliği, sonra o ağacın kendisi. Kimlik sabit
     * yazılsaydı (`0` = EBAY_US) tüm satıcılar ABD ağacını görürdü.
     */
    public function fetchTree(): CategoryTreeSnapshot
    {
        $treeId = $this->defaultTreeId();

        $response = $this->client->get(
            EbayEndpoints::url(
                EbayEndpoints::CATEGORY_TREE,
                ['treeId' => $treeId],
                sandbox: $this->sandbox,
            ),
        );

        // ⚠️ BAŞARISIZ YANIT SESSİZCE BOŞ AĞACA DÖNÜŞMEZ.
        //
        // `json()` bir 500 gövdesinde de dizi döndürür ve beklenen anahtar
        // bulunmadığı için ağaç BOŞ çıkardı. O boş ağaç GEÇERLİ bir
        // sürümle veritabanına yazılır, panel "bu kanalda hiç kategori
        // yok" der ve ürün aktarımı ön koşul kapısında SONSUZA KADAR
        // takılırdı — üstelik hata hiçbir yere düşmeden.
        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        /** @var array<string, mixed> $root */
        $root = is_array($body['rootCategoryNode'] ?? null) ? $body['rootCategoryNode'] : [];

        /** @var list<array<string, mixed>> $children */
        $children = is_array($root['childCategoryTreeNodes'] ?? null)
            ? $root['childCategoryTreeNodes']
            : [];

        $flat = [];
        $this->flatten($children, parentId: null, path: [], into: $flat);

        return new CategoryTreeSnapshot(
            categories: $flat,
            version: $this->versionFor($body, $flat),
            fetchedAt: new DateTimeImmutable,
        );
    }

    /**
     * Yaprak kategorinin "aspect"leri — Trendyol'un zorunlu
     * özniteliklerinin karşılığı (§13.5).
     *
     * ⚠️ YALNIZCA YAPRAK İÇİN ÇAĞRILIR (`SyncTaxonomy` bunu garanti
     * eder). Ara kategoriye ürün açılamaz; öznitelik istemek boşuna
     * istek ve boşuna KOTADIR — eBay'de kota uç nokta başına ~5.000/gün
     * (§21) ve taksonomi ağacı ON BİNLERCE yaprak taşır.
     *
     * ⚠️ KATEGORİ KİMLİĞİ SORGU PARAMETRESİDİR, YOLDA DEĞİL. Etsy'de
     * yolda taşınıyordu (`/properties/{taxonomy_id}`); buraya kopyalansaydı
     * istek `category_id` olmadan gider ve eBay TÜM kategorilerin
     * aspect'lerini reddederdi.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAttributes(string $categoryId): array
    {
        $response = $this->client->get(
            EbayEndpoints::url(
                EbayEndpoints::CATEGORY_ASPECTS,
                ['treeId' => $this->defaultTreeId()],
                sandbox: $this->sandbox,
            ),
            query: ['category_id' => $categoryId],
        );

        // Ağaçtaki ile aynı gerekçe: başarısız yanıt "bu kategoride
        // zorunlu aspect yok" anlamına GELMEZ. Sessizce boş dönseydi ön
        // koşul kapısı ürünü geçirir ve kanal onu reddederdi — o hata
        // `VALIDATION`, yani KALICIDIR.
        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        /** @var list<array<string, mixed>> $raw */
        $raw = is_array($body['aspects'] ?? null) ? $body['aspects'] : [];

        $definitions = [];

        foreach ($raw as $aspect) {
            if (! is_array($aspect)) {
                continue;
            }

            $definition = $this->toDefinition($aspect);

            // Adsız aspect YAZILMAZ: `external_attribute_id` kimliktir ve
            // boş dize iki farklı aspect'i BİRBİRİNE eşlerdi
            // (`updateOrCreate` anahtarı odur).
            if ($definition['external_attribute_id'] !== '') {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * Marketplace'in varsayılan kategori ağacı kimliği (§13.5).
     *
     * ⚠️ SABİT YAZILAMAZ. eBay'de `EBAY_US` ağacı `0`, `EBAY_DE` `77`,
     * `EBAY_GB` `3`'tür ve liste zamanla DEĞİŞİR; kodda sabitlenseydi
     * yeni bir pazar sessizce YANLIŞ ağaca bağlanır ve o ağaçtan seçilen
     * kategori `VALIDATION` alırdı.
     *
     * ⚠️ KİMLİK GELMEZSE İSTİSNA FIRLATILIR. Boş dizeyle devam
     * edilseydi `EbayEndpoints::url()` doldurulmamış yer tutucu
     * bulmazdı (yer tutucu DOLDURULMUŞ ama BOŞ değerle) ve istek
     * `/category_tree/` adresine gider, 404 alınır ve sebep hiçbir yerde
     * görünmezdi.
     */
    private function defaultTreeId(): string
    {
        if ($this->treeId !== null) {
            return $this->treeId;
        }

        $response = $this->client->get(
            EbayEndpoints::url(
                EbayEndpoints::DEFAULT_CATEGORY_TREE_ID,
                sandbox: $this->sandbox,
            ),
            query: ['marketplace_id' => $this->marketplaceId],
        );

        $response->throw();

        $treeId = $response->json('categoryTreeId');

        if (! is_string($treeId) && ! is_int($treeId)) {
            throw new RuntimeException(
                'eBay varsayılan kategori ağacı kimliği okunamadı — '
                ."marketplace `{$this->marketplaceId}` için ağaç çekilemez."
            );
        }

        $treeId = (string) $treeId;

        if ($treeId === '') {
            throw new RuntimeException('eBay kategori ağacı kimliği boş döndü.');
        }

        // ⚠️ ÖNBELLEK YALNIZCA BAŞARIDA YAZILIR. Hata yolunda yazılsaydı
        // boş bir kimlik donar ve turun geri kalanı sessizce yanlış
        // adrese giderdi.
        return $this->treeId = $treeId;
    }

    /**
     * eBay aspect → çekirdeğin beklediği tanım.
     *
     * @param  array<string, mixed>  $aspect
     * @return array<string, mixed>
     */
    private function toDefinition(array $aspect): array
    {
        $constraint = is_array($aspect['aspectConstraint'] ?? null)
            ? $aspect['aspectConstraint']
            : [];

        /** @var list<array<string, mixed>> $values */
        $values = is_array($aspect['aspectValues'] ?? null) ? $aspect['aspectValues'] : [];

        $allowed = [];

        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }

            $label = (string) ($value['localizedValue'] ?? '');

            if ($label === '') {
                continue;
            }

            // ⚠️ eBay ASPECT DEĞERİNİN AYRI BİR KİMLİĞİ YOKTUR — değer
            // METNİN KENDİSİDİR ve gövdeye de öyle yazılır. Etsy'de
            // `value_id` vardı ve oradan kopyalanan bir `id` alanı BOŞ
            // kalırdı; boş kimlik izinli değer kapısını devre dışı
            // bırakır ve iki farklı değeri birbirine eşlerdi.
            $allowed[] = ['id' => $label, 'label' => $label];
        }

        // ⚠️ ZORUNLULUK eBay'DE GERÇEKTİR — Etsy'nin TERSİ.
        //
        // Etsy'de `is_required` DAİMA `false` yazılıyordu çünkü o kanalda
        // zorunlu öznitelik kavramı YOKTU ve uydurma bir zorunluluk ön
        // koşul kapısını sonsuza kadar kapatırdı. eBay'de kavram VAR ve
        // eksik zorunlu aspect offer yaratmada `VALIDATION` üretir — o
        // hata KALICIDIR. `false` yazılsaydı kapı ürünü geçirir ve HER
        // ürün kanalda ölürdü.
        $required = ($constraint['aspectRequired'] ?? false) === true;

        // ⚠️ SERBEST METİN KABUL EDİYORSA DEĞER LİSTESİ BAĞLAYICI
        // DEĞİLDİR. eBay `SELECTION_ONLY` derse liste kapalıdır;
        // `FREE_TEXT` derse satıcı kendi değerini yazabilir ve `enum`
        // denseydi izinli liste kapısı MEŞRU bir değeri REDDEDERDİ.
        //
        // BOŞ İZİNLİ LİSTE "HİÇBİRİ" DEĞİL "SERBEST METİN" DEMEKTİR
        // (eşleştirme kuralları).
        $selectionOnly = ($constraint['aspectMode'] ?? null) === 'SELECTION_ONLY';

        return [
            // Kimlik ADIN KENDİSİDİR — eBay ayrı bir aspect id vermez ve
            // gövdede de ad kullanılır (`product.aspects` anahtarı).
            'external_attribute_id' => (string) ($aspect['localizedAspectName'] ?? ''),
            'name' => (string) ($aspect['localizedAspectName'] ?? ''),
            'is_required' => $required,

            // Varyant belirleyici — beden/renk gibi. eBay bunu AÇIKÇA
            // söyler (`aspectEnabledForVariations`) ve türetilmez.
            'is_variant_defining' => ($constraint['aspectEnabledForVariations'] ?? false) === true,

            'data_type' => $allowed !== [] && $selectionOnly ? 'enum' : 'string',
            'allowed_values' => $allowed,
        ];
    }

    /**
     * İç içe ağacı düz listeye indirir.
     *
     * AĞAÇ DÜZLEŞTİRİLİR: eBay `childCategoryTreeNodes` ile iç içe
     * döner; biz `parent_external_id` taşıyan düz bir liste saklarız.
     * İç içe yapı saklansaydı "şu kategorinin tüm çocukları" sorgusu
     * özyinelemeli CTE gerektirirdi ve eşleştirme ekranı her tuşta ağacı
     * yeniden yürürdü.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<string>  $path
     * @param  list<array<string, mixed>>  $into
     */
    private function flatten(array $nodes, ?string $parentId, array $path, array &$into): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $category = is_array($node['category'] ?? null) ? $node['category'] : [];

            $id = (string) ($category['categoryId'] ?? '');

            if ($id === '') {
                continue;
            }

            $name = (string) ($category['categoryName'] ?? '');
            $children = is_array($node['childCategoryTreeNodes'] ?? null)
                ? $node['childCategoryTreeNodes']
                : [];
            $currentPath = [...$path, $name];

            $into[] = [
                'external_id' => $id,
                'parent_external_id' => $parentId,
                'name' => $name,
                // Okunabilir yol: eşleştirme ekranında kullanıcı
                // kategoriyi ancak bağlamıyla tanır ("Ring" tek başına
                // yetmez).
                'path' => implode(' > ', $currentPath),

                // ⚠️ YAPRAK BİLGİSİ ÇOCUK LİSTESİNDEN TÜRETİLİR —
                // `leafCategoryTreeNode` bayrağı gövdede VAR ama TEK
                // BAŞINA okunmaz.
                //
                // Gerekçe Etsy'nin `level` notuyla aynı aileden ama
                // daha ince: bayrak ile çocuk listesi ÇELİŞEBİLİR
                // (eBay ara düğümü yaprak işaretleyip yine de çocuk
                // döndürebilir). Çocuğu OLAN bir düğüm yaprak sayılsaydı
                // ürün ARA kategoriye açılmaya çalışılır, kanal
                // `VALIDATION` döner ve o hata KALICIDIR.
                //
                // İki kaynak da "yaprak" diyorsa yapraktır; çelişirse
                // GÜVENLİ taraf "yaprak DEĞİL"dir — ara kategori
                // eşleştirilemez, ama yanlış yaprak ürünü ÖLDÜRÜR.
                'is_leaf' => $children === []
                    && ($node['leafCategoryTreeNode'] ?? true) === true,
            ];

            if ($children !== []) {
                $this->flatten($children, $id, $currentPath, $into);
            }
        }
    }

    /**
     * Sürüm — KANALIN VERDİĞİ sürüm + MARKETPLACE kimliği.
     *
     * ⚠️ ETSY/TRENDYOL'DAN AYRILIR: orada kanal sürüm yayımlamıyordu ve
     * parmak izi ağacın İÇERİĞİNDEN üretiliyordu. eBay
     * `categoryTreeVersion` YAYIMLAR ve onu kullanmak DOĞRUDUR — kanalın
     * kendi gerçeği, bizim türettiğimiz bir yaklaşıktan iyidir.
     *
     * ⚠️ MARKETPLACE KİMLİĞİ SÜRÜME GİRMEK ZORUNDADIR (§13.5). `EBAY_US`
     * ve `EBAY_DE` ağaçları FARKLIDIR ama sürüm numaraları AYNI olabilir;
     * tekillik `(channel_type_code, taxonomy_version, external_id)`
     * olduğu için iki pazarın aynı kimlikli kategorileri BİRBİRİNİ
     * EZERDİ — ve satıcı ABD ağacından seçtiği kategoriyi Almanya'ya
     * gönderip `VALIDATION` alırdı.
     *
     * ⚠️ SÜRÜM GELMEZSE UYDURULMAZ, AĞAÇTAN TÜRETİLİR. Sabit bir dize
     * yazılsaydı ağaç değiştiğinde sürüm AYNI kalır, yeni satırlar
     * eskilerin üzerine yazılır ve eşleştirmeler sessizce başka bir
     * kategoriyi gösterirdi.
     *
     * @param  array<string, mixed>  $body
     * @param  list<array<string, mixed>>  $categories
     */
    private function versionFor(array $body, array $categories): string
    {
        $version = $body['categoryTreeVersion'] ?? null;

        $version = is_string($version) || is_int($version) ? (string) $version : '';

        if ($version === '') {
            $version = $this->fingerprint($categories);
        }

        return $this->marketplaceId.':'.$version;
    }

    /**
     * Kanal sürüm vermediğinde ağacın ŞEKLİNDEN türetilen parmak izi.
     *
     * Etsy/Trendyol kalıbı birebir: kimlik + ebeveyn + ad.
     *
     * ⚠️ HAM GÖVDE HASH'LENMEZ. Gövde kategorileri KANALIN döndürdüğü
     * SIRADA taşır ve o sıra değişince ağaç AYNIYKEN sürüm değişir —
     * tüm eşleştirmeler "yeniden doğrula" damgası yer ve alan anlamını
     * kaybederdi. Gövde ayrıca sürümle ilgisiz alanlar da taşır
     * (`applicableToAll` gibi) ve onların değişmesi de sahte bir sürüm
     * artışı üretirdi.
     *
     * ⚠️ SIRALAMA ZORUNLUDUR — aynı ağaç HER ZAMAN aynı sürümü vermeli.
     *
     * @param  list<array<string, mixed>>  $categories
     */
    private function fingerprint(array $categories): string
    {
        $lines = array_map(
            static fn (array $c): string => sprintf(
                '%s|%s|%s',
                $c['external_id'],
                $c['parent_external_id'] ?? '',
                $c['name'],
            ),
            $categories,
        );

        sort($lines);

        return substr(hash('sha256', implode("\n", $lines)), 0, 16);
    }
}
