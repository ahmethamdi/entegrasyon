<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mimari Karar Dokümanı v2.2 · §4 · Listings ve Sync, §19 · adım 1.5.
 *
 * İkinci migration grubu: listings, listing_images, listing_sync_states,
 * sync_operations, sync_attempts, api_calls.
 *
 * ÜÇ AYRI SÜRÜM ALANI (§1 · Karar 15):
 *   desired_version  ne istendi      — OpenSyncOperation yazar
 *   synced_version   ne gönderildi   — SyncResultRecorder yazar
 *   remote_version   ne gözlendi     — mutabakat yazar
 * Tek alanla çakışma tespiti imkânsızdır.
 *
 * is_dirty ÜRETİLMİŞ KOLON (§4):
 *   PostgreSQL kısmi indeks yükleminde iki kolon birbiriyle karşılaştırılamaz;
 *   yüklem yalnızca sabitle karşılaştırma kabul eder. Bu yüzden
 *   desired_version > synced_version koşulu doğrudan indekslenemez ve 80.000
 *   satırlık tabloda her beş dakikada tam tarama yapılırdı. Üretilmiş boolean
 *   kolon bu koşulu indekslenebilir kılar ve türetilmiş olduğu için ikinci bir
 *   durum kaynağı yaratmaz.
 *
 * sync_operations.outbox_event_id ÜZERİNDE TEKİLLİK YOK (§17 · P0):
 *   Bir olay N operasyona yayılır (listing × domain × sürüm). Tekillik kısıtı
 *   fan-out'u imkânsız kılardı. Yalnızca korelasyon indeksi vardır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_connection_id');
            $table->uuid('variant_id');

            $table->string('external_id')->nullable();
            $table->string('external_parent_id')->nullable();
            $table->text('external_url')->nullable();
            $table->string('lifecycle_status')->default('draft');
            // draft | live | delisted | archived
            $table->timestamp('listed_at')->nullable();
            $table->timestamp('delisted_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')
                ->on('channel_connections')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('variants')->cascadeOnDelete();

            // Bir varyant bir bağlantıda yalnızca bir kez listelenir.
            $table->unique(['channel_connection_id', 'variant_id'], 'listings_connection_variant_unique');

            $table->index(['tenant_id', 'lifecycle_status']);

            // Fan-out sorgusu: varyantın TÜM canlı listeleri.
            $table->index(['tenant_id', 'variant_id'], 'listings_variant_idx');
        });

        // external_id yalnızca kanala gönderildikten sonra dolar; taslak
        // satırlarda NULL'dur ve tekillik onları kapsamamalıdır.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX listings_external_unique
                ON listings (channel_connection_id, external_id)
                WHERE external_id IS NOT NULL
        SQL);

        Schema::create('listing_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('listing_id');
            $table->uuid('product_image_id');
            $table->string('external_image_id')->nullable();
            $table->integer('position')->default(0);
            $table->timestamp('uploaded_at')->nullable();
            $table->string('checksum')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('listing_id')->references('id')->on('listings')->cascadeOnDelete();
            $table->foreign('product_image_id')->references('id')
                ->on('product_images')->cascadeOnDelete();

            $table->unique(['listing_id', 'product_image_id'], 'listing_images_unique');
            $table->index(['listing_id', 'position']);
        });

        Schema::create('listing_sync_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('listing_id');
            $table->string('domain');            // CONTENT | PRICE | INVENTORY | MEDIA

            $table->text('desired_hash')->nullable();
            $table->text('synced_hash')->nullable();
            $table->text('remote_hash')->nullable();

            $table->bigInteger('desired_version')->default(0);
            $table->bigInteger('synced_version')->default(0);
            $table->bigInteger('remote_version')->nullable();

            // pending | syncing | synced | error_transient | error_permanent | blocked
            //
            // error_permanent → pending geçişi TEK BAŞINA iş üretmez; geçişi
            // yapan her yer aynı transaction'da bir ListingResyncRequested
            // outbox olayı yazmak ZORUNDADIR (§9).
            $table->string('status')->default('pending');

            $table->timestamp('last_repair_at')->nullable();     // son mutabakat onarımı
            $table->timestamp('last_requested_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_observed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('error_count')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('listing_id')->references('id')->on('listings')->cascadeOnDelete();

            $table->unique(['listing_id', 'domain'], 'sync_state_unique');
        });

        // Bekleyen iş var mı — kısmi indeks bunun üzerine kurulur.
        DB::statement(<<<'SQL'
            ALTER TABLE listing_sync_states
                ADD COLUMN is_dirty boolean
                GENERATED ALWAYS AS (desired_version > synced_version) STORED
        SQL);

        // Mutabakatın stale_sync adayları.
        DB::statement(<<<'SQL'
            CREATE INDEX sync_states_dirty_idx
                ON listing_sync_states (tenant_id, domain, last_requested_at)
                WHERE is_dirty AND status <> 'error_permanent'
        SQL);

        // Hata ekranı ve mutabakatın previous_error adayları.
        DB::statement(<<<'SQL'
            CREATE INDEX sync_states_error_idx
                ON listing_sync_states (tenant_id, domain)
                WHERE status = 'error_transient'
        SQL);

        // Uzak gözlem yaşı: soğuk katman örneklemesi.
        DB::statement(<<<'SQL'
            CREATE INDEX sync_states_observed_idx
                ON listing_sync_states (domain, last_observed_at NULLS FIRST)
        SQL);

        Schema::create('sync_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_connection_id');

            $table->string('operation_type');                    // INVENTORY_PUSH | PRICE_PUSH | ...
            $table->string('intent')->default('NORMAL_SYNC');    // NORMAL_SYNC | REPAIR

            $table->string('entity_type');                       // her zaman 'listing'
            $table->uuid('entity_id');                           // listing_id
            $table->bigInteger('entity_version');

            $table->string('idempotency_key');
            $table->string('status')->default('pending');
            $table->integer('attempt_count')->default(0);
            $table->integer('priority')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error_class')->nullable();

            $table->uuid('outbox_event_id')->nullable();
            $table->uuid('reconciliation_item_id')->nullable();  // yalnızca intent = REPAIR
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')
                ->on('channel_connections')->cascadeOnDelete();
            $table->foreign('outbox_event_id')->references('id')
                ->on('outbox_events')->nullOnDelete();

            $table->unique(['tenant_id', 'idempotency_key'], 'sync_operations_idempotent');

            $table->index(['entity_type', 'entity_id', 'operation_type'], 'sync_ops_entity_idx');

            // Korelasyon: bir olay N operasyona yayılır. UNIQUE DEĞİL.
            $table->index('outbox_event_id', 'sync_ops_outbox_idx');
        });

        // Bekleyen işler.
        DB::statement(<<<'SQL'
            CREATE INDEX sync_ops_pending_idx
                ON sync_operations (status, scheduled_at)
                WHERE status IN ('pending', 'retrying')
        SQL);

        // SEVİYE 2 TESLİM TARAMASI: operasyon yaratıldı ama worker hiç
        // çalışmadı (Redis işi kaybetti). DetectStuckSyncOperations kullanır.
        DB::statement(<<<'SQL'
            CREATE INDEX sync_ops_never_attempted_idx
                ON sync_operations (created_at)
                WHERE status = 'pending' AND attempt_count = 0
        SQL);

        Schema::create('sync_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('sync_operation_id');
            $table->integer('attempt_number');
            $table->string('outcome');                   // success | transient | permanent
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('sync_operation_id')->references('id')
                ->on('sync_operations')->cascadeOnDelete();

            $table->unique(['sync_operation_id', 'attempt_number'], 'sync_attempts_unique');
            $table->index(['tenant_id', 'started_at']);
        });

        // TEKNİK GÜNLÜK TABLOSU — en çok yazılan tablo.
        // UUID YOK: bigserial taşır (§1 · Karar 23 istisnası).
        Schema::create('api_calls', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->uuid('channel_connection_id');
            $table->uuid('sync_attempt_id')->nullable();   // ilişki bu alan üzerinden

            $table->string('method');
            $table->text('endpoint');
            $table->integer('status_code')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->jsonb('request_body')->nullable();     // maskelenmiş
            $table->jsonb('response_body')->nullable();    // maskelenmiş
            $table->integer('rate_limit_remaining')->nullable();
            $table->string('error_class')->nullable();
            $table->timestamp('called_at')->useCurrent();

            // Saklama burada: 2xx +7 gün, 4xx/5xx +90 gün. Günlük bakım işi
            // partileyerek siler; tablo sabit boyutta kalır ve bölümlemeye
            // gerek kalmaz (bölümleme P2, tetikleyici §17).
            $table->timestamp('expires_at');

            // FK YOK: bu tablo çok yazılır ve teknik günlüktür; referans
            // bütünlüğü kontrolü her INSERT'e maliyet bindirirdi.
            $table->index(['tenant_id', 'called_at'], 'api_calls_tenant_time_idx');
            $table->index(['channel_connection_id', 'called_at'], 'api_calls_conn_time_idx');
            $table->index('sync_attempt_id', 'api_calls_attempt_idx');
            $table->index('expires_at', 'api_calls_expiry_idx');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX api_calls_errors_idx
                ON api_calls (tenant_id, called_at DESC)
                WHERE status_code >= 400
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS api_calls_errors_idx');
        Schema::dropIfExists('api_calls');
        Schema::dropIfExists('sync_attempts');

        DB::statement('DROP INDEX IF EXISTS sync_ops_never_attempted_idx');
        DB::statement('DROP INDEX IF EXISTS sync_ops_pending_idx');
        Schema::dropIfExists('sync_operations');

        DB::statement('DROP INDEX IF EXISTS sync_states_observed_idx');
        DB::statement('DROP INDEX IF EXISTS sync_states_error_idx');
        DB::statement('DROP INDEX IF EXISTS sync_states_dirty_idx');
        Schema::dropIfExists('listing_sync_states');

        Schema::dropIfExists('listing_images');
        DB::statement('DROP INDEX IF EXISTS listings_external_unique');
        Schema::dropIfExists('listings');
    }
};
