<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mimari Karar Dokümanı v2.2 · §4 · Channels · tablolar 014–016.
 *
 * MIGRATION SIRASI NOTU: dokümanın mantıksal tablo listesinde kanal tabloları
 * 14–16 sırasında, stok tabloları 11–12 sırasındadır. Ancak
 * inventory_movements.channel_connection_id bu tabloya FK verir; PostgreSQL'de
 * referans verilen tablo önce yaratılmak zorundadır. Bu yüzden fiziksel
 * migration sırası kanal tablolarını stoktan öne alır. Şema tanımları
 * değişmemiştir.
 *
 * channel_connections üzerindeki ikinci tekillik kısıtı güvenlik amaçlıdır:
 * bir mağaza alan adı yalnızca tek bir kiracıya bağlanabilir (§11, Shopify
 * servis sınırı).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Kiracısız statik tanım tablosu; birincil anahtar metin koddur.
        Schema::create('channel_types', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->string('kind');                  // marketplace | storefront
            $table->string('adapter_class');
            $table->jsonb('capabilities')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('rate_limit_profile')->default(DB::raw("'{}'::jsonb"));
            $table->boolean('supports_webhooks')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('channel_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('channel_type_code');
            $table->string('label');
            $table->string('external_account_id');
            $table->string('status')->default('pending');
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
            $table->string('health_status')->default('unknown');
            $table->timestamp('last_healthy_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_type_code')->references('code')->on('channel_types');

            $table->unique(['tenant_id', 'channel_type_code', 'external_account_id'],
                'channel_connections_tenant_account_unique');

            // Bir mağaza alan adı yalnızca TEK kiracıya bağlanabilir.
            $table->unique(['channel_type_code', 'external_account_id'],
                'channel_connections_account_unique');

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('channel_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_connection_id');
            $table->text('encrypted_payload');
            $table->integer('key_version')->default(1);
            $table->string('scope')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')
                ->on('channel_connections')->cascadeOnDelete();
        });

        // Bağlantı başına tek AKTİF kimlik bilgisi; iptal edilmişler saklanır.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX channel_credentials_active_unique
                ON channel_credentials (channel_connection_id)
                WHERE revoked_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX channel_credentials_expiring_idx
                ON channel_credentials (expires_at)
                WHERE revoked_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS channel_credentials_expiring_idx');
        DB::statement('DROP INDEX IF EXISTS channel_credentials_active_unique');
        Schema::dropIfExists('channel_credentials');
        Schema::dropIfExists('channel_connections');
        Schema::dropIfExists('channel_types');
    }
};
