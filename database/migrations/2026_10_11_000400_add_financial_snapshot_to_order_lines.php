<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satış anı finansal görüntüsü + sipariş kesintileri (ADR-002).
 *
 * order_lines (hepsi NULL = BİLİNMİYOR; 0 ile karıştırılmaz):
 *   gross_line_total     indirim ÖNCESİ kalem tutarı (kanalın verdiği)
 *   discount_total       kaleme düşen indirim
 *   net_line_total       müşterinin kalem için ödediği
 *   amounts_include_vat  yukarıdaki tutarlar KDV dahil mi (kanal tabanı)
 *   vat_rate / vat_amount
 *   unit_cost, cost_currency, cost_captured_at — SATIŞ ANINDAKİ birim maliyet
 *   financials_source    kanalın ham para alanları (kişisel veri YOK) — tanı
 *   financials_captured_at
 *
 * MEVCUT KOLONLARIN ANLAMI DEĞİŞMEZ: `unit_price`, `line_total`, sipariş
 * `subtotal/grand_total` bugünkü değerleriyle kalır (bazı kanallarda indirim
 * öncesi — denetim 06 §2). Yeniden yorumlamak geçmiş finansal veriyi sessizce
 * değiştirirdi; yeni rapor yeni kolonları okur.
 *
 * DEĞİŞMEZLİK veritabanında: bir kez yazılan görüntü kolonu (ve birim maliyet)
 * güncellenemez — tetikleyici reddeder. Maliyet sonradan değişse bile geçmiş
 * kâr değişmez.
 *
 * order_charges — komisyon / kargo kesintisi / hizmet bedeli / stopaj gibi
 * kanal kesintileri; İŞARETLİ tutar (satıcıdan düşen negatif) ve AYRI taban:
 *   basis = expected  sipariş anında kanalın bildirdiği/beklenen
 *   basis = actual    hakedişte kesinleşen (S1 içe aktarması yazar)
 * Ekleme-yalnız; `(tenant, source, external_ref)` tekil → idempotent alım.
 *
 * Geri doldurma YOK: eski siparişlerin görüntüsü bilinmiyor ve NULL kalır.
 * Maliyet bugünkü değerden doldurulmaz — bu, bugünün maliyetini geçmişin
 * maliyeti gibi gösterirdi. Geri alma: kolonlar/tablo düşer, eski veri aynen.
 */
return new class extends Migration
{
    private const SNAPSHOT_COLUMNS = [
        'gross_line_total', 'discount_total', 'net_line_total', 'amounts_include_vat',
        'vat_rate', 'vat_amount', 'unit_cost', 'cost_currency',
    ];

    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->decimal('gross_line_total', 14, 2)->nullable();
            $table->decimal('discount_total', 14, 2)->nullable();
            $table->decimal('net_line_total', 14, 2)->nullable();
            $table->boolean('amounts_include_vat')->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('vat_amount', 14, 2)->nullable();
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->char('cost_currency', 3)->nullable();
            $table->timestamp('cost_captured_at')->nullable();
            $table->jsonb('financials_source')->nullable();
            $table->timestamp('financials_captured_at')->nullable();
        });

        $guards = implode("\n", array_map(static fn (string $c): string => <<<SQL
                IF OLD.{$c} IS NOT NULL AND NEW.{$c} IS DISTINCT FROM OLD.{$c} THEN
                    RAISE EXCEPTION 'order_lines.{$c} satış anı görüntüsüdür ve değiştirilemez';
                END IF;
            SQL, self::SNAPSHOT_COLUMNS));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION order_lines_snapshot_immutable() RETURNS trigger AS \$\$
            BEGIN
            {$guards}
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER order_lines_snapshot_immutable
                BEFORE UPDATE ON order_lines
                FOR EACH ROW EXECUTE FUNCTION order_lines_snapshot_immutable();
        SQL);

        Schema::create('order_charges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');
            $table->uuid('order_line_id')->nullable();
            $table->uuid('channel_connection_id');

            // commission | commission_refund | shipping_fee | service_fee | withholding | other
            $table->string('kind', 32);
            $table->string('basis', 16);          // expected | actual
            $table->decimal('amount', 14, 2);     // İŞARETLİ: satıcıdan düşen negatif
            $table->char('currency', 3);
            $table->decimal('rate', 7, 4)->nullable();  // ör. komisyon oranı %

            $table->string('source', 32);         // order | settlement | manual
            $table->string('external_ref')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('order_line_id')->references('id')->on('order_lines')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')->on('channel_connections')->cascadeOnDelete();

            $table->index(['tenant_id', 'order_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE order_charges
                ADD CONSTRAINT order_charges_basis_check CHECK (basis IN ('expected', 'actual')),
                ADD CONSTRAINT order_charges_kind_check CHECK (kind IN
                    ('commission', 'commission_refund', 'shipping_fee', 'service_fee', 'withholding', 'other'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX order_charges_external_unique
                ON order_charges (tenant_id, source, external_ref)
                WHERE external_ref IS NOT NULL
        SQL);

        // Ekleme-yalnız: tutar/taban/tür sonradan değiştirilemez.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION order_charges_append_only() RETURNS trigger AS $$
            BEGIN
                IF NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.basis IS DISTINCT FROM OLD.basis
                    OR NEW.kind IS DISTINCT FROM OLD.kind
                    OR NEW.currency IS DISTINCT FROM OLD.currency THEN
                    RAISE EXCEPTION 'order_charges kaydı değiştirilemez; düzeltme yeni kayıtla yapılır';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_charges_append_only
                BEFORE UPDATE ON order_charges
                FOR EACH ROW EXECUTE FUNCTION order_charges_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_charges');
        DB::unprepared('DROP FUNCTION IF EXISTS order_charges_append_only() CASCADE');
        DB::unprepared('DROP TRIGGER IF EXISTS order_lines_snapshot_immutable ON order_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS order_lines_snapshot_immutable()');

        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'gross_line_total', 'discount_total', 'net_line_total', 'amounts_include_vat',
                'vat_rate', 'vat_amount', 'unit_cost', 'cost_currency', 'cost_captured_at',
                'financials_source', 'financials_captured_at',
            ]);
        });
    }
};
