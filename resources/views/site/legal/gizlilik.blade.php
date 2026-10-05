{{--
    Gizlilik politikası + KVKK aydınlatma metni (tek sayfa).

    NEDEN TEK SAYFADA İKİ MEVZUAT: işletmeci Almanya'da (GDPR/DSGVO
    doğrudan uygulanır), kullanıcılar ağırlıkla Türkiye'de (KVKK m.10
    aydınlatma yükümlülüğü). İki ayrı metin aynı gerçeği iki kez anlatır
    ve zamanla birbirinden ayrışırdı.

    YALNIZ GERÇEKTEN KULLANILAN HİZMETLER YAZILIR: barındırma IONOS
    (Almanya), ödeme Stripe. Analitik, reklam, hata izleme aracı YOK —
    eklenirse bu metin ve çerez politikası birlikte güncellenmeli.
    E-posta gönderimi Google Workspace (smtp.gmail.com). Shopify
    uygulaması (5 Eki 2026): Shopify Billing + kaldırma/gizlilik
    webhook'ları. İngilizce çevirisi `privacy.blade.php` — ikisi BİRLİKTE
    güncellenir.
--}}
@extends('site.legal.layout')

@section('description', '34Pazar gizlilik politikası ve KVKK aydınlatma metni: hangi kişisel verileri, hangi amaçla ve hukuki sebeple işliyoruz, kimlere aktarıyoruz ve haklarınız.')

