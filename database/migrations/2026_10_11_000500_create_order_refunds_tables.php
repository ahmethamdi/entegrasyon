<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * İade / para iadesi finans olayları (ADR-002, ADR-005 ile uyumlu).
 *
 * Bugüne kadar iade yalnız ADET sayacıydı (`order_lines.quantity_returned`) ve
 * stok içindi; geri ödenen PARA hiçbir yerde yoktu. Sipariş durumuna da
 * gömülmez (durum "ne oldu", bu tablo "ne kadar para döndü").
 *
 * order_refunds
 *   kind              refund (para iadesi; kalemli ya da kalemsiz)
 *                     return (adet bazlı iade; tutarı kanal bildirmedi)
 *   amount            müşteriye dönen toplam; NULL = BİLİNMİYOR
 *   shipping_amount   bunun kargo iadesi kısmı (biliniyorsa)
 *   idempotency_key   kanal iade kimliğinden (`refund:{id}`) ya da olay
 *                     kimliğinden; (tenant, order, key) TEKİL → tekrar gelen
 *                     webhook / kümülatif Woo olayı ikinci kayıt açmaz
 * order_refund_lines  kalem, adet, tutar, stoğa döndü mü
 *
 * Ekleme-yalnız: tutar ve tür değiştirilemez (tetikleyici); düzeltme yeni kayıt.
 * Geri doldurma yok — geçmiş iadelerin tutarı bilinmiyor. Geri alma: tablolar düşer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');
            $table->uuid('channel_connection_id');

            $table->string('idempotency_key');
            $table->string('external_refund_id')->nullable();
            $table->string('kind', 16);                       // refund | return
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('shipping_amount', 14, 2)->nullable();
            $table->char('currency', 3);
            $table->string('status', 64)->nullable();
            $table->string('reason', 500)->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamp('occurred_at');
            $table->uuid('inbox_message_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')->on('channel_connections')->cascadeOnDelete();
            $table->foreign('inbox_message_id')->references('id')->on('inbox_messages')->nullOnDelete();

            $table->unique(['tenant_id', 'order_id', 'idempotency_key'], 'order_refunds_idempotent');
            $table->index(['tenant_id', 'occurred_at']);
        });

        DB::statement("ALTER TABLE order_refunds ADD CONSTRAINT order_refunds_kind_check CHECK (kind IN ('refund', 'return'))");
        DB::statement('ALTER TABLE order_refunds ADD CONSTRAINT order_refunds_amount_check CHECK (amount IS NULL OR amount >= 0)');

        Schema::create('order_refund_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_refund_id');
            $table->uuid('order_line_id')->nullable();
            $table->string('external_line_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('amount', 14, 2)->nullable();
            $table->boolean('restock')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('order_refund_id')->references('id')->on('order_refunds')->cascadeOnDelete();
            $table->foreign('order_line_id')->references('id')->on('order_lines')->nullOnDelete();

            $table->index('order_refund_id');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION order_refunds_append_only() RETURNS trigger AS $$
            BEGIN
                IF NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.shipping_amount IS DISTINCT FROM OLD.shipping_amount
                    OR NEW.kind IS DISTINCT FROM OLD.kind
                    OR NEW.currency IS DISTINCT FROM OLD.currency THEN
                    RAISE EXCEPTION 'order_refunds kaydı değiştirilemez; düzeltme yeni kayıtla yapılır';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_refunds_append_only
                BEFORE UPDATE ON order_refunds
                FOR EACH ROW EXECUTE FUNCTION order_refunds_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_refund_lines');
        Schema::dropIfExists('order_refunds');
        DB::unprepared('DROP FUNCTION IF EXISTS order_refunds_append_only() CASCADE');
    }
};
