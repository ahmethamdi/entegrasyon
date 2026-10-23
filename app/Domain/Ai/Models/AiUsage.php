<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Model çağrısı kaydı — jeton, maliyet, sonuç (S8).
 *
 * `status`: `ok` (öneri üretildi), `invalid` (yanıt geldi ama kurallardan
 * geçmedi — jeton harcandı, kotaya SAYILIR), `refusal`, `error` (çağrı
 * yapılamadı — kotaya SAYILMAZ).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $user_id
 * @property string|null $suggestion_id
 * @property string $kind
 * @property string $model
 * @property string $status
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property int $cost_micro_usd
 * @property string|null $error
 */
class AiUsage extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const UPDATED_AT = null;

    /** Kotaya sayılmayan durum: çağrı hiç yapılamadı. */
    public const STATUS_ERROR = 'error';

    protected $table = 'ai_usage';

    protected $fillable = [
        'tenant_id', 'user_id', 'suggestion_id', 'kind', 'model', 'status',
        'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens',
        'cost_micro_usd', 'error',
    ];
}
