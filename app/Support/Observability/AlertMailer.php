<?php

declare(strict_types=1);

namespace App\Support\Observability;

use App\Mail\MetricAlertMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Uyarıyı e-postaya çevirip gönderir.
 *
 * Mimari Karar Dokümanı v2.2 · §11, §12.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SAĞLAYICIDAN BAĞIMSIZ
 * ─────────────────────────────────────────────────────────────────────
 * Laravel'in `Mail` cephesi kullanılır; Mailgun/Postmark/SES seçimi
 * `.env`'deki `MAIL_MAILER` ile yapılır ve BU KOD DEĞİŞMEZ. Sağlayıcı
 * SDK'sı doğrudan çağrılsaydı seçim koda gömülür ve değiştirmek
 * yeniden yazmak demek olurdu. Bugün `log` sürücüsü kullanılıyor:
 * e-postalar `storage/logs` altına düşer ve akış uçtan uca sınanabilir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DEĞİŞMEZ KURAL — GÖNDERİM HATASI TARAMAYI DÜŞÜRMEZ
 * ─────────────────────────────────────────────────────────────────────
 * `api_calls` günlüklemesindeki kuralın aynısı: yan iş, ana işi
 * durdurmamalı. SMTP sağlayıcısının erişilemez olması KALAN uyarıların
 * da gönderilmemesi demek olmamalı — biri düşerse diğerleri denenir.
 * Hata yutulur ama SESSİZ DEĞİLDİR: uygulama günlüğüne yazılır.
 *
 * Buradaki risk kabul edilmiştir: çıpa gönderimden ÖNCE yazıldığı için
 * düşen bir e-posta o gün TEKRAR DENENMEZ. Alternatifi (çıpayı sonra
 * yazmak) aynı uyarının iki kez gitmesine kapı açardı ve §12'nin amacı
 * dikkat çekmektir — tekrar eden bildirim dikkati YOK EDER.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DEĞİŞMEZ KURAL — ALICILAR BİRBİRİNİ GÖRMEZ
 * ─────────────────────────────────────────────────────────────────────
 * Her alıcıya AYRI gönderilir. Tek `to()` çağrısına dizi verilseydi
 * kiracının sahipleri birbirinin e-posta adresini görürdü; aynı
 * kiracının üyeleri için bu görece zararsız görünse de adres sızdırmak
 * geri alınamaz ve `Bcc` yerine ayrı gönderim niyeti açıkça belgeler.
 */
final class AlertMailer
{
    /**
     * @param  array{key: string, metric: Metric, scope: string, value: float, tenantId: ?string}  $breach
     * @param  list<string>  $recipients
     */
    public function send(array $breach, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send(new MetricAlertMail(
                    metric: $breach['metric'],
                    scope: $breach['scope'],
                    value: $breach['value'],
                ));
            } catch (Throwable $e) {
                // YUTULUR ama SESSİZ DEĞİL — gerekçe sınıf başlığında.
                Log::warning('alerts.mail_failed', [
                    'alert_key' => $breach['key'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
