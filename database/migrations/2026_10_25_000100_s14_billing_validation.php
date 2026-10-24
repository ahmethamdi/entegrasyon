<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S14 · ödeme doğrulaması — YALNIZ EKLEME (deploy.sh migration kuralı:
 * eski kod bu kolonları/tabloları bilmez ve okumaz; geriye uyumlu).
 *
 * `tenants.billing_customer_ref` — sağlayıcıdaki müşteri (Stripe `cus_…`).
 *   Sağlayıcıdan bağımsız ad (`subscriptions.external_ref` ile aynı ilke).
 *   İki iş görür: (1) her checkout'ta yeni müşteri açılmaz (terk edilen
 *   ödemeler Stripe'ta yetim müşteri bırakıyordu), (2) iade ve başarısız
 *   ödeme olayları kiracıya bağlanır — o olaylar bizim metadata'mızı
 *   taşımaz, yalnız `customer` taşır.
 *
 * `subscriptions.cancel_at` — "dönem sonunda iptal edilecek" tarihi
 *   (Stripe `cancel_at`). Abonelik o tarihe kadar `active` kalır ve kota
 *   verir; panel "şu tarihte bitecek" der.
 *
 * `billing_webhook_events` — olay kimliği TEKİLLİĞİ (Stripe en az bir kez
 *   gönderir). Kiracı kolonu YOK: sistem tablosudur, satıcıya dönmez.
 *
 * `billing_refunds` — iade kaydı; 14 gün güvencesinin "hesap başına bir
 *   kez" kuralı buradan okunur. Tutar `decimal(12,2)`, float değil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('billing_customer_ref')->nullable()->unique();
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('cancel_at')->nullable();
        });

        Schema::create('billing_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 20);
            $table->string('event_id');
            $table->string('type');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('attempts')->default(1);

            $table->unique(['provider', 'event_id']);
            $table->index('received_at');
        });

        Schema::create('billing_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('subscription_id')->nullable();
            $table->string('provider', 20);
            // Sağlayıcıdaki ödeme (Stripe `ch_…`). Kısmi iadeler aynı satırı
            // günceller: tutar KÜMÜLATİFTİR.
            $table->string('charge_ref');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->boolean('full');
            // 14 gün güvencesi kapsamında mı (hesap başına bir kez).
            $table->boolean('guarantee')->default(false);
            $table->timestamp('refunded_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->unique(['provider', 'charge_ref']);
            $table->index(['tenant_id', 'guarantee']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_refunds');
        Schema::dropIfExists('billing_webhook_events');

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('cancel_at');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['billing_customer_ref']);
            $table->dropColumn('billing_customer_ref');
        });
    }
};
