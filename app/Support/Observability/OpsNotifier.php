<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 34Devs ekibine anlık bildirim — ntfy + posta (S7 · 23 Ekim 2026).
 *
 * `BetaChannelNotifier`'ın gönderim kısmı buraya taşındı; izleme turu
 * (`ops:watch`) da aynı yoldan gider. Satıcıya DEĞİL, işletene gider:
 * satıcıya giden günlük özet `alerts:dispatch`'tir.
 *
 * TEKRAR KORUMASI ANAHTAR BAŞINA: aynı anahtar `throttleSeconds` içinde
 * ikinci kez gönderilmez (`Cache::add`). Black Friday'de kuyruk 3 saat
 * tıkalı kalırsa telefon 5 dakikada bir değil saatte bir öter.
 *
 * Gönderim hatası işi DÜŞÜRMEZ — yalnız günlüğe yazılır.
 */
final class OpsNotifier
{
    public function send(string $title, string $body, string $tag = 'warning', ?string $throttleKey = null, int $throttleSeconds = 3600): bool
    {
        if ($throttleKey !== null && ! Cache::add("ops-notify:{$throttleKey}", 1, $throttleSeconds)) {
            return false;
        }

        $topic = (string) config('entegrasyon.beta.ntfy_topic');

        if ($topic !== '') {
            try {
                Http::timeout(5)->post(rtrim((string) config('entegrasyon.beta.ntfy_base_url'), '/').'/', [
                    'topic' => $topic,
                    'title' => $title,
                    'message' => $body,
                    'tags' => [$tag],
                ]);
            } catch (Throwable $e) {
                Log::warning('ops_notify.ntfy_failed', ['error' => $e->getMessage()]);
            }
        }

        $email = (string) config('entegrasyon.beta.notify_email');

        if ($email !== '') {
            try {
                Mail::raw($body, function ($message) use ($email, $title): void {
                    $message->to($email)->subject('[34Pazar] '.$title);
                });
            } catch (Throwable $e) {
                Log::warning('ops_notify.mail_failed', ['error' => $e->getMessage()]);
            }
        }

        return true;
    }
}
