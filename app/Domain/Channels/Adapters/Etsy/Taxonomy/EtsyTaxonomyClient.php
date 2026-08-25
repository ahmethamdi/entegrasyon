<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Etsy\Taxonomy;

use App\Domain\Channels\Adapters\Etsy\EtsyEndpoints;
use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Sync\Support\CategoryTreeSnapshot;
use DateTimeImmutable;

/**
 * Etsy seller taxonomy istemcisi — ağacı çeker ve düzleştirir.
 *
 * V3.0 · §11.5 · §19 · v2.2 §14 · §19 (`Taxonomy/ TaxonomyClient`).
 *
 * ─────────────────────────────────────────────────────────────────────
 * TRENDYOL KALIBI BİREBİR GEÇERLİ — ama gövde ŞEKLİ farklı
 * ─────────────────────────────────────────────────────────────────────
 * Ağaç kanalın GERÇEĞİDİR (`channel_categories`, KİRACISIZ); eşleştirme
 * satıcının KARARIDIR (`category_mappings`, kiracıya ait). Sürüm
 * İÇERİKTEN türer ve SIRALANIR.
 *
 * Farklar:
 *   • İç içe alan adı `children` (Trendyol'da `subCategories`)
 *   • Kök liste `results` altında (Trendyol'da `categories`)
 *   • Öznitelikler de `results` altında ve `scales` ayrı bir kavram
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ TAKSONOMİ UÇ NOKTASI SATICIYA ÖZGÜ DEĞİLDİR
 * ─────────────────────────────────────────────────────────────────────
 * Yol `shops/{shop_id}` öneki TAŞIMAZ — ağaç tüm satıcılar için aynıdır.
 * Bu, ağacın neden kiracısız saklandığının API tarafındaki karşılığıdır.
 *
 * Yine de çağrı KİMLİKLİDİR (`Bearer` + `x-api-key`): Etsy anonim istek
 * kabul etmez ve anahtarsız çağrı 401 alır.
 */
