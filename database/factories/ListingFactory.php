<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Models\Listing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_connection_id' => ChannelConnection::factory(),
            'variant_id' => Variant::factory(),
            'external_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'external_url' => 'https://example.com/p/'.Str::lower(Str::random(6)),
            'lifecycle_status' => 'live',
            'listed_at' => now(),
        ];
    }

    /** Kanala hiç gönderilmemiş taslak — external_id yok, fan-out hedefi değil. */
    public function draft(): static
    {
        return $this->state(fn (): array => [
            'lifecycle_status' => 'draft',
            'external_id' => null,
            'listed_at' => null,
        ]);
    }

    public function delisted(): static
    {
        return $this->state(fn (): array => [
            'lifecycle_status' => 'delisted',
            'delisted_at' => now(),
        ]);
    }
}
