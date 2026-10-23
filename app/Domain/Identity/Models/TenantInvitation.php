<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\TenantRole;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bekleyen ekip daveti (S7). Kabul edilince `tenant_users` satırı doğar
 * ve bu satır `accepted_at` ile kapanır — silinmez, "kim kimi ne zaman
 * davet etti" sorusu burada da okunur.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property string $role
 * @property string $token_hash
 * @property ?string $invited_by
 * @property Carbon $expires_at
 * @property ?Carbon $accepted_at
 */
class TenantInvitation extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    /** Davet bağlantısının ömrü. */
    public const TTL_DAYS = 7;

    protected $fillable = [
        'tenant_id',
        'email',
        'role',
        'token_hash',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function roleEnum(): TenantRole
    {
        return TenantRole::fromStored($this->role);
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
