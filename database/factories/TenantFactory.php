<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'default_currency' => 'TRY',
            'default_locale' => 'tr',
            'timezone' => 'Europe/Istanbul',
        ];
    }
}
