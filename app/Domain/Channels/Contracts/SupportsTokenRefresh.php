<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

/**
 * Token yenileme yeteneği — V3.0'ın 10. support/capability arayüzü.
 *
 * V3.0 · §03 · Delta 3 · §20 · P0-5 · T-V3-15.
 *
 * NUMARALANDIRMA: v2.2 sonunda sekiz capability arayüzü vardı;
 * `SupportsOfferLifecycle` 9., bu arayüz 10.'dur. İkisi de mevcutların
 * davranışını DEĞİŞTİRMEZ — yalnızca genişletir.
 *
 * KİMLER UYGULAR: Etsy (access token **1 saat**) ve eBay (**2 saat** access,
 * 18 ay refresh). Woo, Trendyol ve Hepsiburada KALICI anahtar taşır ve bu
 * arayüzü UYGULAMAZ. Shopify'ın offline token'ı süresizdir; iptal
 * `app/uninstalled` webhook'uyla gelir ve `revoked_at` yazılır — o da bu
 * arayüzü uygulamaz (§04 · dipnot).
 *
 * NEDEN ZORUNLU: 1 saatlik token saatlik koşan mutabakat turunu bile aşar.
 * Yenileme olmadan HER İKİNCİ TUR 401 alır ve `AUTHENTICATION` KALICI
 * sayılır — listing'ler "anahtarın yanlış" damgasıyla TOPLU ölür. Oysa
 * anahtar doğrudur, yalnızca süresi dolmuştur.
 *
 * ⚠️ DEĞİŞMEZ KURAL — YENİLEME İSTEK ANINDA DEĞİL, TARAMAYLA YAPILIR
 * (P0-5). İstek anında yenilemek şu tuzağı doğurur: aynı bağlantı için
 * paralel koşan iki iş aynı anda yeniler, ikisi de yeni token alır ve
 * KANAL İLKİNİ İPTAL EDER — Etsy ve eBay'de refresh token TEK KULLANIMLIKTIR.
 * Tarama tek süreçte koşar (`withoutOverlapping`) ve satırı
 * `FOR UPDATE SKIP LOCKED` ile kilitler.
 *
 * ⚠️ DEĞİŞMEZ KURAL — ADAPTER VAULT'A YAZMAZ. `refreshCredentials()` sonuç
 * döner; yazmayı `TokenRefresher` yapar (v2.2 · "adapter yan etkisizdir").
 */
interface SupportsTokenRefresh
{
    /**
     * Kanaldan yeni kimlik bilgisi alır.
     *
     * VAULT'A YAZMAZ — dönen nesneyi çekirdek saklar. Başarısızlıkta
     * İSTİSNA fırlatır; `AdapterResult::failure()` benzeri sessiz bir
     * dönüş YOKTUR, çünkü "yenilenemedi" ile "yenilendi" arasındaki fark
     * bağlantının yaşamı demektir.
     */
    public function refreshCredentials(): RefreshedCredentials;

    /**
     * Süre dolmadan kaç saniye önce yenilensin.
     *
     * Tarama bu payı `expires_at`'ten geriye sayarak adayları seçer. Pay
     * sıfır olsaydı yenileme ancak token ÖLDÜKTEN sonra denenir ve aradaki
     * her çağrı 401 alırdı.
     */
    public function refreshLeadSeconds(): int;
}
