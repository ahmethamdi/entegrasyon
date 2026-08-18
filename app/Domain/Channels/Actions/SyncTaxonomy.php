<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Channels\Contracts\SupportsTaxonomy;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\TaxonomySyncResult;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Kanalın kategori ağacını çeker ve önbelleğe yazar.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 ("Taksonomi çekme,
 * önbellekleme, sürümleme"), §4 · Mapping, §14 · Trendyol.
 *
 * DEĞİŞMEZ KURAL — TAKSONOMİ KİRACISIZ YAZILIR:
 *   `channel_categories` kiracı kolonu taşımaz ve yazım açıkça
 *   `runAsSystem()` altında yapılır. Ağaç KANALA aittir: Trendyol'un
 *   kategorileri tüm satıcılar için aynıdır. Bağlantı kiracıya aittir ve
 *   turu O tetikler, ama YAZILAN VERİ kiracıya ait değildir.
 *
 * DEĞİŞMEZ KURAL — YENİ SÜRÜM ESKİYİ SİLMEZ:
 *   Yazım `updateOrCreate` ile yapılır ve eski sürümün satırlarına
 *   DOKUNULMAZ. Eşleştirmeler eski sürüme bağlıdır; silinseydi satıcının
 *   aylarca emek verdiği kategori eşleştirmeleri bir gecede yok olurdu.
 *
 * DEĞİŞMEZ KURAL — YETENEK `instanceof` İLE OKUNUR:
 *   `if ($code === 'trendyol')` YAZILMAZ. WooCommerce `SupportsTaxonomy`
 *   uygulamaz ve o bağlantı için tur sessizce atlanır — hata değil.
 *
 * DEĞİŞMEZ KURAL — TAKSONOMİ STOK AKIŞINA DOKUNMAZ (§14):
 *   Bu action hiçbir senkron operasyonu açmaz, hiçbir outbox olayı yazmaz
 *   ve hiçbir listing'in durumunu değiştirmez.
 *
 * ÖZNİTELİK YALNIZCA YAPRAK İÇİN ÇEKİLİR: ara kategoriye ürün açılamaz ve
 * öznitelik istemek boşuna istek, boşuna kotadır. 30 bin kategorili bir
 * ağaçta bu fark saatler demektir.
 */
final class SyncTaxonomy
{
    public function __construct(
        private readonly AdapterRegistry $registry,
    ) {}

    public function run(ChannelConnection $connection, bool $withAttributes = false): TaxonomySyncResult
    {
        $adapter = $this->registry->for($connection);

        // Yetenek TİP SİSTEMİNDEN okunur; kanal kodu kontrol edilmez.
        if (! $adapter instanceof SupportsTaxonomy) {
            return TaxonomySyncResult::unsupported();
        }

        $snapshot = $adapter->fetchCategoryTree();

        $channelTypeCode = $connection->channel_type_code;

        // TAKSONOMİ KİRACISIZDIR: yazım açıkça sistem bağlamında yapılır.
        // Bağlantı kiracıya aittir ve turu o tetikler, ama yazılan veri
        // kanala aittir.
        //
        // DÜRÜST SINIR: bu sarmalayıcı BUGÜN davranışı değiştirmez ve
        // kaldırılırsa hiçbir test kırmızıya dönmez (mutasyonla doğrulandı).
        // `ChannelCategory` `BelongsToTenant` KULLANMADIĞI için global scope
        // hiç uygulanmaz; sarmalayıcı niyeti belgeler ve birisi ileride o
        // trait'i eklerse yazımın sessizce kiracıya kapanmasını önler.
        // Sahte bir test yazmak yerine sınır burada kaydedildi.
        return TenantContext::runAsSystem(function () use (
            $adapter, $snapshot, $channelTypeCode, $withAttributes,
        ): TaxonomySyncResult {
            $written = $this->writeCategories($channelTypeCode, $snapshot->version, $snapshot->categories);

            $attributesWritten = 0;
            $leavesFetched = 0;

            if ($withAttributes) {
                [$attributesWritten, $leavesFetched] = $this->writeAttributes(
                    $adapter,
                    $channelTypeCode,
                    $snapshot->version,
                );
            }

            return new TaxonomySyncResult(
                supported: true,
                version: $snapshot->version,
                categoriesWritten: $written,
                attributesWritten: $attributesWritten,
                leavesFetched: $leavesFetched,
            );
        });
    }

    /**
     * Kategorileri yazar — aynı sürüm ikinci kez yazılırsa günceller.
     *
     * Tekillik `(channel_type_code, taxonomy_version, external_id)`;
     * kopya satır AÇILMAZ.
     *
     * @param  list<array<string, mixed>>  $categories
     */
    private function writeCategories(string $channelTypeCode, string $version, array $categories): int
    {
        $written = 0;

        // Tek transaction: yarım yazılmış bir ağaç, ön koşul kapısına
        // "bu kategori yok" dedirtir ve listing haksız yere blocked olur.
        DB::transaction(function () use ($channelTypeCode, $version, $categories, &$written): void {
            foreach ($categories as $category) {
                ChannelCategory::query()->updateOrCreate(
                    [
                        'channel_type_code' => $channelTypeCode,
                        'taxonomy_version' => $version,
                        'external_id' => $category['external_id'],
                    ],
                    [
                        'parent_external_id' => $category['parent_external_id'],
                        'name' => $category['name'],
                        'path' => $category['path'],
                        'is_leaf' => $category['is_leaf'],
                    ],
                );

                $written++;
            }
        });

        return $written;
    }

    /**
     * Yaprakların özniteliklerini çeker ve yazar.
     *
     * HTTP çağrıları TRANSACTION DIŞINDA yapılır: 30 bin yaprak için açık
     * kalan bir transaction veritabanını saatlerce kilitler.
     *
     * @return array{0: int, 1: int}
     */
    private function writeAttributes(
        SupportsTaxonomy $adapter,
        string $channelTypeCode,
        string $version,
    ): array {
        $attributesWritten = 0;
        $leavesFetched = 0;

        $leaves = ChannelCategory::query()
            ->forVersion($channelTypeCode, $version)
            ->leaves()
            ->get();

        foreach ($leaves as $leaf) {
            // Ağ çağrısı transaction dışında.
            $definitions = $adapter->fetchCategoryAttributes($leaf->external_id);
            $leavesFetched++;

            DB::transaction(function () use ($leaf, $definitions, &$attributesWritten): void {
                foreach ($definitions as $definition) {
                    ChannelCategoryAttribute::query()->updateOrCreate(
                        [
                            'channel_category_id' => $leaf->id,
                            'external_attribute_id' => $definition['external_attribute_id'],
                        ],
                        [
                            'name' => $definition['name'],
                            'is_required' => $definition['is_required'],
                            'is_variant_defining' => $definition['is_variant_defining'],
                            'data_type' => $definition['data_type'],
                            'allowed_values' => $definition['allowed_values'],
                        ],
                    );

                    $attributesWritten++;
                }

                // Damga: hangi yaprakların çekildiği bilinmeden her turda
                // 30 bin istek yeniden atılırdı.
                $leaf->forceFill(['attributes_fetched_at' => now()])->save();
            });
        }

        return [$attributesWritten, $leavesFetched];
    }
}
