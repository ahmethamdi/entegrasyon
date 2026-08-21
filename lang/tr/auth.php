<?php

declare(strict_types=1);

/*
 * Giriş ekranı mesajları (§13 · Faz 4).
 *
 * `failed` KASITLI OLARAK BELİRSİZDİR: "e-posta bulunamadı" ile "parola
 * yanlış" ayrı ayrı söylenseydi saldırgan hangi adreslerin kayıtlı
 * olduğunu tek tek ÖĞRENEBİLİRDİ (kullanıcı sayımı). Laravel'in
 * varsayılan davranışı da budur ve korunuyor.
 */

return [

    'failed' => 'Bu bilgilerle eşleşen bir hesap bulunamadı.',
    'password' => 'Parola hatalı.',
    'throttle' => 'Çok fazla giriş denemesi yapıldı. :seconds saniye sonra tekrar deneyin.',

];
