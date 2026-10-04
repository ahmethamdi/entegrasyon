<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E-posta doğrulama devreye girerken VAR OLAN hesaplar doğrulanmış sayılır (B4).
 *
 * Panel `verified` ara katmanının arkasına alındı. Bu migration olmasaydı
 * özellik açıldığı an kayıtlı her satıcı panelden KİLİTLENİR ve hiç
 * istemediği bir doğrulama postasını beklerdi. Kural yalnız bundan SONRA
 * açılan hesaplar içindir. Geri alma yoktur: hangi damganın buradan
 * geldiği ayırt edilemez ve damgayı silmek hesapları kilitlerdi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Bilinçli olarak boş — sınıf başlığı.
    }
};
