<?php

declare(strict_types=1);

namespace App\Support\Site;

/**
 * İletişim formunun konuları (6 Kas 2026 · site sprint 1).
 *
 * Anahtar adreste taşınır (`/iletisim?konu=gorusme`): menüdeki "Görüşme
 * planla" ve hizmetlerin "Teklif al" bağlantısı konuyu ön seçili açar.
 * Takvim aracı YOK (kullanıcı kararı): görüşme isteği bir mesajdır, saati
 * yazışarak belirlenir.
 */
final class ContactForm
{
    public const FORM = 'contact';

    public const TOPICS = [
        'gorusme' => 'Görüşme',
        'teklif' => 'Hizmet teklifi',
        'destek' => 'Destek',
        'diger' => 'Diğer',
    ];

    public static function topicLabel(string $key): string
    {
        return self::TOPICS[$key] ?? $key;
    }
}
