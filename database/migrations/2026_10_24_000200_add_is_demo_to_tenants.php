<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S12 · herkese açık demo hesabı (`/demo`).
 *
 * Demo kiracısı salt okunurdur ve dışarı istek atmaz; bayrak bunu
 * tek yerden söyler (AuthorizeTenantRoute, ChannelHttpClient).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('is_demo');
        });
    }
};
