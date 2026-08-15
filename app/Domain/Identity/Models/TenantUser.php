<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Kiracı üyeliği.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 003.
 *
 * BelongsToTenant UYGULANMAZ: bu tablo kiracı bağlamı kurulmadan ÖNCE
 * sorgulanır — kullanıcı giriş yaptığında hangi kiracılara üye olduğunu
 * bulmak için. Kapsam kontrolü uygulama katmanında yapılır.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $role
 */
class TenantUser extends Pivot
{
    use HasFactory;
    use HasUuidV7;

    public $incrementing = false;

    protected $table = 'tenant_users';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
        'invited_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
