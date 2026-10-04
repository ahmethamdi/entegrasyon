<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panelden girilen kargo bildiriminin kanala gönderim durumu.
 *
 * `fulfillments` şimdiye kadar yalnız KANALDAN gelen kargoyu tutuyordu.
 * Satıcı takip numarasını artık bizim panelden girer ve satır kanala
 * GÖNDERİLİR; gönderimin durumu satırın kendisinde yaşar.
 *
 * `push_status` NULL = kanaldan geldi, gönderilecek bir şey yok.
 * Ayrı kolon (`status` değil): `status` kanalın söylediği teslim durumudur
 * ("success", "delivered") ve kanal yankısı onu ezer; gönderim durumu
 * aynı kolonda yaşasaydı yankı "gönderilemedi" uyarısını SİLERDİ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillments', function (Blueprint $table): void {
            $table->string('source')->default('channel');
            $table->string('push_status')->nullable();
            $table->unsignedSmallInteger('push_attempts')->default(0);
            $table->text('push_error')->nullable();
            $table->timestamp('pushed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fulfillments', function (Blueprint $table): void {
            $table->dropColumn(['source', 'push_status', 'push_attempts', 'push_error', 'pushed_at']);
        });
    }
};
