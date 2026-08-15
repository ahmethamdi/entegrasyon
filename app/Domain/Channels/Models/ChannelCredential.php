<?php

declare(strict_types=1);

namespace App\Domain\Channels\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Şifreli kanal kimlik bilgisi.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 016 ve §11.
 *
 * encrypted_payload ASLA düz metin tutmaz ve ASLA loglanmaz.
 * Okuma ve yazma yalnızca CredentialVault üzerinden yapılır.
 *
 * @property string $id
 * @property string $encrypted_payload
 * @property int $key_version
 */
class ChannelCredential extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'channel_connection_id',
        'encrypted_payload',
        'key_version',
        'scope',
        'expires_at',
        'refreshed_at',
        'revoked_at',
    ];

    /** Şifreli yük hiçbir serileştirmede görünmez. */
    protected $hidden = ['encrypted_payload'];

    protected function casts(): array
    {
        return [
            'key_version' => 'integer',
            'expires_at' => 'datetime',
            'refreshed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
