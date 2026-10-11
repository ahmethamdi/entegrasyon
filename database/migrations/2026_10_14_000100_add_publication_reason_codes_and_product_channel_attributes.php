<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S3 · Ürün yayını — kararlı sebep kodu ve ürün düzeyinde kanal öznitelikleri.
 *
 * `listing_sync_states.reason_code`: yayın engeli / hatasının MAKİNE
 * okunur kodu (`PublishReason`). `last_error` insan metni olarak kalır
 * (geriye uyum, kanalın ham hata metni); sunum kodu çevirir.
 *
 * `listings.create_requested_at`: belirsiz yaratma talebi (bkz. aşağı).
 *
 * `product_channel_attribute_values`: kanal kategorisinin ZORUNLU ama
 * varyant belirlemeyen öznitelikleri (HB "Garanti Süresi", N11 "Menşei")
 * için ÜRÜN düzeyinde seçilen değer. Varyant belirleyiciler (Beden, Renk)
 * buraya YAZILMAZ — onlar `variant_options` + `attribute_mappings` +
 * `attribute_value_mappings` zincirinden gelir; iki tür birbirine
 * karışmaz ve serbest metne düzleştirilmez. Değer ya kanalın değer
 * listesinden bir kimlik (`external_value_id`) ya da kanal serbest değer
 * kabul ediyorsa `custom_value`'dur — ikisi birden değil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_sync_states', function (Blueprint $table): void {
            $table->string('reason_code', 48)->nullable()->after('last_error');
        });

        // Belirsiz sonuçlu yaratma TALEBİ (`GuardsAmbiguousCreate` kanalları):
        // istekten ÖNCE koşullu yazılır; doluyken otomatik ikinci yaratma
        // GÖNDERİLMEZ, yalnız arama yapılır. Açık "yeniden yayınla" sıfırlar.
        Schema::table('listings', function (Blueprint $table): void {
            $table->timestampTz('create_requested_at')->nullable();
        });

        Schema::create('product_channel_attribute_values', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->uuid('channel_category_id');
            $table->string('external_attribute_id');
            $table->string('external_value_id')->nullable();
            $table->string('custom_value', 500)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('channel_category_id')->references('id')->on('channel_categories')->cascadeOnDelete();
            $table->unique(['product_id', 'channel_category_id', 'external_attribute_id'], 'product_channel_attribute_values_unique');
            $table->index(['tenant_id', 'product_id']);
        });

        DB::statement('ALTER TABLE product_channel_attribute_values ADD CONSTRAINT product_channel_attribute_values_one_value CHECK ((external_value_id IS NULL) <> (custom_value IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_channel_attribute_values');

        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn('create_requested_at');
        });

        Schema::table('listing_sync_states', function (Blueprint $table): void {
            $table->dropColumn('reason_code');
        });
    }
};
