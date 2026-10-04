<script setup>
import PanelShot from '../../Components/Site/PanelShot.vue';
import SiteLayout from '../../Layouts/SiteLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * 34pazar.com ana sayfası — herkese açık tanıtım.
 *
 * DEĞİŞMEZ KURAL — SAYFA VERİ UYDURMAZ (SiteController ile aynı kural):
 *   Fiyat ve kanal listesi prop'tan gelir, burada elle yazılmaz. Müşteri
 *   sayısı, yorum, puan, "bilmem kaç satıcı" gibi sahip olmadığımız
 *   hiçbir rakam sayfaya girmez; ilk satıcıyı kaybettiren şey abartılı
 *   bir vaadin panelde tutmamasıdır.
 *
 * GÖRSELLER PANELİN GERÇEK EKRANLARIDIR (kullanıcı kararı, 4 Ekim 2026).
 *   İlk sürümdeki HTML/CSS "örnek panel" çizimi şablon gibi durduğu için
 *   kaldırıldı. Satıcı sitede gördüğünü panelde de görmeli.
 */
const props = defineProps({
    plans: { type: Array, default: () => [] },
    channels: { type: Array, default: () => [] },
    isLoggedIn: { type: Boolean, default: false },
});

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring';

const availableChannels = computed(() => props.channels.filter((c) => c.available));
const upcomingChannels = computed(() => props.channels.filter((c) => !c.available));

function joinTurkish(names) {
    if (names.length <= 1) {
        return names.join('');
    }

    return `${names.slice(0, -1).join(', ')} ve ${names[names.length - 1]}`;
}

const availableNames = computed(() => joinTurkish(availableChannels.value.map((c) => c.name)));
const upcomingNames = computed(() => joinTurkish(upcomingChannels.value.map((c) => c.name)));

// ─────────────────────────────────────────────── hero başlığı

/*
 * Başlık somut bir satış anını anlatır: "X'te sattın, Y'deki stok düştü".
 * Kanal adları AÇIK kanallardan seçilir — bir kanal kapanırsa başlık onu
 * vaat etmeye devam etmesin.
 *
 * Bulunma eki ('da/'de/'te) elle tutulur: yabancı marka adlarının
 * okunuşu yazılışından tahmin edilemez ("Shopify" yazılışta i ile biter,
 * söylenişte a ile). Sözlükte olmayan kanal başlığa girmez; o zaman genel
 * başlık kullanılır.
 */
const locative = {
    trendyol: "Trendyol'da",
    shopify: "Shopify'da",
    woocommerce: "WooCommerce'te",
    etsy: "Etsy'de",
    ebay: "eBay'de",
    hepsiburada: "Hepsiburada'da",
    n11: "N11'de",
    amazon: "Amazon'da",
};

// Önce tanınan pazaryeri + mağaza ikilisi; yoksa sözlükteki ilk iki açık kanal.
const heroPair = computed(() => {
    const known = availableChannels.value.filter((c) => locative[c.code]);
    const preferred = ['trendyol', 'shopify'].map((code) => known.find((c) => c.code === code)).filter(Boolean);
    const pair = preferred.length === 2 ? preferred : known.slice(0, 2);

    return pair.length === 2 ? pair : null;
});

// ─────────────────────────────────────────────── fiyat biçimleme

const numberTr = new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 0 });

function isFree(plan) {
    return Number(plan.priceMonthly) === 0;
}

/*
 * Fiyat sunucudan "1499.00" gibi METİN olarak gelir (decimal sütun).
 * Türk okur "1.499 ₺" bekler; kuruş yoksa ",00" gürültüdür. Para birimi
 * TRY dışında bir şeyse simge uydurulmaz, kod olduğu gibi yazılır.
 */
function formatPrice(plan) {
    if (isFree(plan)) {
        return 'Ücretsiz';
    }

    const amount = Number(plan.priceMonthly);
    const formatted = new Intl.NumberFormat('tr-TR', {
        minimumFractionDigits: Number.isInteger(amount) ? 0 : 2,
        maximumFractionDigits: 2,
    }).format(amount);

    return plan.currency === 'TRY' ? `${formatted} ₺` : `${formatted} ${plan.currency}`;
}

// `null` limit = sınırsız (PlanSeeder sözleşmesi).
function formatCount(value) {
    return value === null || value === undefined ? 'Sınırsız' : numberTr.format(value);
}

