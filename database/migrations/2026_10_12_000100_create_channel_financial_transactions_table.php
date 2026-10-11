<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kanalın HAM finansal işlem defteri (S1 · Trendyol cari hesap ekstresi).
 *
 * İki katman tek satırda ama AYRI kolonlarda:
 *   - HAM OLGU: kanalın söylediği (kimlik, tür etiketi, borç/alacak,
 *     komisyon, hakediş, tarihler, ham gövde). Veritabanı tetikleyicisiyle
 *     DEĞİŞTİRİLEMEZ — yorumumuz değişince olgu ezilmez.
 *   - YORUM: normalize tür, sınıflandırma durumu, sipariş eşleşmesi.
 *     Yeniden sınıflandırma/eşleme bunları günceller.
 *
 * Kolonlar canlı yanıta göre (11 Eki 2026, `S1-TRENDYOL-HAKEDIS-ANALIZI.md`):
 * `currency` belgede yok ama yanıtta var; adet alanı yok.
 *
 * Tutarlar NULL olabilir: NULL = kanal bildirmedi, 0 DEĞİL.
 */
return new class extends Migration
{
    private const IMMUTABLE = [
        'tenant_id', 'channel_connection_id', 'source', 'external_id', 'type_raw',
        'debt', 'credit', 'commission_amount', 'commission_rate', 'seller_revenue',
        'currency', 'order_number', 'shipment_package_id', 'barcode',
        'transaction_date', 'payment_date', 'payment_order_id', 'receipt_id',
        'commission_invoice_serial', 'raw', 'raw_hash',
    ];

    public function up(): void
    {
        Schema::create('channel_financial_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_connection_id');

            // ---- ham olgu
            $table->string('source', 32);                 // settlements | otherfinancials
            $table->string('external_id');                // kanalın işlem kimliği
            $table->string('type_raw');                   // "Satış", "Komisyon Faturası"…
            $table->decimal('debt', 14, 2)->nullable();
            $table->decimal('credit', 14, 2)->nullable();
            $table->decimal('commission_amount', 14, 2)->nullable();
            $table->decimal('commission_rate', 7, 4)->nullable();
            $table->decimal('seller_revenue', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('order_number')->nullable();
            $table->string('shipment_package_id')->nullable();
            $table->string('barcode')->nullable();
            $table->timestamp('transaction_date')->nullable();
            $table->timestamp('payment_date')->nullable();
            $table->string('payment_order_id')->nullable();
            $table->string('receipt_id')->nullable();
            $table->string('commission_invoice_serial')->nullable();
            $table->string('description', 1000)->nullable();
            $table->jsonb('raw');
            $table->char('raw_hash', 64);

            // ---- yorum (güncellenebilir)
            $table->string('type', 48)->nullable();       // normalize; NULL = tanınmadı
            $table->string('classification', 16);         // verified | unverified | unknown
            $table->string('classification_reason')->nullable();
            $table->uuid('order_id')->nullable();
            $table->string('match_status', 24);           // matched | unmatched | ambiguous | not_order_level
            $table->string('match_reason')->nullable();
            $table->timestamp('matched_at')->nullable();

            $table->timestamps();                          // created_at = içe alınma anı

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')->on('channel_connections')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();

            // İDEMPOTENS: aynı kanal işlemi bir kez. Kaynak dahil — iki uç
            // noktanın kimlik uzayı ayrı (satış "14124406379", fatura "DCA…").
            $table->unique(['channel_connection_id', 'source', 'external_id'], 'cft_identity_unique');
            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'match_status']);
            $table->index(['channel_connection_id', 'shipment_package_id']);
        });

        DB::statement("ALTER TABLE channel_financial_transactions ADD CONSTRAINT cft_classification_check CHECK (classification IN ('verified', 'unverified', 'unknown'))");
        DB::statement("ALTER TABLE channel_financial_transactions ADD CONSTRAINT cft_match_status_check CHECK (match_status IN ('matched', 'unmatched', 'ambiguous', 'not_order_level'))");

        $guards = implode("\n", array_map(static fn (string $c): string => <<<SQL
                IF NEW.{$c} IS DISTINCT FROM OLD.{$c} THEN
                    RAISE EXCEPTION 'channel_financial_transactions.{$c} kanalın ham olgusudur ve değiştirilemez';
                END IF;
            SQL, self::IMMUTABLE));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION channel_financial_transactions_raw_immutable() RETURNS trigger AS \$\$
            BEGIN
            {$guards}
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER channel_financial_transactions_raw_immutable
                BEFORE UPDATE ON channel_financial_transactions
                FOR EACH ROW EXECUTE FUNCTION channel_financial_transactions_raw_immutable();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_financial_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS channel_financial_transactions_raw_immutable() CASCADE');
    }
};
