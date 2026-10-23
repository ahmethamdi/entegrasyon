<?php

declare(strict_types=1);

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Support\AiReply;

/**
 * Yapılandırılmış çıktı veren dil modeli (S8).
 *
 * Arayüz NEDEN VAR: Anthropic SDK'sı kendi HTTP istemcisiyle çıkar ve
 * `Http::preventStrayRequests()` onu YAKALAMAZ (Stripe ile aynı durum).
 * Testler `FakeLanguageModel` bağlar; gerçek istemci testte hiç kurulmaz.
 */
interface LanguageModel
{
    /**
     * @param  list<array<string, mixed>>  $content  kullanıcı içerik blokları (metin, görsel)
     * @param  array<string, mixed>  $schema  yanıtın JSON şeması
     *
     * @throws AiUnavailable ağ/sağlayıcı hatası — kota harcanmaz
     */
    public function complete(string $model, string $system, array $content, array $schema, string $effort = 'low'): AiReply;
}
