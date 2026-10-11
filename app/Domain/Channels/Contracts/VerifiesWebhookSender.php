<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

/**
 * Webhook'u GÖNDEREN hesabın, adresi kullanılan bağlantıya ait olduğunu
 * doğrulama yeteneği (mimari denetim P0-5).
 *
 * İmza tek başına yetmez: uygulama düzeyinde TEK sırla imzalanan kanallarda
 * (Shopify uygulaması) A mağazasının geçerli imzalı webhook'u B mağazasının
 * `/webhooks/{bağlantı}` adresine gönderilirse imza TUTAR ve sipariş yanlış
 * kiracıya işlenirdi. Bu kapı imzadan SONRA, kayıttan ÖNCE sorulur;
 * eşleşmezse 401 ve kayıt yok.
 *
 * Başlık YOKSA da reddedilir (güvenli taraf).
 */
interface VerifiesWebhookSender
{
    /** @param  array<string, array<int, string|null>>  $headers */
    public function webhookSenderMatches(array $headers): bool;
}
