<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Domain\Ai\Contracts\LanguageModel;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Support\AiReply;
use RuntimeException;

/**
 * Dil modeli taklidi — sıradaki yanıtı döner, çağrıları kaydeder, ağa ÇIKMAZ.
 *
 * TestCase her testte bunu bağlar: Anthropic SDK'sı kendi HTTP istemcisiyle
 * çıkar ve `Http::preventStrayRequests()` onu YAKALAMAZ. Kuyrukta yanıt
 * yokken çağrı testi DÜŞÜRÜR: beklenmeyen model çağrısı (ör. aday yokken)
 * sessizce geçmesin.
 */
final class FakeLanguageModel implements LanguageModel
{
    /** @var list<AiReply|AiUnavailable> */
    private array $queue = [];

    /** @var list<array{model: string, system: string, content: list<array<string, mixed>>, effort: string}> */
    public array $calls = [];

    /** @param  array<string, mixed>|null  $data */
    public function reply(?array $data, string $stopReason = 'end_turn', string $model = 'claude-haiku-5-5'): self
    {
        $this->queue[] = new AiReply($data, $model, $stopReason, inputTokens: 1200, outputTokens: 300);

        return $this;
    }

    public function fail(string $reason = 'connection'): self
    {
        $this->queue[] = new AiUnavailable('AI hizmetine ulaşılamadı; biraz sonra tekrar dene.', $reason);

        return $this;
    }

    public function complete(string $model, string $system, array $content, array $schema, string $effort = 'low'): AiReply
    {
        $this->calls[] = ['model' => $model, 'system' => $system, 'content' => $content, 'effort' => $effort];

        $next = array_shift($this->queue) ?? throw new RuntimeException('Beklenmeyen model çağrısı (FakeLanguageModel kuyruğu boş).');

        if ($next instanceof AiUnavailable) {
            throw $next;
        }

        return $next;
    }
}
