<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Süreli kampanyalar — tarih aralığında seçili ürünlere, seçili kanallarda
 * yüzde ya da tutar indirimi; bitince fiyat kendiliğinden geri döner.
 *
 * Kampanya fiyatı SAKLANMAZ: `Listing::effectivePrice()` anlık hesaplar.
 * Saklansaydı bitişte "geri yaz" adımı kaçtığında ürün sonsuza kadar
 * indirimli kalırdı; hesaplanınca saat geçtiği an doğru fiyat budur ve
 * zamanlayıcının işi yalnız kanala yeniden göndermektir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 120);

            // percent | amount
            $table->string('discount_type', 8);
            $table->decimal('discount_value', 12, 2);

            // UTC saklanır; ekran kiracının saat diliminde gösterir.
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');

            // Normal fiyat üstü çizili gösterilsin mi.
            $table->boolean('show_compare_at')->default(true);

            // Zamanlayıcı işaretleri: başlangıç/bitiş yeniden gönderimi
            // yapıldı mı. Tekrar koşan tur aynı ilanları ikinci kez göndermez.
            $table->timestampTz('start_pushed_at')->nullable();
            $table->timestampTz('end_pushed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'starts_at']);
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('price_campaign_variants', function (Blueprint $table): void {
            $table->uuid('price_campaign_id');
            $table->uuid('variant_id');
            $table->uuid('tenant_id');

            $table->primary(['price_campaign_id', 'variant_id']);
            $table->foreign('price_campaign_id')->references('id')->on('price_campaigns')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('variants')->cascadeOnDelete();
            $table->index('variant_id');
        });

        Schema::create('price_campaign_connections', function (Blueprint $table): void {
            $table->uuid('price_campaign_id');
            $table->uuid('channel_connection_id');
            $table->uuid('tenant_id');

            $table->primary(['price_campaign_id', 'channel_connection_id']);
            $table->foreign('price_campaign_id')->references('id')->on('price_campaigns')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')->on('channel_connections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_campaign_connections');
        Schema::dropIfExists('price_campaign_variants');
        Schema::dropIfExists('price_campaigns');
    }
};
