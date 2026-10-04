<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ödeme öncesi onaylar — mesafeli hizmet sözleşmesi madde 9 ve cayma
 * hakkı istisnası (Mesafeli Sözleşmeler Yönetmeliği m.15/1-ğ,h).
 *
 * Kanıt yükü SATICIDADIR: "müşteri onayladı" diyebilmek için kim, ne
 * zaman, hangi metin sürümüne, hangi seçimle onay verdi kayıtlı olmalı.
 * Kayıt SİLİNMEZ ve GÜNCELLENMEZ; her ödeme denemesi yeni satırdır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->string('plan_code');
            $table->string('terms_version');
            $table->timestamp('terms_accepted_at');
            $table->boolean('withdrawal_waived');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_consents');
    }
};
