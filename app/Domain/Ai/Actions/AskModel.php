<?php

declare(strict_types=1);

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\Contracts\LanguageModel;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Models\AiUsage;
use App\Domain\Ai\Support\AiFeature;
use App\Domain\Ai\Support\AiPricing;
use App\Domain\Ai\Support\AiReply;
use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Exceptions\QuotaExceededException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Tek model çağrısı — kapı, kota ve kayıt TEK YERDE (S8).
 *
 * Sıra: özellik açık mı → aylık hak var mı → çağrı → `ai_usage` satırı.
 * Çağrı yapılamazsa satır `error` yazılır ve kotaya SAYILMAZ; yanıt geldiyse
 * (ret dahil) jeton harcanmıştır ve sayılır. Kayıt çağrıdan SONRA yazılır:
 * önce yazılsaydı zaman aşımına uğrayan istek kotayı boşuna yerdi.
 */
final class AskModel
{
    public function __construct(
        private readonly LanguageModel $model,
        private readonly EnforceQuota $quota,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     * @return array{0: AiReply, 1: AiUsage}
     *
     * @throws QuotaExceededException
     * @throws AiUnavailable
     */
    public function run(string $kind, string $model, string $system, array $content, array $schema, string $effort = 'low'): array
    {
        if (! AiFeature::enabled()) {
            throw new RuntimeException('AI listeleme kapalı (anahtar yok).');
        }

        $this->quota->check(QuotaMetric::AI_SUGGESTIONS);

        try {
            $reply = $this->model->complete($model, $system, $content, $schema, $effort);
        } catch (AiUnavailable $e) {
            AiUsage::query()->create([
                'user_id' => Auth::id(),
                'kind' => $kind,
                'model' => $model,
                'status' => AiUsage::STATUS_ERROR,
                'error' => mb_substr($e->reason, 0, 250),
            ]);

            throw $e;
        }

        $usage = AiUsage::query()->create([
            'user_id' => Auth::id(),
            'kind' => $kind,
            'model' => $reply->model !== '' ? $reply->model : $model,
            'status' => $reply->refused() ? 'refusal' : ($reply->data === null ? 'invalid' : 'ok'),
            'input_tokens' => $reply->inputTokens,
            'output_tokens' => $reply->outputTokens,
            'cache_read_tokens' => $reply->cacheReadTokens,
            'cache_write_tokens' => $reply->cacheWriteTokens,
            'cost_micro_usd' => AiPricing::microUsd($reply, $model),
            'error' => $reply->data === null ? mb_substr((string) $reply->stopReason, 0, 250) : null,
        ]);

        return [$reply, $usage];
    }
}
