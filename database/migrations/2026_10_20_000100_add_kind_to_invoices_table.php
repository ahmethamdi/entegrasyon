<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fatura türü — satış, iade, iptal (ADR-007, S6).
 *
 *   kind = sale    siparişin satış faturası (bugünkü tüm satırlar)
 *   kind = return  kabul edilen iade talebinin muhasebe kaydı
 *   kind = cancel  satış faturasının iptali
 *
 * İade ve iptal satış faturasına bağlıdır (`parent_invoice_id`); satış
 * faturası bağsızdır — kısıt veritabanında.
 *
 * TEKİLLİK `(order_id, kind, ref)`: satışta ve iptalde `ref` boş dizedir
 * (sipariş başına bir satış, bir iptal), iadede iade talebinin kimliği
 * (talep başına bir iade kaydı). `ref` NULL OLAMAZ: Postgres NULL'ları
 * eşit saymaz ve aynı siparişe ikinci satış faturası kısıttan geçerdi.
 *
 * Geri doldurma gerekmez: var olan her satır satış faturasıdır ve
 * varsayılanlar onu tam öyle işaretler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('kind', 16)->default('sale');
            $table->uuid('parent_invoice_id')->nullable();
            $table->string('ref', 64)->default('');

            $table->foreign('parent_invoice_id')->references('id')->on('invoices')->restrictOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_order_unique');
            $table->unique(['order_id', 'kind', 'ref'], 'invoices_order_kind_ref_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE invoices
                ADD CONSTRAINT invoices_kind_check CHECK (kind IN ('sale', 'return', 'cancel')),
                ADD CONSTRAINT invoices_parent_check CHECK ((kind = 'sale') = (parent_invoice_id IS NULL))
        SQL);
    }

    public function down(): void
    {
        DB::table('invoices')->where('kind', '!=', 'sale')->delete();

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_parent_check, DROP CONSTRAINT invoices_kind_check');

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_order_kind_ref_unique');
            $table->unique('order_id', 'invoices_order_unique');
            $table->dropForeign(['parent_invoice_id']);
            $table->dropColumn(['kind', 'parent_invoice_id', 'ref']);
        });
    }
};
