<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

/**
 * S15 · adaptör, kanalın (ya da bilinçli kararımızın) bir yeteneği
 * SUNMADIĞINI gerekçesiyle beyan eder → matriste NOT_SUPPORTED (başarısız
 * değil). Beyan edilmeyen ve arayüzü olmayan yetenek NOT_IMPLEMENTED'dır.
 *
 * Gerekçe belgeye dayanmalı (docs/*-API-NOTLARI.md); tahminle yazılmaz.
 */
interface DeclaresUnsupportedCapabilities
{
    /** @return array<string, string> yetenek değeri (`ChannelCapability`) => gerekçe */
    public static function unsupportedCapabilities(): array;
}
