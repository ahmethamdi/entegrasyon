<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel dili kullanıcı başına (TR/EN).
 *
 * NULL = seçim yapılmadı; dil tarayıcıdan türetilir (`SetLocale`).
 * Shopify inceleme ekibi İngilizce tarayıcıyla gelir ve paneli seçim
 * yapmadan İngilizce görür.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
