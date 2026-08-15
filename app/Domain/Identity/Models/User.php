<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Kiracısız kullanıcı. Bir kullanıcı birden fazla kiracıya
 * tenant_users üzerinden bağlanabilir.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 002.
 *
 * @property string $id
 * @property string $email
 */
class User extends Authenticatable
{
    use HasFactory;
    use HasUuidV7;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->using(TenantUser::class)
            ->withPivot(['id', 'role', 'invited_at', 'accepted_at'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }
}
