<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token yenileme geri çekilmesi (mimari denetim P0-4).
 *
 * `credentials:refresh` adayları `expires_at` sırasıyla ve tur sınırıyla
 * seçer. Kalıcı bozuk bir bağlantı (iptal edilmiş yenileme anahtarı, bozuk
 * ayar) her turda yine en başta durur; sayısı sınırı doldurursa SAĞLAM
 * kiracıların token'ları hiç sıraya giremez ve süresi dolar.
 *
 *   refresh_failures  — ardışık başarısız yenileme sayısı
 *   refresh_retry_at  — bu andan önce yeniden denenmez (NULL = hemen)
 *
 * GERİYE UYUMLU: 0 / NULL = bugünkü davranış. Eski kod kolonları okumaz.
 * Başarılı yazım (`CredentialVault::store`) ikisini de sıfırlar.
 * Geri alma: kolonlar düşer; bozuk satırlar yeniden her turda denenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_credentials', function (Blueprint $table): void {
            $table->unsignedInteger('refresh_failures')->default(0);
            $table->timestamp('refresh_retry_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channel_credentials', function (Blueprint $table): void {
            $table->dropColumn(['refresh_failures', 'refresh_retry_at']);
        });
    }
};
