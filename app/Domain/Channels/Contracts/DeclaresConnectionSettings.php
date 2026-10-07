<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Channels\Support\ConnectionSettingField;

/**
 * Bağlantı KURULDUKTAN SONRA satıcının seçmesi gereken kanal ayarları.
 *
 * Bağlama formundaki kimlik alanlarından (`ChannelConnectForm`) AYRIDIR:
 * onlar bağlantıyı kurmak için gerekir; bunlar ilan açmak için. Etsy'nin
 * kargo profili ve yasal beyanları bağlantı ANINDA sorulamaz — seçenekler
 * mağazanın kendisinden gelir ve token ancak OAuth dönüşünde vardır.
 *
 * Bu sözleşme olmadan ayarlar yalnızca sunucuda elle yazılabiliyordu
 * (7 Eki: canlıdaki ilk Etsy ilanı böyle açıldı) ve gerçek bir satıcı ilk
 * ilanında "beyan eksik" hatasıyla kalırdı.
 *
 * Yetenek `instanceof` ile okunur; panelde kanal adı kontrol edilmez.
 */
interface DeclaresConnectionSettings
{
    /**
     * Ayar alanları, seçenekleriyle birlikte.
     *
     * ⚠️ AĞ ÇAĞRISI YAPABİLİR — seçenekler (Etsy kargo profilleri) kanaldan
     * canlı okunur. Yalnızca ayar ekranında çağrılır; liste ekranı
     * `missingConnectionSettings()`'i kullanır.
     *
     * @return list<ConnectionSettingField>
     */
    public function connectionSettingFields(): array;

    /**
     * Zorunlu olup DEĞERİ OLMAYAN ayarların anahtarları — AĞ ÇAĞRISI YOK.
     *
     * Kanal listesi her açılışta bunu sorar; kanaldan seçenek okusaydı
     * liste her bağlantı için kanala istek atar ve kotayı yakardı.
     *
     * @return list<string>
     */
    public function missingConnectionSettings(): array;
}
