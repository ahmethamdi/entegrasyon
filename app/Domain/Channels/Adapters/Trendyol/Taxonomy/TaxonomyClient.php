<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Trendyol\Taxonomy;

use App\Domain\Channels\Support\ChannelHttpClient;
use App\Domain\Sync\Support\CategoryTreeSnapshot;
use DateTimeImmutable;

/**
 * Trendyol taksonomi istemcisi — ağacı çeker ve düzleştirir.
 *
 * Mimari Karar Dokümanı v2.2 · §19 (`Taxonomy/ TaxonomyClient`), §14.
 *
 * TAKSONOMİ UÇ NOKTASI SATICIYA ÖZGÜ DEĞİLDİR: kategori ağacı tüm
 * satıcılar için aynıdır ve yol `/sellers/{id}/` öneki taşımaz. Bu,
 * ağacın neden kiracısız saklandığının da API tarafındaki karşılığıdır.
 *
 * AĞAÇ DÜZLEŞTİRİLİR: Trendyol `subCategories` ile iç içe döner; biz
 * `parent_external_id` taşıyan düz bir liste saklarız. İç içe yapı
 * saklansaydı "şu kategorinin tüm çocukları" sorgusu özyinelemeli CTE
 * gerektirirdi ve eşleştirme ekranı her tuşta ağacı yeniden yürürdü.
 *
 * YAPRAK BİLGİSİ TÜRETİLİR: kanal "bu yaprak mı" demez; alt kategorisi
 * olmayan düğüm yapraktır. Ürün YALNIZCA yaprağa açılabilir.
 */
