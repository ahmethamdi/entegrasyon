<?php

declare(strict_types=1);

namespace App\Domain\Ai\Support;

/**
 * AI listeleme açık mı — anahtar VE bayrak (S8).
 *
 * Tek kapı: panel düğmeleri (paylaşılan `features.aiListing`), rotalar
 * (404) ve eylemler buna bakar. Anahtar yokken özellik hiç görünmez.
 */
final class AiFeature
{
    public static function enabled(): bool
    {
        return (bool) config('entegrasyon.ai.enabled')
            && trim((string) config('entegrasyon.ai.api_key')) !== '';
    }
}
