<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S8 · AI ile listeleme (23 Ekim 2026).
 *
 * `ai_suggestions` — modelin ÖNERİSİ. Kanala hiçbir şey kendiliğinden
 * gitmez: satıcı görür, düzenler, onaylar; onay mevcut yazma yollarından
 * (öznitelik, kategori eşleme, kanal içeriği) geçer. Öneri silinmez,
 * durumu kapanır — "bu başlığı kim, hangi modelle önerdi" sorusu okunur.
 *
 * `input_hash` önerinin hangi ürün içeriğinden üretildiğini tutar: ürün
 * sonradan değiştiyse öneri BAYAT sayılır ve panelde işaretlenir.
 *
 * `ai_usage` — her model çağrısının jeton ve maliyet kaydı. Aylık kota
 * buradan sayılır; hatalı çağrı (ağ, 5xx) kota HARCAMAZ ama kaydı tutulur
 * (ops:watch hata oranını buradan ölçer).
 *
 * `product_channel_contents` — kanal türüne özgü başlık/açıklama. Yalnız
 * satıcı onayladığında yazılır; yoksa kanala kanonik ürün metni gider
 * (`ListingPayloadBuilder`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_suggestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->string('kind');
            $table->string('channel_type_code');
            $table->uuid('channel_category_id')->nullable();
            $table->string('model');
            $table->string('input_hash', 64);
            $table->string('status');
            $table->jsonb('payload');
            $table->jsonb('warnings')->default('[]');
            $table->uuid('created_by')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['product_id', 'kind', 'channel_type_code', 'status']);
        });

        Schema::create('ai_usage', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->uuid('suggestion_id')->nullable();
            $table->string('kind');
            $table->string('model');
            $table->string('status');
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('cache_read_tokens')->default(0);
            $table->integer('cache_write_tokens')->default(0);
            $table->bigInteger('cost_micro_usd')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('suggestion_id')->references('id')->on('ai_suggestions')->nullOnDelete();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['created_at', 'status']);
        });

        Schema::create('product_channel_contents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->string('channel_type_code');
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->uuid('source_suggestion_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('source_suggestion_id')->references('id')->on('ai_suggestions')->nullOnDelete();
            $table->unique(['product_id', 'channel_type_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_channel_contents');
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ai_suggestions');
    }
};
