<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S7 · ekip davetleri (23 Ekim 2026).
 *
 * Davet `tenant_users`'a YAZILMAZ, kendi tablosundadır: kabul edilmemiş
 * davet üyelik değildir. Üyelik satırı olsaydı `EstablishTenantContext`
 * onu üye sayar ve davet edilen kişi (aynı e-postayla başka hesabı varsa)
 * kabul etmeden panele girerdi.
 *
 * Bağlantıdaki belirteç DÜZ SAKLANMAZ (`token_hash`, sha256): tablo
 * sızarsa bekleyen davetler kabul edilemez.
 *
 * Eski `member` varsayılanı `viewer`'a çevrilir (bugüne kadar hiçbir yol
 * `member` yazmadı; satır varsa en dar role düşer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('email');
            $table->string('role');
            $table->string('token_hash', 64)->unique();
            $table->uuid('invited_by')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'accepted_at']);
        });

        // Aynı kiracıya aynı adres için TEK bekleyen davet.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tenant_invitations_pending_uniq
                ON tenant_invitations (tenant_id, lower(email))
             WHERE accepted_at IS NULL
        SQL);

        DB::table('tenant_users')->where('role', 'member')->update(['role' => 'viewer']);

        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->string('role')->default('viewer')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->string('role')->default('member')->change();
        });

        Schema::dropIfExists('tenant_invitations');
    }
};
