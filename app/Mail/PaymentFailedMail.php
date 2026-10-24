<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * S14 · "ödemen alınamadı" — hesap sahibine, Türkçe.
 *
 * Stripe'ın kendi "başarısız ödeme" postası AÇIKSA çift posta gider:
 * Dashboard → Settings → Billing → Subscriptions and emails → "Send emails
 * when card payments fail" KAPALI olmalı (API'den okunamıyor; RUNBOOK).
 *
 * TAVSİYE ZORUNLU: en sık sebep Türk bankasının yurt dışı/internet
 * alışverişini kapalı tutması (Stripe hesabı Almanya'da). Posta bunu söyler.
 *
 * Kuyruğa atılmaz: webhook olayının tekilliği (`WebhookEventLedger`)
 * gönderimle aynı istekte damgalanır; kuyruğa atılsaydı iş düşse de olay
 * "işlendi" sayılır ve posta hiç gitmezdi.
 */
final class PaymentFailedMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $amount,
        public readonly ?string $nextAttempt,
        public readonly string $payUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "34Pazar ödemen alınamadı ({$this->amount})");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment-failed');
    }
}
