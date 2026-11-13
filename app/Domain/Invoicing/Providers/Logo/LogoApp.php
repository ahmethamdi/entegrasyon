<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Providers\Logo;

/**
 * 34Pazar'ın Logo çözüm ortağı kimliği (REST servis `ClientID`/`ClientSecret`).
 *
 * Logo belgesi: "Çözüm ortaklarımıza ClientID ve ClientSecret bilgileri
 * oluşturmaktayız. Bu bilgiler olmadan aktarım yapılamaz." Satıcının değil
 * PLATFORMUN kimliğidir; token isteğinde `Authorization: Basic
 * base64(ClientID:ClientSecret)` olarak gider. Tanımlı değilse Logo satıcıya
 * HİÇ sunulmaz (`ParasutApp::configured` deseni).
 */
final class LogoApp
{
    /** @return array{id: string, secret: string}|null */
    public static function credentials(): ?array
    {
        $id = trim((string) config('services.logo.client_id', ''));
        $secret = trim((string) config('services.logo.client_secret', ''));

        return $id !== '' && $secret !== '' ? ['id' => $id, 'secret' => $secret] : null;
    }

    public static function configured(): bool
    {
        return self::credentials() !== null;
    }
}
