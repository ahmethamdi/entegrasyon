<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify Billing (5 Eki 2026) — Shopify'dan faturalanan satıcı planı
 * Shopify faturasıyla öder (App Store kuralı 1.2.1; Stripe = ret).
 *
 * USD FİYAT AYRI KOLON: Shopify Türk lirasıyla fatura KESMEZ (Türkiye'deki
 * mağaza Shopify'a USD öder). Fiyatlar kullanıcı kararı: $9.99 / $29.99 /
 * $79.99. NULL = Shopify'da satılmaz (ücretsiz plan ödeme açmaz).
 *
 * `provider`: aboneliği hangi sağlayıcının yönettiği — webhook ve plan
 * değişikliği doğru kapıya gitsin. `channel_connection_id`: Shopify
 * aboneliği MAĞAZAYA bağlıdır (o mağazanın anahtarıyla yönetilir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->decimal('shopify_price_usd', 10, 2)->nullable()->after('currency');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('provider')->default('stripe')->after('plan_code');
            $table->uuid('channel_connection_id')->nullable()->after('provider');
            $table->foreign('channel_connection_id')->references('id')->on('channel_connections')->nullOnDelete();
        });

        foreach (['starter' => 9.99, 'pro' => 29.99, 'business' => 79.99] as $code => $usd) {
            DB::table('plans')->where('code', $code)->update(['shopify_price_usd' => $usd]);
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['channel_connection_id']);
            $table->dropColumn(['provider', 'channel_connection_id']);
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('shopify_price_usd');
        });
    }
};
