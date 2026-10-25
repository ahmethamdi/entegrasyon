<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use App\Domain\Identity\Enums\TenantRole;
use App\Support\Mail\MailGreeting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Ekip daveti e-postası (S7). Alıcının henüz hesabı olmayabilir; bildirim
 * `Notification::route('mail', ...)` ile adrese gider.
 *
 * Bağlantı İKİ kez korunur: imzalı ve süreli (`signed:relative`) + içinde
 * yalnız özeti saklanan tek kullanımlık belirteç. İmza adresin
 * kurcalanmadığını, belirteç davetin hâlâ geçerli olduğunu söyler.
 *
 * Adres `url()` ile kurulur (APP_URL) — Host başlığı KULLANILMAZ, gerekçesi
 * `ResetPasswordNotification`'da.
 */
final class TeamInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
        private readonly string $tenantName,
        private readonly string $inviterName,
        private readonly TenantRole $role,
        private readonly Carbon $expiresAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function acceptUrl(): string
    {
        return url(URL::temporarySignedRoute(
            'invitations.show',
            $this->expiresAt,
            ['token' => $this->token],
            absolute: false,
        ));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':tenant seni 34Pazar\'a davet etti', ['tenant' => $this->tenantName]))
            ->greeting(MailGreeting::hello($notifiable))
            ->line(__(':inviter seni :tenant ekibine :role rolüyle davet etti.', [
                'inviter' => $this->inviterName,
                'tenant' => $this->tenantName,
                'role' => $this->role->label(),
            ]))
            ->line($this->role->description())
            ->action(__('Daveti kabul et'), $this->acceptUrl())
            ->line(__('Bu bağlantı :days gün geçerli. Hesabın yoksa açılır, varsa giriş yapman yeterli.', [
                'days' => (int) round(now()->diffInDays($this->expiresAt, true)),
            ]))
            ->line(__('Bu daveti beklemiyorsan e-postayı yok sayabilirsin.'))
            ->salutation('34Pazar');
    }
}
