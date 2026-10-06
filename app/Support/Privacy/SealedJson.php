<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use Illuminate\Support\Facades\Crypt;

/**
 * Sipariş verisini diskte ŞİFRELİ tutar (at-rest encryption).
 *
 * App Store korumalı müşteri verisi formunda "verileri durağan halde
 * şifreliyor musunuz" sorusuna EVET dendi (6 Eki 2026). Kapsam: alıcıya
 * dokunan üç kolon — `orders.customer_ref`, `order_events.payload`,
 * `inbox_messages.payload`.
 *
 * Modeller `encrypted:array` cast'i kullanır; bu sınıf AYNI BİÇİMİ
 * (`Crypt::encryptString(json)`) üretir. Neden ayrı bir sınıf:
 * bu tabloların yazımlarının çoğu `DB::table()->insertOrIgnore()` ile
 * yapılır (tekillik ihlali Postgres transaction'ını kirletmesin diye) ve
 * `DB::table()` cast'ten GEÇMEZ. Ham yazımda `json_encode` kalsaydı satır
 * sessizce DÜZ METİN yazılır, okurken de `DecryptException` patlardı.
 *
 * KURAL: bu üç kolona ham sorguyla yazan her yer `seal()` kullanır.
 * `ColumnsEncryptedAtRestTest` diskteki değerin düz JSON olmadığını
 * her yazım yolu için ölçer.
 *
 * Anahtar `APP_KEY`'dir: kaybolursa bu kolonlar da (kanal anahtarları
 * gibi) okunamaz. Döndürmede eski anahtar `APP_PREVIOUS_KEYS`'e yazılır.
 */
final class SealedJson
{
    /** @param  array<array-key, mixed>|null  $value */
    public static function seal(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<array-key, mixed>|null */
    public static function open(?string $sealed): ?array
    {
        if ($sealed === null) {
            return null;
        }

        $decoded = json_decode(Crypt::decryptString($sealed), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }
}
