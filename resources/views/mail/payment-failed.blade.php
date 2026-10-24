{{--
    S14 · başarısız ödeme. Ne oldu, ne olacak, ne yapmalı — üçü birlikte.
    Paket ödeme alınamadığı sürece ÜCRETSİZ limitlerle çalışır (past_due
    kota vermez); veri silinmez. Bunu SÖYLEMEK gerekir, yoksa satıcı
    "ürünlerim gitti" sanar.
--}}
<x-mail::message>
# Ödemen alınamadı

**{{ $tenantName }}** hesabının 34Pazar aboneliği için **{{ $amount }}** tahsil edilemedi.

Ödeme alınana kadar hesabın **ücretsiz paketin sınırlarıyla** çalışır: verilerin silinmez, stok ve siparişler akmaya devam eder; yalnız yeni ürün ve kanal eklenemez.

@if ($nextAttempt)
Kartın **{{ $nextAttempt }}** tarihinde yeniden denenecek.
@endif

<x-mail::panel>
**En sık sebep:** ödeme Almanya'daki hizmet sağlayıcımız üzerinden alınır ve Türk bankaları bunu yurt dışı alışveriş sayar. Bankanın mobil uygulamasından kartının **yurt dışı ve internet alışverişi** iznini açıp tekrar dene.
</x-mail::panel>

<x-mail::button :url="$payUrl">
Ödemeyi tamamla
</x-mail::button>

Sorun sürerse WhatsApp'tan yaz: +49 176 76798125 (her gün 09.00–18.00) ya da info@34devs.com.

Teşekkürler,<br>
34Pazar
</x-mail::message>
