<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Set / paket ürün (bundle) — `docs/architecture-review/03-DOMAIN-MODEL-AUDIT.md` § E.
 *
 * Set bir VARYANTTIR (`variants.kind = 'bundle'`); kendi stok satırı
 * YOKTUR. Stoğu bileşenlerinden TÜRETİLİR ve SAKLANMAZ:
 * min(⌊bileşen stoğu / adet⌋). Saklansaydı bileşen her değiştiğinde iki
 * gerçek senkron tutulmak zorunda kalırdı (defter = projeksiyon kuralı).
 *
 * `bundle_revision` setin TANIM sürümüdür: setin kanala giden stok sürümü
 * bileşen seviye sürümlerinin toplamı + bu değerdir. Tanım (bileşen/adet)
 * değişince artar ki sürüm kapısı yeni değeri "eski" sanmasın.
 *
 * SET İÇİNDE SET YOK — eylemde (`SaveBundle`) zorlanır; burada bileşenin
 * kendisi olmadığı kısıtı durur.
 *
 * `order_lines.bundle_components` SATIŞ ANI görüntüsüdür: set satırının
 * stoğu düşülürken hangi bileşenden kaçar adet düşüldüğü yazılır. İptal ve
 * iade BUNU okur — setin tanımı sonradan değişirse bugünkü tanımla geri
 * eklemek, satılmamış bir bileşeni stoğa sokar (maliyet görüntüsünün
 * "satış anı" kuralıyla aynı gerekçe, ADR-002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variants', function (Blueprint $table): void {
            $table->string('kind', 16)->default('standard');
            $table->unsignedInteger('bundle_revision')->default(0);
        });

        DB::statement("ALTER TABLE variants ADD CONSTRAINT variants_kind_check CHECK (kind IN ('standard', 'bundle'))");

        Schema::create('variant_components', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('bundle_variant_id');
            $table->uuid('component_variant_id');
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('bundle_variant_id')->references('id')->on('variants')->cascadeOnDelete();
            // Bileşen setteyken silinemez: silinseydi set sessizce "bileşensiz"
            // kalır ve stoğu yanlış hesaplanırdı.
            $table->foreign('component_variant_id')->references('id')->on('variants')->restrictOnDelete();

            $table->unique(['bundle_variant_id', 'component_variant_id']);
            // Fan-out: "bu bileşen hangi setlerde?" sorusu.
            $table->index(['tenant_id', 'component_variant_id']);
        });

        Schema::table('order_lines', function (Blueprint $table): void {
            $table->jsonb('bundle_components')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE variant_components
                ADD CONSTRAINT variant_components_quantity_check CHECK (quantity > 0),
                ADD CONSTRAINT variant_components_not_self CHECK (bundle_variant_id <> component_variant_id)
        SQL);
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropColumn('bundle_components');
        });

        Schema::dropIfExists('variant_components');
        DB::statement('ALTER TABLE variants DROP CONSTRAINT variants_kind_check');

        Schema::table('variants', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'bundle_revision']);
        });
    }
};
