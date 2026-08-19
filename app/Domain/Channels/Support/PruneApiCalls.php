<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * api_calls saklama taraması — süresi geçen teknik günlükleri siler.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · api_calls saklama politikası,
 * §13 · Faz 3 · güvenilirlik, §15 · zamanlanmış işler. Günlük, gece.
 *
 * KAPATILAN BOŞLUK: api_calls bu şemanın EN ÇOK YAZILAN tablosudur — her
 * kanal çağrısı bir satır yazar (başarı, hata, ağ kopması) ve `expires_at`
 * ilk günden beri DOLDURULUYOR (2xx +7 gün, 4xx/5xx +90 gün). Ama SİLEN
 * HİÇBİR ŞEY YOKTU: saklama politikası yalnızca bir niyet olarak duruyordu
 * ve tablo sınırsız büyüyordu. Migration'ın kendi yorumu bu işi şart koşar
 * ("Günlük bakım işi partileyerek siler; tablo sabit boyutta kalır ve
 * bölümlemeye gerek kalmaz").
 *
 * ÖLÇÜT `expires_at`, DURUM KODU DEĞİL. Saklama süresi satır YAZILIRKEN
 * kararlaştırılır ve `expires_at` içinde donar; tarama o kararı yeniden
 * yorumlamaz. Yorumlasaydı politika iki yerde yaşar ve biri değiştiğinde
 * diğeri sessizce eski kuralı uygulardı — üstelik geçmiş satırlar
 * yazıldıkları günün kuralıyla değil bugünün kuralıyla silinirdi.
 *
 * SİLME PARTİLENİR. Aylarca birikmiş satırı tek DELETE ile silmek en çok
 * yazılan tabloyu dakikalarca kilitler; o süre boyunca kanal çağrıları
 * günlüklenemez. `api_calls` yazımı çağrıyı düşürmüyor (hata yutulur), yani
 * senkron durmaz — ama iz kaybolur ve iz tam olarak sorun anında gerekir.
 *
 * TUR BAŞINA ÜST SINIR VAR. Hiç çalışmamış bir kurulumda birikim
 * milyonlarca satır olabilir; bitene kadar dönen bir tarama günlük bakım
 * penceresini saatlerce tutar ve `withoutOverlapping` yüzünden sonraki
 * turlar hiç başlamaz — tarama kendi kuyruğunu kilitler. Kalan satırlar
 * YARIN silinir; gecikmenin bedeli yalnızca diskte biraz fazla günlüktür.
 *
 * TRANSACTION YOK ve bu bilinçlidir: her parti kendi başına atomiktir ve
 * silinen bir günlük satırının geri alınmasına gerek yoktur. Turu tek
 * transaction'a sarmak, silinen her satırın kilidini tur sonuna kadar
 * tutar ve tam olarak kaçınmak istediğimiz kilit birikimini üretir.
 *
 * api_calls_expiry_idx (`expires_at`) bu taramayı besler.
 */
final class PruneApiCalls
{
    /** Parti boyutu — tek DELETE'in kilitleyeceği satır sayısı. */
    public const DEFAULT_CHUNK_SIZE = 5_000;

    /** Tur başına üst sınır: bakım penceresi bundan uzun tutulmaz. */
    public const DEFAULT_MAX_ROWS = 500_000;

    /**
     * Tek tur: süresi geçmiş api_calls satırlarını partileyerek siler.
     *
     * @return int Silinen satır sayısı
     */
    public function run(
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        int $maxRows = self::DEFAULT_MAX_ROWS,
    ): int {
        $chunkSize = max(1, $chunkSize);
        $maxRows = max(1, $maxRows);

        // Saklama politikası kiracıya göre DEĞİŞMEZ ve tarama tüm kiracıları
        // görmek zorundadır: kiracı bağlamıyla çalışsaydı yalnızca birinin
        // günlükleri temizlenir, geri kalanlar sonsuza kadar birikirdi.
        // Erişim bilinçli ve açıktır.
        //
        // DÜRÜST SINIR: api_calls'un modeli yoktur ve tablo `DB::table()`
        // ile okunur — global scope hiç uygulanmadığı için bu sarmalayıcı
        // BUGÜN davranış katmaz ve mutasyonla kaldırıldığında hiçbir test
        // kırılmaz. Duruyor çünkü niyeti belgeler ve tabloya bir gün model
        // eklenirse (BelongsToTenant ile) tarama sessizce tek kiracıya
        // daralmaz.
        $deleted = TenantContext::runAsSystem(
            fn (): int => $this->deleteInChunks($chunkSize, $maxRows),
        );

        if ($deleted > 0) {
            Log::info('api_calls.pruned', [
                'deleted' => $deleted,
                'chunk_size' => $chunkSize,
                'hit_ceiling' => $deleted >= $maxRows,
            ]);
        }

        // Üst sınıra dayanmak "birikim tek turda erimiyor" demektir. Bir kez
        // olması normaldir (ilk tur, geçmiş birikimi); her gün olması
        // saklama süresinin trafiğe göre uzun kaldığını veya taramanın
        // günlerce hiç çalışmadığını gösterir.
        if ($deleted >= $maxRows) {
            Log::warning('api_calls.prune_hit_ceiling', [
                'deleted' => $deleted,
                'max_rows' => $maxRows,
            ]);
        }

        return $deleted;
    }

    private function deleteInChunks(int $chunkSize, int $maxRows): int
    {
        $deleted = 0;

        while ($deleted < $maxRows) {
            // ÜST SINIRI UYGULAYAN YER BURASI: kalan bütçeden büyük parti
            // alınmaz, yoksa son parti sınırı aşar ve sınır yalnızca
            // yaklaşık olurdu.
            //
            // Döngü koşulu (`$deleted < $maxRows`) bu clamp'in yanında
            // İKİNCİ bir kapıdır ve tek başına GEREKLİ DEĞİLDİR: bütçe
            // dolduğunda `min()` sıfır döner, `LIMIT 0` hiçbir satır silmez
            // ve döngü `$affected === 0` dalından zaten çıkar. Koşul yine
            // duruyor çünkü boşa dönen o son sorguyu tümden engeller —
            // mutasyonla `while (true)` yapıldığında hiçbir test kırılmaz
            // ve bu bir test boşluğu değil, o yapısal fazlalıktır.
            $limit = min($chunkSize, $maxRows - $deleted);

            // clock_timestamp() KULLANILIYOR, now() DEĞİL — proje genelindeki
            // kural (bkz. CLAUDE.md · zaman damgası tuzağı).
            //
            // DÜRÜST SINIR: bu tur transaction DIŞINDA çalıştığı için ikisi
            // BUGÜN aynı sonucu verir ve mutasyon hayatta kalır; sahte test
            // YAZILMADI. Kural yine de burada duruyor, çünkü yukarıdaki
            // "TRANSACTION YOK" kararı bir gün geri alınırsa (ör. turu
            // atomik yapmak isteyen biri) donmuş now() uzun süren turun son
            // partilerini eşiğin gerisinde bırakır ve o satırlar bir daha
            // hiç silinmezdi. Doğrulandı: transaction içinde now() donuyor,
            // clock_timestamp() ilerliyor.
            //
            // KARŞILAŞTIRMA `<`, `<=` DEĞİL: expires_at "bu ana kadar
            // saklanacak" demektir ve tam eşit olan satırın süresi henüz
            // geçmemiştir. Fark sınanamaz (kolon saniye hassasiyetli,
            // clock_timestamp() mikrosaniye taşır) — testte belgelendi.
            //
            // DELETE ... WHERE id IN (SELECT ... LIMIT n): PostgreSQL DELETE
            // üzerinde LIMIT kabul etmez. Alt sorgu birincil anahtarla
            // seçtiği için silme indeks üzerinden gider.
            $affected = DB::affectingStatement(<<<'SQL'
                DELETE FROM api_calls
                 WHERE id IN (
                       SELECT id
                         FROM api_calls
                        WHERE expires_at < clock_timestamp()
                        LIMIT ?
                 )
            SQL, [$limit]);

            if ($affected === 0) {
                break;
            }

            $deleted += $affected;
        }

        return $deleted;
    }
}
