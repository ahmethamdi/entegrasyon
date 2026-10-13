<?php

declare(strict_types=1);

namespace App\Domain\Questions\Models;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Questions\Enums\QuestionReason;
use App\Domain\Questions\Enums\QuestionStatus;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pazaryeri müşteri sorusu — soru başına TEK satır (S4).
 *
 * CEVAP DURUMU (`answer_status`) kanal durumundan AYRIDIR:
 *   pending = istek kuyrukta / gitmek üzere
 *   submitted = kanal isteği kabul etti; YAYINDA olduğu ayrıca görülür
 *               (kanal durumu ANSWERED) — "kuyruğa aldık" ≠ "cevaplandı"
 *   rejected = kanal cevabı reddetti (soru yeniden cevap bekler)
 *   failed = gönderilemedi — yeniden denenebilir
 *   remote_state_unknown = gitmiş olabilir; kör tekrar YOK
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $channel_connection_id
 * @property string $channel_type_code
 * @property string $external_question_id
 * @property string $body
 * @property string $status
 * @property string|null $channel_status
 * @property string|null $answer_status
 * @property string|null $answer_reason_code
 * @property string|null $submitted_answer
 * @property string|null $submitted_answer_external_id
 * @property Carbon|null $answer_requested_at
 */
class ChannelQuestion extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const ANSWER_PENDING = 'pending';

    public const ANSWER_SUBMITTED = 'submitted';

    public const ANSWER_REJECTED = 'rejected';

    public const ANSWER_FAILED = 'failed';

    public const ANSWER_UNKNOWN = 'remote_state_unknown';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'asked_at' => 'datetime',
            'expires_at' => 'datetime',
            'answered_at' => 'datetime',
            'answer_requested_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    public function statusEnum(): QuestionStatus
    {
        return QuestionStatus::tryFrom($this->status) ?? QuestionStatus::UNKNOWN;
    }

    public function answerReason(): ?QuestionReason
    {
        return $this->answer_reason_code === null ? null : QuestionReason::tryFrom($this->answer_reason_code);
    }

    /** Cevap isteği yolda ya da sonucu belirsiz — yeni cevap gönderilmez. */
    public function answerInFlight(): bool
    {
        return in_array($this->answer_status, [self::ANSWER_PENDING, self::ANSWER_SUBMITTED, self::ANSWER_UNKNOWN], true);
    }

    /**
     * CEVAPSIZ SORU (pano sayacı): kanalda cevap bekliyor VE 34Pazar'dan
     * kanala ulaşmış bir cevap yok. Gönderilmiş (`submitted`) ya da yolda
     * (`pending`) cevap sayılmaz — satıcı işini yaptı; sonucu belirsiz
     * (`remote_state_unknown`) cevap SAYILIR: satıcının bakması gerekir.
     *
     * @param  Builder<ChannelQuestion>  $query
     * @return Builder<ChannelQuestion>
     */
    public function scopeUnanswered(Builder $query): Builder
    {
        return $query->where('status', QuestionStatus::AWAITING_ANSWER->value)
            ->where(fn (Builder $q) => $q->whereNull('answer_status')
                ->orWhereNotIn('answer_status', [self::ANSWER_PENDING, self::ANSWER_SUBMITTED]));
    }
}
