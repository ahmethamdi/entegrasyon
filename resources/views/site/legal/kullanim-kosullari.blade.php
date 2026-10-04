{{--
    Kullanım koşulları — SaaS hizmet şartları.

    Plan adları ve fiyatları `$plans`'tan (veritabanı) okunur: koşullarda
    yazan fiyat ile fiyatlar sayfası ayrışmasın. Kanal adı sayılmaz,
    "bağlanabilen kanallar" entegrasyonlar sayfasına bağlanır — kanal
    açılıp kapandığında bu metni değiştirmek gerekmesin.

    Fazla satış konusunda "asla" denmez: eşzamanlı siparişlerde senkron
    penceresi kapanmadan çakışma olabilir; söz verilemeyecek şey yazılmaz.
--}}
@extends('site.legal.layout')

@section('description', '34Pazar kullanım koşulları: hizmetin kapsamı, hesap, satıcının sorumlulukları, planlar ve ücretler, iptal, sorumluluğun sınırları ve uygulanacak hukuk.')

@section('legal_body')
    <p>Bu koşullar, {{ config('site.operator') }} (“34Devs”, “biz”) tarafından sunulan {{ config('site.brand') }} hizmetinin (“Hizmet”) kullanımını düzenler. Hesap oluşturarak bu koşulları kabul etmiş olursunuz.</p>

    <h2>1. Hizmetin kapsamı</h2>
    <p>{{ config('site.brand') }}, satıcıların farklı satış kanallarını (pazaryerleri ve e-ticaret altyapıları) tek bir panelden yönetmesini sağlayan, internet üzerinden sunulan bir yazılım hizmetidir. Hizmet özellikle şunları kapsar:</p>
    <ul>
        <li>merkezi stok defteri ve bir kanaldaki satışın diğer bağlı kanallardaki stoka yansıtılması,</li>
        <li>ürünlerin bağlı kanallarda yayımlanması,</li>
        <li>bağlı kanallardan gelen siparişlerin tek listede gösterilmesi,</li>
        <li>kargo takip bilgisinin destekleyen kanallara iletilmesi,</li>
        <li>fiyat senkronizasyonu ve fiyat çakışmalarının gösterilmesi,</li>
        <li>stoku aşan siparişlerin (fazla satış) işaretlenmesi.</li>
    </ul>
    <p>Hangi kanalların bağlanabildiği ve her kanalda hangi işlemlerin desteklendiği <a href="{{ route('site.channels') }}">entegrasyonlar sayfasında</a> belirtilir. Hizmetin işlevleri zaman içinde geliştirilebilir veya değiştirilebilir.</p>

    <h2>2. Hesap</h2>
    <ul>
        <li>Hesap oluştururken doğru ve güncel bilgi vermeniz gerekir. Hizmet, ticari faaliyet yürüten satıcılara yöneliktir.</li>
        <li>Parolanızı gizli tutmak ve hesabınız üzerinden yapılan işlemler sizin sorumluluğunuzdadır. Yetkisiz bir kullanım fark ederseniz bize hemen bildirin.</li>
        <li>Bir hesap tek bir işletme adına kullanılır.</li>
    </ul>

    <h2>3. Satıcının sorumlulukları</h2>
    <ul>
        <li><strong>Kanal erişim bilgileri:</strong> bağladığınız kanalların API anahtarlarını ve erişim yetkilerini siz sağlarsınız. Bu bilgileri Hizmet'e vermeye yetkili olduğunuzdan ve kanalın kurallarına uygun kullandığınızdan siz sorumlusunuz.</li>
        <li><strong>İlan ve ürün içerikleri:</strong> ürün bilgileri, görseller, fiyatlar, stok miktarları ve ilan içerikleri sizin tarafınızdan belirlenir. Bunların doğruluğundan, mevzuata ve ilgili pazaryerinin kurallarına uygunluğundan siz sorumlusunuz.</li>
        <li><strong>Kanal kuralları:</strong> her pazaryerinin ve satış altyapısının kendi satıcı sözleşmesi ve kuralları vardır. Bu kurallar değişebilir; uyum sağlamak satıcının sorumluluğundadır.</li>
        <li><strong>Alıcı verileri:</strong> kanallarınızdan gelen siparişlerdeki alıcı verileri bakımından veri sorumlusu sizsiniz. Ayrıntılar <a href="{{ route('site.legal', 'gizlilik') }}">gizlilik metninde</a>.</li>
        <li><strong>Kontrol:</strong> Hizmet stok ve fiyat yönetimini kolaylaştırır, ancak işletmenizin kayıtlarını denetleme sorumluluğunu ortadan kaldırmaz. Kritik ürünlerde kanallardaki stok ve fiyatları düzenli aralıklarla kontrol etmenizi öneririz.</li>
    </ul>

    <h2>4. Yasak kullanımlar</h2>
    <p>Hizmet'i hukuka aykırı amaçlarla, başkalarının haklarını ihlal edecek şekilde veya Hizmet'in ya da bağlı kanalların güvenliğini ve işleyişini tehlikeye atacak şekilde (ör. yetkisiz erişim denemesi, aşırı yük oluşturma, tersine mühendislik) kullanamazsınız. Bu durumda hesabınızı askıya alma veya kapatma hakkımız saklıdır.</p>

    <h2>5. Planlar ve ücretler</h2>
    @if (! empty($plans))
        <p>Güncel planlar şunlardır:</p>
        <ul>
            @foreach ($plans as $plan)
                <li>
                    <strong>{{ $plan['name'] }}</strong>:
                    @if ((float) $plan['priceMonthly'] === 0.0)
                        ücretsiz
                    @else
                        aylık {{ number_format((float) $plan['priceMonthly'], 0, ',', '.') }} {{ $plan['currency'] === 'TRY' ? 'TL' : $plan['currency'] }}
                    @endif
                    — {{ $plan['productLimit'] === null ? 'sınırsız ürün' : number_format($plan['productLimit'], 0, ',', '.').' ürün' }},
                    {{ $plan['channelLimit'] === null ? 'sınırsız kanal' : $plan['channelLimit'].' kanal' }}
                </li>
            @endforeach
        </ul>
    @endif
    <p>Planların güncel içeriği ve fiyatları <a href="{{ route('site.pricing') }}">fiyatlar sayfasında</a> yayımlanır.</p>
    <ul>
        <li>Ücretli planlar aylık abonelik olarak sunulur ve her dönemin başında, ödeme hizmet sağlayıcımız Stripe aracılığıyla seçtiğiniz ödeme yönteminden tahsil edilir. Ödenecek toplam tutar, ödemeden önce ödeme sayfasında gösterilir.</li>
        <li>Plan limitlerine (ürün ve kanal sayısı) ulaşıldığında, limiti aşan yeni ekleme yapılamaz; mevcut verileriniz silinmez. Daha yüksek bir plana geçerek limiti artırabilirsiniz.</li>
        <li>Ödeme alınamazsa sizi bilgilendiririz; ödeme makul bir süre içinde tamamlanmazsa hesabınız ücretsiz plan koşullarına düşürülebilir.</li>
        <li>Fiyat değişiklikleri, yürürlüğe girmeden önce kayıtlı e-posta adresinize bildirilir ve bir sonraki fatura döneminden itibaren uygulanır. Yeni fiyatı kabul etmiyorsanız aboneliğinizi değişiklik yürürlüğe girmeden iptal edebilirsiniz.</li>
    </ul>

    <h2>6. İptal ve hesabın kapatılması</h2>
    <ul>
        <li>Ücretli aboneliğinizi istediğiniz zaman iptal edebilirsiniz. İptal, ödemesi yapılmış dönemin sonunda geçerli olur; o tarihe kadar ücretli plan özelliklerini kullanmaya devam edersiniz. Kısmi dönem için ücret iadesi yapılmaz; tüketicilerin kanundan doğan hakları saklıdır (bkz. <a href="{{ route('site.legal', 'mesafeli-satis') }}">mesafeli hizmet sözleşmesi</a>).</li>
        <li>Hesabınızın tamamen kapatılmasını <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a> adresine yazarak isteyebilirsiniz. Kapatma sonrası verilerin akıbeti gizlilik metninde açıklanmıştır.</li>
        <li>Bu koşulların esaslı bir ihlali halinde hesabınızı, mümkünse önceden bildirimde bulunarak, askıya alabilir veya kapatabiliriz.</li>
    </ul>

    <h2>7. Hizmetin sürekliliği</h2>
    <p>Hizmet'i kesintisiz ve hatasız sunmak için makul özeni gösteririz; ancak kesintisiz erişim garanti edilmez. Bakım, güncelleme, altyapı sağlayıcılarından kaynaklanan arızalar veya bağlı kanalların (pazaryerleri, e-ticaret altyapıları) API'lerindeki kesinti ve değişiklikler nedeniyle Hizmet geçici olarak kullanılamayabilir ya da senkronizasyon gecikebilir. Planlı bakımları mümkün olduğunca önceden duyururuz.</p>
    <p>Kanallar arasındaki senkronizasyon anlık değildir. Özellikle aynı ürün için birden fazla kanaldan aynı anda sipariş gelmesi halinde, stok tüm kanallara yansımadan önce fazla satış yaşanabilir. Hizmet bu durumları tespit edip işaretlemeye çalışır, ancak tamamen önleyemez.</p>

    <h2>8. Sorumluluğun sınırları</h2>
    <ul>
        <li>Kasıt ve ağır ihmal hallerinde, hayata, vücut bütünlüğüne veya sağlığa gelen zararlarda ve emredici mevzuatın öngördüğü diğer hallerde kanuni sorumluluğumuz saklıdır.</li>
        <li>Hafif ihmal halinde yalnızca sözleşmenin amacına ulaşması için zorunlu olan temel yükümlülüklerin ihlalinden ve sözleşmenin kuruluşunda öngörülebilir, tipik zararlarla sınırlı olarak sorumluyuz. Bu durumda sorumluluğumuz, zarara yol açan olaydan önceki on iki ayda tarafınızca Hizmet için ödenen toplam ücretle sınırlıdır.</li>
        <li>Bağlı kanalların kendi sistemlerindeki hata, kesinti, kural değişikliği veya yaptırımlarından; tarafınızca girilen hatalı ürün, stok veya fiyat bilgisinden kaynaklanan zararlardan sorumlu değiliz.</li>
    </ul>

    <h2>9. Fikri mülkiyet</h2>
    <p>Hizmet'in yazılımı, tasarımı ve içerikleri 34Devs'e aittir. Size, abonelik süresince Hizmet'i bu koşullara uygun olarak kullanmak için devredilemez, münhasır olmayan bir kullanım hakkı tanınır. Hizmet'e yüklediğiniz ürün ve sipariş verileri size aittir.</p>

    <h2>10. Kişisel veriler</h2>
    <p>Kişisel verilerin işlenmesi <a href="{{ route('site.legal', 'gizlilik') }}">gizlilik ve KVKK aydınlatma metninde</a> düzenlenmiştir.</p>

    <h2>11. Değişiklikler</h2>
    <p>Bu koşulları; mevzuat değişikliği, yeni özellikler veya hizmet koşullarındaki değişiklikler gibi haklı sebeplerle güncelleyebiliriz. Esaslı değişiklikleri yürürlüğe girmeden en az otuz gün önce kayıtlı e-posta adresinize bildiririz. Bu süre içinde itiraz etmeniz halinde aboneliğinizi değişiklik yürürlüğe girmeden ücretsiz olarak sonlandırabilirsiniz.</p>

    <h2>12. Uygulanacak hukuk ve uyuşmazlıklar</h2>
    <p>Bu koşullara Almanya Federal Cumhuriyeti hukuku uygulanır; Birleşmiş Milletler Uluslararası Mal Satımına İlişkin Sözleşmeler Hakkında Antlaşma (CISG) uygulanmaz. Tüketici sıfatıyla hareket eden kullanıcılar bakımından, mutad meskeninin bulunduğu ülkenin tüketiciyi koruyan emredici hükümleri saklıdır; Türkiye'de yerleşik tüketiciler 6502 sayılı Kanun kapsamındaki tüketici hakem heyetlerine ve tüketici mahkemelerine başvurabilir.</p>
    <p>Tacir sıfatıyla hareket eden kullanıcılarla doğan uyuşmazlıklarda, kanunen izin verilen ölçüde, 34Devs'in yerleşim yeri mahkemeleri yetkilidir.</p>

    <h2>13. İletişim</h2>
    <p>
        {{ config('site.operator') }}<br>
        {{ config('site.street') }}, {{ config('site.postal_code') }} {{ config('site.city') }}, {{ config('site.country') }}<br>
        <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a>
    </p>
@endsection
