<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\SyncOperation;

/**
 * Onboarding ilerlemesi — dokümanın dört adımı, VERİDEN türetilir.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 4 · "Onboarding: kayıt → kanal
 * bağla → ürün aktar → ilk senkron — 20 sa".
 *
 * Doküman adımları SAYAR ama saklama biçimini söylemez; karar burada
 * alındı ve gerekçesi aşağıdadır.
 *
 * DEĞİŞMEZ KURAL — İLERLEME SAKLANMAZ, TÜRETİLİR:
 *   `tenants` tablosunda onboarding kolonu YOKTUR ve §4 de tanımlamaz.
 *   Ayrı kolon veya tablo açmak bu projenin iki yerleşik kararına
 *   aykırıdır: `is_dirty` generated column'dır (§4) ve `DriftHistory`
 *   sayacı ayrı kolonda TUTMAZ (§10). Gerekçe birebir aynıdır — ayrı
 *   sayaç, adımı bitiren HER yolun onu da güncellemesini zorunlu kılar;
 *   biri unutulunca iki gerçek kaynağı SESSİZCE ayrışır.
 *
 *   Burada tuzak daha da keskindir: adım "bitti" diye damgalanıp veri
 *   sonradan giderse (bağlantı sağlıksızlığa düşer, ürün silinir)
 *   kayıtlı ilerleme YALAN söyler. Türetilmiş ilerleme yalan söyleyemez:
 *   kanal varsa adım bitmiştir, yoksa bitmemiştir.
 *
 * DEĞİŞMEZ KURAL — KANAL ADIMI `active` İSTER, VARLIK YETMEZ:
 *   "Sağlık kontrolü geçmeden bağlantı `active` olmaz" (§13 · faz 1.4).
 *   `pending` bir bağlantı kanalla HİÇ konuşamamıştır; adım kapatılsaydı
 *   kullanıcı ürün göndermeye başlar ve hepsi `AUTHENTICATION` ile
 *   KALICI hataya düşerdi — "aktif ama çalışmayan bağlantı en pahalı
 *   hata biçimidir".
 *
 * DEĞİŞMEZ KURAL — SENKRON ADIMI `completed` İSTER, AÇILMA YETMEZ:
 *   Operasyonun AÇILMASI hiçbir şey kanıtlamaz: `pending` kuyrukta
 *   bekliyordur ve `dead` tam olarak BAŞARISIZ olmuştur. İkisini de
 *   "ilk senkron tamam" saymak, ürünün temel iddiasının çalışmadığı anda
 *   kullanıcıya "kurulum bitti" demektir.
 *
 * Sorgular kiracı scope'u altında çalışır — bağlamı `EstablishTenantContext`
 * kurar. Scope'suz çalıştırmak başka kiracının verisiyle adım kapatırdı.
 */
final class OnboardingProgress
{
    /**
     * Adım sırası — şerit bu sırayla gösterilir ve "sıradaki adım" bundan
     * türer. Sıra doküman cümlesinin sırasıdır:
     * kayıt → kanal bağla → ürün aktar → ilk senkron.
     */
    public const STEPS = ['account', 'channel', 'product', 'sync'];

    /**
     * Dört adımın durumu + görünürlük + sıradaki adım.
     *
     * @return array{steps: array<string, bool>, visible: bool, next: ?string}
     */
    public function forCurrentTenant(): array
    {
        $steps = [
            // Kullanıcı paneldeyse kiracısı vardır — bağlam kurulmadan
            // bu kod hiç çalışmaz. Adım tanım gereği kapalıdır.
            'account' => true,
            'channel' => $this->hasActiveConnection(),
            'product' => $this->hasProduct(),
            'sync' => $this->hasCompletedSync(),
        ];

        $pending = array_search(false, $steps, strict: true);

        return [
            'steps' => $steps,
            // Dört adım bitince şerit KAYBOLUR (kullanıcı kararı). Veri
            // sonradan giderse geri gelir; bu türetilmiş durumun doğal
            // sonucu ve KASITLI davranıştır.
            'visible' => $pending !== false,
            'next' => $pending === false ? null : (string) $pending,
        ];
    }

    /**
     * SAĞLIK KONTROLÜNDEN GEÇMİŞ bağlantı var mı?
     *
     * `exists()` kullanılır: sayı sorulmuyor, varlık soruluyor.
     */
    private function hasActiveConnection(): bool
    {
        return ChannelConnection::query()
            ->where('status', 'active')
            ->exists();
    }

    private function hasProduct(): bool
    {
        return Product::query()->exists();
    }

    /**
     * TAMAMLANMIŞ bir senkron operasyonu var mı?
     *
     * `superseded` de terminaldir ama BAŞARI DEĞİLDİR: daha yeni bir sürüm
     * istendiği için o operasyon hiç gönderilmedi (§8). Sayılsaydı hiç
     * kanala ulaşmamış bir satır "ilk senkron tamam" derdi.
     */
    private function hasCompletedSync(): bool
    {
        return SyncOperation::query()
            ->where('status', SyncOperationStatus::COMPLETED)
            ->exists();
    }
}