@section('legal_body')
    <p>Bu metin, {{ config('site.brand') }} hizmetini ve 34pazar.com sitesini kullanırken kişisel verilerinizin nasıl işlendiğini açıklar. Hem 6698 sayılı Kişisel Verilerin Korunması Kanunu'nun (KVKK) 10. maddesi kapsamındaki aydınlatma yükümlülüğünü hem de Avrupa Birliği Genel Veri Koruma Tüzüğü'nün (GDPR) 13. maddesi kapsamındaki bilgilendirmeyi karşılamak amacıyla hazırlanmıştır.</p>

    <h2>1. Veri sorumlusu</h2>
    <p>
        {{ config('site.operator') }}<br>
        {{ config('site.street') }}, {{ config('site.postal_code') }} {{ config('site.city') }}, {{ config('site.country') }}<br>
        E-posta: <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a><br>
        Telefon: {{ config('site.phone') }}
    </p>
    <p>Yasal olarak zorunlu olmadığı için bir veri koruma görevlisi atanmamıştır. Kişisel verilerinizle ilgili tüm sorularınız için yukarıdaki iletişim bilgilerini kullanabilirsiniz.</p>

    <h2>2. Hangi verileri işliyoruz?</h2>

    <h3>2.1. Siteyi ziyaret ettiğinizde</h3>
    <p>Sunucumuz, siteyi görüntüleyebilmeniz ve güvenliği sağlayabilmek için her istekte teknik bilgileri işler: IP adresi, isteğin tarih ve saati, istenen adres, tarayıcı ve işletim sistemi bilgisi (user agent). Ayrıca yalnızca oturum ve güvenlik için zorunlu çerezler kullanılır; ayrıntılar <a href="{{ route('site.legal', 'cerez') }}">çerez politikasında</a>. Sitede analitik, reklam veya izleme aracı kullanılmaz.</p>

    <h3>2.2. Hesap oluşturduğunuzda</h3>
    <ul>
        <li><strong>Kimlik ve iletişim:</strong> ad soyad, e-posta adresi, firma veya mağaza adı.</li>
        <li><strong>Hesap güvenliği:</strong> parolanız (yalnızca geri döndürülemez biçimde, özetlenmiş olarak saklanır), oturum kayıtları (IP adresi, tarayıcı bilgisi, son etkinlik zamanı).</li>
    </ul>
    <p>Kayıt sırasında ödeme bilgisi istenmez.</p>

    <h3>2.3. Hizmeti kullandığınızda</h3>
    <ul>
        <li><strong>Kanal bağlantı bilgileri:</strong> bağladığınız satış kanallarının (ör. Shopify, WooCommerce) API anahtarları ve erişim belirteçleri. Bu bilgiler veritabanında <strong>şifrelenmiş</strong> olarak saklanır ve kayıt dosyalarına yazılmaz.</li>
        <li><strong>Katalog ve stok verileri:</strong> ürünler, varyantlar, stok kodları, stok hareketleri ve fiyatlar.</li>
        <li><strong>Shopify uygulaması:</strong> uygulamayı Shopify'dan kurduğunuzda mağazanızın alan adı (xxx.myshopify.com), mağaza adı ve mağaza e-posta adresi hesabınızı açmak ve mağazanızı bağlamak için alınır.</li>
        <li><strong>Sipariş verileri:</strong> kanallarınızdan gelen siparişlerin numarası, tarihi, durumu, kalemleri, tutarları ve kargo takip bilgisi. Siparişteki alıcıya ait ad, e-posta, telefon ve adres gibi alanlar sipariş kaydında tutulmaz; yalnızca kanalın verdiği müşteri numarası gibi bir referans saklanır. Kanaldan gelen ham bildirimler kayıt altına alınırken bu kişisel alanlar maskelenir.</li>
    </ul>

    <h3>2.4. Ücretli plana geçtiğinizde</h3>
    <p>Ödeme, ödeme hizmet sağlayıcımız Stripe'ın ödeme sayfasında alınır. Kart bilgileriniz Stripe tarafından işlenir; bu bilgiler bizim sunucularımıza ulaşmaz ve tarafımızca saklanmaz. Biz yalnızca abonelik durumunu, planı, Stripe'taki müşteri ve abonelik numarasını ve fatura bilgilerini saklarız.</p>
    <p>{{ config('site.brand') }}'ı Shopify uygulaması olarak kullanıyorsanız abonelik ücreti Shopify faturanıza eklenir ve Shopify tarafından tahsil edilir (Shopify Billing). Bu durumda ödeme bilgileriniz yalnızca Shopify'da işlenir; biz abonelik numarasını, planı ve durumunu saklarız.</p>

    <h3>2.5. Bizimle iletişime geçtiğinizde</h3>
    <p>E-posta veya telefonla bize ulaştığınızda adınız, iletişim bilginiz ve mesajınızın içeriği, talebinizi yanıtlamak için işlenir.</p>

    <h2>3. Amaçlar ve hukuki sebepler</h2>
    <table>
        <thead>
            <tr><th>Amaç</th><th>KVKK</th><th>GDPR</th></tr>
        </thead>
        <tbody>
            <tr><td>Siteyi sunmak, güvenliğini sağlamak, kötüye kullanımı önlemek</td><td>m.5/2-f (meşru menfaat)</td><td>md. 6/1-f</td></tr>
            <tr><td>Hesap açmak, hizmeti sunmak, kanallarınızla senkronizasyonu yürütmek</td><td>m.5/2-c (sözleşmenin kurulması ve ifası)</td><td>md. 6/1-b</td></tr>
            <tr><td>Abonelik ve ödeme işlemleri, faturalama</td><td>m.5/2-c ve m.5/2-ç (hukuki yükümlülük)</td><td>md. 6/1-b ve 6/1-c</td></tr>
            <tr><td>Muhasebe ve vergi kayıtlarının saklanması</td><td>m.5/2-ç</td><td>md. 6/1-c</td></tr>
            <tr><td>Destek taleplerini yanıtlamak</td><td>m.5/2-c veya m.5/2-f</td><td>md. 6/1-b veya 6/1-f</td></tr>
            <tr><td>Hukuki taleplerin tespiti, kullanılması ve savunulması</td><td>m.5/2-e (bir hakkın tesisi, kullanılması veya korunması)</td><td>md. 6/1-f</td></tr>
        </tbody>
    </table>
    <p>Kişisel verileriniz otomatik karar alma veya profil oluşturma amacıyla kullanılmaz. Pazarlama e-postası gönderilmez; ileride gönderilecekse ayrıca açık rızanız istenir.</p>

    <h2>4. Satıcı adına işlenen veriler</h2>
    <p>Kanallarınızdan gelen sipariş ve müşteri referans verileri bakımından veri sorumlusu, o satışı yapan satıcı olarak sizsiniz. {{ config('site.brand') }} bu verileri yalnızca sizin talimatınızla ve hizmeti sunmak amacıyla, sizin adınıza işler (KVKK anlamında veri işleyen, GDPR md. 28 anlamında işleyen). Alıcılarınızı kendi gizlilik metninizle bilgilendirmek sizin sorumluluğunuzdadır.</p>

    <h2>5. Verilerin aktarıldığı taraflar</h2>
    <p>Kişisel verileriniz satılmaz ve reklam amacıyla paylaşılmaz. Yalnızca hizmeti sunmak için gerekli olan şu taraflara aktarılır:</p>
    <ul>
        <li><strong>Barındırma — IONOS SE (Almanya):</strong> site ve veritabanı Almanya'daki sunucularda (AB) çalışır. IONOS, sözleşmeye dayalı olarak veri işleyen sıfatıyla hizmet verir.</li>
        <li><strong>Ödeme — Stripe:</strong> ücretli abonelikler Stripe üzerinden tahsil edilir. Avrupa'daki kullanıcılar için sözleşme tarafı Stripe Payments Europe, Ltd. (İrlanda) olup veriler ABD'deki Stripe, Inc.'e de aktarılabilir. Bu aktarım, AB-ABD Veri Gizliliği Çerçevesi ve/veya AB Komisyonu'nun standart sözleşme maddeleri gibi uygun güvenceler kapsamında yapılır. Stripe, ödeme hizmeti ve dolandırıcılık önleme bakımından kendi gizlilik politikasına tabidir.</li>
        <li><strong>E-posta gönderimi — Google (Google Ireland Ltd.):</strong> hesap doğrulama ve parola sıfırlama gibi hizmet e-postaları Google Workspace üzerinden gönderilir; bu kapsamda alıcı e-posta adresi ve e-posta içeriği Google tarafından işlenir. Veriler ABD'deki Google LLC'ye de aktarılabilir; aktarım AB-ABD Veri Gizliliği Çerçevesi ve/veya standart sözleşme maddeleri kapsamındadır.</li>
        <li><strong>Shopify (Shopify International Ltd., İrlanda):</strong> uygulamayı Shopify üzerinden kullandığınızda abonelik ücreti Shopify tarafından tahsil edilir; mağazanızla veri alışverişi Shopify'ın API'si üzerinden yapılır.</li>
        <li><strong>Bağladığınız satış kanalları:</strong> stok, fiyat, ürün ve kargo bilgileri, sizin talimatınızla bağladığınız kanallara (ör. Shopify, WooCommerce mağazanız veya pazaryeri hesabınız) API üzerinden gönderilir. Bu aktarımın amacı hizmetin kendisidir; kanalların veri işleme koşulları kendi politikalarına tabidir.</li>
        <li><strong>Yetkili kurumlar:</strong> yalnızca hukuken zorunlu olduğu durumlarda, yetkili kamu kurum ve kuruluşlarına.</li>
    </ul>

    <h3>Yurt dışına aktarım</h3>
    <p>Veri sorumlusu Almanya'da yerleşik olduğundan ve sunucular Almanya'da bulunduğundan, Türkiye'den hizmeti kullandığınızda kişisel verileriniz Almanya'da işlenir. Bu aktarım KVKK m.9 kapsamında, hizmet sözleşmesinin kurulması ve ifası için zorunlu olduğu ölçüde ve kanunun öngördüğü güvencelere uygun olarak gerçekleştirilir. Stripe'a yapılan aktarım için yukarıdaki açıklama geçerlidir.</p>

    <h2>6. Saklama süreleri</h2>
    <ul>
        <li><strong>Hesap, katalog ve sipariş verileri:</strong> hesabınız açık kaldığı sürece. Hesabınızı kapattığınızda, yasal saklama yükümlülüğü bulunmayan veriler makul bir süre içinde silinir veya anonim hale getirilir. Hesabınızın kapatılmasını <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a> adresine yazarak isteyebilirsiniz.</li>
        <li><strong>Kanal bağlantı bilgileri:</strong> hesabınız kapatıldığında silinir.</li>
        <li><strong>Shopify uygulamasını kaldırdığınızda:</strong> mağazanızın erişim belirteci geçersiz kılınır ve bağlantı kapatılır. Shopify'ın mağaza verisi silme bildirimi (kaldırmadan yaklaşık 48 saat sonra gelir) ulaştığında o mağazadan gelen siparişlerdeki müşteri referansları ve ham bildirim içerikleri silinir. Shopify üzerinden iletilen müşteri verisi erişim ve silme talepleri 30 gün içinde yanıtlanır.</li>
        <li><strong>Fatura ve ödeme kayıtları:</strong> vergi ve ticaret mevzuatının öngördüğü yasal saklama süreleri boyunca.</li>
        <li><strong>Sunucu ve oturum kayıtları:</strong> güvenlik amacıyla sınırlı bir süre; oturum kayıtları oturumunuz sona erdiğinde geçerliliğini yitirir.</li>
        <li><strong>Destek yazışmaları:</strong> talebiniz sonuçlandıktan sonra, olası soruların yanıtlanabilmesi için makul bir süre.</li>
    </ul>

    <h2>7. Veri güvenliği</h2>
    <p>Bağlantılar şifreli (HTTPS) yapılır; kanal kimlik bilgileri şifrelenmiş olarak saklanır; parolalar geri döndürülemez biçimde özetlenir; kayıt dosyalarında kimlik bilgileri ve kişisel alanlar maskelenir. Her hesabın verisi diğer hesaplardan ayrılmış olarak işlenir.</p>

    <h2>8. Haklarınız</h2>
    <p><strong>KVKK m.11 kapsamında</strong> veri sorumlusuna başvurarak:</p>
    <ul>
        <li>kişisel verilerinizin işlenip işlenmediğini öğrenme,</li>
        <li>işlenmişse buna ilişkin bilgi talep etme,</li>
        <li>işlenme amacını ve amacına uygun kullanılıp kullanılmadığını öğrenme,</li>
        <li>yurt içinde veya yurt dışında aktarıldığı üçüncü kişileri bilme,</li>
        <li>eksik veya yanlış işlenmişse düzeltilmesini isteme,</li>
        <li>KVKK m.7'de öngörülen şartlar çerçevesinde silinmesini veya yok edilmesini isteme,</li>
        <li>düzeltme, silme veya yok etme işlemlerinin verilerin aktarıldığı üçüncü kişilere bildirilmesini isteme,</li>
        <li>işlenen verilerin münhasıran otomatik sistemler vasıtasıyla analiz edilmesi suretiyle aleyhinize bir sonucun ortaya çıkmasına itiraz etme,</li>
        <li>kanuna aykırı işleme sebebiyle zarara uğramanız halinde zararın giderilmesini talep etme</li>
    </ul>
    <p>haklarına sahipsiniz.</p>

    <p><strong>GDPR kapsamında</strong> şu haklara sahipsiniz: erişim (md. 15), düzeltme (md. 16), silme (md. 17), işlemenin kısıtlanması (md. 18), veri taşınabilirliği (md. 20) ve meşru menfaate dayanan işlemeye itiraz (md. 21). Ayrıca bir denetim makamına şikâyette bulunma hakkınız vardır (md. 77); işletmecinin bağlı olduğu makam Kuzey Ren-Vestfalya Veri Koruma ve Bilgi Edinme Özgürlüğü Görevlisi'dir (Landesbeauftragte für Datenschutz und Informationsfreiheit Nordrhein-Westfalen). Türkiye'de Kişisel Verileri Koruma Kurulu'na şikâyet hakkınız saklıdır.</p>

    <h3>Başvuru yolu</h3>
    <p>Taleplerinizi <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a> adresine, hesabınızda kayıtlı e-posta adresinden yazarak iletebilirsiniz. Kimliğinizi doğrulamak için ek bilgi isteyebiliriz. Talepleriniz en geç otuz gün içinde ücretsiz olarak sonuçlandırılır; işlemin ayrıca bir maliyet gerektirmesi halinde mevzuatta öngörülen ücret alınabilir.</p>

    <h2>9. Değişiklikler</h2>
    <p>Hizmete yeni bir özellik veya hizmet sağlayıcı eklendiğinde bu metni güncelleriz. Güncel sürüm her zaman bu sayfada yayımlanır; sayfanın başındaki tarih son değişikliği gösterir. Önemli değişiklikleri kayıtlı kullanıcılara ayrıca bildiririz.</p>
@endsection
