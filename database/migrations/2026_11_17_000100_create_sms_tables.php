<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMS bildirimleri — satıcının kendi Netgsm hesabı.
 *
 * `sms_accounts` kiracı başına TEK satırdır. Kimlik (kullanıcı adı +
 * şifre) ve satıcının bildirim numarası ŞİFRELİDİR (`encrypted` cast).
 *
 * `sms_messages` gönderim kaydıdır: numara yalnız MASKELİ durur
 * (90532•••4455), alıcının adı/adresi hiç yazılmaz.
 *
 * `sms_event_keys` TEKRAR GÖNDERİMİ ÖNLEYEN ÇIPADIR: aynı olay (aynı
 * sipariş, aynı gün aynı ürünün tükenmesi, aynı kargo) için ikinci SMS
 * gitmez. Netgsm'in kendi tekilleştirmesi yoktur; çıpa gönderimden ÖNCE
 * yazılır, böylece iki eşzamanlı tur aynı olayı iki kez göndermez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // netgsm — ileride başka sağlayıcı aynı tabloya girer.
            $table->string('provider', 16);

            // live | test (Netgsm'in herkese açık test ortamı)
            $table->string('environment', 8)->default('live');

            // {username, password} ŞİFRELİ; Inertia'ya ve log'a düşmez.
            $table->text('credentials');

            // Seçili gönderici başlığı + bağlanırken okunan onaylı başlıklar.
            $table->string('sender', 35)->nullable();
            $table->jsonb('senders')->default('[]');

            // connected | revoked (sağlayıcı kimliği reddetti)
            $table->string('status', 16);
            $table->text('last_error')->nullable();
            $table->timestampTz('verified_at')->nullable();

            // Satıcının bildirim numarası ŞİFRELİ (kişisel veri).
            $table->text('notify_phone')->nullable();

            // Açık bildirim türleri: new_order, stock_out, channel_problem, buyer_shipped
            $table->jsonb('events')->default('[]');
            $table->boolean('quiet_hours')->default(true);

            // Bundan önceki olaylar için SMS gitmez (bağlanınca geçmiş
            // siparişler toplu SMS'e dönüşmesin).
            $table->timestampTz('notify_since')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique('tenant_id', 'sms_accounts_tenant_unique');
        });

        Schema::create('sms_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // new_order | stock_out | channel_problem | buyer_shipped | test
            $table->string('kind', 24);
            $table->string('recipient_masked', 32);
            $table->text('body');

            // sent | failed | skipped
            $table->string('status', 12);
            $table->string('provider_job_id', 64)->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('event_count')->default(1);

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('sms_event_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id');
            $table->string('event_key', 120);
            $table->uuid('sms_message_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'event_key'], 'sms_event_keys_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_event_keys');
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('sms_accounts');
    }
};