final readonly class EtsyTaxonomyClient
{
    public function __construct(
        private ChannelHttpClient $client,
        /** @var array<string, string> Adapter'ın verdiği `x-api-key` başlığı */
        private array $headers = [],
    ) {}

    /**
     * Kategori ağacını çeker, düzleştirir ve sürümler.
     */
    public function fetchTree(): CategoryTreeSnapshot
    {
        $response = $this->client->get(
            EtsyEndpoints::url(EtsyEndpoints::TAXONOMY_NODES),
            headers: $this->headers,
        );

        // ⚠️ BAŞARISIZ YANIT SESSİZCE BOŞ AĞACA DÖNÜŞMEZ.
        //
        // `json()` bir 500 gövdesinde de dizi döndürür ve `results`
        // anahtarı bulunmadığı için ağaç BOŞ çıkardı. O boş ağaç GEÇERLİ
        // bir sürümle veritabanına yazılır, panel "bu kanalda hiç kategori
        // yok" der ve ürün aktarımı ön koşul kapısında SONSUZA KADAR
        // takılırdı — üstelik hata hiçbir yere düşmeden.
        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        /** @var list<array<string, mixed>> $roots */
        $roots = $body['results'] ?? [];

        $flat = [];
        $this->flatten($roots, parentId: null, path: [], into: $flat);

        return new CategoryTreeSnapshot(
            categories: $flat,
            version: $this->versionFor($flat),
            fetchedAt: new DateTimeImmutable,
        );
    }

    /**
     * Bir kategorinin öznitelik tanımları.
     *
     * ⚠️ YALNIZCA YAPRAK İÇİN ÇAĞRILIR (`SyncTaxonomy` bunu garanti eder).
     * Ara kategoriye ürün açılamaz; öznitelik istemek boşuna istek ve
     * boşuna KOTADIR — Etsy'de kota GERÇEK bir tavandır (§21: 10.000
     * istek/gün, hesap başına).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAttributes(string $categoryId): array
    {
        $response = $this->client->get(
            EtsyEndpoints::url(EtsyEndpoints::TAXONOMY_PROPERTIES, ['taxonomy_id' => $categoryId]),
            headers: $this->headers,
        );

        // Ağaçtaki ile aynı gerekçe: başarısız yanıt "bu kategoride zorunlu
        // öznitelik yok" anlamına GELMEZ. Sessizce boş dönseydi ön koşul
        // kapısı ürünü geçirir ve kanal onu reddederdi — o hata
        // `VALIDATION` yani KALICIDIR ve listing "düzeltilemez" damgasıyla
        // ölürdü.
        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        /** @var list<array<string, mixed>> $raw */
        $raw = $body['results'] ?? [];

        return array_values(array_map(
            fn (array $item): array => $this->toDefinition($item),
            $raw,
        ));
    }

    /**
     * Etsy property → çekirdeğin beklediği tanım.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function toDefinition(array $item): array
    {
        /** @var list<array<string, mixed>> $possible */
        $possible = $item['possible_values'] ?? [];

        $allowed = array_values(array_map(
            static fn (array $value): array => [
                'id' => (string) ($value['value_id'] ?? ''),
                'label' => (string) ($value['name'] ?? ''),
            ],
            $possible,
        ));

        return [
            'external_attribute_id' => (string) ($item['property_id'] ?? ''),
            'name' => (string) ($item['display_name'] ?? ($item['name'] ?? '')),

            // ⚠️ ETSY'DE ÖZNİTELİK ZORUNLU DEĞİLDİR ve `is_required`
            // DAİMA `false`'tur. Uydurma bir zorunluluk yazmak, ön koşul
            // kapısının ürünü HİÇ geçirmemesi demektir: satıcı kanalın
            // istemediği bir alanı doldurana kadar listing `blocked`
            // kalır ve o alan panelde hiçbir zaman dolmaz.
            //
            // §11.5'in "onay süreci yoktur" kararıyla aynı aile: kanalda
            // olmayan bir kısıt UYDURULMAZ.
            'is_required' => false,

            // Varyant belirleyici — beden/renk gibi.
            'is_variant_defining' => (bool) ($item['is_multivalued'] ?? false),

            // ⚠️ SERBEST METİN KABUL EDİYORSA DEĞER LİSTESİ BAĞLAYICI
            // DEĞİLDİR. `supports_attributes` true ise satıcı kendi
            // değerini yazabilir; `enum` denseydi izinli liste kapısı
            // meşru bir değeri REDDEDERDİ.
            //
            // BOŞ İZİNLİ LİSTE "HİÇBİRİ" DEĞİL "SERBEST METİN" DEMEKTİR
            // (eşleştirme kuralları) — aksi yorumla satıcı o özniteliği
            // ASLA eşleştiremezdi.
            'data_type' => $allowed === [] ? 'string' : 'enum',
            'allowed_values' => $allowed,
        ];
    }

    /**
     * İç içe ağacı düz listeye indirir.
     *
     * AĞAÇ DÜZLEŞTİRİLİR: Etsy `children` ile iç içe döner; biz
     * `parent_external_id` taşıyan düz bir liste saklarız. İç içe yapı
     * saklansaydı "şu kategorinin tüm çocukları" sorgusu özyinelemeli CTE
     * gerektirirdi ve eşleştirme ekranı her tuşta ağacı yeniden yürürdü.
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

            $id = (string) ($node['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $name = (string) ($node['name'] ?? '');
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $currentPath = [...$path, $name];

            $into[] = [
                'external_id' => $id,
                'parent_external_id' => $parentId,
                'name' => $name,
                // Okunabilir yol: eşleştirme ekranında kullanıcı kategoriyi
                // ancak bağlamıyla tanır ("Ring" tek başına yetmez).
                'path' => implode(' > ', $currentPath),

                // ⚠️ YAPRAK BİLGİSİ TÜRETİLİR, `level` ALANINDAN OKUNMAZ.
                // Etsy her düğümde bir `level` döndürür ama o DERİNLİKTİR,
                // yaprak olup olmadığını SÖYLEMEZ: farklı dallar farklı
                // derinlikte biter. `level` okunsaydı derin bir dalın ara
                // düğümü yaprak sanılır ve ürün ARA kategoriye açılmaya
                // çalışılırdı — kanal `VALIDATION` döner ve o hata
                // KALICIDIR.
                'is_leaf' => $children === [],
            ];

            if ($children !== []) {
                $this->flatten($children, $id, $currentPath, $into);
            }
        }
    }

    /**
     * Sürüm — AĞACIN İÇERİĞİNDEN türer.
     *
     * Etsy bir sürüm numarası VERMEZ. Zaman veya rastgelelik karışsaydı
     * her çekim yeni sürüm üretir, hiç değişmemiş ağaç için TÜM
     * eşleştirmeler "yeniden doğrula" damgası yer ve alan anlamını
     * kaybederdi. Aynı ağaç her zaman aynı sürümü verir.
     *
     * SIRALAMA ZORUNLUDUR: kanalın döndürme sırası değişince ağaç AYNIYKEN
     * sürüm değişir ve tüm eşleştirmeler bayat işaretlenirdi.
     *
     * @param  list<array<string, mixed>>  $categories
     */
    private function versionFor(array $categories): string
    {
        // Kimlik + ebeveyn + ad: ağacın ŞEKLİNİ tanımlayan alanlar.
        $fingerprint = array_map(
            static fn (array $c): string => sprintf(
                '%s|%s|%s',
                $c['external_id'],
                $c['parent_external_id'] ?? '',
                $c['name'],
            ),
            $categories,
        );

        sort($fingerprint);

        return substr(hash('sha256', implode("\n", $fingerprint)), 0, 16);
    }
}