final readonly class TaxonomyClient
{
    /** Kategori ağacı — satıcıdan bağımsız uç nokta. */
    private const CATEGORY_TREE_ENDPOINT = 'product/product-categories';

    /**
     * @param  array<string, string>  $headers  Adapter'ın zorunlu başlıkları
     *                                          (`User-Agent`); eksikse 403
     */
    public function __construct(
        private ChannelHttpClient $client,
        private string $baseUrl,
        private array $headers = [],
    ) {}

    /**
     * Kategori ağacını çeker, düzleştirir ve sürümler.
     */
    public function fetchTree(): CategoryTreeSnapshot
    {
        $response = $this->client->get($this->baseUrl.'/'.self::CATEGORY_TREE_ENDPOINT, headers: $this->headers);

        // BAŞARISIZ YANIT SESSİZCE BOŞ AĞACA DÖNÜŞMEZ.
        //
        // `json()` bir 500 gövdesinde de dizi döndürür ve `categories`
        // anahtarı bulunmadığı için ağaç BOŞ çıkardı. O boş ağaç geçerli
        // bir sürümle veritabanına yazılır, panel "bu kanalda hiç kategori
        // yok" der ve ürün aktarımı ön koşul kapısında sonsuza kadar
        // takılırdı — üstelik hata hiçbir yere düşmeden.
        //
        // `throw()` istisnayı yükseltir; sınıflandırmayı adapter,
        // ne yapılacağını çekirdek belirler.
        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        /** @var list<array<string, mixed>> $roots */
        $roots = $body['categories'] ?? [];

        $flat = [];
        $this->flatten($roots, parentId: null, path: [], into: $flat);

        return new CategoryTreeSnapshot(
            categories: $flat,
            version: $this->versionFor($flat),
            fetchedAt: new DateTimeImmutable,
        );
    }

    /** Değer listesi sayfa boyutu — kanalın üst sınırı 1000. */
    private const VALUES_PAGE_SIZE = 1000;

    /** Sonsuz döngü sigortası: 1000 × 1000 değer hiçbir öznitelikte yok. */
    private const MAX_VALUE_PAGES = 1000;

    /**
     * Bir kategorinin öznitelik tanımları — Product V2.
     *
     * ⚠️ V2 LİSTESİ DEĞER İÇERMEZ (A11 ④c). V1 `attributeValues`'ı satır
     * içinde döndürüyordu; V2'de her özniteliğin değerleri AYRI ve
     * SAYFALI uç noktadan (`.../attributes/{id}/values`) gelir. Eski
     * okuma V2 gövdesinde boş değer listesi bulur ve eşleştirme ekranı
     * "bu öznitelikte seçenek yok" derdi — ürün zorunlu özniteliği
     * olmadan gidip kalıcı hatayla reddedilirdi.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAttributes(string $categoryId): array
    {
        $categoryId = rawurlencode($categoryId);

        $response = $this->client->get(
            "{$this->baseUrl}/product/categories/{$categoryId}/attributes",
            headers: $this->headers,
        );

        // Ağaçtaki ile aynı gerekçe: başarısız yanıt "bu kategoride zorunlu
        // öznitelik yok" anlamına GELMEZ. Sessizce boş dönseydi ön koşul
        // kapısı ürünü geçirir ve kanal onu reddederdi.
        $response->throw();

        /** @var list<array<string, mixed>> $raw */
        $raw = $response->json('categoryAttributes') ?? [];

        $attributes = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $attributeId = (string) ($item['attribute']['id'] ?? '');

            if ($attributeId === '') {
                continue;
            }

            $attributes[] = [
                'external_attribute_id' => $attributeId,
                'name' => (string) ($item['attribute']['name'] ?? ''),
                'is_required' => (bool) ($item['required'] ?? false),
                // Varyant belirleyici: ürünün kaç varyantla açılacağını
                // belirler (beden, renk).
                'is_variant_defining' => (bool) ($item['varianter'] ?? false),
                // Serbest metin kabul ediyorsa değer listesi bağlayıcı değildir.
                'data_type' => ($item['allowCustom'] ?? false) ? 'string' : 'enum',
                'allowed_values' => isset($item['attributeValues']) && is_array($item['attributeValues'])
                    // Satır içi değer gelirse (V1 biçimi) ek çağrı yapılmaz.
                    ? $this->inlineValues($item['attributeValues'])
                    : $this->fetchValues($categoryId, rawurlencode($attributeId)),
            ];
        }

        return $attributes;
    }

    /**
     * Bir özniteliğin bütün değerleri — sayfa sayfa.
     *
     * İlk sayfayla yetinilseydi 1000'den fazla değerli öznitelikte (renk,
     * beden) kalanlar eşleştirme ekranında HİÇ görünmez, satıcı doğru
     * değeri bulamaz ve yanlış değer seçerdi.
     *
     * @return list<array{id: string, label: string}>
     */
    private function fetchValues(string $categoryId, string $attributeId): array
    {
        $values = [];
        $page = 0;

        do {
            $response = $this->client->get(
                "{$this->baseUrl}/product/categories/{$categoryId}/attributes/{$attributeId}/values",
                ['page' => $page, 'size' => self::VALUES_PAGE_SIZE],
                headers: $this->headers,
            );

            $response->throw();

            foreach ((array) ($response->json('content') ?? []) as $value) {
                if (is_array($value) && isset($value['attributeValueId'])) {
                    $values[] = [
                        'id' => (string) $value['attributeValueId'],
                        'label' => (string) ($value['attributeValue'] ?? ''),
                    ];
                }
            }

            $page++;
        } while ($page < min((int) ($response->json('totalPages') ?? 1), self::MAX_VALUE_PAGES));

        return $values;
    }

    /**
     * @param  array<int, mixed>  $raw
     * @return list<array{id: string, label: string}>
     */
    private function inlineValues(array $raw): array
    {
        $values = [];

        foreach ($raw as $value) {
            if (is_array($value)) {
                $values[] = [
                    'id' => (string) ($value['id'] ?? ''),
                    'label' => (string) ($value['name'] ?? ''),
                ];
            }
        }

        return $values;
    }

    /**
     * İç içe ağacı düz listeye indirir.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<string>  $path
     * @param  list<array<string, mixed>>  $into
     */
    private function flatten(array $nodes, ?string $parentId, array $path, array &$into): void
    {
        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $name = (string) ($node['name'] ?? '');
            $children = $node['subCategories'] ?? [];
            $currentPath = [...$path, $name];

            $into[] = [
                'external_id' => $id,
                'parent_external_id' => $parentId,
                'name' => $name,
                // Okunabilir yol: eşleştirme ekranında kullanıcı kategoriyi
                // ancak bağlamıyla tanır ("Elbise" tek başına yetmez).
                'path' => implode(' > ', $currentPath),
                // Kanal "yaprak mı" demez; alt kategorisi olmayan yapraktır.
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
     * Trendyol bir sürüm numarası vermez. Zaman veya rastgelelik
     * karışsaydı her çekim yeni sürüm üretir, hiç değişmemiş ağaç için tüm
     * eşleştirmeler "yeniden doğrula" damgası yer ve alan anlamını
     * kaybederdi. Aynı ağaç her zaman aynı sürümü verir.
     *
     * @param  list<array<string, mixed>>  $categories
     */
    private function versionFor(array $categories): string
    {
        // Kimlik + ad + ebeveyn: ağacın ŞEKLİNİ tanımlayan alanlar.
        // Sıralama kanalın döndürme sırasından bağımsız olmalı, yoksa aynı
        // ağaç farklı sırada gelince yeni sürüm sanılırdı.
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
