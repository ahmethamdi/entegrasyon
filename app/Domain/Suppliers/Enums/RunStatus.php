<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Enums;

/**
 * Tedarikçi turunun sonucu — biri ötekine ÇÖKMEZ.
 *
 *   FETCH_FAILED      akış inmedi (ağ/HTTP/SSRF/boyut) — hiçbir şey okunmadı
 *   PARSE_FAILED      XML bozuk/güvensiz — doğrulama geçişi bitmeden durdu, SIFIR yazma
 *   VALIDATION_FAILED eşleme geçersiz ya da kayıt düğümü yok — SIFIR yazma
 *   PARTIAL           akış tam okundu ama bazı kayıtlar uygulanamadı (kota, beklenmeyen hata)
 *   COMPLETE          akış tam okundu, her kayıt işlendi (geçersiz VERİ satırları dahil)
 *   FAILED            tur yarıda öldü (çökme, zaman aşımı) — başarı damgası İLERLEMEZ
 *
 * Yalnız COMPLETE `last_succeeded_at`'i ilerletir. Yokluk işaretlemesi
 * (presence = missing) yalnız akışın tamamı okunmuş turlarda (COMPLETE,
 * PARTIAL) yapılır.
 */
enum RunStatus: string
{
    case RUNNING = 'running';
    case COMPLETE = 'complete';
    case PARTIAL = 'partial';
    case FETCH_FAILED = 'fetch_failed';
    case PARSE_FAILED = 'parse_failed';
    case VALIDATION_FAILED = 'validation_failed';
    case FAILED = 'failed';

    public function isTerminal(): bool
    {
        return $this !== self::RUNNING;
    }
}
