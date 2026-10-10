<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kesilen faturanın PDF'inin siparişin kanalına yüklenme durumu.
 *
 * `upload_status` NULL = kanal fatura dosyası almıyor ya da fatura henüz
 * kesilmedi; yüklenecek bir şey yok. pending → sent | failed.
 *
 * AYRI KOLONLAR (`status`/`attempts`/`error` değil): fatura KESİLDİ ve
 * resmîdir; yüklemenin başarısızlığı onu "kesilemedi" yapamaz. Aynı
 * kolonlar paylaşılsaydı "Trendyol'a tekrar yükle" düğmesi kesimi yeniden
 * denetir ve ikinci bir resmî belge açılabilirdi (`RequestInvoice::retry`).
 *
 * PDF'in kendisi BURADA YOKTUR: yükleme anında entegratörden taze
 * bağlantıyla indirilir ve bellekte kanala gider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('upload_status', 16)->nullable();
            $table->unsignedSmallInteger('upload_attempts')->default(0);
            $table->text('upload_error')->nullable();
            $table->timestampTz('uploaded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['upload_status', 'upload_attempts', 'upload_error', 'uploaded_at']);
        });
    }
};
