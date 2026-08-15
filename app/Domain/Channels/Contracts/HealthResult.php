<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

/**
 * Bağlantı sağlık kontrolü sonucu.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · ChannelAdapter::healthCheck().
 *
 * channel_connections.health_status ve last_healthy_at alanlarını besler.
 * Sağlıksız bağlantı panelde görünür; kullanıcı token'ı yenilemeden
 * operasyonlar kalıcı hataya düşmeye devam eder.
 */
final readonly class HealthResult
{
    private function __construct(
        public bool $healthy,
        public ?string $message = null,
        public ?int $latencyMs = null,
    ) {}

    public static function healthy(?int $latencyMs = null): self
    {
        return new self(healthy: true, latencyMs: $latencyMs);
    }

    public static function unhealthy(string $message): self
    {
        return new self(healthy: false, message: $message);
    }

    public function status(): string
    {
        return $this->healthy ? 'healthy' : 'unhealthy';
    }
}
