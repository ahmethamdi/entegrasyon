<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faturanın kalıcı vekil bağlantısı — `https://34pazar.com/f/{token}.pdf` (S6).
 *
 * Hepsiburada ve n11 fatura DOSYASI almaz, bağlantı ister; Paraşüt'ün PDF
 * bağlantısı sürelidir. Token 32 rastgele bayttır (64 onaltılık): tahmin
 * edilemez, sıralı kimlik taşımaz. PDF bizde SAKLANMAZ — istek gelince
 * Paraşüt'ten taze adres alınır ve yönlendirilir.
 *
 * NULL = bağlantı hiç verilmedi. Tekil: aynı token iki faturayı açmasın.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('public_token', 64)->nullable()->unique('invoices_public_token_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_public_token_unique');
            $table->dropColumn('public_token');
        });
    }
};
