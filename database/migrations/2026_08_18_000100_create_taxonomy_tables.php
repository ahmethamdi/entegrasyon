<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mimari Karar Dokümanı v2.2 · §4 · Mapping, §13 · Faz 2 (taksonomi),
 * §14 · Trendyol.
 *
 * TAKSONOMİ KİRACIYA AİT DEĞİLDİR — `tenant_id` KOLONU YOKTUR.
 *
 *   Trendyol'un kategori ağacı TÜM satıcılar için aynıdır. Kiracı başına
 *   kopyalansaydı aynı 30 bin satırlık ağaç her kiracı için yeniden
 *   saklanır, her kiracı onu ayrı ayrı çekmek zorunda kalır ve kanal
 *   kotası anlamsızca tükenirdi.
 *
 *   Kiracıya ait olan EŞLEŞTİRMEDİR: `category_mappings` kiracı başınadır
 *   ve o tablo sonraki maddenin konusudur ("Kategori ve öznitelik
 *   eşleştirme arayüzü — 28 sa"). Ayrım şudur: ağaç kanalın GERÇEĞİ,
 *   eşleştirme satıcının KARARIDIR.
 *
 *   `channel_types` de aynı gerekçeyle kiracısızdır; bu tablo onun
 *   uzantısıdır.
 *
 * SÜRÜM TEKİLLİĞİN PARÇASIDIR — VE ESKİYİ SİLMEZ.
 *
 *   Tekillik `(channel_type_code, taxonomy_version, external_id)`. Kanal
 *   ağacı değiştirdiğinde yeni sürüm YENİ SATIRLAR olarak yazılır ve
 *   eskiler KALIR. Eşleştirmeler eski sürüme bağlıdır
 *   (`category_mappings.taxonomy_version`); eski satırlar silinseydi FK
 *   kopar ve satıcının aylarca emek verdiği kategori eşleştirmeleri bir
 *   gecede yok olurdu.
 *
 *   Sürüm bir AYIRAÇTIR, bir imha emri değil. Eski sürümlerin temizliği
 *   ayrı bir bakım işidir ve "hiçbir eşleştirme buna bakmıyor" koşuluna
 *   bağlıdır.
 *
 * `attributes_fetched_at` NEDEN VAR:
 *   Öznitelikler kategori başına AYRI bir istektir ve yalnızca yapraklar
 *   için anlamlıdır. Bu damga olmadan hangi yaprakların çekildiği
 *   bilinemez ve her turda 30 bin istek yeniden atılırdı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // KİRACI KOLONU YOK — taksonomi kanala aittir (yukarıdaki not).
            $table->string('channel_type_code');
            $table->string('taxonomy_version');

            $table->string('external_id');
            $table->string('parent_external_id')->nullable();
            $table->string('name');

            // Okunabilir yol: "Giyim > Kadın Elbise". Eşleştirme ekranında
            // kullanıcı kategoriyi ancak bağlamıyla tanır; her seferinde
            // ağacı yürüyerek üretmek 30 bin satırda pahalıdır.
            $table->text('path')->nullable();

            // Ürün YALNIZCA yaprağa açılır; ara kategori seçilemez.
            $table->boolean('is_leaf')->default(false);

            $table->timestamp('attributes_fetched_at')->nullable();
            $table->timestamps();

            $table->foreign('channel_type_code')->references('code')
                ->on('channel_types')->cascadeOnDelete();

            $table->unique(
                ['channel_type_code', 'taxonomy_version', 'external_id'],
                'channel_categories_version_unique',
            );

            // Ağaç gezinme: bir kategorinin çocukları.
            $table->index(['channel_type_code', 'parent_external_id'], 'channel_categories_parent_idx');
        });

        // Yaprak arama: eşleştirme ekranı yalnızca yapraklarda arar ve
        // kısmi indeks ara kategorileri hiç taşımaz.
        DB::statement(<<<'SQL'
            CREATE INDEX channel_categories_leaf_idx
                ON channel_categories (channel_type_code, taxonomy_version)
             WHERE is_leaf
        SQL);

        Schema::create('channel_category_attributes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('channel_category_id');

            $table->string('external_attribute_id');
            $table->string('name');

            // Eksikse listing `blocked` olur ve stok akışı ETKİLENMEZ (§14).
            $table->boolean('is_required')->default(false);

            // Varyant belirleyici öznitelik (beden, renk) ürünün kaç
            // varyantla açılacağını belirler; sıradan bir özniteliktan
            // farklı ele alınır.
            $table->boolean('is_variant_defining')->default(false);

            $table->string('data_type')->default('string');

            // İzinli değerler: eşleştirme ekranının girdisi. Serbest metin
            // kabul eden öznitelikte boştur.
            $table->jsonb('allowed_values')->default(DB::raw("'[]'::jsonb"));

            $table->timestamps();

            $table->foreign('channel_category_id')->references('id')
                ->on('channel_categories')->cascadeOnDelete();

            $table->unique(
                ['channel_category_id', 'external_attribute_id'],
                'channel_category_attributes_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_category_attributes');
        DB::statement('DROP INDEX IF EXISTS channel_categories_leaf_idx');
        Schema::dropIfExists('channel_categories');
    }
};
