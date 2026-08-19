<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mimari Karar Dokümanı v2.2 · §4 · metric_snapshots,
 * §11 · Ölçülecek metrikler, §13 · Faz 3 · madde 2, §15 · CaptureMetrics.
 *
 * §4 tabloyu birebir şöyle tanımlar:
 *   metric_snapshots · id, metric, scope, value, captured_at
 *                    · INDEX(metric, captured_at DESC)
 *
 * TABLO KİRACIYA AİT DEĞİLDİR ve bu bilinçlidir. Metriklerin ÇOĞU sistem
 * genelidir (outbox birikmesi, inbox gecikmesi, senkron hata oranı);
 * `tenant_id` zorunlu olsaydı onlara uydurma bir kiracı yazmak gerekir ve
 * `BelongsToTenant` global scope'u tüm sistem metriklerini panelden
 * gizlerdi. Kapsam bunun yerine `scope` kolonunda METİN olarak yaşar:
 * `system` · `tenant:{uuid}` · `connection:{uuid}`.
 *
 * ANLIK GÖRÜNTÜ ÜZERİNE YAZILMAZ, EKLENİR. Her tur yeni satır yazar ve
 * tablo bir ZAMAN SERİSİDİR; "gecikme artıyor mu" sorusu ancak geçmişle
 * cevaplanır. `UNIQUE(metric, scope)` konsaydı yalnızca ŞU AN bilinir ve
 * grafik hiç çizilemezdi.
 *
 * UUID YOK: bu tablo `api_calls` gibi teknik bir günlüktür ve bigserial
 * taşır (§1 · Karar 23 istisnası). Saatlik tur sabit sayıda satır yazar;
 * kimlik yalnızca sıralama ve okunabilirlik için gerekir.
 *
 * `value` DOUBLE PRECISION: metrikler hem sayı (kaç ölü iş), hem oran
 * (%5.3 hata), hem süre (p95 = 1247.5 ms) taşır. `decimal` seçilseydi
 * ölçek her metrik için ayrı düşünülmek zorunda kalır ve p95
 * milisaniyeleri ile yüzde oranı aynı ölçeğe sıkışırdı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Metrik adı — App\Support\Observability\Metric enum'ının değeri.
            $table->string('metric');

            // Kapsam: `system` · `tenant:{uuid}` · `connection:{uuid}`.
            // FK YOK: kapsam heterojendir ve bir kiracı silinince metrik
            // geçmişinin de silinmesi İSTENMEZ — "geçen ay ne oldu"
            // sorusu kiracı ayrıldıktan sonra da sorulur.
            $table->string('scope')->default('system');

            $table->double('value');

            $table->timestamp('captured_at')->useCurrent();
        });

        // §4'ün tanımladığı indeks: panel "şu metriğin son N kaydı"
        // sorgusunu atar ve sıralama captured_at DESC'tir.
        DB::statement(<<<'SQL'
            CREATE INDEX metric_snapshots_metric_time_idx
                ON metric_snapshots (metric, captured_at DESC)
        SQL);

        // Kapsamlı metriklerde (kiracı/kanal başına) panel tek bir
        // kapsamın geçmişini okur; yukarıdaki indeks yalnızca metriğe
        // göre daraltır ve çok kiracılı kurulumda o metriğin TÜM
        // kiracılarını tarardı.
        DB::statement(<<<'SQL'
            CREATE INDEX metric_snapshots_scope_time_idx
                ON metric_snapshots (metric, scope, captured_at DESC)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS metric_snapshots_scope_time_idx');
        DB::statement('DROP INDEX IF EXISTS metric_snapshots_metric_time_idx');
        Schema::dropIfExists('metric_snapshots');
    }
};
