<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Notifications\ResetPasswordNotification;
use App\Domain\Identity\Notifications\VerifyEmailNotification;
use App\Http\Middleware\SetLocale;
use App\Support\Uuid\HasUuidV7;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Kiracısız kullanıcı. Bir kullanıcı birden fazla kiracıya
 * tenant_users üzerinden bağlanabilir.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 002.
 *
 * @property string $id
 * @property string $email
 */
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    use HasFactory;
    use HasUuidV7;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Posta dili — kullanıcının panelde seçtiği dil.
     *
     * Laravel bildirimi göndermeden önce bu dile geçer. Seçim yoksa (ya da
     * İngilizce panel kapalıysa) null döner ve isteğin dili kullanılır:
     * `SetLocale` onu zaten oturumdan/tarayıcıdan çözmüştür. Parola
     * sıfırlamada istek giriş yapılmadan gelir; kayıtlı tercih yine burada
     * okunur, posta isteği yapanın tarayıcısına değil hesabın diline gider.
     */
    public function preferredLocale(): ?string
    {
        if (! config('app.english_panel')) {
            return null;
        }

        $locale = $this->getAttribute('locale');

        return is_string($locale) && in_array($locale, SetLocale::SUPPORTED, true) ? $locale : null;
    }

    /** Doğrulama e-postası (alıcının dilinde) — gerekçe bildirim sınıfında. */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /** Sıfırlama e-postası (alıcının dilinde) — gerekçe bildirim sınıfında. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->using(TenantUser::class)
            ->withPivot(['id', 'role', 'invited_at', 'accepted_at'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }
}
