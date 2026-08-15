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

];
