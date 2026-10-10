<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faturaya işlenen tahsilatın entegratördeki kimliği.
 *
 * Satış faturası açıldıktan sonra kanal → kasa/banka eşlemesi varsa
 * tahsilat da işlenir (pazar yeri parayı tahsil etmiştir; fatura "açık
 * hesap" kalsaydı satıcının cari bakiyesi her siparişte şişerdi).
 *
 * ⚠️ KİMLİK ADIM BİTER BİTMEZ YAZILIR (`provider_contact_id` /
 * `provider_invoice_id` deseni): iş tahsilattan sonra ölürse yeniden deneme
 * bu kolonu dolu görür ve İKİNCİ tahsilat açmaz. Sonda yazılsaydı aynı
 * siparişin parası satıcının kasasına iki kez girmiş görünürdü.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('provider_payment_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('provider_payment_id');
        });
    }
};
