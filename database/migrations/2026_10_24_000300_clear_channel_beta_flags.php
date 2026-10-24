<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Beta durumu kaldırıldı (kullanıcı kararı 24 Eki 2026): bütün kanallar
 * sitede ve panelde "bağlı" görünür, rozet yok. Kolon kalır (geri dönüş
 * ucuz olsun); değer her kanalda false. `is_active` DOKUNULMAZ.
 *
 * Geri alma bilerek boştur: hangi kanalın Beta olduğu artık karar değil,
 * eski listeyi geri yazmak kararı da geri getirirdi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('channel_types')->where('is_beta', true)->update(['is_beta' => false]);
    }

    public function down(): void
    {
        //
    }
};
