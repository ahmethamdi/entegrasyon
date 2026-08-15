<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mimari Karar Dokümanı v2.2 · §4 · Messaging · tablo 013.
 *
 * consumed_at SEMANTİĞİ: tüketici FAN-OUT / PLANLAMA işini bitirdi.
 * Downstream API çağrılarının başarısını GÖSTERMEZ. Kalıcı bir kanal hatası
 * bu alanı boş bırakmaz; o hata sync_operations seviyesinde yaşar.
 *
 * İki kısmi indeks iki ayrı bütünlük taramasını besler:
 *   outbox_unpublished_idx → relay tarama
 *   outbox_unconsumed_idx  → seviye 1: tüketici hiç çalışmadı
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('aggregate_type');
            $table->uuid('aggregate_id');
            $table->string('event_type');
            $table->jsonb('payload');

            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->integer('operations_planned')->nullable();

            $table->integer('publish_attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['aggregate_type', 'aggregate_id'], 'outbox_aggregate_idx');
        });

        // Relay tarama indeksi.
        DB::statement(<<<'SQL'
            CREATE INDEX outbox_unpublished_idx
                ON outbox_events (available_at)
                WHERE published_at IS NULL
        SQL);

        // Seviye 1 bütünlük taraması: yayınlandı ama tüketici hiç çalışmadı.
        DB::statement(<<<'SQL'
            CREATE INDEX outbox_unconsumed_idx
                ON outbox_events (published_at)
                WHERE published_at IS NOT NULL AND consumed_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS outbox_unconsumed_idx');
        DB::statement('DROP INDEX IF EXISTS outbox_unpublished_idx');
        Schema::dropIfExists('outbox_events');
    }
};
