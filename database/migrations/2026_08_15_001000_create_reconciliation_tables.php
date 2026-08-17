<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mimari Karar Dokümanı v2.2 · §4 · Reconciliation, §10 · Reconciliation Engine.
 *
 * İKİ TABLO, İKİ SORU:
 *   reconciliation_runs   — bir tur ne yaptı (kaç aday, kaç sürüklenme)
 *   reconciliation_items  — tek listing için ne bulundu ve ne yapıldı
 *
 * local_value / remote_value JSONB'dir: karşılaştırmanın HAM girdisi denetim
 * için saklanır. Yalnızca "sürüklenme var" demek yetmez — destek "hangi değer
 * neydi" sorusuna cevap veremez ve sürüklenmenin gerçek mi yoksa okuma
 * gecikmesi mi olduğu bir daha anlaşılamaz.
 *
 * reconciliation_item_id sync_operations'ta ZATEN var (migration 000800) ve
 * onarım anahtarını tekilleştirir: aynı kalem iki kez işlense tek operasyon
 * oluşur (§8 · idempotency).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_connection_id');

            // hot | warm | cold — §10 üç katman
            $table->string('scope');
            // scheduled | manual | webhook
            $table->string('trigger_reason')->default('scheduled');

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->integer('candidates_count')->default(0);
            $table->integer('checked_count')->default(0);
            $table->integer('drift_count')->default(0);
            $table->integer('repaired_count')->default(0);

            // running | completed | failed
            $table->string('status')->default('running');
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')
                ->on('channel_connections')->cascadeOnDelete();

            $table->index(['tenant_id', 'started_at'], 'recon_runs_tenant_time_idx');
        });

        // Açık turlar: aynı bağlantıda ikinci tur başlatmamak için okunur.
        DB::statement(<<<'SQL'
            CREATE INDEX recon_runs_running_idx
                ON reconciliation_runs (status)
                WHERE status = 'running'
        SQL);

        Schema::create('reconciliation_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('reconciliation_run_id');
            $table->uuid('listing_id');

            $table->string('domain');            // INVENTORY | PRICE | CONTENT | MEDIA

            // recently_sold | stale_sync | previous_error | drift_detected | sampled
            $table->string('priority_reason');

            // MATCHED | DRIFT_DETECTED | REMOTE_MISSING | REMOTE_AHEAD
            // LOCAL_AHEAD | REMOTE_UNREACHABLE | REPAIR_QUEUED | REPAIRED
            $table->string('status');

            // Karşılaştırmanın HAM girdisi — denetim için saklanır.
            $table->jsonb('local_value')->nullable();
            $table->jsonb('remote_value')->nullable();

            // |beklenen − gözlenen|; sıralama ve alarm eşiği bundan beslenir.
            $table->integer('drift_magnitude')->nullable();

            $table->uuid('repair_operation_id')->nullable();

            $table->timestamp('checked_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('reconciliation_run_id')->references('id')
                ->on('reconciliation_runs')->cascadeOnDelete();
            $table->foreign('listing_id')->references('id')->on('listings')->cascadeOnDelete();
            // repair_operation_id'ye FK YOK: operasyon temizlenebilir ama
            // mutabakat kaydı denetim izidir ve kalmalıdır.

            $table->index(['reconciliation_run_id', 'status'], 'recon_items_run_status_idx');

            // Doğrulama turu adayları: "son 24 saatte onarılmış" sorgusu.
            $table->index(['listing_id', 'checked_at'], 'recon_items_listing_time_idx');
        });

        // Açık sürüklenmeler — panel ve alarm bunu okur.
        DB::statement(<<<'SQL'
            CREATE INDEX recon_items_drift_idx
                ON reconciliation_items (tenant_id, status)
                WHERE status = 'DRIFT_DETECTED'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS recon_items_drift_idx');
        Schema::dropIfExists('reconciliation_items');

        DB::statement('DROP INDEX IF EXISTS recon_runs_running_idx');
        Schema::dropIfExists('reconciliation_runs');
    }
};
