<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'wh-'.Str::lower(Str::random(6)),
            'name' => fake()->city().' Deposu',
            'is_default' => false,
            'is_active' => true,
            'priority' => 0,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => 'default',
            'name' => 'Default Warehouse',
            'is_default' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
