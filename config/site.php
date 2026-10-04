<?php

declare(strict_types=1);

/*
 * Tanıtım sitesinin şirket bilgileri — künye, yasal metinler, footer ve
 * JSON-LD buradan okur. Tek yerde durur: adres değişince yasal metinlerin
 * biri eski adreste kalmasın.
 *
 * `contact_email` 34Devs'in ortak adresi (kullanıcı kararı, 5 Ekim 2026);
 * 34pazar.com posta kutusu açılınca SITE_CONTACT_EMAIL ile değişir.
 */
return [
    'brand' => '34Pazar',
    'operator' => '34Devs — Ahmet Hamdi Kilic',
    'owner' => 'Ahmet Hamdi Kilic',
    'street' => 'Hedwigstraße 27',
    'postal_code' => '41352',
    'city' => 'Korschenbroich',
    'country' => 'Almanya',
    'phone' => '+49 176 76798125',
    'contact_email' => env('SITE_CONTACT_EMAIL', 'info@34devs.com'),
    'parent_url' => 'https://34devs.com',

    // Blog yazılarının Markdown dizini. Ayar olarak durur ki testler
    // gerçek içeriğe dokunmadan fikstür dizinine çevirebilsin.
    'blog_path' => resource_path('content/blog'),
];
