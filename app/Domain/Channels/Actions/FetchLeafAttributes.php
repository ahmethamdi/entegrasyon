<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Channels\Contracts\SupportsTaxonomy;
use App\Domain\Channels\Models\ChannelCategory;
use App\Domain\Channels\Models\ChannelCategoryAttribute;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * TEK bir yaprağın özniteliklerini kanaldan çeker ve yazar.
 *
 * İki yerden çağrılır: gece taksonomi turu (`--with-attributes`, bütün
 * yapraklar) ve kategori eşleştirmesi kaydedildiği an (yalnız o yaprak).
 *
 * NEDEN EŞLEŞTİRME ANINDA: gece turu öznitelikleri varsayılan olarak
 * ÇEKMEZ (30 bin yaprak, saatler sürer). Önceden başka hiçbir yol da
 * çekmiyordu; ön koşul kapısı zorunlu öznitelikleri tanımadığı için
 * "eksik yok" der, ürün kanala gider ve kanal onu toplu işin sonucunda
 * SESSİZCE reddederdi ("Menşei zorunlu"). Gerçek Trendyol hesabında
 * bulundu (6 Eki).
 *
 * HTTP çağrısı TRANSACTION DIŞINDA yapılır; yazım kiracısız (öznitelik
 * tanımı kanala aittir).
 */
final class FetchLeafAttributes
{
    /** @return int Yazılan öznitelik sayısı */
    public function run(SupportsTaxonomy $adapter, ChannelCategory $leaf): int
    {
        $definitions = $adapter->fetchCategoryAttributes($leaf->external_id);

        return TenantContext::runAsSystem(fn (): int => DB::transaction(function () use ($leaf, $definitions): int {
            $written = 0;

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

                $written++;
            }

            // Damga: hangi yaprakların çekildiği bilinmeden her turda
            // 30 bin istek yeniden atılırdı.
            $leaf->forceFill(['attributes_fetched_at' => now()])->save();

            return $written;
        }));
    }
}
