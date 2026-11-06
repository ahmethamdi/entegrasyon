<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İletişim formu gönderimleri (6 Kasım 2026 · site sprint 1).
 *
 * Platform düzeyi, kiracısız: formu dolduran çoğu zaman henüz müşteri
 * değildir. `tenant_id` YOK (TenantScopeContractTest'e girmez); Eloquent
 * modeli yok, `DB::table` ile yazılır ve okunur (`visit_stats` ile aynı).
 *
 * Posta gönderilemese de kayıt kalır: `mailed_at` boşsa yönetim ekranı
 * mesajı "posta gitmedi" diye gösterir — gönderim hatası sessizce mesaj
 * kaybettirmez.
 *
 * IP ham saklanmaz (çerezsiz sayaçla aynı ilke); kötüye kullanım için hız
 * sınırı IP üzerinden önbellekte tutulur, veritabanına girmez.
 *
 * Yalnız ekleme: eski kod bu tabloyu okumaz, geri alma tabloyu düşürür.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 40)->nullable();
            $table->string('topic', 20);
            $table->string('service', 60)->nullable();
            $table->text('message');
            $table->timestampTz('mailed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
