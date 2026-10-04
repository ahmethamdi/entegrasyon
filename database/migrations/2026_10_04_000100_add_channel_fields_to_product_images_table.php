<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Görselin KAYNAĞI ve kanal HARİÇ TUTMA listesi (A15).
 *
 * `source_connection_id`: görsel bir kanaldan içe aktarıldıysa o bağlantı.
 * Yeniden içe aktarmada kaynakta silinen görsel bizde de silinir — ama
 * YALNIZCA o kaynağınki; elle eklenen ya da başka kanaldan gelen görsele
 * dokunulmaz. Kaynak bilinmeseydi ya hiç silinemez (kaynakta kaldırılan
 * görsel her kanala gitmeye devam eder) ya da her şey silinirdi.
 *
 * `excluded_channels`: görselin GÖNDERİLMEYECEĞİ kanal türleri
 * (`["trendyol"]`). Varsayılan NULL = her kanala gider. Ters liste
 * ("gideceği kanallar") seçilmedi: yeni bir kanal bağlandığında her
 * görsel o kanala varsayılan olarak KAPALI olur ve ürünler görselsiz
 * reddedilirdi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->uuid('source_connection_id')->nullable()->after('variant_id');
            $table->jsonb('excluded_channels')->nullable()->after('alt');

            $table->foreign('source_connection_id')->references('id')
                ->on('channel_connections')->nullOnDelete();
            $table->index(['product_id', 'source_connection_id']);
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropForeign(['source_connection_id']);
            $table->dropIndex(['product_id', 'source_connection_id']);
            $table->dropColumn(['source_connection_id', 'excluded_channels']);
        });
    }
};
