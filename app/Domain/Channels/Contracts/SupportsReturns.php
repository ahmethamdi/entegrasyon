<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Returns\Support\RemoteReturn;
use App\Domain\Returns\Support\RemoteReturnPage;
use Carbon\CarbonInterface;

/**
 * Kanalın iade taleplerini OKUYABİLME — S4.
 *
 * Adapter HAM talebi döndürür; durum yorumu (`ReturnInterpretation`),
 * sipariş eşleşmesi ve stok iadesi çekirdeğin işidir. Okuma hiçbir yazma
 * iznine bağlı değildir.
 */
interface SupportsReturns
{
    /** Tek istekte sorulabilecek en uzun aralık (gün). */
    public function returnWindowDays(): int;

    /**
     * Her turda en az bu kadar gün GERİYE yeniden taranır.
     *
     * Tarih süzgeci talebin DEĞİŞİMİNE bakan kanalda (Trendyol) küçük bir
     * örtüşme yeter; AÇILIŞINA bakan kanalda (HB) eski talebin sonradan
     * değişen durumu ancak pencere yeniden okunursa görülür.
     */
    public function returnRescanDays(): int;

    /**
     * Aralıkta DEĞİŞEN taleplerin bir sayfası (sayfa 0'dan). HTTP hatası
     * istisnadır — "talep yok" sayılmaz.
     */
    public function fetchReturnPage(CarbonInterface $from, CarbonInterface $to, int $page): RemoteReturnPage;

    /** Tek talebin GÜNCEL hâli (karar öncesi tazeleme); kanal tekil sorguyu desteklemiyorsa ya da talep yoksa null. */
    public function fetchReturn(string $externalClaimId): ?RemoteReturn;
}