function formatLimit(value, unit) {
    return value === null || value === undefined ? `Sınırsız ${unit}` : `${numberTr.format(value)} ${unit}`;
}

/*
 * "Profesyonel" için "en çok tercih edilen" DENMEZ: elimizde bunu
 * gösteren veri yok. Yerine kimin için olduğunu söyleyen nötr bir etiket.
 */
const planNotes = { pro: 'Büyüyen satıcılar için' };

const freePlan = computed(() => props.plans.find(isFree) ?? null);

// Giriş yapmış kullanıcıyı kayıt formuna göndermek anlamsız: paketler panelde.
const planHref = computed(() => (props.isLoggedIn ? '/billing' : '/register'));
const primaryHref = computed(() => (props.isLoggedIn ? '/panel' : '/register'));
const primaryLabel = computed(() => (props.isLoggedIn ? 'Panele git' : 'Ücretsiz başla'));

const metaDescription = computed(() => {
    const where = availableNames.value ? `${availableNames.value} mağazalarındaki` : 'Bütün kanallarındaki';

    return `${where} stoğunu, ürünlerini ve siparişlerini tek panelden yönet. Bir kanalda satış olunca stok her yerde birlikte düşer.`;
});

// ─────────────────────────────────────────────── içerik

/*
 * Her satır TEK bir gerçek durum anlatır ve yanında o işin yapıldığı
 * panel ekranı durur. İkon ızgarası yerine bu düzen: satıcı "bu ne işe
 * yarar"ı soyut sıfatlardan değil kendi gününden tanır.
 */
const scenes = [
    {
        id: 'siparisler',
        kicker: 'Siparişler',
        title: 'Bütün siparişler tek listede.',
        body: 'Sabah paneli açarsın; hangi kanaldan gelirse gelsin bütün siparişler alt alta. Sekmeler arasında dolaşıp hangisini kaçırdığını aramazsın.',
        points: ['Her siparişin hangi kanaldan geldiği yanında yazar', 'Stoğu yetmeyen sipariş ayrıca işaretlenir'],
        shot: { src: '/images/site/panel-siparisler.png', alt: 'Panelde farklı kanallardan gelen siparişlerin listesi' },
    },
    {
        id: 'stok',
        kicker: 'Stok',
        /*
         * "Hiç şaşmaz" DENMEZ: panel görüntüsünde fazla satılmış bir ürün
         * kırmızıyla duruyor (kanal satışı geç bildirdiğinde olur). Vaat,
         * görselin yanında yalan çıkmamalı; doğrusu "olursa hemen görürsün".
         */
        title: 'Stok tek yerde. Ters giden hemen görünür.',
        body: 'Ürünün adedi tek bir yerde tutulur. Satış nereden gelirse gelsin adet oradan düşer ve yeni sayı bağlı bütün kanallara gider.',
        points: ['Bir kanalda satılan ürün diğerlerinde de azalır', 'Bir şey ters giderse fazla satılan ürün kırmızıyla en üstte durur'],
        shot: { src: '/images/site/panel-stok.png', alt: 'Panelde ürünlerin stok adetleri listesi' },
    },
    {
        id: 'kargo',
        kicker: 'Kargo',
        title: 'Kargo numarasını bir kez gir.',
        body: 'Paketi kargoya verdin, takip numarasını siparişin içine yazdın. Gerisi bizde: numara siparişin geldiği kanala iletilir, müşterin takibi orada görür.',
        points: ['Kanalın kendi panelini açman gerekmez'],
        shot: { src: '/images/site/panel-kargo.png', alt: 'Sipariş detayında kargo takip numarası girme formu', shiftY: 30 },
    },
];

/*
 * Ekran görüntüsü olmayan özellikler düz bir liste olarak durur: her
 * biri için uydurma bir görsel üretmek yerine ne yaptığını tek cümleyle
 * söylemek daha dürüst.
 */
