<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\WelcomeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hoş geldin postasını EN FAZLA BİR KEZ gönderir.
 *
 * TEKİLLİK KOLONLA, OLAYLA DEĞİL: `Verified` olayı bir kez atılır ama aynı
 * kullanıcı Shopify kurulumundan da doğrulanmış gelebilir; iki yol da bu
 * sınıfı çağırır. `welcome_sent_at IS NULL` koşullu UPDATE yarışı tek
 * kazanana indirir — iki eşzamanlı istek iki posta göndermez.
 *
 * YALNIZ HESAP SAHİBİ: davetle katılan ekip üyesi (depo, salt okunur) için
 * "kanalını bağla" adımları anlamsızdır; davet postası zaten gitti.
 *
 * POSTA HATASI DOĞRULAMAYI BOZMAZ: gönderim düşerse damga geri alınır ve
 * hata günlüğe yazılır; kullanıcı doğrulama sonrası panele girer.
 */
final class SendWelcomeMail
{
    public function __invoke(User $user): void
    {
        if (! $user->hasVerifiedEmail() || ! $this->ownsATenant($user)) {
            return;
        }

        $claimed = DB::table('users')
            ->where('id', $user->getKey())
            ->whereNull('welcome_sent_at')
            ->update(['welcome_sent_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        try {
            $user->notify(new WelcomeNotification);
        } catch (Throwable $e) {
            DB::table('users')->where('id', $user->getKey())->update(['welcome_sent_at' => null]);
            Log::warning('welcome_mail.failed', ['user_id' => $user->getKey(), 'error' => $e->getMessage()]);
        }
    }

    private function ownsATenant(User $user): bool
    {
        return DB::table('tenant_users')
            ->where('user_id', $user->getKey())
            ->where('role', 'owner')
            ->exists();
    }
}
