<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Onay durumu takibi — §13 · Faz 2, §7 · SupportsApprovalWorkflow, §14.
 *
 * ONAY DURUMU LIFECYCLE'DAN AYRI BİR BİLGİ DEĞİL, ONUN BİR DEĞERİDİR:
 *   `lifecycle_status` zaten satırın kanaldaki halini taşıyor
 *   (`draft` · `blocked` · `pending_approval` · `live` · `rejected` ·
 *   `delisted`). Onay için AYRI bir durum kolonu açılsaydı iki kolon
 *   çelişebilir ve "hangisi doğru" sorusunun cevabı olmazdı. Stok
 *   fan-out'u `lifecycle_status = 'live'` bakar ve bu kural DEĞİŞMEZ —
 *   onay yalnızca o bayrağı yönetir (§14).
 *
 * RED SEBEBİ AYRI KOLONDUR VE GÖSTERİLİR:
 *   "Reddedildi" tek başına satıcıya ne düzelteceğini söylemez. Sebep
 *   `listing_sync_states.last_error` yerine burada durur: o alan SENKRON
 *   hatalarına aittir (gönderim başarısız oldu), oysa red bir senkron
 *   hatası DEĞİLDİR — gönderim başarılıydı, kanal içeriği beğenmedi.
 *   İkisi aynı alana yazılsaydı biri diğerini ezer ve "neden
 *   gönderilmedi" ile "neden yayında değil" ayırt edilemezdi.
 *
 * `approval_checked_at` NEDEN VAR:
 *   Onay turu periyodiktir. Damga olmadan hangi satırların ne zaman
 *   sorulduğu bilinemez ve panel "kontrol edildi mi yoksa hiç mi
 *   bakılmadı" sorusunu cevaplayamazdı — kullanıcı bekleyen bir ürünün
 *   unutulup unutulmadığını anlayamaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->text('approval_rejection_reason')->nullable();
            $table->timestamp('approval_checked_at')->nullable();
        });

        // Onay turunun aday sorgusu: bu bağlantıda onay bekleyen satırlar.
        // Kısmi indeks yalnızca bekleyenleri taşır; canlı katalog büyüdükçe
        // tam indeks anlamsızca şişerdi.
        DB::statement(<<<'SQL'
            CREATE INDEX listings_pending_approval_idx
                ON listings (channel_connection_id, approval_checked_at)
             WHERE lifecycle_status IN ('pending_approval', 'rejected')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS listings_pending_approval_idx');

        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn(['approval_rejection_reason', 'approval_checked_at']);
        });
    }
};
