<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Inventory\Models\Warehouse;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kiracılığın kökü. BelongsToTenant UYGULANMAZ — bu tablo kiracısızdır.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 001.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 */
class Tenant extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'plan_code',
        'default_currency',
        'default_locale',
        'timezone',
        'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->using(TenantUser::class)
            ->withPivot(['id', 'role', 'invited_at', 'accepted_at'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function channelConnections(): HasMany
    {
        return $this->hasMany(ChannelConnection::class);
    }

    /**
     * Kiracının varsayılan deposu.
     *
     * CreateTenant her kiracı için bir tane yaratır; "en az bir varsayılan
     * depo" garantisi veritabanı kısıtından değil oradan gelir
     * (§4 · DDL Correction).
     */
    public function defaultWarehouse(): ?Warehouse
    {
        return $this->warehouses()->where('is_default', true)->first();
    }
}
