<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S2 · Depo akışı — kargo etiketi ve toplu işlem.
 *
 * `shipping_labels`: pazaryerinin ORTAK ETİKETİ (Trendyol createCommonLabel).
 * ADR-004 etiketi `fulfillments` üzerinde öneriyordu; AYRI TABLO seçildi
 * çünkü `fulfillments` satırı "kargoya verildi" demektir ve
 * `Order::scopeAwaitingShipment` fulfillment'ı olan siparişi listeden
 * düşürür — etiketi basılan ama henüz kargolanmamış sipariş "kargo
 * bekliyor"dan kaybolurdu (ARCHITECTURE-DECISIONS ADR-004 notu).
 *
 * İŞ KİMLİĞİ = sipariş (Trendyol'da sipariş = paket). Aynı paket için
 * ikinci etiket satırı açılamaz; çift tıklama, iki sekme ve kuyruk tekrarı aynı
 * satıra düşer. Kanala istek (`POST`) satır başına bir kez gönderilir
 * (`requested_at`); sonucu belirsiz istek tekrar GÖNDERİLMEZ, yalnız
 * etiket sorulur (bkz. `PrepareShippingLabel`).
 *
 * Etiketin ASLI (`payload`, ZPL metni ya da kanalın verdiği adres) olduğu
 * gibi saklanır; depo için üretilen görünümler ondan türetilir, onu ezmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_labels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');
            $table->uuid('channel_connection_id');

            // Paket kimliği (Trendyol shipmentPackageId) ve kargo takip no.
            $table->string('package_external_id', 64);
            // Takip no bizde saklanmıyordu; iş paketi kanaldan okuyunca yazar.
            $table->string('cargo_tracking_number', 64)->nullable();
            // Kanalın verdiği ham taşıyıcı adı ("Trendyol Express Marketplace").
            $table->string('cargo_provider', 128)->nullable();

            // pending → requested → ready | failed
            $table->string('status', 16);
            // Kararlı sebep kodu (FulfillmentReason); sunum metni DEĞİL.
            $table->string('reason_code', 48)->nullable();
            // Kanalın teknik hata metni (gizlenmiş) — yalnız tanı için.
            $table->text('error_detail')->nullable();

            $table->unsignedSmallInteger('box_quantity')->default(1);
            $table->string('format', 8)->nullable();
            // Kanalın döndürdüğü etiket(ler), DOKUNULMADAN: JSON liste
            // [{format, label}] — kutu başına bir etiket.
            $table->text('payload')->nullable();
            $table->char('payload_sha256', 64)->nullable();

            $table->unsignedInteger('attempts')->default(0);
            // POST (etiket isteği) kanala gitti mi — gittiyse bir daha GÖNDERİLMEZ.
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')->on('channel_connections')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->unique('order_id', 'shipping_labels_order_unique');
        });

        Schema::create('fulfillment_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            // labels | invoice_documents | issue_invoices | picking
            $table->string('action', 32);
            // Formun tek kullanımlık anahtarı: aynı form iki kez gönderilirse
            // ikinci istek İLK toplu işi döndürür, yenisini açmaz.
            $table->string('idempotency_key', 64);
            $table->unsignedInteger('total')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'idempotency_key'], 'fulfillment_batches_idempotency_unique');
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('fulfillment_batch_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('batch_id');
            $table->uuid('order_id');
            // Kabul anındaki karar: queued (işlenecek) | succeeded |
            // action_required | failed. Kuyruktaki kalemin SON sonucu
            // kaynağından (etiket/fatura satırı) okunur — iki yerde tutulmaz.
            $table->string('outcome', 16);
            $table->string('reason_code', 48)->nullable();
            $table->uuid('shipping_label_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('batch_id')->references('id')->on('fulfillment_batches')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('shipping_label_id')->references('id')->on('shipping_labels')->nullOnDelete();
            $table->unique(['batch_id', 'order_id'], 'fulfillment_batch_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_batch_items');
        Schema::dropIfExists('fulfillment_batches');
        Schema::dropIfExists('shipping_labels');
    }
};
