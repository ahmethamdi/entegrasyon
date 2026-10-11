<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox yeniden deneme takvimi + sipariş bekleyen olay (mimari denetim P0-1, P0-6).
 *
 *   next_attempt_at     — mesaj bu andan ÖNCE yeniden işlenmez. NULL = hemen.
 *                         Önceden beş deneme saniyeler içinde biterdi: kısa
 *                         bir DB kesintisi siparişi kalıcı `failed` yapardı.
 *   awaiting_order_ref  — sipariş henüz gelmediği için ERTELENMİŞ iptal/iade
 *                         olayının beklediği kanal sipariş kimliği. Sipariş
 *                         alınınca bu kolondan bulunup HEMEN uyandırılır;
 *                         önceden böyle bir olay "işlendi" sayılıp kaybolurdu.
 *
 * GERİYE UYUMLU: iki kolon da boş doğar ve boş = bugünkü davranış. Eski
 * kodla çalışan bir işçi (deploy sırasında) kolonları hiç okumaz; ertelenmiş
 * bir mesajı erken alırsa yeni kod onu yine erteler — zararsız.
 * Geri alma: kolonlar düşer; ertelenmiş mesajlar `pending` kalır ve eski kod
 * onları (bugünkü gibi) "sipariş yok" diye işlenmiş sayar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table): void {
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('awaiting_order_ref')->nullable();
        });

        // Sipariş gelince bekleyen olaylar bu indeksle bulunur.
        DB::statement(<<<'SQL'
            CREATE INDEX inbox_awaiting_order_idx
                ON inbox_messages (channel_connection_id, awaiting_order_ref)
                WHERE awaiting_order_ref IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inbox_awaiting_order_idx');

        Schema::table('inbox_messages', function (Blueprint $table): void {
            $table->dropColumn(['next_attempt_at', 'awaiting_order_ref']);
        });
    }
};
