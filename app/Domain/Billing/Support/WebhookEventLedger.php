<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * S14 · ödeme olaylarının tekilliği — Stripe EN AZ BİR KEZ gönderir.
 *
 * Abonelik satırı zaten `external_ref` ile idempotentti; ama yeni olaylar
 * (başarısız ödeme postası, iade kaydı, denetim kaydı) her tekrarda bir kez
 * daha iş yapardı: satıcıya aynı "ödemen alınamadı" postası iki kez gider.
 *
 * KURAL: olay İŞLENDİKTEN SONRA "işlendi" damgalanır. İşleme ortasında
 * düşerse (500) satır `processed_at = NULL` kalır, Stripe yeniden dener ve
 * olay yeniden işlenir — kaybolmaz. İşlenmiş olay tekrar gelirse atlanır.
 */
final class WebhookEventLedger
{
    /** Olay işlenmeli mi? İşlenmişse false (tekrar), değilse kaydı açar/sayar. */
    public function claim(string $provider, string $eventId, string $type): bool
    {
        $inserted = DB::table('billing_webhook_events')->insertOrIgnore([
            'id' => self::uuid(),
            'provider' => $provider,
            'event_id' => $eventId,
            'type' => $type,
            'received_at' => now(),
            'attempts' => 1,
        ]);

        if ($inserted === 1) {
            return true;
        }

        $row = DB::table('billing_webhook_events')
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->first(['processed_at']);

        if ($row?->processed_at !== null) {
            return false;
        }

        DB::table('billing_webhook_events')
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->increment('attempts');

        return true;
    }

    public function markProcessed(string $provider, string $eventId): void
    {
        DB::table('billing_webhook_events')
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->update(['processed_at' => now()]);
    }

    private static function uuid(): string
    {
        return (string) new UuidV7;
    }
}
