{{--
    Künye — Almanya'daki "Impressum" yükümlülüğünün karşılığı.

    NEDEN: işletmeci Almanya'da yerleşik; ticari internet sitesi § 5 DDG
    (Digitale-Dienste-Gesetz) uyarınca ad, adres, hızlı iletişim (e-posta +
    telefon) ve varsa KDV kimlik numarası göstermek zorunda. Gazetecilik-
    editoryal içerik (blog) için § 18 Abs. 2 MStV sorumlu kişiyi ister.

    USt-IdNr BİLEREK YOK: numara bize bildirilmedi; uydurma ya da boş bir
    satır yanlış bilgi olurdu. Numara alınırsa buraya eklenecek.
    Bilgiler config/site.php'den — adres tek yerde değişsin.
--}}
@extends('site.legal.layout')

@section('description', '34Pazar künyesi: işletmeci, adres, iletişim bilgileri ve içerikten sorumlu kişi. 34Pazar, 34Devs\'in bir ürünüdür.')

@section('legal_body')
    <p><strong>{{ config('site.brand') }}</strong>, 34Devs'in bir ürünüdür. Bu sayfa, Almanya'da yerleşik işletmeci için yasal olarak zorunlu bilgileri içerir (§ 5 DDG).</p>

    <h2>İşletmeci</h2>
    <p>
        {{ config('site.operator') }}<br>
        Şahıs işletmesi (Einzelunternehmen)<br>
        Sahibi: {{ config('site.owner') }}<br>
        {{ config('site.street') }}<br>
        {{ config('site.postal_code') }} {{ config('site.city') }}<br>
        {{ config('site.country') }}
    </p>

    <h2>İletişim</h2>
    <p>
        E-posta: <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a><br>
        Telefon: <a href="tel:{{ preg_replace('/[^0-9+]/', '', config('site.phone')) }}">{{ config('site.phone') }}</a>
    </p>

    <h2>İçerikten sorumlu kişi</h2>
    <p>§ 18 Abs. 2 MStV uyarınca içerikten sorumlu:</p>
    <p>
        {{ config('site.owner') }}<br>
        {{ config('site.street') }}<br>
        {{ config('site.postal_code') }} {{ config('site.city') }}, {{ config('site.country') }}
    </p>

    <h2>Tüketici uyuşmazlıklarının çözümü</h2>
    <p>Almanya'daki bir tüketici hakem kurulu (Verbraucherschlichtungsstelle) önünde uyuşmazlık çözüm sürecine katılmaya yükümlü değiliz ve katılmıyoruz. Bu, Türkiye'deki tüketicilerin kanundan doğan başvuru yollarını (tüketici hakem heyeti ve tüketici mahkemesi) etkilemez; ayrıntılar <a href="{{ route('site.legal', 'mesafeli-satis') }}">mesafeli hizmet sözleşmesinde</a> yer alır.</p>

    <h2>Bağlantılar ve içerik</h2>
    <p>Sitedeki içerikler özenle hazırlanır; ancak doğruluğu, eksiksizliği ve güncelliği için güvence verilmez. Blog yazılarında anılan pazaryeri kuralları zaman içinde değişebilir; güncel kurallar için ilgili pazaryerinin satıcı panelini esas alın. Dış sitelere verilen bağlantıların içeriğinden o sitelerin işletmecileri sorumludur.</p>

    <h2>Diğer yasal metinler</h2>
    <ul>
        <li><a href="{{ route('site.legal', 'gizlilik') }}">Gizlilik ve KVKK aydınlatma metni</a></li>
        <li><a href="{{ route('site.legal', 'cerez') }}">Çerez politikası</a></li>
        <li><a href="{{ route('site.legal', 'kullanim-kosullari') }}">Kullanım koşulları</a></li>
        <li><a href="{{ route('site.legal', 'mesafeli-satis') }}">Mesafeli hizmet sözleşmesi</a></li>
    </ul>
@endsection
