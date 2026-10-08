<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kanalın asenkron toplu iş kimlikleri ve satır sonuçları.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN — "SIRAYA ALINDI" UYGULANDI DEMEK DEĞİLDİR
 * ─────────────────────────────────────────────────────────────────────
 * Trendyol, Hepsiburada, N11, Pazarama ve Çiçeksepeti stok/fiyat yükünü
 * kuyruğa alıp yalnız bir iş kimliği döner. Kimlik saklanmadığı için
 * satır bazındaki ret (geçersiz barkod, onaysız ürün, fiyat bandı) hiç
 * okunmuyordu; mutabakat sonradan "fark var" diyor ama NEDEN görünmüyordu.
 *
 * İKİ TABLO:
 *   channel_batches       push başına kanal işi — yoklama durumu burada
 *   channel_batch_items   işin taşıdığı satırlar — operasyon × listing
 *
 * YALNIZ EKLEME: var olan hiçbir tabloya dokunulmaz. Satır hükmü mevcut
 * `listing_sync_states` hata alanlarına yazılır (yeni panel ekranı yok).
 *
 * KİRACI İZOLASYONU projedeki diğer tablolar gibi: `tenant_id` kolonu +
 * `BelongsToTenant` global scope'u (fail-closed). Yoklama turu her işi
 * KENDİ kiracısının bağlamında işler.
 *
 * TEKİLLİK = İDEMPOTENTLİK:
 *   (bağlantı, alan, kanal iş kimliği) tekil — aynı push sonucu iki kez
 *   kaydedilse tek iş olur. (iş, operasyon) tekil — satır iki kez yazılmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_connection_id');

            // INVENTORY | PRICE — bazı kanallarda sonuç uç noktası alana göre
            // değişir (Hepsiburada stock-uploads / price-uploads).
            $table->string('domain', 16);

            // Kanalın kimliği METİN: Trendyol/HB/Pazarama/Çiçeksepeti GUID,
            // N11 sayı — sayıya çevirmek GUID'i bozardı.
            $table->string('external_batch_id', 191);

            // pending → completed | expired. Yalnız `pending` yoklanır.
            $table->string('status', 16)->default('pending');

            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('poll_count')->default(0);
            $table->timestampTz('last_polled_at')->nullable();

            // Yoklama hatası (ağ/5xx) — maskelenmiş metin. İş `pending` kalır.
            $table->text('last_poll_error')->nullable();

            // Kanalın sonucu sakladığı süre dolunca yoklama BIRAKILIR.
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')
                ->on('channel_connections')->cascadeOnDelete();

            $table->unique(
                ['channel_connection_id', 'domain', 'external_batch_id'],
                'channel_batches_external_unique',
            );
            $table->index(['tenant_id', 'created_at'], 'channel_batches_tenant_idx');
        });

        // Yoklama turunun TEK sorgusu: bekleyen işler, en uzun süredir
        // bakılmayan önce. Kısmi indeks — bitmiş işler (çoğunluk) taranmaz.
        DB::statement(<<<'SQL'
            CREATE INDEX channel_batches_pending_idx
                ON channel_batches (last_polled_at NULLS FIRST, created_at)
                WHERE status = 'pending'
        SQL);

        Schema::create('channel_batch_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_batch_id');
            $table->uuid('listing_id');

            // Operasyon silinse de satır hükmü kalır (denetim izi).
            $table->uuid('sync_operation_id')->nullable();

            // Kanala giden kimlik — kanalın satır sonucu bununla eşlenir.
            $table->string('external_id', 191);

            // Satırın taşıdığı iş sürümü: hüküm yalnız bu sürüm hâlâ en
            // yenisiyse sync state'e yazılır.
            $table->unsignedBigInteger('entity_version');

            // pending | succeeded | failed | awaiting_approval | unknown | expired
            $table->string('outcome', 20)->default('pending');

            // sync state'e YAZILAN metnin aynısı (maskelenmiş). Başarılı bir
            // sonraki iş, satırdaki hatanın toplu işten geldiğini bu eşitlikle
            // anlar ve yalnız onu temizler.
            $table->text('reason')->nullable();
            $table->string('error_class', 32)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_batch_id')->references('id')
                ->on('channel_batches')->cascadeOnDelete();
            $table->foreign('listing_id')->references('id')->on('listings')->cascadeOnDelete();
            $table->foreign('sync_operation_id')->references('id')
                ->on('sync_operations')->nullOnDelete();

            $table->unique(['channel_batch_id', 'sync_operation_id'], 'channel_batch_items_unique');
            $table->index(['listing_id', 'outcome'], 'channel_batch_items_listing_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_batch_items');
        Schema::dropIfExists('channel_batches');
    }
};
