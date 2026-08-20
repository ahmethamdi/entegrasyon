<?php

declare(strict_types=1);

/**
 * Entegrasyon uygulama ayarları.
 *
 * Mimari Karar Dokümanı v2.2 referans alınarak yazılmıştır.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Kimlik bilgisi şifreleme
    |--------------------------------------------------------------------------
    |
    | key_version yalnızca hangi kayıtların henüz yeniden şifrelenmediğini
    | görmek için tutulur. Çözme yönlendirmesi Laravel'in APP_PREVIOUS_KEYS
    | mekanizmasına bırakılır (§11).
    |
    */
    'credentials' => [
        'key_version' => (int) env('APP_KEY_VERSION', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kuyruk adları
    |--------------------------------------------------------------------------
    |
    | Mimari Karar Dokümanı v2.2 · §12. Kritik kuyruklar toplu işlerden ayrı
    | worker havuzlarında çalışır; reconciliation kendi havuzundadır.
    |
    */
    'queues' => [
        'orders' => 'orders:high',
        'inventory' => 'inventory:high',
        'inbox' => 'inbox:process',
        'price' => 'price:high',
        'listing' => 'listing:default',
        'listing_bulk' => 'listing:bulk',
        'reconciliation' => 'reconciliation',
        'maintenance' => 'maintenance',
    ],

    /*
    |--------------------------------------------------------------------------
    | Uyarı bildirimleri
    |--------------------------------------------------------------------------
    |
    | Mimari Karar Dokümanı v2.2 · §11 ("eşik aşımında e-posta") ve §12
    | ("sistem geneli: eşik aşarsa yönetici uyarısı").
    |
    | ADRES KODA GÖMÜLMEZ. Sistem ve bağlantı kapsamlı uyarıların kiracısı
    | yoktur; alıcıları buradan okunur. TANIMSIZSA o uyarılar GÖNDERİLMEZ
    | ve bu bilinçli bir kapıdır — uydurma bir adrese göndermek ya da
    | sessizce ilk kullanıcıya düşmek, uyarının yanlış kişiye gitmesi
    | demektir. Gönderilmeyen uyarı uygulama günlüğüne yazılır.
    |
    | Kiracı kapsamlı uyarılar bu ayardan BAĞIMSIZDIR: onlar kiracının
    | sahiplerine gider ve adresleri `tenant_users` üzerinden bulunur.
    |
    */
    'alerts' => [
        'admin_email' => env('ALERT_ADMIN_EMAIL'),
    ],

    /*
    | Ödeme sağlayıcısı — §13 · Faz 4.
    |
    | KULLANICI KARARI: Stripe (doküman "iyzico" diyor; sapma onaylı).
    |
    | ANAHTARLAR KODA GÖMÜLMEZ ve `.env`'den okunur. Gömülü anahtar
    | depoya sızar ve iptal edilene kadar herkes hesabı kullanabilir.
    |
    | `webhook_secret` AYRI bir sırdır ve gizli anahtarla AYNI DEĞİLDİR:
    | imza doğrulaması onunla yapılır. Karıştırılırsa doğrulama HER
    | ZAMAN başarısız olur ve hiçbir ödeme işlenmez.
    */
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

];
