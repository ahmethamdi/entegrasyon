<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Yardım ekranı — §13 · Faz 4 · "Türkçe yardım dokümantasyonu".
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 4 · "Türkçe yardım
 * dokümantasyonu ve hata mesajları — 12 sa". Doküman maddeyi SAYAR ama
 * içeriğini tanımlamaz; içerik ve biçim kararları burada alınır.
 *
 * KARAR — İÇERİK KODDA YAŞAR, VERİTABANINDA DEĞİL.
 *   Yardım metni sürümlenmiş bir ÜRÜN parçasıdır: kod değiştiğinde
 *   birlikte değişmeli ve aynı commit'te gözden geçirilebilmelidir.
 *   Veritabanına konsaydı metin ile davranış AYRI zamanlarda değişir,
 *   hangi sürümün neyi anlattığı kaybolur ve yeni kurulum boş yardım
 *   ekranıyla açılırdı (seed unutulursa sessizce).
 *
 * KARAR — KİRACIYA AİT DEĞİLDİR ve kiracı verisi OKUMAZ.
 *   Yardım metni tüm satıcılar için aynıdır. Bu yüzden ekran hiçbir
 *   tenant-scoped sorgu yapmaz; `tenant` ara katmanının altında yaşar
 *   çünkü panelin bir parçasıdır, ama veriye DOKUNMAZ.
 *
 * KARAR — SORULAR SİSTEMİN GERÇEK TUZAKLARINI ANLATIR.
 *   Genel bir "nasıl kullanılır" metni değil: fazla satış neden
 *   gösteriliyor, eşleşmemiş SKU ne demek, `error_permanent` neden
 *   kendiliğinden düzelmiyor, kanaldaki stok neden ezilmiyor. Bunlar
 *   satıcının PANELDE KARŞILAŞTIĞI ve açıklanmazsa destek çağrısına
 *   dönüşen durumlar — §17 yardım ekranını tam olarak destek yükünü
 *   düşürmek için istiyor.
 */
final class HelpController extends Controller
{
    public function __invoke(): InertiaResponse
    {
        return Inertia::render('Help/Index', [
            'sections' => self::sections(),
        ]);
    }

