<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mimari Karar Dokümanı v2.2 · §4 · Orders ve Messaging, §19 · adım 1.6.
 *
 * Üçüncü migration grubu: inbox_messages, orders, order_lines, order_events,
 * fulfillments.
 *
 * ORDER_EVENTS İKİ İŞ YAPAR (§4):
 *   1. Sipariş yaşam döngüsünün denetim kaydı
 *   2. Stok hareketlerinin IDEMPOTENCY ÇIPASI
 *
 *   Kanaldan gelen her iptal ve iade önce bir olay satırı yaratır; hareket
 *   anahtarı o satırın kimliğinden türetilir. external_ref kanalın olay
 *   kimliği olduğu için, aynı iptal ikinci kez geldiğinde olay satırı çakışır
 *   ve hareket HİÇ OLUŞMAZ. Farklı iki kısmi iade ise farklı external_ref
 *   taşıdığı için iki ayrı olay ve iki ayrı hareket üretir.
 *
 *   Tekillik KISMİDİR (WHERE external_ref IS NOT NULL): bizim ürettiğimiz
 *   olaylar (OVERSELL_DETECTED gibi) external_ref taşımaz ve tekillik onları
 *   kapsamamalıdır.
 *
 * INBOX ÇİFT TEKİLLİK İNDEKSİ (§4, §17 · P0):
 *   Birincil: (channel_connection_id, external_event_id) — gerçek olay kimliği
 *   Son çare: (channel_connection_id, payload_hash, dedupe_window) — kimlik
 *             vermeyen kanallar için saatlik pencere
 *
 *   Hash yolu bilinçli olarak son çaredir: saat sınırında bölünme riski taşır.
 *   Woo, Shopify ve Trendyol kimlik verdiği için o yol hiç çalışmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_connection_id');

            $table->string('source');                        // webhook | polling
            $table->string('external_event_id')->nullable(); // tercih edilen kimlik
            $table->string('event_type');
            $table->jsonb('payload');
            $table->string('payload_hash');

            $table->boolean('signature_valid')->default(false);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->string('status')->default('pending');    // pending | processed | failed
            $table->integer('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')
                ->on('channel_connections')->cascadeOnDelete();
        });

        // Saatlik tekilleştirme penceresi — yalnızca son çare yolunda kullanılır.
        DB::statement(<<<'SQL'
            ALTER TABLE inbox_messages
                ADD COLUMN dedupe_window timestamptz
                GENERATED ALWAYS AS (date_trunc('hour', received_at)) STORED
        SQL);

        // BİRİNCİL tekilleştirme: gerçek olay kimliği.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX inbox_event_id_unique
                ON inbox_messages (channel_connection_id, external_event_id)
                WHERE external_event_id IS NOT NULL
        SQL);

        // SON ÇARE: olay kimliği vermeyen kanallar için hash + saatlik pencere.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX inbox_hash_unique
                ON inbox_messages (channel_connection_id, payload_hash, dedupe_window)
                WHERE external_event_id IS NULL
        SQL);

        // İşlenecekler ve kurtarma taraması (RecoverPendingInbox).
        DB::statement(<<<'SQL'
            CREATE INDEX inbox_pending_idx
                ON inbox_messages (received_at)
                WHERE status = 'pending'
        SQL);

        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_connection_id');

            $table->string('external_id');
            $table->string('external_number')->nullable();
            $table->string('status')->default('pending');
            $table->string('financial_status')->nullable();
            $table->string('currency', 3)->default('TRY');

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('shipping_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);

            $table->timestamp('placed_at')->nullable();

            // Kişisel veri: maskelenerek saklanır (§11).
            $table->jsonb('customer_ref')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')
                ->on('channel_connections')->cascadeOnDelete();

            // Sipariş alımının idempotency çıpası: ON CONFLICT DO NOTHING
            // bu kısıta dayanır.
            $table->unique(['channel_connection_id', 'external_id'], 'orders_external_unique');

            $table->index(['tenant_id', 'placed_at']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');

            // Eşleşmemiş SKU: sipariş yine kaydedilir, stok düşülmez.
            // Sipariş kaybetmek, stok tutarsızlığından daha kötüdür.
            $table->uuid('variant_id')->nullable();

            $table->string('external_line_id');
            $table->string('sku');
            $table->string('title');

            $table->integer('quantity');
            $table->integer('quantity_cancelled')->default(0);
            $table->integer('quantity_returned')->default(0);
            $table->integer('quantity_fulfilled')->default(0);

            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);

            // PENDING | APPLIED | OVERSOLD
            $table->string('stock_status')->default('PENDING');
            $table->timestamp('stock_applied_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('variants')->nullOnDelete();

            $table->unique(['order_id', 'external_line_id'], 'order_lines_external_unique');
            $table->index('variant_id');
        });

        // İptal + iade toplamı sipariş miktarını aşamaz.
        DB::statement(<<<'SQL'
            ALTER TABLE order_lines
                ADD CONSTRAINT order_lines_quantity_balance
                CHECK (quantity_cancelled + quantity_returned <= quantity)
        SQL);

        Schema::create('order_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');
            $table->uuid('order_line_id')->nullable();

            $table->string('type');
            $table->integer('quantity')->nullable();

            // Kanalın olay kimliği — idempotency çıpası.
            $table->string('external_ref')->nullable();

            $table->jsonb('payload')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('source')->default('webhook');
            $table->uuid('inbox_message_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('order_line_id')->references('id')->on('order_lines')->cascadeOnDelete();
            $table->foreign('inbox_message_id')->references('id')
                ->on('inbox_messages')->nullOnDelete();

            $table->index(['order_id', 'occurred_at']);
            $table->index(['tenant_id', 'type', 'occurred_at']);
        });

        // KISMİ tekillik: yalnızca kanaldan gelen olaylar kapsanır.
        // Bizim ürettiğimiz olaylar (OVERSELL_DETECTED) external_ref taşımaz.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX order_events_external_unique
                ON order_events (order_id, type, external_ref)
                WHERE external_ref IS NOT NULL
        SQL);

        Schema::create('fulfillments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');

            $table->string('external_id')->nullable();
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            $table->unique(['order_id', 'external_id'], 'fulfillments_external_unique');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillments');
        DB::statement('DROP INDEX IF EXISTS order_events_external_unique');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');

        DB::statement('DROP INDEX IF EXISTS inbox_pending_idx');
        DB::statement('DROP INDEX IF EXISTS inbox_hash_unique');
        DB::statement('DROP INDEX IF EXISTS inbox_event_id_unique');
        Schema::dropIfExists('inbox_messages');
    }
};
