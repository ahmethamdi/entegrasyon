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
 * satıcılar için aynıdır ve yol `/suppliers/{id}/` öneki taşımaz. Bu,
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
    private const CATEGORY_TREE_ENDPOINT = 'product-categories';

    public function __construct(
        private ChannelHttpClient $client,
    ) {}

    /**
     * Kategori ağacını çeker, düzleştirir ve sürümler.
     */
    public function fetchTree(): CategoryTreeSnapshot
    {
        $response = $this->client->get(self::CATEGORY_TREE_ENDPOINT);

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

    /**
     * Bir kategorinin öznitelik tanımları.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAttributes(string $categoryId): array
    {
        $response = $this->client->get("product-categories/{$categoryId}/attributes");

        // Ağaçtaki ile aynı gerekçe: başarısız yanıt "bu kategoride zorunlu
        // öznitelik yok" anlamına GELMEZ. Sessizce boş dönseydi ön koşul
        // kapısı ürünü geçirir ve kanal onu reddederdi.
        $response->throw();

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        /** @var list<array<string, mixed>> $raw */
        $raw = $body['categoryAttributes'] ?? [];

        return array_values(array_map(
            static fn (array $item): array => [
                'external_attribute_id' => (string) ($item['attribute']['id'] ?? ''),
                'name' => (string) ($item['attribute']['name'] ?? ''),
                'is_required' => (bool) ($item['required'] ?? false),
                // Varyant belirleyici: ürünün kaç varyantla açılacağını
                // belirler (beden, renk).
                'is_variant_defining' => (bool) ($item['varianter'] ?? false),
                // Serbest metin kabul ediyorsa değer listesi bağlayıcı değildir.
                'data_type' => ($item['allowCustom'] ?? false) ? 'string' : 'enum',
                'allowed_values' => array_values(array_map(
                    static fn (array $value): array => [
                        'id' => (string) ($value['id'] ?? ''),
                        'label' => (string) ($value['name'] ?? ''),
                    ],
                    $item['attributeValues'] ?? [],
                )),
            ],
            $raw,
        ));
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
