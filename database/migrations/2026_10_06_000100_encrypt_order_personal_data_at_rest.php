<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alıcıya dokunan üç kolon diskte ŞİFRELİ durur (App Store "encrypt at rest").
 *
 *   orders.customer_ref · order_events.payload · inbox_messages.payload
 *
 * jsonb → text: şifreli metin JSON değildir. Biçim modellerin
 * `encrypted:array` cast'iyle aynıdır (`App\Support\Privacy\SealedJson`).
 *
 * `inbox_messages.resource_id`: gövdenin üst düzey `id`'si. Shopify
 * `customers/redact` siparişi `payload->>'id'` ile buluyordu; gövde
 * şifrelenince bu sorgu imkânsız. Kolon ŞİFRELEMEDEN ÖNCE jsonb'den
 * doldurulur.
 *
 * Postgres'te migration tek transaction'dır: şifreleme yarıda kalırsa
 * kolon tipi de geri döner, düz/şifreli karışık tablo oluşmaz.
 */
return new class extends Migration
{
    /** @var array<string, string> tablo => kolon */
    private const COLUMNS = [
        'orders' => 'customer_ref',
        'order_events' => 'payload',
        'inbox_messages' => 'payload',
    ];

    public function up(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table): void {
            $table->string('resource_id', 191)->nullable();
            $table->index(['channel_connection_id', 'resource_id'], 'inbox_messages_resource_idx');
        });

        DB::statement(<<<'SQL'
            UPDATE inbox_messages
               SET resource_id = left(payload->>'id', 191)
             WHERE jsonb_typeof(payload) = 'object'
               AND jsonb_typeof(payload->'id') IN ('string', 'number')
        SQL);

        foreach (self::COLUMNS as $table => $column) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE text USING {$column}::text");

            $this->rewrite($table, $column, fn (string $json): string => Crypt::encryptString($json));
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            $this->rewrite($table, $column, fn (string $sealed): string => Crypt::decryptString($sealed));

            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE jsonb USING {$column}::jsonb");
        }

        Schema::table('inbox_messages', function (Blueprint $table): void {
            $table->dropIndex('inbox_messages_resource_idx');
            $table->dropColumn('resource_id');
        });
    }

    /** @param  Closure(string): string  $transform */
    private function rewrite(string $table, string $column, Closure $transform): void
    {
        DB::table($table)
            ->whereNotNull($column)
            ->select(['id', $column])
            ->lazyById(500)
            ->each(fn (object $row) => DB::table($table)
                ->where('id', $row->id)
                ->update([$column => $transform((string) $row->{$column})]));
    }
};
