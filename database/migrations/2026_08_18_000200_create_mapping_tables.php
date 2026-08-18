<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mimari Karar Dokümanı v2.2 · §4 · Mapping, §13 · Faz 2 ("Kategori ve
 * öznitelik eşleştirme arayüzü"), §14 · ön koşul kapısı.
 *
 * EŞLEŞTİRME KİRACIYA AİTTİR — TAKSONOMİNİN AKSİNE.
 *
 *   `channel_categories` kiracı kolonu TAŞIMAZ: kategori ağacı kanalın
 *   GERÇEĞİDİR ve tüm satıcılar için aynıdır. Bu tablolar `tenant_id`
 *   TAŞIR: hangi iç kategorinin kanalın hangi kategorisine karşılık
 *   geldiği satıcının KARARIDIR. İki satıcı aynı ürünü farklı kategoriye
 *   açabilir ve ikisi de haklıdır.
 *
 *   Ayrımın pratik sonucu: ağaç bir kez çekilir ve paylaşılır, eşleştirme
 *   kiracı başına saklanır ve ASLA paylaşılmaz. Eşleştirmeler kiracısız
 *   olsaydı bir satıcının kararı hepsini bağlardı.
 *
 * SÜRÜM EŞLEŞTİRMEDE DE TAŞINIR — VE FK SÜRÜMLÜ SATIRA BAĞLIDIR.
 *
 *   `category_mappings.channel_category_id` belirli bir SÜRÜMÜN satırına
 *   bakar; `taxonomy_version` ayrıca kolon olarak tutulur. Neden ikisi de:
 *   FK hangi satır olduğunu söyler, kolon hangi sürümde karar verildiğini
 *   SORGULANABİLİR yapar ("yeni sürüm geldi, hangi eşleştirmeler eski
 *   sürüme bakıyor" tek indeksle cevaplanır, join gerekmez).
 *
 *   Kanal yeni sürüm yayınladığında eski satırlar SİLİNMEZ (taksonomi
 *   migration'ındaki gerekçe) ve bu FK kopmaz. Eşleştirme eski sürümde
 *   yaşamaya devam eder; panel onu "yeniden doğrula" olarak işaretler ama
 *   satıcının emeği YOK OLMAZ.
 *
 * `RESTRICT` DEĞİL `CASCADE` DEĞİL — KATEGORİ SİLİNİRSE NE OLUR:
 *   `channel_category_id` üzerinde `cascadeOnDelete` kullanılır çünkü
 *   kategori satırı ancak bakım işiyle ("hiçbir eşleştirme buna bakmıyor"
 *   koşuluna bağlı) silinir. Eşleştirme varken silinmeyecektir; yine de
 *   kanal türü tamamen kaldırılırsa artık satır kalmaz.
 *
 * ÜÇ SEVİYE, ÜÇ AYRI TABLO — VE NEDEN TEK TABLO DEĞİL:
 *   Kategori eşleştirmesi kiracı başına BİR karardır (iç kategori →
 *   kanal kategorisi). Öznitelik eşleştirmesi KATEGORİ BAŞINA değişir:
 *   "Beden" özniteliği Trendyol'un elbise kategorisinde farklı bir
 *   `external_attribute_id` taşır, ayakkabı kategorisinde farklı. Değer
 *   eşleştirmesi ise ÖZNİTELİK başınadır ("S" → "SMALL"). Tek tabloya
 *   sıkıştırılsaydı her seviyenin tekilliği diğerinin NULL'larıyla
 *   bozulurdu.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────── kategori eşleştirmesi

        Schema::create('category_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // İç kategori serbest metindir (`products.internal_category_id`);
            // ayrı bir iç kategori tablosu YOKTUR ve §4 de istemez. Satıcı
            // kendi kategori adını yazar, biz onu kanalınkine bağlarız.
            $table->string('internal_category_id');

            $table->string('channel_type_code');
            $table->uuid('channel_category_id');
            $table->string('taxonomy_version');

            // Otomatik eşleştirme önerisinin gücü (0–100). Elle yapılan
            // eşleştirme 100'dür. Öneri motoru henüz yok; kolon şemada
            // duruyor çünkü sonradan eklemek satıcının onayladığı
            // eşleştirmeleri yeniden onaylatmayı gerektirirdi.
            $table->smallInteger('confidence')->default(100);

            // 'user' | 'auto' — kimin karar verdiği. Otomatik eşleştirme
            // panelde AYRI gösterilir: satıcı onaylamadığı bir kararla
            // ürününün yanlış kategoride açıldığını sonradan öğrenmemeli.
            $table->string('mapped_by')->default('user');

            // Satıcı gözden geçirip onayladığında dolar. NULL = doğrulanmadı.
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_type_code')->references('code')
                ->on('channel_types')->cascadeOnDelete();
            $table->foreign('channel_category_id')->references('id')
                ->on('channel_categories')->cascadeOnDelete();

            // §4: UNIQUE(tenant_id, internal_category_id, channel_type_code).
            // Bir iç kategori bir kanalda TEK kategoriye eşlenir; ikinci
            // eşleştirme ürünün hangi kategoriye açılacağını belirsiz
            // bırakırdı. Sürüm tekilliğe GİRMEZ: yeni sürüm geldiğinde
            // eşleştirme GÜNCELLENİR, ikinci satır açılmaz.
            $table->unique(
                ['tenant_id', 'internal_category_id', 'channel_type_code'],
                'category_mappings_unique',
            );

            // "Yeni sürüm geldi, hangi eşleştirmeler bayat" sorgusu.
            $table->index(['tenant_id', 'channel_type_code', 'taxonomy_version'], 'category_mappings_version_idx');
        });

        // ─────────────────────────────────────────── öznitelik eşleştirmesi

        Schema::create('attribute_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // İç seçenek tanımı: "Beden", "Renk" (`option_definitions`).
            $table->uuid('option_definition_id');

            // Eşleştirme KATEGORİ BAŞINADIR: aynı "Beden" özniteliği farklı
            // kategorilerde farklı `external_attribute_id` taşır.
            $table->uuid('channel_category_id');
            $table->string('external_attribute_id');

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('option_definition_id')->references('id')
                ->on('option_definitions')->cascadeOnDelete();
            $table->foreign('channel_category_id')->references('id')
                ->on('channel_categories')->cascadeOnDelete();

            // §4: UNIQUE(tenant_id, option_definition_id, channel_category_id).
            $table->unique(
                ['tenant_id', 'option_definition_id', 'channel_category_id'],
                'attribute_mappings_unique',
            );

            // Ön koşul kapısının sorgusu: "bu kategorinin zorunlu
            // öznitelikleri eşleşmiş mi" — kategori üzerinden aranır.
            $table->index(['tenant_id', 'channel_category_id'], 'attribute_mappings_category_idx');
        });

        // ─────────────────────────────────────────────── değer eşleştirmesi

        Schema::create('attribute_value_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // İç seçenek değeri: "S", "Kırmızı" (`option_values`).
            $table->uuid('option_value_id');

            // Değer eşleştirmesi ÖZNİTELİK başınadır, kategori başına
            // DEĞİL: Trendyol'un "Beden" özniteliği tek bir değer listesi
            // taşır ve o liste kategoriden bağımsızdır. Kategori de
            // anahtara girseydi satıcı aynı "S → SMALL" kararını her
            // kategori için yeniden vermek zorunda kalırdı.
            $table->string('external_attribute_id');
            $table->string('external_value_id');

            // Kanalın gösterdiği etiket ("SMALL"). Panelde kimlik yerine bu
            // gösterilir; satıcı `12345` kodundan ne seçtiğini anlayamaz.
            $table->string('external_value_label')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('option_value_id')->references('id')
                ->on('option_values')->cascadeOnDelete();

            // §4: UNIQUE(tenant_id, option_value_id, external_attribute_id).
            $table->unique(
                ['tenant_id', 'option_value_id', 'external_attribute_id'],
                'attribute_value_mappings_unique',
            );

            // Ön koşul kapısı: "bu özniteliğin değerleri eşleşmiş mi".
            $table->index(['tenant_id', 'external_attribute_id'], 'attribute_value_mappings_attribute_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_value_mappings');
        Schema::dropIfExists('attribute_mappings');
        Schema::dropIfExists('category_mappings');
    }
};
