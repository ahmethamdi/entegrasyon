<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\User;

/**
 * Platform yöneticisi (34Pazar'ı işleten) kim?
 *
 * Rol kolonu DEĞİL, sunucu ayarındaki e-posta listesi
 * (`SUPER_ADMIN_EMAILS`, boşsa `HORIZON_ADMIN_EMAILS`). Gerekçe: yönetici
 * yetkisi panelden ya da bir veri hatasıyla kazanılamamalı; yalnız sunucuya
 * erişen kişi listeyi değiştirebilir.
 *
 * DOĞRULANMAMIŞ E-POSTA GİREMEZ: listedeki adresle biri kayıt olup
 * doğrulamadan girmeye çalışırsa (adresin sahibi o değilse) reddedilir —
 * `viewHorizon` kapısıyla aynı kural.
 */
final class SuperAdmin
{
    /** @return list<string> */
    public static function emails(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $email): string => mb_strtolower(trim($email)),
            explode(',', (string) config('entegrasyon.super_admin_emails', '')),
        )));
    }

    public static function is(?User $user): bool
    {
        return $user !== null
            && $user->hasVerifiedEmail()
            && in_array(mb_strtolower((string) $user->email), self::emails(), true);
    }
}
