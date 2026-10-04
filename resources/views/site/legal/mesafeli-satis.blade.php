{{--
    Mesafeli hizmet sözleşmesi — ücretli abonelik için.

    NEDEN: 6502 sayılı Tüketicinin Korunması Hakkında Kanun ve Mesafeli
    Sözleşmeler Yönetmeliği, tüketiciyle uzaktan kurulan sözleşmede ön
    bilgilendirmeyi ve sözleşme metnini ister. Hizmet satıcılara yönelik
    olsa da şahıs olarak (tacir olmadan) satış yapan bir kullanıcı tüketici
    sayılabilir; metin bu ihtimal için hazırlanmıştır.

    CAYMA HAKKI İSTİSNASI (Yönetmelik m.15/1-ğ ve h) ancak ödeme adımında
    tüketicinin AÇIK ONAYI alınırsa geçerlidir. Onay alınmıyorsa 14 günlük
    cayma hakkı geçerli kalır — metin iki durumu da bu yüzden anlatır.

    Plan fiyatları `$plans`'tan; şirket bilgisi config/site.php'den.
--}}
@extends('site.legal.layout')

@section('description', '34Pazar ücretli abonelikleri için mesafeli hizmet sözleşmesi: taraflar, hizmetin konusu, ücret ve ödeme, ifa, cayma hakkı ve uyuşmazlık çözümü.')

