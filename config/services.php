<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // 34Pazar Shopify uygulaması (34Devs Partner hesabı) — OAuth istemcisi.
    // Satıcı başına DEĞİL, tek uygulama: tüm mağazalar bununla bağlanır.
    // Boşsa Shopify bağlantısı açılmaz (ShopifyAuth::configured()).
    'shopify' => [
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        'scopes' => env('SHOPIFY_SCOPES'),
        // true: bütün mağazalarda TEST aboneliği (canlı öncesi deneme).
        // Geliştirme mağazası her durumda testtir (ShopifyBilling).
        'billing_test' => (bool) env('SHOPIFY_BILLING_TEST', false),
        // Panelde "Shopify'dan kur" düğmesinin adresi — App Store sayfası.
        // Mağaza adresi panelde SORULAMAZ (App Store kuralı 2.3.1).
        'install_url' => env('SHOPIFY_INSTALL_URL', 'https://apps.shopify.com/34pazar'),
    ],

];
