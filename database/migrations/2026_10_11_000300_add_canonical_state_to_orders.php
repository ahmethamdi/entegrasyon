<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kanonik sipariş durumu (ADR-001).
 *
 *   state             — `OrderState`; NULL = henüz eşlenmedi (geri doldurma
 *                       öncesi ya da durumu tanınmayan kanal kelimesi)
 *   state_changed_at  — son geçerli geçiş anı
 *
 * GERİYE UYUMLU: ham `status` / `financial_status` DOKUNULMAZ, silinmez.
 * Okuyucular `state` NULL iken eski ham listeye düşer (`Order::awaitingShipment`,
 * pano), yani deploy anında eski kodun yazdığı satırlar görünmez olmaz.
 * Geri doldurma: `orders:backfill-state` (idempotent, yalnız NULL satırlar).
 * Geri alma: kolonlar düşer; ham durum zaten duruyor.
 *
 * CHECK kısıtı bilinmeyen değeri reddeder — enum dışı yazım sessizce
 * yerleşmesin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('state', 32)->nullable();
            $table->timestamp('state_changed_at')->nullable();
            $table->index(['tenant_id', 'state'], 'orders_tenant_state_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE orders ADD CONSTRAINT orders_state_check CHECK (
                state IS NULL OR state IN (
                    'awaiting_payment', 'awaiting_shipment', 'shipped', 'delivered',
                    'partially_returned', 'cancelled', 'returned', 'superseded'
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_state_check');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_tenant_state_idx');
            $table->dropColumn(['state', 'state_changed_at']);
        });
    }
};
