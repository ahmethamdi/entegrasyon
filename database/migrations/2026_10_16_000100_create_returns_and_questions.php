<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S4 · İade talepleri ve müşteri soruları.
 *
 * `return_requests`: pazaryerinin İADE TALEBİ (Trendyol claim, HB claim) —
 * talep başına TEK satır. Ham kanal durumu (`channel_status`) ile kanonik
 * yorum (`status`) AYRI kolonlardadır: yorum değişince ham olgu yeniden
 * çekilmeden güncellenir.
 *
 * `return_request_lines`: talebin KALEMİ. Trendyol'da bir talep kalemi
 * (claimItem) TEK BİRİMDİR (`orderLineItemId` birim kimliğidir); HB'de
 * talebin kendisi bir kalemdir ve `quantity` taşır. EKONOMİK İADE KİMLİĞİ
 * `(bağlantı, external_item_id)`: aynı iade yoklamada, uzlaştırmada,
 * tekrarda hangi yoldan gelirse gelsin AYNI satıra düşer; stok iadesinin
 * idempotens anahtarı da bu kimlikten türer (bkz. RestoreReturnedStock).
 *
 * Talep kaydı stok DEĞİLDİR: stok yalnız `restock_status = restored` ve
 * envanter defterindeki hareketle döner. "Talep var" ≠ "stok döndü".
 *
 * `channel_questions`: müşteri sorusu, soru başına TEK satır. Müşteri adı
 * ve kanal müşteri kimliği SAKLANMAZ — destek akışı için gerekmez.
 *
 * `channel_connections`: iki yeni dışarı yazma izni (ADR-010 genişlemesi).
 * Varsayılan `false` ve mevcut satırlar da `false` kalır: iade kararı ve
 * soru cevabı hiçbir kanalda canlı doğrulanmadı; satıcı açık eylemle açar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_connections', function (Blueprint $table): void {
            $table->boolean('return_decision_enabled')->default(false);
            $table->boolean('question_answer_enabled')->default(false);
        });

        Schema::create('return_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_connection_id');
            $table->string('channel_type_code', 32);

            // Kanalın talep kimliği (Trendyol claimId, HB claim number).
            $table->string('external_claim_id', 64);
            $table->string('external_order_number', 64)->nullable();
            // Talebin ait olduğu GİDEN paket (Trendyol orderOutboundPackageId).
            $table->string('external_package_id', 64)->nullable();
            $table->uuid('order_id')->nullable();
            // matched | order_missing | ambiguous | partial
            $table->string('match_status', 16);

            // Kanonik durum (ReturnStatus) — kalemlerden türer.
            $table->string('status', 24);
            // Talep düzeyinde ham durum özeti (kalem durumları, ör. "WaitingInAction").
            $table->string('channel_status', 64)->nullable();
            $table->string('claim_type', 64)->nullable();

            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('remote_modified_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')->on('channel_connections')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->unique(['channel_connection_id', 'external_claim_id'], 'return_requests_claim_unique');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('return_request_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('return_request_id');
            $table->uuid('channel_connection_id');

            // EKONOMİK İADE KİMLİĞİ: Trendyol claimItem.id, HB claim number.
            $table->string('external_item_id', 64);
            // Kanalın sipariş kalemi kimliği (Trendyol orderLine.id) — eşleşme için.
            $table->string('external_order_line_id', 64)->nullable();
            $table->string('sku', 128)->nullable();
            $table->string('barcode', 128)->nullable();
            $table->string('product_name', 255)->nullable();
            $table->unsignedInteger('quantity');

            $table->uuid('order_line_id')->nullable();
            $table->uuid('variant_id')->nullable();
            // matched | order_missing | line_missing | ambiguous
            $table->string('match_status', 16);

            $table->string('status', 24);
            $table->string('channel_status', 64)->nullable();
            // Kanalın ham sebep kodu / metni ve müşteri açıklaması.
            $table->string('reason_code', 64)->nullable();
            $table->string('reason_text', 255)->nullable();
            $table->text('customer_note')->nullable();
            // Kanalın "kim kabul etti" bilgisi (Trendyol acceptDetail / autoAccepted).
            $table->string('accept_detail', 32)->nullable();

            // Fiziksel varış kanıtı: kalem kanalın "depoya ulaştı" durumunda
            // GÖRÜLDÜ ya da kanalın denetim kaydında o durum var.
            $table->timestampTz('receipt_proven_at')->nullable();
            // not_applicable | pending | restored | blocked
            $table->string('restock_status', 16)->default('not_applicable');
            $table->string('restock_reason_code', 48)->nullable();
            // Stoğu geri getiren iade olayı (order_events) — kanıt.
            $table->uuid('restock_order_event_id')->nullable();
            $table->timestampTz('restocked_at')->nullable();

            // Satıcı kararı (onay/red): kanala istek bir kez gönderilir.
            $table->string('decision', 16)->nullable();
            // pending | submitted | failed | remote_state_unknown
            $table->string('decision_status', 24)->nullable();
            $table->string('decision_reason_code', 48)->nullable();
            $table->string('decision_issue_reason_id', 16)->nullable();
            $table->string('decision_description', 500)->nullable();
            $table->text('decision_error')->nullable();
            $table->timestampTz('decision_requested_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('return_request_id')->references('id')->on('return_requests')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')->on('channel_connections')->cascadeOnDelete();
            $table->foreign('order_line_id')->references('id')->on('order_lines')->nullOnDelete();
            $table->unique(['channel_connection_id', 'external_item_id'], 'return_request_lines_item_unique');
            $table->index(['tenant_id', 'restock_status']);
        });

        Schema::create('channel_questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_connection_id');
            $table->string('channel_type_code', 32);

            $table->string('external_question_id', 64);
            // Ürün referansı (Trendyol productMainId) ve adı.
            $table->string('product_ref', 128)->nullable();
            $table->string('product_name', 255)->nullable();
            $table->text('body');
            $table->timestampTz('asked_at')->nullable();
            $table->timestampTz('expires_at')->nullable();

            // Kanonik durum (QuestionStatus) ve ham kanal durumu.
            $table->string('status', 24);
            $table->string('channel_status', 32)->nullable();

            // Kanalda yayında olan cevap (kanaldan okunur).
            $table->text('answer_body')->nullable();
            $table->string('answer_external_id', 64)->nullable();
            $table->timestampTz('answered_at')->nullable();
            // Kanalın reddettiği son cevap ve sebebi.
            $table->string('rejected_answer_external_id', 64)->nullable();
            $table->string('rejected_answer_reason', 255)->nullable();

            // 34Pazar'dan gönderilen cevap: istek bir kez gider.
            $table->text('submitted_answer')->nullable();
            $table->string('submitted_answer_external_id', 64)->nullable();
            // pending | submitted | failed | rejected | remote_state_unknown
            $table->string('answer_status', 24)->nullable();
            $table->string('answer_reason_code', 48)->nullable();
            $table->text('answer_error')->nullable();
            $table->timestampTz('answer_requested_at')->nullable();
            $table->uuid('answered_by')->nullable();

            $table->timestampTz('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')->on('channel_connections')->cascadeOnDelete();
            $table->unique(['channel_connection_id', 'external_question_id'], 'channel_questions_unique');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_questions');
        Schema::dropIfExists('return_request_lines');
        Schema::dropIfExists('return_requests');

        Schema::table('channel_connections', function (Blueprint $table): void {
            $table->dropColumn(['return_decision_enabled', 'question_answer_enabled']);
        });
    }
};