const extras = [
    {
        title: 'Ürünü bir kez hazırla',
        body: 'Ürün bilgisini bir kere gir, hangi kanalda satılacağını seç ve buradan gönder.',
    },
    {
        title: 'Fiyatı bir yerde değiştir',
        body: 'Yeni fiyat bağlı kanallara gider; mağaza mağaza dolaşıp düzeltmezsin.',
    },
    {
        title: 'Sormadan üzerine yazmayız',
        body: 'Bir kanalın içinde fiyatı kendin değiştirdiysen fark ederiz ve hangisinin geçerli olacağını sana sorarız.',
    },
    {
        title: 'Telefonda da açılır',
        body: 'Uygulama indirmen gerekmez; panel telefonunun tarayıcısında da çalışır.',
    },
];

/*
 * SSS cevapları YALNIZCA doğrulanmış şeyleri söyler. "Kart bilgisi
 * istenmez" iddiası kayıt formuna bakılarak yazıldı (Auth/Register.vue
 * ödeme sormuyor) ve yalnız ücretsiz paket varsa gösterilir.
 */
const faqs = computed(() => {
    const items = [
        {
            q: '34Pazar ne işe yarar?',
            a: 'Birden fazla kanalda satış yapıyorsan stoğunu, ürünlerini ve siparişlerini tek panelden yönetmeni sağlar. En önemlisi: bir kanalda satılan ürünün stoğu diğer kanallarda da düşer, böylece elinde olmayan ürünü satmazsın.',
        },
    ];

    if (availableNames.value) {
        let answer = `Şu an ${availableNames.value} bağlanabiliyor.`;
        if (upcomingNames.value) {
            answer += ` ${upcomingNames.value} için çalışıyoruz; hazır olduğunda bu listede görünecek.`;
        }
        items.push({ q: 'Hangi kanallarla çalışıyor?', a: answer });
    }

    if (freePlan.value) {
        items.push({
            q: 'Ücretsiz deneyebilir miyim?',
            a: `Evet. ${freePlan.value.name} paketle kart bilgisi girmeden deneyebilirsin. Bu pakette ${formatLimit(freePlan.value.productLimit, 'ürün')} ve ${formatLimit(freePlan.value.channelLimit, 'kanal')} hakkın var.`,
        });
    }

    items.push(
        {
            q: 'Bir kanalda satış olunca diğerlerinde ne olur?',
            a: 'Stok tek bir yerde tutulur. Ürün hangi kanalda satılırsa satılsın adet oradan düşer ve yeni adet bağlı bütün kanallara gönderilir.',
        },
        {
            q: 'Kanalın kendi panelinde fiyat değiştirirsem ne olur?',
            a: 'Değişikliğini sessizce ezmeyiz. Farkı gördüğümüzde sana gösterir, hangi fiyatın geçerli olacağını sorarız.',
        },
        {
            q: 'Teknik bilgi gerekiyor mu?',
            a: 'Hayır. Kod yazman ya da bir yazılımcıyla çalışman gerekmez. Bir kanalı bağlamak için o kanaldaki satıcı hesabına erişimin olması yeterli.',
        },
        {
            q: 'Muhasebe ya da e-fatura var mı?',
            a: 'Henüz yok. Muhasebe modülü yakında geliyor. Şu an 34Pazar stok, ürün, sipariş ve kargo takibine odaklanıyor.',
        },
        {
            q: '34Pazar\'ı kim yapıyor?',
            a: '34Pazar, Almanya ve Türkiye\'de çalışan Shopify partner ajansı 34Devs ekibinden çıktı.',
        },
    );

    return items;
});
</script>

