<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\InventoryPushBatch;
use App\Domain\Sync\Support\RemoteInventorySnapshot;

/**
 * Stok senkronu yeteneği.
 *
 * Mimari Karar Dokümanı v2.2 · §7, §1 · Karar 25.
 *
 * DEĞİŞMEZ KURAL — STOK HER ZAMAN MUTLAK DEĞER OLARAK GÖNDERİLİR:
 *   Delta ASLA gönderilmez. Delta göndermek, kaybolan veya iki kez işlenen
 *   bir isteğin kanaldaki bakiyeyi kalıcı olarak kaydırması demektir ve fark
 *   geri kazanılamaz. Mutlak değerde tekrar zararsızdır — aynı sayıyı ikinci
 *   kez yazmak durumu değiştirmez. Yeniden denemenin güvenli olmasının ve
 *   mutabakatın çalışabilmesinin dayanağı budur.
 *
 * Gönderilen değer OutboundQuantity::forChannel() ile kırpılır: kanonik
 * bakiye fazla satış nedeniyle negatif olabilir, kanallar negatif kabul etmez.
 * Kırpma YALNIZCA giden yüktedir; kanonik durum asla değişmez.
 */
interface SupportsInventory
{
    /** MUTLAK değer gönderir. Delta asla. */
    public function pushInventory(InventoryPushBatch $batch): AdapterResult;

    /**
     * Uzak stok durumunu okur — mutabakat için.
     *
     * @param  list<Listing>  $listings
     */
    public function fetchInventory(array $listings): RemoteInventorySnapshot;

    /**
     * Tek API çağrısında kaç kalem gönderilebilir.
     *
     * InventoryBatchBuilder bu sınıra göre GRUPLAMA yapar; operasyon sayısı
     * değişmez. Woo wc/v3 batch: 100, Trendyol stok-fiyat: 1000.
     */
    public function maxInventoryBatchSize(): int;
}
