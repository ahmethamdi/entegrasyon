<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Beta kanallar (kullanıcı kararı 21 Eki 2026).
 *
 * Gerçek hesapta doğrulanmamış kanallar canlıda AÇIK olur ama "Beta" rozeti
 * taşır. Liste bu migration'da ANLIK GÖRÜNTÜ olarak yazılır (o günkü karar);
 * sonraki değişiklikler seeder ile gelir. 24 Eki 2026: Beta kaldırıldı
 * (2026_10_24_000300 bütün bayrakları söndürür).
 */
return new class extends Migration
{
    private const BETA = ['woocommerce', 'hepsiburada', 'n11', 'ikas', 'ticimax', 'pazarama', 'ciceksepeti', 'ebay'];

    public function up(): void
    {
        Schema::table('channel_types', function (Blueprint $table): void {
            $table->boolean('is_beta')->default(false);
        });

        DB::table('channel_types')
            ->whereIn('code', self::BETA)
            ->update(['is_beta' => true, 'is_active' => true]);
    }

    public function down(): void
    {
        Schema::table('channel_types', function (Blueprint $table): void {
            $table->dropColumn('is_beta');
        });
    }
};
