<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'brand' => fake()->company(),
            'status' => 'active',
            'content_version' => 0,
        ];
    }
}
