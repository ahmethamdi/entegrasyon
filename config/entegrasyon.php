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

    /*
    | Kuyruk paneli (/horizon) erişimi — virgülle ayrılmış e-postalar.
    | Boşsa üretimde kimse göremez (HorizonServiceProvider).
    */
    /*
    | ADR-010 · Canlı doğrulama kapısından GEÇMEMİŞ kanallar. Bu türlerin
    | bağlantılarında yazma izinleri (ürün yayını, fiyat, stok) panelden
    | AÇILAMAZ. Bir kanal listeden yalnız canlı doğrulama tamamlanıp açık
    | onay verildiğinde çıkarılır.
    */
    'outbound_write_locked_channels' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OUTBOUND_WRITE_LOCKED_CHANNELS', 'hepsiburada,n11')),
    ))),

    /*
    | S5 · Tedarikçi XML akışı en büyük boyutu (bayt). Okuma bu sınırda
    | KESİLİR; tur FETCH_FAILED (SUPPLIER_FEED_TOO_LARGE) olur.
    */
    'supplier_feed_max_bytes' => (int) env('SUPPLIER_FEED_MAX_BYTES', 64_000_000),

    'horizon_admin_emails' => env('HORIZON_ADMIN_EMAILS', ''),

    /*
    | Süper admin (/admin) — virgülle ayrılmış e-postalar. Boşsa Horizon
    | listesine düşer: iki ayrı yönetici listesi tutulup ayrışmasın diye.
    | E-postası DOĞRULANMAMIŞ hesap listede olsa bile giremez.
    */
    'super_admin_emails' => env('SUPER_ADMIN_EMAILS', env('HORIZON_ADMIN_EMAILS', '')),
];
