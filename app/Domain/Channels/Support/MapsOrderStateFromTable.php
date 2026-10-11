<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Orders\Enums\OrderState;
use App\Domain\Sync\Support\NormalizedOrderEvent;

/**
 * `MapsOrderState`'in tablo uygulaması — adapter `ORDER_STATE_MAP` sabitini tanımlar.
 *
 * Anahtar ham kelimenin NORMAL biçimidir: küçük harf, boşluk/tire → `_`
 * (`Fully Refunded` → `fully_refunded`, `on-hold` → `on_hold`). Önce
 * `status`, eşleşmezse `financial_status` sorulur.
 *
 * Tablo bilinçli olarak her kanal sınıfında AYRI durur (adapter'ın şeklidir);
 * yalnız bakma kodu ortaktır.
 */
trait MapsOrderStateFromTable
{
    public function orderStateFor(NormalizedOrderEvent $event): ?OrderState
    {
        foreach (['status', 'financial_status'] as $field) {
            $raw = $event->payload[$field] ?? null;

            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            $state = static::ORDER_STATE_MAP[self::orderStateKey($raw)] ?? null;

            if ($state !== null) {
                return OrderState::from($state);
            }
        }

        return null;
    }

    public static function orderStateKey(string $raw): string
    {
        return (string) preg_replace('/[\s\-]+/', '_', mb_strtolower(trim($raw)));
    }
}