    /**
     * Yardım içeriği.
     *
     * TEK KAYNAK: ekran yalnızca gösterir, metin burada yaşar. Bölüm
     * kimlikleri (`id`) SÖZLEŞMEDİR — panelin başka ekranlarından
     * `/help#stok` gibi doğrudan bağlantı verilebilsin diye sabittir ve
     * değiştirilirse o bağlantılar sessizce kırılır.
     *
     * @return list<array{id: string, title: string, intro: string, items: list<array{q: string, a: string}>}>
     */
    private static function sections(): array
    {
        return [
            [
                'id' => 'baslangic',
                'title' => 'Başlangıç',
                'intro' => 'Kurulum dört adımdır ve panelin üstündeki şerit hangi adımda olduğunuzu gösterir. Şerit dört adım bitince kendiliğinden kaybolur.',
                'items' => [
                    [
                        'q' => 'Nereden başlamalıyım?',
                        'a' => 'Önce bir kanal bağlayın, sonra ürünlerinizi ekleyin, sonra bir ürünü kanala gönderin. Şeritteki düğme her zaman sıradaki adımı gösterir; hangisinden başlayacağınıza karar vermeniz gerekmez.',
                    ],
                    [
                        'q' => 'Kanalı bağladım ama adım kapanmadı.',
                        'a' => 'Bağlantı yalnızca sağlık kontrolünü geçtiğinde “aktif” olur ve adım ancak o zaman kapanır. Bekleyen bir bağlantı kanalla henüz hiç konuşamamış demektir — anahtarları kontrol edin. Aktif olmayan bağlantıya ürün gönderilseydi hepsi kalıcı hataya düşerdi.',
                    ],
                    [
                        'q' => 'Şerit kaybolmuştu, geri geldi.',
                        'a' => 'Bu kasıtlıdır. İlerleme kaydedilmez, verinizden hesaplanır: bağlantınız sağlıksızlığa düşerse ya da ürününüz kalmazsa ilgili adım yeniden açılır. Böylece şerit hiçbir zaman gerçekte olmayan bir şeyi “bitti” diye göstermez.',
                    ],
                ],
            ],
            [
                'id' => 'stok',
                'title' => 'Stok',
                'intro' => 'Stok bir defter üzerinden yürür: her değişiklik bir hareket olarak yazılır ve bakiye o hareketlerden hesaplanır. Bu yüzden her sayının bir açıklaması vardır.',
                'items' => [
                    [
                        'q' => 'Stok eksiye düştü, bu bir hata mı?',
                        'a' => 'Hayır. Pazaryeri siparişi kabul ettiyse o sipariş gerçektir ve biz onu geri çeviremeyiz; stok yetmediğinde bakiye eksiye düşer ve satır “fazla satış” işaretlenir. Eksi bakiye gizlenseydi elinizde olmayan malı sattığınızı fark edemezdiniz.',
                    ],
                    [
                        'q' => 'Bakiyem −2 ama kanala 0 gitmiş.',
                        'a' => 'Doğrusu budur. Kanallar negatif stok kabul etmez, bu yüzden giden değer sıfıra kırpılır. Kırpma yalnızca kanala giden yükte olur; panelde gerçeği görürsünüz. İki değerin farklı olması bir tutarsızlık değildir.',
                    ],
                    [
                        'q' => 'Stok sayımım tutmuyor, nasıl düzeltirim?',
                        'a' => 'Stok ekranından düzeltme girin. Düzeltme de deftere bir hareket olarak yazılır, yani izi kalır. İki ayrı sayım iki ayrı düzeltmedir — aynı düzeltmeyi iki kez girerseniz iki kez uygulanır, bu kasıtlıdır.',
                    ],
                    [
                        'q' => 'Kanaldan ürün çektim ama stoğum değişmedi.',
                        'a' => 'Var olan bir SKU’da kanalın stoğu asla yazılmaz. Kanaldaki sayı bayat olabilir ve uygulansaydı satılmış mallar geri gelir, bakiyeniz sessizce bozulurdu. Kanal stoğu yalnızca sistemde hiç olmayan yeni bir üründe, açılış hareketi olarak yazılır.',
                    ],
                ],
            ],
            [
                'id' => 'siparisler',
                'title' => 'Siparişler',
                'intro' => 'Sipariş asla reddedilmez veya geri alınmaz. Pazaryeri onu kabul etmiştir; bizim işimiz onu doğru yansıtmaktır.',
                'items' => [
                    [
                        'q' => '“Eşleşmemiş” satır ne demek?',
                        'a' => 'Siparişteki SKU kataloğunuzda bulunamadı. Sipariş kaydedilir ama o satır için stok düşülmez ve satır bekler. Sipariş kaybetmek stok tutarsızlığından kötüdür — o yüzden sipariş atılmaz. Ürünü aynı SKU ile ekleyin.',
                    ],
                    [
                        'q' => 'Fazla satış ile eşleşmemiş satır arasındaki fark ne?',
                        'a' => 'Fazla satışta stok düşülmüştür ve bakiye eksidir — kargo çıkışı gerçekten tehlikededir. Eşleşmemiş satırda stoğa hiç dokunulmamıştır ve tablo “her şey yolunda” der; bakiyeniz olduğundan fazla görünür. İkisi ayrı uyarıdır çünkü ayrı işler yaptırırlar.',
                    ],
                    [
                        'q' => 'İptal ettiğim sipariş stoğu geri ekledi mi?',
                        'a' => 'Evet, iptal ve iade stoğu geri ekler ve bunlar da deftere hareket olarak yazılır. Sipariş ayrıntısındaki olay listesinde ne olduğunu görebilirsiniz.',
                    ],
                ],
            ],
            [
                'id' => 'senkron',
                'title' => 'Senkron ve hatalar',
                'intro' => 'Kanala giden her iş bir işlem olarak kaydedilir; başarısı, denemeleri ve hatası ayrı ayrı görünür.',
                'items' => [
                    [
                        'q' => '“Kalıcı hata” ile “geçici hata” farkı ne?',
                        'a' => 'Geçici hata kendiliğinden yeniden denenir (ağ kopması, hız sınırı). Kalıcı hata denenmeye devam edilmez çünkü tekrar denemek aynı sonucu verir: yetki hatasında anahtarı yenilemeniz, doğrulama hatasında ürün verisini düzeltmeniz gerekir. Bu yüzden kalıcı hata sizden bir iş bekler.',
                    ],
                    [
                        'q' => 'Kalıcı hataya düşen satır kendiliğinden düzelir mi?',
                        'a' => 'Hayır ve bu kasıtlıdır. Kalıcı hatalı satıra otomatik hiçbir mekanizma dokunmaz; sonsuza kadar aynı hatayı üretmesin diye. Sebebi giderdikten sonra “Başarısız işlemler” ekranından yeniden deneyin.',
                    ],
                    [
                        'q' => 'Yeniden denedim, eski hata kaydı hâlâ duruyor.',
                        'a' => 'Eski kayıt bilerek silinmez — “beş kez denendi ve öldü” bilgisi denetim izidir. Yeniden deneme yeni bir işlem açar; eskisi geçmişte kalır.',
                    ],
                    [
                        'q' => 'Mutabakat ne yapıyor?',
                        'a' => 'Kanaldaki değerle bizdeki değeri karşılaştırır ve fark bulursa düzeltir. Sizin bir şey yapmanız gerekmez. Ama “elle inceleme” işaretli satırlarda otomatik onarım durmuştur: art arda üç turda düzelmediği için sistem denemeyi bırakmıştır ve o satıra bakmanız gerekir.',
                    ],
                    // §9 · PRICE politikası. Bu üç madde stokla fiyat
                    // arasındaki OTORİTE farkını anlatır: satıcı bunu
                    // bilmezse "neden stoğu düzeltti de fiyatı sormadan
                    // bıraktı" diye sistemi tutarsız sanar.
                    [
                        'q' => 'Kanaldaki fiyat benimkinden farklı çıktı, neden düzeltmediniz?',
                        'a' => 'Bilerek. Kanal panelinden kampanya yapmış olabilirsiniz ve o fiyatı sessizce ezmek yaptığınız indirimi silerdi. Bu yüzden fiyatta karar sizindir: mutabakat ekranında “Kanalınki kalsın” ya da “Bizimki gitsin” diyebilirsiniz. Stokta durum farklıdır — orada tek doğru kaynak biziz ve fark otomatik düzeltilir.',
                    ],
                    [
                        'q' => '“Kanalınki kalsın” dersem ne olur?',
                        'a' => 'O ürüne artık fiyat göndermeyiz; kanaldaki fiyat olduğu gibi kalır. Kararınız kaydedilir ve kim ne zaman verdi bilgisiyle birlikte saklanır. Panelden o ürünün fiyatını değiştirirseniz kararınız eskimiş sayılır ve yeni fiyat kanala gönderilmeye başlar — yoksa yaptığınız zam o kanala hiç ulaşmazdı.',
                    ],
                    [
                        'q' => '“Bizimki gitsin” dersem hemen gider mi?',
                        'a' => 'Gönderim sıraya alınır ve normal senkron akışından geçer; birkaç dakika içinde kanala ulaşır. Daha önce “kanalınki kalsın” demişseniz o karar kaldırılır.',
                    ],
                ],
            ],
            [
                'id' => 'kanallar',
                'title' => 'Kanallar',
                'intro' => 'Her kanalın yetenekleri farklıdır; panel hangi kanalın neyi desteklediğini bağlantı kartında gösterir.',
                'items' => [
                    [
                        'q' => 'Anahtarlarım değişti, yeni bağlantı mı açmalıyım?',
                        'a' => 'Hayır. Aynı mağazayı yeniden bağlayın; sistem var olan bağlantıyı günceller. Yeni satır açılsaydı ürün ve sipariş geçmişiniz eski bağlantıda kalırdı. Anahtar yenileme kotanızdan da etkilenmez.',
                    ],
                    [
                        'q' => 'Bağlantım “sağlıksız” oldu, ürünlerim silinir mi?',
                        'a' => 'Hayır. Sağlıksız bağlantıya yeni iş gönderilmez ama bağlantı ve ona bağlı geçmiş silinmez. Sorunu giderip sağlık kontrolünü yeniden çalıştırın.',
                    ],
                    [
                        'q' => 'Ürünü kanaldan kaldırmak siler mi?',
                        'a' => 'Silmez, taslağa çeker. Silme geri alınamaz ve kanaldaki yorumları, sıralamayı ve arama geçmişini de götürürdü.',
                    ],
                    [
                        'q' => 'Ürünüm “gönderilecek bir şey yok” diyor.',
                        'a' => 'İki farklı sebep olabilir: içerik zaten güncel olduğu için gönderim atlanmıştır ya da eşleştirme eksik olduğu için engellenmiştir. Ekran hangisi olduğunu söyler; engelse eşleştirme ekranından eksikleri tamamlayın.',
                    ],
                    // §14 · onay süreci. Satıcı bu farkı bilmezse
                    // Trendyol'a gönderdiği ürünün neden satmadığını
                    // anlamaz ve sorunu bizde arar.
                    [
                        'q' => 'Ürünü gönderdim ama kanalda görünmüyor.',
                        'a' => 'Pazaryerlerinde ürün gönderilir gönderilmez yayına girmez: kanal önce inceler. O sırada ürün “onay bekliyor” durumundadır ve yapmanız gereken bir şey yoktur. Kanal reddederse sebebini “Onaylar” ekranında görürsünüz. WooCommerce gibi kendi mağazanızda onay süreci yoktur, ürün anında yayına girer.',
                    ],
                    [
                        'q' => 'Ürünüm reddedildi, ne yapmalıyım?',
                        'a' => '“Onaylar” ekranında red sebebi yazılıdır — genellikle eksik bir bilgi ya da kanal kurallarına uymayan bir görseldir. Ürünü düzeltip kanala yeniden gönderin; sonraki kontrol turunda durum güncellenir. Reddedilen satır kendiliğinden düzelmez.',
                    ],
                ],
            ],
            [
                'id' => 'abonelik',
                'title' => 'Abonelik ve kota',
                'intro' => 'Planınız ürün ve kanal sayınızı sınırlar. Kota yalnızca yeni eklemeyi engeller.',
                'items' => [
                    [
                        'q' => 'Kotam doldu, mevcut ürünlerime ne olacak?',
                        'a' => 'Hiçbir şey. Var olan ürünler silinmez, senkronları durmaz ve siparişleriniz işlenmeye devam eder. Yalnızca yenisini ekleyemezsiniz. Ödeme sorunu yüzünden stok bozmak veya sipariş kaybetmek, çözdüğünden büyük zarar verirdi.',
                    ],
                    [
                        'q' => 'Planı yükseltince kotam hemen artar mı?',
                        'a' => 'Ödeme onaylandıktan sonra artar. Abonelik bilgisi ödeme sağlayıcısından gelen onayla yazılır; ödeme sayfasında vazgeçerseniz hiçbir şey değişmez.',
                    ],
                ],
            ],
        ];
    }
}
