<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hoş geldin postası tekilliği (25 Ekim 2026). Yalnız ekleme, nullable:
 * eski kod kolonu okumaz. Var olan kullanıcılar NULL kalır ama posta
 * yalnız doğrulama ANINDA tetiklenir — geçmiş hesaplara toplu posta gitmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('welcome_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('welcome_sent_at');
        });
    }
};
