{{--
    Eşik aşımı uyarısı — §11.

    DEĞER VE EŞİK BİRLİKTE: "5 adet" tek başına bir şey söylemez,
    "5 adet (eşik 3)" satıcıya ne kadar aştığını söyler.

    TAVSİYE ZORUNLUDUR: sayı tek başına eylem üretmez. Ölü mektup
    ekranındaki "hata sınıfı ve tavsiye gösterilir" kuralının aynısı.
--}}
<x-mail::message>
# {{ $label }} eşiği aşıldı

**{{ $scopeText }}** için ölçülen değer uyarı eşiğinin üzerinde.

<x-mail::panel>
Ölçülen: **{{ $value }}** — uyarı eşiği: {{ $threshold }}
</x-mail::panel>

{{ $advice }}

<x-mail::button :url="config('app.url') . '/metrics'">
Sistem sağlığını aç
</x-mail::button>

{{--
    AYNI UYARI AYNI GÜN TEKRAR GÖNDERİLMEZ ve bunu SÖYLEMEK gerekir:
    yoksa satıcı sorunu çözmediği hâlde e-posta gelmemesini "düzeldi"
    sanabilir.
--}}
<small>Bu uyarı günde en fazla bir kez gönderilir. Durum düzelene kadar
sistem sağlığı ekranından takip edebilirsiniz.</small>

<x-mail::subcopy>
Bu e-postayı Entegrasyon hesabınızın sistem uyarıları kapsamında aldınız.
</x-mail::subcopy>
</x-mail::message>
