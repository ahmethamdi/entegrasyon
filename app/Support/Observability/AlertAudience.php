<?php

declare(strict_types=1);

namespace App\Support\Observability;

/**
 * Eşik aşımı uyarısını KİM alır.
 *
 * V3.0 · §25 ("İSTİSNA — TOKEN UYARISI SATICIYA GİDER").
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN KAPSAMDAN TÜRETİLMİYOR
 * ─────────────────────────────────────────────────────────────────────
 * v2.2'ye kadar alıcı KAPSAMDAN okunuyordu: kiracı kapsamlı uyarı
 * satıcıya, sistem ve bağlantı kapsamlı uyarı yöneticiye. Kural
 * işliyordu çünkü bağlantı kapsamlı iki metrik de (api gecikmesi, 429)
 * ALTYAPI sorunuydu ve satıcının elinde düğme yoktu.
 *
 * §25'in token metrikleri bu eşitliği KIRAR: kapsamları bağlantıdır ama
 * çözümü YALNIZCA SATICI yapabilir — kendi Etsy hesabına girip yeniden
 * izin verecek. Yöneticiye gitseydi uyarı hiçbir işe yaramaz, yönetici
 * satıcıyı aramak zorunda kalır ve bu arada bağlantı ÖLÜ kalırdı.
 *
 * Bu yüzden alıcı artık kapsamdan TÜRETİLMEZ, metriğin KENDİ
 * özelliğidir. Kapsamdan türetilmeye devam etseydi istisna
 * `DispatchAlerts` içinde bir `if ($metric === TOKEN_...)` bloğu olurdu
 * ve o blok, eşiğin panelde yeniden tanımlanmasıyla aynı hatanın
 * biçimidir: kural metrikten UZAKTA yaşar ve yeni bir token metriği
 * eklendiğinde oraya eklenmesi UNUTULUR.
 */
enum AlertAudience: string
{
    /**
     * Kiracının SAHİPLERİ.
     *
     * Yapılacak iş satıcınındır: fazla satışı düzeltmek, ölü işi
     * yeniden denemek, kanalı yeniden yetkilendirmek.
     */
    case TENANT = 'tenant';

    /**
     * Sistem YÖNETİCİSİ.
     *
     * Yapılacak iş altyapıdadır: yavaş kanal, 429 dalgası, kuyruk
     * birikmesi, kota tavanına dayanmış bir kurulum. Satıcıya
     * gönderilseydi yapamayacağı bir iş için uyarılmış olurdu.
     */
    case ADMIN = 'admin';
}
