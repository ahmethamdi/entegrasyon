<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S6 · Kargo II — satıcının kendi Yurtiçi / Aras sözleşmesiyle gönderi.
 *
 * `carrier_accounts` kiracı + firma başına TEK satırdır. Kanal bağlantısı
 * DEĞİLDİR (fatura hesabıyla aynı gerekçe): kanal kotasına, yetenek
 * haritasına ve devre kesicisine karışmaz.
 *
 * `carrier_shipments` sipariş başına TEK satırdır: çift tıklama, iki sekme
 * ya da ikinci toplu işlem aynı siparişe ikinci gönderi açamaz. ALICININ
 * KİŞİSEL VERİSİ SÜTUN OLARAK YOKTUR — ad/adres/telefon gönderi açılırken
 * kanaldan ANLIK okunur ve firmaya gider. Etiket belgesi (adres basılı)
 * kargo belgesi olarak saklanır ama diskte ŞİFRELİDİR (model `encrypted`
 * cast'i; App Store "encrypt at rest" sözü).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // yurtici | aras
            $table->string('provider', 16);

            // live | test — test, firmanın herkese açık test sunucusudur.
            $table->string('environment', 8)->default('live');

            // Kullanıcı adı + şifre ŞİFRELİ (`encrypted:array`); Inertia'ya
            // ve log'a asla düşmez.
            $table->text('credentials');

            // connected | revoked (firma kimliği reddetti)
            $table->string('status', 16);
            $table->text('last_error')->nullable();
            $table->timestampTz('verified_at')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'provider'], 'carrier_accounts_tenant_provider_unique');
        });

        Schema::create('carrier_shipments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');
            $table->string('provider', 16);
            $table->string('environment', 8);

            // Firmaya giden BİZİM anahtarımız (Yurtiçi cargoKey, Aras
            // IntegrationCode). Satır açılırken yazılır; aynı anahtarla
            // tekrar gönderim firmada ikinci gönderi açmaz.
            $table->string('cargo_key', 32);

            // pending → creating → created → shipped → delivered
            //                    ↘ failed        created → cancelling → cancelled
            $table->string('status', 16);
            $table->string('reason_code', 48)->nullable();
            $table->text('error_detail')->nullable();

            // Firmanın ham durum kodu ve metni (ör. Yurtiçi NOP / "Kargo İşlem Görmemiş.")
            $table->string('carrier_status', 16)->nullable();
            $table->string('carrier_status_text')->nullable();

            $table->string('tracking_number', 64)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->unsignedSmallInteger('package_count')->default(1);

            // Etiket: firmanın verdiği ya da bizim ürettiğimiz belge.
            $table->longText('label_zpl')->nullable();
            // PDF base64 (firma veriyorsa; Aras). Yurtiçi PDF vermez.
            $table->longText('label_pdf')->nullable();

            $table->unsignedInteger('attempts')->default(0);

            // Firmaya oluşturma isteği GİTTİ ya da gitmiş olabilir.
            $table->timestampTz('create_requested_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('last_tracked_at')->nullable();

            // Kanala giden kargo bildirimi (RecordPanelShipment) — en çok bir kez.
            $table->uuid('fulfillment_id')->nullable();

            $table->uuid('requested_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->unique('order_id', 'carrier_shipments_order_unique');
            $table->unique(['provider', 'cargo_key'], 'carrier_shipments_key_unique');
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'last_tracked_at']);
        });

        Schema::table('fulfillment_batch_items', function (Blueprint $table): void {
            $table->uuid('carrier_shipment_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_batch_items', function (Blueprint $table): void {
            $table->dropColumn('carrier_shipment_id');
        });

        Schema::dropIfExists('carrier_shipments');
        Schema::dropIfExists('carrier_accounts');
    }
};
