<?php

declare(strict_types=1);

namespace App\Domain\Ai\Clients;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Domain\Ai\Contracts\LanguageModel;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Support\AiReply;
use Illuminate\Support\Facades\Log;

/**
 * Claude Messages API — resmî PHP SDK'sı (S8).
 *
 * YAPILANDIRILMIŞ ÇIKTI (`outputConfig.format = json_schema`): yanıt
 * şemaya uyan JSON gelir; metinden JSON ayıklamaya çalışılmaz.
 *
 * SİSTEM TALİMATI ÖNBELLEKLENİR (`cacheControl`): kanal kuralları ve
 * görev tarifi her çağrıda aynıdır, ürün verisi kullanıcı mesajındadır —
 * değişken içerik önbellek sınırının ARKASINDA kalır.
 *
 * RET YEDEĞİ (`fallbacks: default`): model politika gereği reddederse
 * sunucu aynı isteği kendi tanımlı yedek modelde dener; yedeği olmayan
 * modelde istek olduğu gibi kalır. Ürün metninde ret beklenmez ama olursa
 * satıcı boş ekran yerine sonuç görür.
 */
final class AnthropicLanguageModel implements LanguageModel
{
    private ?Client $client = null;

    public function complete(string $model, string $system, array $content, array $schema, string $effort = 'low'): AiReply
    {
        try {
            $message = $this->client()->beta->messages->create(
                maxTokens: 8000,
                messages: [['role' => 'user', 'content' => $content]],
                model: $model,
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                outputConfig: [
                    'effort' => $effort,
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException $e) {
            Log::error('ai.auth_failed', ['error' => $e->getMessage()]);

            throw new AiUnavailable(__('AI anahtarı geçersiz; 34Pazar ekibine haber verildi.'), 'auth');
        } catch (RateLimitException $e) {
            throw new AiUnavailable(__('AI şu an yoğun; bir dakika sonra tekrar dene.'), 'rate_limit');
        } catch (BadRequestException $e) {
            Log::error('ai.bad_request', ['model' => $model, 'error' => $e->getMessage()]);

            throw new AiUnavailable(__('AI isteği reddedildi; 34Pazar ekibine haber verildi.'), 'bad_request');
        } catch (APIStatusException $e) {
            throw new AiUnavailable(__('AI hizmetine ulaşılamadı; biraz sonra tekrar dene.'), 'status_'.$e->status);
        } catch (APIConnectionException $e) {
            throw new AiUnavailable(__('AI hizmetine ulaşılamadı; biraz sonra tekrar dene.'), 'connection');
        }

        $text = '';

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        $data = null;

        if (in_array($message->stopReason, ['end_turn', 'stop_sequence'], true)) {
            $decoded = json_decode($text, true);
            $data = is_array($decoded) ? $decoded : null;
        }

        return new AiReply(
            data: $data,
            model: (string) $message->model,
            stopReason: $message->stopReason,
            inputTokens: (int) $message->usage->inputTokens,
            outputTokens: (int) $message->usage->outputTokens,
            cacheReadTokens: (int) ($message->usage->cacheReadInputTokens ?? 0),
            cacheWriteTokens: (int) ($message->usage->cacheCreationInputTokens ?? 0),
        );
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: (string) config('entegrasyon.ai.api_key'),
            requestOptions: ['timeout' => (float) config('entegrasyon.ai.timeout_seconds', 60), 'maxRetries' => 1],
        );
    }
}
