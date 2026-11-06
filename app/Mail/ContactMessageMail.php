<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Site\ContactForm;
use App\Support\Site\Services;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * İletişim formu → 34Devs posta kutusu (`site.contact_form_to`), DÜZ METİN.
 *
 * HTML şablonu yok, bilerek: ziyaretçinin yazdığı metin aynen okunur,
 * biçimlendirme ya da bağlantı olarak işlenmez. "Yanıtla" doğrudan
 * ziyaretçiye gider (`replyTo`); gönderen adresi uygulamanın kendi
 * adresidir — ziyaretçinin adresinden posta gönderilseydi SPF/DMARC'tan
 * düşerdi.
 *
 * Kuyruğa atılmaz: gönderim denetleyicide denenir ve sonucu
 * `contact_messages.mailed_at`'e yazılır; kuyrukta düşseydi kayıt "gitti"
 * görünürdü.
 */
final class ContactMessageMail extends Mailable
{
    use Queueable;

    /** @param array{name: string, email: string, phone: ?string, topic: string, service: ?string, message: string} $data */
    public function __construct(public readonly array $data) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->data['email'], $this->data['name'])],
            subject: sprintf('[34Pazar iletişim] %s — %s', ContactForm::topicLabel($this->data['topic']), $this->data['name']),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.contact-message',
            with: [
                'topicLabel' => ContactForm::topicLabel($this->data['topic']),
                'serviceTitle' => $this->data['service'] !== null ? (Services::find($this->data['service'])['title'] ?? $this->data['service']) : null,
            ],
        );
    }
}
