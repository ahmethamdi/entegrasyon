<?php

declare(strict_types=1);

namespace App\Domain\Ai\Support;

/**
 * Jeton başına maliyet — mikro-dolar (1 USD = 1.000.000).
 *
 * Anthropic birinci taraf fiyatları, milyon jeton başına USD (Ekim 2026):
 * Haiku 5.5 0,10/0,50 · Sonnet 5.5 2/10 · Opus 5.5 4/20. Önbellek okuma
 * girdinin %10'u, önbellek yazma %125'i. Bilinmeyen model en pahalı
 * (Opus) fiyatından yazılır: maliyet olduğundan DÜŞÜK görünmesin.
 *
 * Kayıt İÇ izleme içindir; satıcıya dolar gösterilmez.
 */
final class AiPricing
{
    /** @var array<string, array{0: float, 1: float}> girdi, çıktı — USD / milyon jeton */
    private const PRICES = [
        'claude-haiku-5-5' => [0.10, 0.50],
        'claude-sonnet-5-5' => [2.00, 10.00],
        'claude-opus-5-5' => [4.00, 20.00],
    ];

    public static function microUsd(AiReply $reply, string $requestedModel): int
    {
        [$in, $out] = self::PRICES[$reply->model] ?? self::PRICES[$requestedModel] ?? self::PRICES['claude-opus-5-5'];

        // USD / milyon jeton = mikro-dolar / jeton.
        return (int) round(
            $reply->inputTokens * $in
            + $reply->outputTokens * $out
            + $reply->cacheReadTokens * $in * 0.10
            + $reply->cacheWriteTokens * $in * 1.25
        );
    }
}
