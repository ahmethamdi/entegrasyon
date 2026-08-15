<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mimari Karar Dokümanı v2.2 · §4 · Catalog · tablolar 005–010.
 *
 * Varyant seçenekleri İLİŞKİSEL tutulur, JSONB'ye gömülmez: kanal öznitelik
 * eşleştirmesi (attribute_value_mappings) bir option_value_id'ye bağlanmak
 * zorundadır ve JSONB'de bu bağ kurulamaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('sku');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('brand')->nullable();
            $table->string('internal_category_id')->nullable();
            $table->string('status')->default('draft');
            $table->bigInteger('content_version')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('variants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->string('sku');
            $table->string('barcode')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->string('currency', 3)->default('TRY');
            $table->integer('weight_grams')->nullable();
            $table->string('status')->default('active');
            $table->bigInteger('content_version')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->unique(['tenant_id', 'sku']);
            $table->index('product_id');
        });

        // Barkod yalnızca dolu olduğunda tekil — kısmi indeks.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX variants_barcode_unique
                ON variants (tenant_id, barcode)
                WHERE barcode IS NOT NULL
        SQL);

        Schema::create('option_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('option_values', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('option_definition_id');
            $table->string('value');
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('option_definition_id')->references('id')
                ->on('option_definitions')->cascadeOnDelete();

            $table->unique(['option_definition_id', 'value']);
        });

        Schema::create('variant_options', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('variant_id');
            $table->uuid('option_definition_id');
            $table->uuid('option_value_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('variants')->cascadeOnDelete();
            $table->foreign('option_definition_id')->references('id')
                ->on('option_definitions')->cascadeOnDelete();
            $table->foreign('option_value_id')->references('id')
                ->on('option_values')->cascadeOnDelete();

            $table->unique(['variant_id', 'option_definition_id']);
            $table->index('option_value_id');
        });

        Schema::create('product_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->uuid('variant_id')->nullable();
            $table->string('storage_path');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->bigInteger('bytes')->nullable();
            $table->string('checksum')->nullable();
            $table->integer('position')->default(0);
            $table->string('alt')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('variants')->nullOnDelete();

            $table->index(['product_id', 'position']);
            $table->index(['tenant_id', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('variant_options');
        Schema::dropIfExists('option_values');
        Schema::dropIfExists('option_definitions');
        DB::statement('DROP INDEX IF EXISTS variants_barcode_unique');
        Schema::dropIfExists('variants');
        Schema::dropIfExists('products');
    }
};
