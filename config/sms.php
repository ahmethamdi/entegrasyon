<?php

declare(strict_types=1);

/*
 * SMS bildirimleri — satıcının KENDİ Netgsm hesabıyla.
 *
 * 34Pazar kendi adına SMS göndermez ve kredi satmaz: satıcı Netgsm
 * hesabını bağlar, mesajlar onun gönderici başlığıyla ve onun
 * kredisinden gider.
 *
 * Netgsm "SMS v3" API'si (resmî belge api-docs.netgsm.com.tr, SDK
 * github.com/netgsm/netgsm-sms-js). Eski REST v2 SDK'da "eskidi" olarak
 * işaretli; kullanılmaz. Ayrıntı docs/NETGSM-SMS-NOTLARI.md.
 */
return [

    // Acil kapatma: false → ekran 404, zamanlanmış tarama hiçbir şey göndermez.
    'enabled' => env('SMS_ENABLED', true),

    'netgsm' => [
        // Canlı uç ve Netgsm'in herkese açık test ortamı (gerçek SMS
        // göndermez, kredi düşmez, sabit veri döner).
        'live' => env('NETGSM_LIVE_URL', 'https://ext-api.netgsm.com.tr'),
        'test' => env('NETGSM_TEST_URL', 'https://ext-sandbox.netgsm.com.tr'),
    ],

    'timeout' => (int) env('SMS_TIMEOUT', 20),

    // Bildirim taraması geriye en çok bu kadar bakar: gece sessiz
    // saatlerinde biriken siparişler sabah tek özet olarak gider.
    'lookback_hours' => 12,

    // Sessiz saatler (satıcı açtıysa): bu aralıkta satıcıya ve alıcıya
    // SMS gitmez, olaylar sessizlik bitince özetlenir. Türkiye saati.
    'quiet_from' => 22,
    'quiet_until' => 8,
    'timezone' => 'Europe/Istanbul',

    // Tek tarama turunda alıcıya en çok bu kadar kargo SMS'i.
    'buyer_batch' => 50,

    // Gönderim kaydı bu kadar gün tutulur.
    'retention_days' => 90,
];
