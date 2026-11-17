<?php

declare(strict_types=1);

namespace App\Domain\Sms\Support;

/**
 * Türkiye cep telefonu numarası — Netgsm v3'ün istediği biçim `90XXXXXXXXXX`.
 *
 * Netgsm `0…` ve `5…` ile başlayan yerel biçimi REDDEDER (test ortamında
 * ölçüldü, belge: sms-v3 send regex). Burada her yazım (0532 111 22 33,
 * +90 532…, 5321112233) tek biçime çevrilir. Cep telefonu olmayan
 * (sabit hat, yabancı) numara null döner: kargo SMS'i sabit hatta gitmez.
 */
final class SmsPhone
{
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        if (str_starts_with($digits, '0090')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = '90'.substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '90'.$digits;
        }

        // 90 + 5XX XXX XX XX
        return preg_match('/^905\d{9}$/', $digits) === 1 ? $digits : null;
    }

    /** Kayıt ve ekran için: 90532•••4455. */
    public static function mask(string $normalized): string
    {
        if (strlen($normalized) < 8) {
            return '•••';
        }

        return substr($normalized, 0, 5).'•••'.substr($normalized, -4);
    }
}