<template>
    <Head title="Pazaryeri entegrasyonu">
        <meta head-key="description" name="description" :content="metaDescription">
    </Head>

    <SiteLayout :is-logged-in="isLoggedIn">
        <!-- ═══════════════════════════════════════════ HERO -->
        <section aria-labelledby="hero-baslik" class="relative">
            <div class="mx-auto max-w-6xl px-5 pt-12 sm:px-6 lg:pt-16">
                <div class="grid grid-cols-1 gap-10 lg:grid-cols-12 lg:items-end">
                    <h1
                        id="hero-baslik"
                        class="text-[2.6rem] leading-[1.02] font-semibold tracking-[-0.03em] text-balance text-stone-900 sm:text-6xl lg:col-span-8 lg:text-[5.25rem]"
                    >
                        <!--
                            Span'lar TEK satırda ve aralarında açık boşlukla: Vue
                            derleyicisi satır sonlu boşlukları elemanlar arasından
                            siler, kelimeler bitişip mobilde yatay taşma yapıyordu.
                        -->
                        <template v-if="heroPair">
                            {{ locative[heroPair[0].code] }} sattın. <span class="text-stone-500">{{ locative[heroPair[1].code] }}ki stok</span> <span class="text-brand-600">kendiliğinden</span> <span class="text-stone-500">düştü.</span>
                        </template>
                        <template v-else>
                            Bir kanalda sattın. <span class="text-stone-500">Ötekilerde stok</span> <span class="text-brand-600">kendiliğinden</span> <span class="text-stone-500">düştü.</span>
                        </template>
                    </h1>

                    <div class="lg:col-span-4 lg:pb-3">
                        <p class="text-lg leading-relaxed text-stone-700">
                            34Pazar bütün mağazalarını tek panele bağlar. Stoğun tek yerde tutulur,
                            elinde olmayan ürünü hiçbir kanalda satmazsın.
                        </p>
                        <div class="mt-7 flex flex-wrap items-center gap-x-6 gap-y-4">
                            <Link
                                :href="primaryHref"
                                class="inline-flex items-center justify-center rounded-md bg-brand-600 px-5 py-3 text-base font-semibold text-white transition-colors hover:bg-brand-700"
                                :class="focusRing"
                            >
                                {{ primaryLabel }}
                            </Link>
                            <a
                                href="#fiyatlar"
                                class="rounded-sm text-base font-medium text-stone-900 underline decoration-stone-300 underline-offset-[6px] hover:decoration-brand-600"
                                :class="focusRing"
                            >
                                Fiyatlara bak
                            </a>
                        </div>
                        <p v-if="freePlan && !isLoggedIn" class="mt-4 text-sm text-stone-500">
                            Ücretsiz paketle, kart bilgisi girmeden.
                        </p>
                    </div>
                </div>

                <!--
                    Ekran görüntüsü alttaki koyu banda TAŞAR: sayfa "yazı +
                    görsel + yazı" diye üç kutuya bölünmesin, tek akış gibi
                    okunsun.
                -->
                <div class="relative z-10 mt-14 -mb-24 sm:-mb-40 lg:mt-20 lg:-mb-56">
                    <PanelShot
                        src="/images/site/panel-ana-sayfa.png"
                        alt="34Pazar paneli: Yapman gerekenler listesiyle ana sayfa"
                        eager
                    />
                </div>
            </div>
        </section>

        <!-- ═══════════════════════════════════════════ SORUN (koyu bant) -->
        <section aria-labelledby="sorun-baslik" class="bg-stone-900 text-white">
            <div class="mx-auto max-w-6xl px-5 pt-36 pb-20 sm:px-6 sm:pt-52 lg:pt-72 lg:pb-28">
                <div class="grid gap-12 lg:grid-cols-12">
                    <div class="lg:col-span-5">
                        <div class="h-0.5 w-10 bg-brand-600" aria-hidden="true" />
                        <h2 id="sorun-baslik" class="mt-6 text-3xl leading-[1.1] font-semibold tracking-tight text-balance sm:text-[2.75rem]">
                            Rafta bir tane vardı. İki kanalda birden satıldı.
                        </h2>
                        <p class="mt-6 text-lg leading-relaxed text-stone-300">
                            Birden fazla yerde satan herkes bunu yaşamıştır. Stoğu her mağazada elle
                            düzeltmeye yetişemezsin; sonunda müşteriye "ürün kalmamış" diye yazar,
                            iptal edersin. Hem müşteri gider hem mağaza puanın düşer.
                        </p>
                    </div>

                    <ol class="space-y-0 lg:col-span-6 lg:col-start-7">
                        <li class="grid grid-cols-[5.5rem_1fr] gap-4 border-t border-stone-700 py-5">
                            <span class="text-sm font-semibold tabular-nums text-stone-400">14:02</span>
                            <span class="text-base text-stone-100">Son ürün bir pazaryerinde satılıyor.</span>
                        </li>
                        <li class="grid grid-cols-[5.5rem_1fr] gap-4 border-t border-stone-700 py-5">
                            <span class="text-sm font-semibold tabular-nums text-stone-400">14:05</span>
                            <span class="text-base text-stone-100">Kendi siten hâlâ "stokta 1" gösteriyor. Orada da satılıyor.</span>
                        </li>
                        <li class="grid grid-cols-[5.5rem_1fr] gap-4 border-t border-stone-700 py-5">
                            <span class="text-sm font-semibold text-stone-400">Ertesi gün</span>
                            <span class="text-base text-stone-100">İki sipariş, bir ürün. Biri iptal, biri özür mesajı.</span>
                        </li>
                        <li class="grid grid-cols-[5.5rem_1fr] gap-4 border-t border-b border-stone-700 py-5">
                            <span class="text-sm font-semibold text-brand-400">34Pazar ile</span>
                            <span class="text-base font-medium text-white">14:02'deki satışla ürün her kanalda tükenir. İkinci sipariş hiç gelmez.</span>
                        </li>
                    </ol>
                </div>
            </div>
        </section>

        <!-- ═══════════════════════════════════════════ NASIL ÇALIŞIR -->
        <section id="nasil-calisir" aria-labelledby="nasil-baslik" class="scroll-mt-20">
            <div class="mx-auto max-w-6xl px-5 py-20 sm:px-6 lg:py-28">
                <h2 id="nasil-baslik" class="max-w-3xl text-3xl leading-[1.1] font-semibold tracking-tight text-stone-900 sm:text-5xl">
                    Kanalını bağla, ürünlerini aktar, tek panelden yönet.
                </h2>
                <ol class="mt-12 grid gap-x-10 gap-y-8 text-base md:grid-cols-3">
                    <li>
                        <p class="text-sm font-semibold text-stone-900">1 · Kanalını bağla</p>
                        <p class="mt-2 leading-relaxed text-stone-600">
                            Satış yaptığın mağazaları ekle. Kod yazman gerekmez; o kanaldaki satıcı
                            hesabına erişimin yeterli.
                        </p>
                    </li>
                    <li>
                        <p class="text-sm font-semibold text-stone-900">2 · Ürünlerini aktar</p>
                        <p class="mt-2 leading-relaxed text-stone-600">
                            Ürünlerini ve stok adetlerini tek yerde topla. Hangi ürün hangi kanalda
                            satılacak, sen seçersin.
                        </p>
                    </li>
                    <li>
                        <p class="text-sm font-semibold text-stone-900">3 · Tek panelden yönet</p>
                        <p class="mt-2 leading-relaxed text-stone-600">
                            Siparişler tek listeye düşer, stok her satışta bütün kanallarda birlikte
                            azalır. Sen paketlersin.
                        </p>
                    </li>
                </ol>
            </div>

            <!-- Senaryolar: yazı + gerçek ekran, sırayla sağa sola. -->
            <div class="mx-auto max-w-6xl space-y-24 px-5 pb-24 sm:px-6 lg:space-y-36 lg:pb-36">
                <article
                    v-for="(scene, index) in scenes"
                    :id="scene.id"
                    :key="scene.id"
                    class="grid grid-cols-1 items-center gap-10 lg:grid-cols-12 lg:gap-14"
                >
                    <div class="lg:col-span-4" :class="index % 2 === 1 ? 'lg:order-2 lg:col-start-9' : ''">
                        <p class="flex items-center gap-3 text-sm font-semibold text-stone-500">
                            <span class="h-px w-6 bg-brand-600" aria-hidden="true" />
                            {{ scene.kicker }}
                        </p>
                        <h3 class="mt-4 text-3xl leading-[1.1] font-semibold tracking-tight text-stone-900 sm:text-4xl">
                            {{ scene.title }}
                        </h3>
                        <p class="mt-5 text-lg leading-relaxed text-stone-600">{{ scene.body }}</p>
                        <ul class="mt-6 space-y-2 border-t border-stone-200 pt-5">
                            <li v-for="point in scene.points" :key="point" class="flex gap-3 text-base text-stone-800">
                                <span class="mt-2.5 h-px w-3 shrink-0 bg-stone-900" aria-hidden="true" />
                                {{ point }}
                            </li>
                        </ul>
                    </div>
                    <!--
                        Görsel geniş sütunda ve sayfa kenarına doğru biraz taşar:
                        ekran okunabilir büyüklükte kalsın, metin sütunu dar.
                        Taşma yalnız xl'de: 1024'te kenar boşluğu 24px ve
                        -mr-12 sayfayı yatayda kaydırırdı.
                    -->
                    <div
                        class="lg:col-span-8"
                        :class="index % 2 === 1 ? 'lg:order-1 lg:col-start-1 xl:-ml-12' : 'xl:-mr-12'"
                    >
                        <PanelShot :src="scene.shot.src" :alt="scene.shot.alt" :mobile-shift-y="scene.shot.shiftY ?? 0" />
                    </div>
                </article>
            </div>
        </section>

        <!-- ═══════════════════════════════════════════ DİĞERLERİ + MOBİL -->
        <section aria-labelledby="diger-baslik" class="border-t border-stone-200 bg-stone-50">
            <div class="mx-auto grid max-w-6xl gap-14 px-5 py-20 sm:px-6 lg:grid-cols-12 lg:py-28">
                <div class="lg:col-span-7">
                    <h2 id="diger-baslik" class="text-3xl leading-[1.1] font-semibold tracking-tight text-stone-900 sm:text-4xl">
                        Bir de şunlar.
                    </h2>
                    <dl class="mt-10 divide-y divide-stone-200 border-y border-stone-200">
                        <div v-for="item in extras" :key="item.title" class="grid gap-2 py-6 sm:grid-cols-[14rem_1fr] sm:gap-8">
                            <dt class="text-base font-semibold text-stone-900">{{ item.title }}</dt>
                            <dd class="leading-relaxed text-stone-600">{{ item.body }}</dd>
                        </div>
                        <div class="grid gap-2 py-6 sm:grid-cols-[14rem_1fr] sm:gap-8">
                            <dt class="text-base font-semibold text-stone-500">Muhasebe modülü</dt>
                            <dd class="text-stone-500">Yakında.</dd>
                        </div>
                    </dl>
                </div>
                <div class="mx-auto w-full max-w-[17rem] lg:col-span-4 lg:col-start-9 lg:mx-0 lg:justify-self-end">
                    <PanelShot
                        src="/images/site/panel-mobil.png"
                        alt="34Pazar paneli telefonda"
                        :width="390"
                        :height="844"
                        device="phone"
                    />
                </div>
            </div>
        </section>

        <!-- ═══════════════════════════════════════════ KANALLAR -->
        <!--
            Kanal LOGOSU kullanılmaz: elimizde lisanslı logo yok, taklit
            çizmek de dürüst değil. Adlar düz yazıyla, durum metinle.
            "Yakında" stone tonunda — marka rengi hata gibi okunurdu.
        -->
        <section id="kanallar" aria-labelledby="kanal-baslik" class="scroll-mt-20">
            <div class="mx-auto grid max-w-6xl gap-10 px-5 py-20 sm:px-6 lg:grid-cols-12 lg:py-28">
                <div class="lg:col-span-4">
                    <h2 id="kanal-baslik" class="text-3xl leading-[1.1] font-semibold tracking-tight text-stone-900 sm:text-4xl">
                        Bağlayabildiğin kanallar
                    </h2>
                    <p class="mt-4 text-lg leading-relaxed text-stone-600">
                        Bugün açık olanlar ve üzerinde çalıştıklarımız.
                    </p>
                </div>
                <ul v-if="channels.length" class="border-t border-stone-900 lg:col-span-7 lg:col-start-6">
                    <li
                        v-for="channel in channels"
                        :key="channel.code"
                        class="flex items-baseline justify-between gap-4 border-b border-stone-200 py-4 sm:py-5"
                    >
                        <span
                            class="text-2xl font-semibold tracking-tight sm:text-3xl"
                            :class="channel.available ? 'text-stone-900' : 'text-stone-500'"
                        >
                            {{ channel.name }}
                        </span>
                        <span class="shrink-0 text-sm" :class="channel.available ? 'font-medium text-stone-700' : 'text-stone-500'">
                            {{ channel.available ? 'Bağlanabilir' : 'Yakında' }}
                        </span>
                    </li>
                </ul>
            </div>
        </section>

        <!-- ═══════════════════════════════════════════ FİYATLAR -->
        <section id="fiyatlar" aria-labelledby="fiyat-baslik" class="scroll-mt-20 border-t border-stone-200">
            <div class="mx-auto max-w-6xl px-5 py-20 sm:px-6 lg:py-28">
                <div class="flex flex-wrap items-end justify-between gap-6">
                    <h2 id="fiyat-baslik" class="max-w-xl text-3xl leading-[1.1] font-semibold tracking-tight text-stone-900 sm:text-4xl">
                        Ürün ve kanal sayına göre paket. Büyüdükçe yükselt.
                    </h2>
                    <p class="text-sm text-stone-600">Fiyatlar aylıktır.</p>
                </div>

                <!--
                    Geniş ekranda karşılaştırma TABLOSU: paketler arasındaki
                    fark (ürün, kanal) yan yana satırda okunur. Dar ekranda
                    dört sütun sığmaz; aynı veri alt alta liste olur.
                -->
                <table v-if="plans.length" class="mt-12 hidden w-full table-fixed border-collapse text-left md:table">
                    <caption class="sr-only">Paketlerin aylık fiyatı, ürün ve kanal sınırı</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="w-[18%] pb-4 align-bottom text-sm font-medium text-stone-500">
                                <span class="sr-only">Özellik</span>
                            </th>
                            <th
                                v-for="plan in plans"
                                :key="plan.code"
                                scope="col"
                                class="border-t-2 px-4 pt-4 pb-4 align-top"
                                :class="planNotes[plan.code] ? 'border-brand-600 bg-stone-50' : 'border-stone-900'"
                            >
                                <span class="block text-lg font-semibold text-stone-900">{{ plan.name }}</span>
                                <span class="mt-1 block min-h-5 text-sm font-normal text-stone-600">{{ planNotes[plan.code] ?? '' }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="text-base">
                        <tr class="border-t border-stone-200">
                            <th scope="row" class="py-5 pr-4 text-sm font-medium text-stone-600">Aylık</th>
                            <td
                                v-for="plan in plans"
                                :key="plan.code"
                                class="px-4 py-5"
                                :class="planNotes[plan.code] ? 'bg-stone-50' : ''"
                            >
                                <span class="text-3xl font-semibold tracking-tight tabular-nums text-stone-900">{{ formatPrice(plan) }}</span>
                            </td>
                        </tr>
                        <tr class="border-t border-stone-200">
                            <th scope="row" class="py-4 pr-4 text-sm font-medium text-stone-600">Ürün</th>
                            <td
                                v-for="plan in plans"
                                :key="plan.code"
                                class="px-4 py-4 tabular-nums text-stone-900"
                                :class="planNotes[plan.code] ? 'bg-stone-50' : ''"
                            >
                                {{ formatCount(plan.productLimit) }}
                            </td>
                        </tr>
                        <tr class="border-t border-stone-200">
                            <th scope="row" class="py-4 pr-4 text-sm font-medium text-stone-600">Kanal</th>
                            <td
                                v-for="plan in plans"
                                :key="plan.code"
                                class="px-4 py-4 tabular-nums text-stone-900"
                                :class="planNotes[plan.code] ? 'bg-stone-50' : ''"
                            >
                                {{ formatCount(plan.channelLimit) }}
                            </td>
                        </tr>
                        <tr class="border-t border-b border-stone-200">
                            <td />
                            <td
                                v-for="plan in plans"
                                :key="plan.code"
                                class="px-4 py-5"
                                :class="planNotes[plan.code] ? 'bg-stone-50' : ''"
                            >
                                <Link
                                    :href="planHref"
                                    class="inline-flex rounded-md px-4 py-2.5 text-sm font-semibold transition-colors"
                                    :class="[
                                        focusRing,
                                        planNotes[plan.code]
                                            ? 'bg-brand-600 text-white hover:bg-brand-700'
                                            : 'border border-stone-300 text-stone-900 hover:border-stone-900',
                                    ]"
                                >
                                    <template v-if="isLoggedIn">Paketleri gör</template>
                                    <template v-else>{{ isFree(plan) ? 'Ücretsiz başla' : 'Hesap aç' }}</template>
                                    <span class="sr-only"> — {{ plan.name }} paketi</span>
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <ul v-if="plans.length" class="mt-10 border-t border-stone-900 md:hidden">
                    <li
                        v-for="plan in plans"
                        :key="plan.code"
                        class="border-b border-stone-200 py-6"
                        :class="planNotes[plan.code] ? 'border-l-2 border-l-brand-600 pl-4' : ''"
                    >
                        <div class="flex items-baseline justify-between gap-4">
                            <h3 class="text-lg font-semibold text-stone-900">{{ plan.name }}</h3>
                            <!-- Paket adı zaten "Ücretsiz" ise fiyatı ikinci kez yazmak tekrar olur. -->
                            <p v-if="formatPrice(plan) !== plan.name" class="text-2xl font-semibold tracking-tight tabular-nums text-stone-900">{{ formatPrice(plan) }}</p>
                        </div>
                        <p v-if="planNotes[plan.code]" class="mt-1 text-sm text-stone-600">{{ planNotes[plan.code] }}</p>
                        <p class="mt-2 text-sm text-stone-700">
                            {{ formatLimit(plan.productLimit, 'ürün') }} · {{ formatLimit(plan.channelLimit, 'kanal') }}
                        </p>
                        <Link
                            :href="planHref"
                            class="mt-4 inline-flex rounded-md px-4 py-2.5 text-sm font-semibold"
                            :class="[
                                focusRing,
                                planNotes[plan.code]
                                    ? 'bg-brand-600 text-white hover:bg-brand-700'
                                    : 'border border-stone-300 text-stone-900 hover:border-stone-900',
                            ]"
                        >
                            <template v-if="isLoggedIn">Paketleri gör</template>
                            <template v-else>{{ isFree(plan) ? 'Ücretsiz başla' : 'Hesap aç' }}</template>
                            <span class="sr-only"> — {{ plan.name }} paketi</span>
                        </Link>
                    </li>
                </ul>
            </div>
        </section>

        <!-- ═══════════════════════════════════════════ SSS -->
        <section id="sss" aria-labelledby="sss-baslik" class="scroll-mt-20 border-t border-stone-200 bg-stone-50">
            <div class="mx-auto grid max-w-6xl gap-10 px-5 py-20 sm:px-6 lg:grid-cols-12 lg:py-28">
                <h2 id="sss-baslik" class="text-3xl leading-[1.1] font-semibold tracking-tight text-stone-900 sm:text-4xl lg:col-span-4">
                    Sık sorulanlar
                </h2>
                <!--
                    <details> yerel açılır-kapanır: klavye ve ekran okuyucu
                    desteği tarayıcıdan gelir, JS gerekmez.
                -->
                <div class="border-t border-stone-900 lg:col-span-7 lg:col-start-6">
                    <details v-for="item in faqs" :key="item.q" class="group border-b border-stone-200">
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-4 rounded-sm py-5 text-left text-base font-semibold text-stone-900 sm:text-lg [&::-webkit-details-marker]:hidden"
                            :class="focusRing"
                        >
                            {{ item.q }}
                            <span class="shrink-0 text-xl leading-none font-normal text-stone-500 group-open:hidden" aria-hidden="true">+</span>
                            <span class="hidden shrink-0 text-xl leading-none font-normal text-stone-500 group-open:inline" aria-hidden="true">−</span>
                        </summary>
                        <p class="max-w-2xl pb-6 leading-relaxed text-stone-600">{{ item.a }}</p>
                    </details>
                </div>
            </div>
        </section>

        <!-- ═══════════════════════════════════════════ SON ÇAĞRI -->
        <section aria-labelledby="cagri-baslik" class="border-t border-stone-200">
            <div class="mx-auto max-w-6xl px-5 py-20 sm:px-6 lg:py-28">
                <div class="h-0.5 w-10 bg-brand-600" aria-hidden="true" />
                <h2
                    id="cagri-baslik"
                    class="mt-6 max-w-4xl text-4xl leading-[1.05] font-semibold tracking-[-0.03em] text-balance text-stone-900 sm:text-6xl"
                >
                    Bir sonraki satışta stok kendiliğinden düşsün.
                </h2>
                <div class="mt-10 flex flex-wrap items-center gap-x-6 gap-y-4">
                    <Link
                        :href="primaryHref"
                        class="inline-flex items-center justify-center rounded-md bg-brand-600 px-6 py-3.5 text-base font-semibold text-white transition-colors hover:bg-brand-700"
                        :class="focusRing"
                    >
                        {{ primaryLabel }}
                    </Link>
                    <p v-if="freePlan && !isLoggedIn" class="text-base text-stone-600">
                        Kart bilgisi gerekmez.
                    </p>
                </div>
            </div>
        </section>
    </SiteLayout>
</template>