@section('legal_body')
    <h2>Madde 1 — Taraflar</h2>
    <p><strong>Hizmet sağlayıcı:</strong><br>
        {{ config('site.operator') }}<br>
        {{ config('site.street') }}, {{ config('site.postal_code') }} {{ config('site.city') }}, {{ config('site.country') }}<br>
        E-posta: <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a> · Telefon: {{ config('site.phone') }}<br>
        (Bundan sonra “HİZMET SAĞLAYICI” olarak anılacaktır.)
    </p>
    <p><strong>Alıcı:</strong> {{ config('site.brand') }} üzerinde hesap oluşturan ve ücretli bir plana abone olan gerçek veya tüzel kişi. Alıcının ad/unvan, adres ve iletişim bilgileri, hesap ve ödeme sırasında verdiği bilgilerdir. (Bundan sonra “ALICI” olarak anılacaktır.)</p>

    <h2>Madde 2 — Konu</h2>
    <p>Bu sözleşmenin konusu, ALICI'nın {{ config('site.brand') }} internet sitesi üzerinden elektronik ortamda abone olduğu ücretli plan kapsamında, HİZMET SAĞLAYICI tarafından sunulan pazaryeri entegrasyonu yazılım hizmetinin (“Hizmet”) sunulmasına ilişkin tarafların hak ve yükümlülüklerinin belirlenmesidir. Hizmet'in kapsamı <a href="{{ route('site.legal', 'kullanim-kosullari') }}">kullanım koşullarında</a> ve <a href="{{ route('site.features') }}">özellikler sayfasında</a> açıklanmıştır. Kullanım koşulları bu sözleşmenin ayrılmaz parçasıdır.</p>

    <h2>Madde 3 — Hizmetin temel nitelikleri ve ücreti</h2>
    <p>Hizmet, internet tarayıcısı üzerinden erişilen, aylık abonelikle sunulan bir yazılım hizmetidir. Ücretli planlar ve aylık ücretleri:</p>
    @if (! empty($plans))
        <ul>
            @foreach ($plans as $plan)
                @continue((float) $plan['priceMonthly'] === 0.0)
                <li><strong>{{ $plan['name'] }}</strong>: aylık {{ number_format((float) $plan['priceMonthly'], 0, ',', '.') }} {{ $plan['currency'] === 'TRY' ? 'TL' : $plan['currency'] }}</li>
            @endforeach
        </ul>
    @endif
    <p>Planların limitleri <a href="{{ route('site.pricing') }}">fiyatlar sayfasında</a> belirtilir. ALICI'nın ödeyeceği toplam tutar, vergiler dahil olarak, ödemeyi onaylamadan önce ödeme sayfasında ayrıca gösterilir. Ücretsiz plan bu sözleşmenin konusu değildir ve ödeme gerektirmez.</p>

    <h2>Madde 4 — Ödeme</h2>
    <ul>
        <li>Ödeme, HİZMET SAĞLAYICI'nın ödeme hizmet sağlayıcısı Stripe'ın güvenli ödeme sayfası üzerinden kredi veya banka kartıyla yapılır. Kart bilgileri HİZMET SAĞLAYICI tarafından görülmez ve saklanmaz.</li>
        <li>Abonelik ücreti, aboneliğin başladığı gün ve sonrasında her aylık dönemin başında, aynı ödeme yönteminden otomatik olarak tahsil edilir.</li>
        <li>Bankanızın veya kart kuruluşunuzun uyguladığı kur farkı, yurt dışı işlem ücreti gibi masraflar HİZMET SAĞLAYICI'nın kontrolünde değildir.</li>
    </ul>

    <h2>Madde 5 — Hizmetin ifası</h2>
    <p>Hizmet, ödemenin onaylanmasının ardından derhal ALICI'nın hesabında kullanıma açılır. Fiziksel bir teslimat yoktur. Hizmet, abonelik iptal edilene kadar her ay yenilenir.</p>

    <h2>Madde 6 — Cayma hakkı</h2>
    <p>ALICI'nın tüketici olması halinde, kural olarak sözleşmenin kurulduğu günden itibaren on dört gün içinde herhangi bir gerekçe göstermeksizin ve cezai şart ödemeksizin sözleşmeden cayma hakkı vardır.</p>
    <p>Ancak Mesafeli Sözleşmeler Yönetmeliği'nin 15. maddesinin birinci fıkrasının (ğ) bendi uyarınca elektronik ortamda anında ifa edilen hizmetlerde ve (h) bendi uyarınca cayma hakkı süresi sona ermeden önce tüketicinin onayı ile ifasına başlanan hizmetlerde cayma hakkı kullanılamaz. ALICI, ücretli plana geçerken Hizmet'in derhal ifasına başlanmasını açıkça talep eder ve bu durumda cayma hakkını kaybedeceğini kabul ederse, cayma hakkı bu istisna kapsamında sona erer.</p>
    <p>Böyle bir onay verilmemişse ALICI, on dört günlük süre içinde cayma bildirimini <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a> adresine yazılı olarak iletebilir. Bu durumda tahsil edilen tutar, bildirimin HİZMET SAĞLAYICI'ya ulaşmasından itibaren en geç on dört gün içinde, ödemede kullanılan yönteme iade edilir.</p>
    <p>Cayma hakkından bağımsız olarak ALICI aboneliğini her zaman iptal edebilir; iptal, ödemesi yapılmış dönemin sonunda geçerli olur.</p>

    <h2>Madde 7 — Tarafların yükümlülükleri</h2>
    <ul>
        <li>HİZMET SAĞLAYICI, Hizmet'i kullanım koşullarında belirtilen kapsamda ve makul özenle sunmakla yükümlüdür.</li>
        <li>ALICI, hesap ve ödeme bilgilerinin doğruluğundan, kanal erişim bilgilerinin ve ürün içeriklerinin hukuka ve ilgili kanalların kurallarına uygunluğundan sorumludur.</li>
    </ul>

    <h2>Madde 8 — Kişisel veriler</h2>
    <p>Sözleşme kapsamında işlenen kişisel veriler <a href="{{ route('site.legal', 'gizlilik') }}">gizlilik ve KVKK aydınlatma metni</a> uyarınca işlenir.</p>

    <h2>Madde 9 — Ön bilgilendirme ve sözleşmenin saklanması</h2>
    <p>ALICI, ödeme adımından önce bu sözleşmenin ve kullanım koşullarının içeriğini okuduğunu ve Hizmet'in temel nitelikleri, toplam ücreti, ödeme şekli ve cayma hakkı konusunda bilgilendirildiğini kabul eder. Sözleşmenin güncel metni bu sayfada yayımlanır; ALICI talep ederse sözleşme kurulduğu tarihteki metin kendisine e-postayla iletilir.</p>

    <h2>Madde 10 — Uyuşmazlıkların çözümü</h2>
    <p>Tüketici sıfatındaki ALICI, şikâyet ve itirazlarını, Ticaret Bakanlığınca her yıl belirlenen parasal sınırlar dahilinde yerleşim yerindeki veya işlemin yapıldığı yerdeki tüketici hakem heyetine, bu sınırları aşan uyuşmazlıklarda ise tüketici mahkemesine iletebilir. Tacir sıfatındaki ALICI bakımından <a href="{{ route('site.legal', 'kullanim-kosullari') }}">kullanım koşullarının</a> uygulanacak hukuk ve yetki hükümleri geçerlidir.</p>

    <h2>Madde 11 — Yürürlük</h2>
    <p>ALICI'nın ödeme sayfasında ödemeyi onaylamasıyla bu sözleşme elektronik ortamda kurulmuş ve yürürlüğe girmiş olur.</p>
@endsection
