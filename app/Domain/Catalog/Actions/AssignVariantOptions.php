<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\OptionDefinition;
use App\Domain\Catalog\Models\OptionValue;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Models\VariantOption;
use Illuminate\Support\Facades\DB;

/**
 * Varyanta seçenek değerlerini yazar: "Renk = Kırmızı", "Beden = M".
 *
 * ADR-003. Şema (`option_definitions` → `option_values` → `variant_options`)
 * baştan vardı ama üretimde HİÇ yazılmıyordu; öznitelik eşleme ekranı bu
 * yüzden boş kalıyor, Trendyol'a gönderimde beden/renk özniteliği hiç
 * gitmiyordu.
 *
 * TANIM KİRACI DÜZEYİNDEDİR (`UNIQUE(tenant_id, name)`): bütün ürünlerin
 * "Beden"i aynı tanımdır, öznitelik eşlemesi bir kez yapılır.
 *
 * AD/DEĞER OLDUĞU GİBİ SAKLANIR (yalnız kırpılır). Büyük/küçük harf
 * birleştirmesi YAPILMAZ: "beden" ile "Beden"i birleştirmek kanaldaki
 * gerçek değeri değiştirirdi ve geri gönderimde satıcının yazdığı metin
 * kaybolurdu. Türkçe `I/ı` dönüşümü de ayrı bir tuzak.
 *
 * VAR OLAN DEĞER EZİLMEZ: varyantın bu seçenekte zaten bir değeri varsa
 * dokunulmaz. Satıcı panelde düzelttiyse bir içe aktarma turu onu geri
 * çevirmemeli.
 */
final class AssignVariantOptions
{
    /**
     * @param  array<string, string>  $options  ad => değer, sıralı
     * @return int Yazılan yeni seçenek sayısı
     */
    public function run(Variant $variant, array $options): int
    {
        $options = self::normalize($options);

        if ($options === []) {
            return 0;
        }

        return DB::transaction(function () use ($variant, $options): int {
            $written = 0;
            $position = 0;

            foreach ($options as $name => $value) {
                // `createOrFirst` UNIQUE ihlalinde var olanı döndürür ve
                // savepoint kullanır — eşzamanlı iki içe aktarma aynı
                // "Beden"i açmaya çalışırsa ikincisi transaction'ı düşürmez.
                $definition = OptionDefinition::query()->createOrFirst(
                    ['tenant_id' => $variant->tenant_id, 'name' => $name],
                    ['position' => $position],
                );

                $optionValue = OptionValue::query()->createOrFirst(
                    ['option_definition_id' => $definition->id, 'value' => $value],
                    ['tenant_id' => $variant->tenant_id, 'position' => 0],
                );

                $exists = VariantOption::query()
                    ->where('variant_id', $variant->id)
                    ->where('option_definition_id', $definition->id)
                    ->exists();

                if (! $exists) {
                    VariantOption::query()->create([
                        'tenant_id' => $variant->tenant_id,
                        'variant_id' => $variant->id,
                        'option_definition_id' => $definition->id,
                        'option_value_id' => $optionValue->id,
                    ]);
                    $written++;
                }

                $position++;
            }

            return $written;
        });
    }

    /**
     * Boş ad/değer atılır, kırpılır. "Title / Default Title" gibi kanal
     * varsayılanları adapter'da elenir; burada kanal bilgisi yoktur.
     *
     * @param  array<array-key, mixed>  $options
     * @return array<string, string>
     */
    public static function normalize(array $options): array
    {
        $clean = [];

        foreach ($options as $name => $value) {
            $name = trim((string) $name);
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($name !== '' && $value !== '') {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }
}
