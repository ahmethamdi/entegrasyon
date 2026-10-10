<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * e-fatura — satıcının fatura entegratörü hesabı ve siparişe kesilen fatura.
 *
 * ALICININ KİŞİSEL VERİSİ BU TABLOLARDA YOKTUR (kullanıcı kararı, 10 Eki
 * 2026): ad, adres, TCKN/VKN fatura kesilirken kanaldan ANLIK okunur ve
 * doğrudan entegratöre gider. Burada yalnız entegratörün KİMLİKLERİ
 * (cari, fatura, iş, belge) ve fatura numarası tutulur — faturanın
 * kendisi satıcının Paraşüt hesabında yaşar ve yasal saklama oradadır.
 *
 * `invoice_accounts` kiracı başına TEK satırdır: bir satıcı tek muhasebe
 * hesabından fatura keser. Kanal bağlantısı DEĞİLDİR — kanal kotasına,
 * yetenek haritasına ve devre kesicisine karışmasın diye ayrı tablodadır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // parasut (sonra: birfatura, edm …)
            $table->string('provider', 32);

            // Token'lar ŞİFRELİ (`encrypted:array`); şifresiz kolona ve
            // Inertia prop'una asla düşmez.
            $table->text('credentials')->nullable();
            $table->timestampTz('token_expires_at')->nullable();

            // Paraşüt firma kimliği — adres yolunda gider. Birden çok
            // firması olan kullanıcıda panelden seçilir.
            $table->string('company_id', 32)->nullable();
            $table->string('company_name')->nullable();

            // Şifresiz, ekranda görünen ayarlar (fatura serisi …).
            $table->jsonb('settings')->nullable();

            // connected | needs_company | revoked
            $table->string('status', 16);
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique('tenant_id', 'invoice_accounts_tenant_unique');
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');
            $table->string('provider', 32);

            // pending → issuing → issued | failed
            $table->string('status', 16);

            // e_archive | e_invoice — entegratörün mükellef sorgusu belirler.
            $table->string('document_type', 16)->nullable();

            // Entegratör kimlikleri. HER ADIMDAN SONRA yazılır: iş yarıda
            // kesilirse yeniden deneme kaldığı yerden devam eder, ikinci
            // satış faturası ya da ikinci e-belge AÇMAZ.
            $table->string('provider_contact_id', 64)->nullable();
            $table->string('provider_invoice_id', 64)->nullable();
            $table->string('provider_job_id', 64)->nullable();
            $table->string('provider_document_id', 64)->nullable();

            $table->string('invoice_number', 32)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('issued_at')->nullable();

            $table->uuid('requested_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);

            // SİPARİŞ BAŞINA TEK FATURA: çift tıklama ya da iki sekme aynı
            // siparişe iki fatura kesemez. Başarısız fatura aynı satırda
            // yeniden denenir. (İade/iptal faturası ayrı belge türüdür ve
            // geldiğinde bu kısıt türe göre genişletilir.)
            $table->unique('order_id', 'invoices_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_accounts');
    }
};
