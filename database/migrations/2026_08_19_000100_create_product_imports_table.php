<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · "Toplu içe aktarma (Excel/CSV)".
 *
 * TABLONUN VARLIK SEBEBİ — İŞ ASENKRONDUR:
 *   Yükleme isteği dosyayı kaydedip işi kuyruğa atar ve HEMEN döner (500
 *   satırlık dosya istekte işlenirse zaman aşımı olur, kullanıcı yeniler ve
 *   aynı dosya iki kez işlenir). Kullanıcı sonucu ancak bu satırdan
 *   öğrenebilir; satır olmasaydı yükleme sessizce "kaybolmuş" görünürdü.
 *
 * `payload` DOSYANIN KENDİSİNİ TAŞIR, disk yolu değil:
 *   Yol saklansaydı iş çalışmadan önce dosya silinebilir, worker başka bir
 *   makinede olabilir veya geçici dizin temizlenebilirdi. CSV metni birkaç
 *   yüz KB'tır ve satır zaten kısa ömürlüdür.
 *
 * `errors` JSONB ve SATIR NUMARASI taşır: sayı tek başına kullanıcıya ne
 * yapacağını söylemez — hangi satırı düzelteceğini bilmeli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            $table->string('filename');
            // Açılış stoğunun yazılacağı depo: yükleme anında seçilir ve
            // DONAR. İş çalışırken varsayılan depo değişmiş olabilir ve o
            // değişiklik bu yüklemeyi etkilememeli.
            $table->uuid('warehouse_id');

            // pending | running | completed | failed
            $table->string('status')->default('pending');

            $table->integer('created_count')->default(0);
            $table->integer('updated_count')->default(0);

            // [{line: 12, message: "SKU boş olamaz."}]
            $table->jsonb('errors')->nullable();
            $table->text('last_error')->nullable();

            // CSV metni — disk yolu DEĞİL (yukarıdaki gerekçe).
            $table->text('payload');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();

            // Ekran kiracı başına en yeniyi önce listeler.
            $table->index(['tenant_id', 'created_at'], 'product_imports_tenant_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_imports');
    }
};
