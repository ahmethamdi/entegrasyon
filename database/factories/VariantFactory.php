<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    protected $model = Variant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => 'VAR-'.Str::upper(Str::random(8)),
            'barcode' => null,
            'price' => fake()->randomFloat(2, 10, 5000),
            'currency' => 'TRY',
            'status' => 'active',
            'content_version' => 0,
        ];
    }
}
