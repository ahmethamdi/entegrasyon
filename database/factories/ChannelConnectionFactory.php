<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Channels\Models\ChannelConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChannelConnection>
 */
class ChannelConnectionFactory extends Factory
{
    protected $model = ChannelConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_type_code' => 'woocommerce',
            'label' => 'Test Mağaza',
            'external_account_id' => Str::lower(Str::random(8)).'.example.com',
            'status' => 'active',
            'settings' => [],
            'health_status' => 'healthy',
            'connected_at' => now(),
        ];
    }
}
