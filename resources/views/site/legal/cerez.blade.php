{{--
    Çerez politikası.

    Çerez adı ve süresi config/session.php'den OKUNUR, elle yazılmaz:
    SESSION_COOKIE veya SESSION_LIFETIME değişirse metin kendiliğinden
    doğru kalsın. XSRF-TOKEN'ın süresi Laravel'de oturum süresiyle aynıdır
    (VerifyCsrfToken::newCookie → session.lifetime).

    ONAY BANDI YOK ve bu bilinçli: yalnızca kesin gerekli çerez kullanılıyor
    (TTDSG/TDDDG § 25 Abs. 2 Nr. 2; GDPR'da md. 6/1-b,f). Analitik veya
    reklam aracı eklenirse ÖNCE onay bandı, sonra araç — bu sayfa da
    birlikte güncellenmeli.
--}}
@extends('site.legal.layout')

@php
    $sessionMinutes = (int) config('session.lifetime');
    $sessionDuration = $sessionMinutes % 60 === 0
        ? ($sessionMinutes / 60).' saat'
        : $sessionMinutes.' dakika';
@endphp

@section('description', '34Pazar yalnızca oturum ve güvenlik için zorunlu çerezler kullanır; analitik veya reklam çerezi yoktur. Kullanılan çerezler, amaçları ve süreleri.')

@section('legal_body')
    <p>Çerezler, bir sitenin tarayıcınıza kaydettiği küçük metin dosyalarıdır. Bu sayfa, {{ config('site.brand') }} sitesinde ve panelinde hangi çerezlerin neden kullanıldığını açıklar.</p>

    <h2>Kısaca</h2>
    <p>Yalnızca sitenin ve panelin çalışması için <strong>kesinlikle gerekli</strong> çerezleri kullanırız. Analitik, reklam, sosyal medya veya izleme çerezi kullanılmaz; ziyaretinizle ilgili veri üçüncü taraf reklam ağlarına gönderilmez. Bu yüzden sitede çerez onay bandı gösterilmez: zorunlu çerezler için onay gerekmez.</p>

    <h2>Kullanılan çerezler</h2>
    <table>
        <thead>
            <tr><th>Çerez</th><th>Amaç</th><th>Süre</th></tr>
        </thead>
        <tbody>
            <tr>
                <td><code>{{ config('session.cookie') }}</code></td>
                <td>Oturum çerezi. Giriş yaptığınızı ve oturumunuzu tanır; formlar arasında bilgiyi taşır. İçeriği rastgele bir oturum kimliğidir.</td>
                <td>Son işlemden itibaren {{ $sessionDuration }}</td>
            </tr>
            <tr>
                <td><code>XSRF-TOKEN</code></td>
                <td>Güvenlik çerezi. Formların gerçekten sizin tarafınızdan gönderildiğini doğrular ve siteler arası istek sahteciliğini (CSRF) önler.</td>
                <td>{{ $sessionDuration }}</td>
            </tr>
            <tr>
                <td><code>remember_web_…</code></td>
                <td>Yalnızca girişte "Beni hatırla" seçeneğini işaretlerseniz oluşturulur; tarayıcıyı kapatıp açtığınızda yeniden giriş yapmanız gerekmez. Çıkış yaptığınızda geçersiz olur.</td>
                <td>En fazla 400 gün</td>
            </tr>
        </tbody>
    </table>
    <p>Bu çerezler yalnızca 34pazar.com alan adına aittir; üçüncü taraflarca okunamaz. Oturum çerezi JavaScript ile okunamayacak şekilde (HttpOnly) ayarlanır.</p>

    <h2>Ödeme sayfası</h2>
    <p>Ücretli plana geçtiğinizde ödeme, Stripe'ın kendi alan adındaki ödeme sayfasında alınır. O sayfada Stripe, ödeme güvenliği ve dolandırıcılık önleme için kendi çerezlerini kullanabilir; bu çerezler Stripe'ın politikalarına tabidir. 34pazar.com üzerinde Stripe çerezi yerleştirilmez.</p>

    <h2>Hukuki dayanak</h2>
    <p>Zorunlu çerezler, talep ettiğiniz hizmeti sunabilmek için gereklidir. Bu nedenle onayınıza bağlı değildir (Almanya'da § 25 Abs. 2 Nr. 2 TDDDG). Bu çerezlerle işlenen veriler için hukuki sebep, sözleşmenin ifası ve sitenin güvenliğine ilişkin meşru menfaattir (KVKK m.5/2-c ve f; GDPR md. 6/1-b ve f). Ayrıntılar için <a href="{{ route('site.legal', 'gizlilik') }}">gizlilik ve KVKK aydınlatma metnine</a> bakabilirsiniz.</p>

    <h2>Çerezleri nasıl yönetirsiniz?</h2>
    <p>Tarayıcınızın ayarlarından çerezleri görüntüleyebilir ve silebilirsiniz. Zorunlu çerezleri engellerseniz site görüntülenmeye devam eder, ancak giriş yapamaz ve paneli kullanamazsınız.</p>

    <h2>Değişiklikler</h2>
    <p>İleride onaya tabi bir çerez (ör. analitik) kullanmaya karar verirsek, bu çerezi yalnızca açık onayınızla etkinleştiririz ve bu sayfayı önceden güncelleriz.</p>

    <h2>Sorumlu</h2>
    <p>{{ config('site.operator') }}, {{ config('site.street') }}, {{ config('site.postal_code') }} {{ config('site.city') }}, {{ config('site.country') }} · <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a></p>
@endsection
