<?php

declare(strict_types=1);

namespace App\Support\Site;

use App\Domain\Channels\Certification\CertificationStatus;

/**
 * Genel sık sorulanlar — ana sayfa (kısa liste) ve /sss (tamamı) AYNI
 * kaynaktan okur (6 Kas 2026 · site sprint 1; önceden ana sayfanın içinde
 * yazılıydı).
 *
 * Cevaplar YALNIZ doğrulanmış şeyleri söyler:
 *   - Kanal ve e-fatura durumu sertifikasyon matrisinden (`SiteProof`);
 *     "Evet, e-fatura kesersin" denmez — Nilvera gerçek hesapta denenmedi.
 *   - "Kart bilgisi gerekmez" yalnız ücretsiz paket varsa (kayıt formu
 *     ödeme sormuyor). Aylık abonelik ve "istediğin zaman iptal" kullanım
 *     koşullarındaki maddelerle aynı.
 */
final class Faq
{
    /**
     * @param  list<array<string, mixed>>  $plans  SiteController::planList()
     * @param  list<array<string, mixed>>  $channels  SiteController::channelList()
     * @return list<array{q: string, a: string}>
     */
    public static function general(array $plans, array $channels, string $lang = 'tr'): array
    {
        $en = $lang === 'en';
        $t = fn (string $tr, string $enText): string => $en ? $enText : $tr;
        $proof = app(SiteProof::class);

        $join = function (array $names) use ($t): string {
            if (count($names) <= 1) {
                return implode('', $names);
            }

            return implode(', ', array_slice($names, 0, -1)).$t(' ve ', ' and ').$names[count($names) - 1];
        };

        $available = array_values(array_filter($channels, fn (array $c): bool => (bool) $c['available']));
        $upcoming = array_values(array_filter($channels, fn (array $c): bool => ! $c['available']));
        $availableNames = $join(array_column($available, 'name'));
        $upcomingNames = $join(array_column($upcoming, 'name'));
        $liveNames = $join($proof->liveChannelNames($channels));

        $freePlan = collect($plans)->first(fn (array $p): bool => (float) $p['priceMonthly'] === 0.0);
        $limit = fn (?int $v, string $unit): string => $v === null ? $t('sınırsız', 'unlimited')." {$unit}" : number_format($v, 0, ',', '.')." {$unit}";

        $faqs = [
            ['q' => $t('34Pazar ne işe yarar?', 'What does 34Pazar do?'), 'a' => $t('Birden fazla kanalda satış yapıyorsan stoğunu, ürünlerini, fiyatlarını ve siparişlerini tek panelden yönetmeni sağlar. En önemlisi: bir kanalda satılan ürünün stoğu diğer kanallarda da düşer.', 'If you sell on more than one channel, it lets you manage stock, products, prices and orders from one panel. Most importantly: an item sold on one channel is deducted on all the others.')],
        ];

        if ($availableNames !== '') {
            $answer = $t("Panelde şu an {$availableNames} bağlanabiliyor.", "The panel currently connects {$availableNames}.");
            $answer .= $liveNames !== ''
                ? $t(" Bunlardan gerçek bir satıcı hesabında uçtan uca doğrulanan: {$liveNames}. Diğerlerinin kodu yazıldı ve kanalın belgesindeki örneklerle sınandı; gerçek hesapta henüz denenmedi. Her kanalın sayfasında satır satır durumu yazıyor.", " Verified end to end on a real seller account: {$liveNames}. The others are built and tested against the channel's documented examples but not yet tried on a real account; each channel page lists the status line by line.")
                : $t(' Her kanalın sayfasında hangi işin hangi düzeyde doğrulandığı satır satır yazıyor.', ' Each channel page lists what is verified, line by line.');
            if ($upcomingNames !== '') {
                $answer .= $t(" {$upcomingNames} için çalışıyoruz; hazır olduğunda bağlanabilir hâle gelecek.", " {$upcomingNames} are in progress.");
            }
            $faqs[] = ['q' => $t('Hangi kanallarla çalışıyor?', 'Which channels does it support?'), 'a' => $answer];
        }

        if ($freePlan) {
            $faqs[] = ['q' => $t('Kart bilgisi vermeden başlayabilir miyim?', 'Can I start without a card?'), 'a' => $t("Evet. {$freePlan['name']} paketle kart bilgisi girmeden başlarsın. Bu pakette {$limit($freePlan['productLimit'], 'ürün')} ve {$limit($freePlan['channelLimit'], 'kanal')} hakkın var.", "Yes. The free plan needs no card and includes {$limit($freePlan['productLimit'], 'products')} and {$limit($freePlan['channelLimit'], 'channel(s)')}.")];
        }

        $faqs[] = ['q' => $t('Yıllık ödeme zorunlu mu?', 'Do I have to pay yearly?'), 'a' => $t('Hayır. Ücretli paketler aylık abonelikle çalışır. Aboneliğini istediğin zaman iptal edebilirsin; iptal, ödemesi yapılmış dönemin sonunda geçerli olur.', 'No. Paid plans are monthly subscriptions. You can cancel at any time; cancellation takes effect at the end of the paid period.')];
        $faqs[] = ['q' => $t('Bir kanalda satış olunca diğerlerinde ne olur?', 'What happens on other channels after a sale?'), 'a' => $t('Stok tek bir yerde tutulur. Ürün hangi kanalda satılırsa satılsın adet oradan düşer ve yeni adet bağlı bütün kanallara gönderilir.', 'Stock is kept in one place. Whichever channel the item sells on, the count is reduced there and the new number is sent to every connected channel.')];
        $faqs[] = ['q' => $t('Yine de fazla satış olabilir mi?', 'Can overselling still happen?'), 'a' => $t('Nadiren olabilir: iki kanalda neredeyse aynı anda satış olursa ya da bir kanal satışı geç bildirirse. Böyle bir durumda ürün panelinde kırmızıyla en üstte görünür; stoğu düzeltir ya da müşteriye haber verirsin.', 'Rarely: if two channels sell at almost the same moment or a channel reports a sale late. The item then shows in red at the top of your panel so you can fix stock or inform the customer.')];
        $faqs[] = ['q' => $t('Her kanalda farklı fiyat kullanabilir miyim?', 'Can I use different prices per channel?'), 'a' => $t('Evet. Kanal başına yüzde ya da tutar farkı ve yuvarlama tanımlayabilir, istersen bir ürüne kanal için elle fiyat girebilirsin. Alış maliyetini girdiysen maliyetin altına düşen fiyat kanala gönderilmez.', 'Yes. Set a percentage or fixed difference and rounding per channel, or enter a manual channel price for a product. If you entered a cost price, a price below cost is never sent.')];
        $faqs[] = ['q' => $t('Kanalın kendi panelinde fiyat değiştirirsem ne olur?', 'What if I change a price in the channel\'s own panel?'), 'a' => $t('Değişikliğini sessizce ezmeyiz. Farkı gördüğümüzde sana gösteririz; hangi fiyatın geçerli olacağına sen karar verirsin.', 'We never overwrite it silently. When we see the difference we show it to you, and you decide which price wins.')];
        $faqs[] = ['q' => $t('Teknik bilgi gerekiyor mu?', 'Do I need technical skills?'), 'a' => $t('Kod yazman gerekmez. Bir kanalı bağlamak için o kanalın satıcı panelinden API anahtarı gibi birkaç bilgiyi alıp bağlantı formuna yapıştırırsın; form hangi bilgiyi istediğini tek tek gösterir.', 'No coding. To connect a channel you copy a few details such as an API key from its seller panel into the connection form, which tells you exactly what it needs.')];

        // E-fatura: durum kanıt kaydından. Gerçek hesapta doğrulanmadıkça
        // "kesebilirsin" denmez; özellik panelde var, düzeyi açıkça yazılır.
        $invoice = $proof->service('Nilvera e-fatura');
        if ($invoice !== null) {
            $faqs[] = ['q' => $t('E-fatura kesebiliyor muyum?', 'Can I issue e-invoices?'), 'a' => $invoice === CertificationStatus::PRODUCTION_VERIFIED
                ? $t('Evet, kendi Nilvera hesabından: alıcı e-fatura mükellefiyse e-fatura, değilse e-arşiv.', 'Yes, from your own Nilvera account (Turkey).')
                : $t('Panelde kendi Nilvera hesabınla e-fatura ve e-arşiv kesme ekranı var, ama durumu: '.mb_strtolower($invoice->publicLabel()).'. '.$invoice->publicNote().' Faturalarını bugün e-fatura için kullanmadan önce bize yaz.', 'The panel has a screen for issuing e-invoices from your own Nilvera account (Turkey), but it is still being tested: it has not been tried on a real Nilvera account yet. Write to us before relying on it.')];
        }

        return $faqs;
    }
}
