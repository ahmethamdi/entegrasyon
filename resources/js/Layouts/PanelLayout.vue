<script setup>
import BrandMark from '../Components/BrandMark.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { k, switchLocale, useI18n } from '../lib/i18n';

const page = usePage();
const { t, locale } = useI18n();

const tenantName = computed(() => page.props.tenant?.name ?? '');

/*
 * Sol sidebar gezinmesi (kullanıcı kararı, 21 Ağustos 2026).
 *
 * ÜST ŞERİTTEN SOL SIDEBAR'A GEÇİLDİ. Menü `/help` ile ON BİR öğeye
 * çıkmıştı ve yatay şeritte yer daralıyordu; sidebar dikeyde
 * sınırsızdır ve on ikinci öğe düzeni bozmaz.
 *
 * ÖĞELER ÜÇ GRUBA AYRILDI. On bir düz öğe tarama maliyetini yükseltir
 * (Hick yasası: karar süresi seçenek sayısıyla logaritmik artar);
 * gruplama sayacı grup başına sıfırlar.
 *
 * SIRA KULLANIM SIKLIĞINA GÖRE: satıcı siparişi ve stoğu günde defalarca
 * açar. 5 Ekim'den beri stok AYRI EKRAN DEĞİL, Ürünler listesinde
 * (kullanıcı kararı) — bu yüzden "Ürünler" günlük grupta, Siparişler'in
 * hemen altında.
 *
 * "Abonelik" ve "Yardım" gezinme değil HESAP öğeleridir; sidebar
 * altında, ayrı bir bölümde yaşarlar (yerleşik SaaS konvansiyonu).
 */
const navGroups = [
    {
        heading: null,
        items: [
            { href: '/panel', label: k('Ana sayfa') },
            { href: '/orders', label: k('Siparişler') },
            { href: '/products', label: k('Ürünler') },
        ],
    },
    {
        heading: k('Mağazam'),
        items: [
            { href: '/channels', label: k('Kanallar') },
            /*
             * Onaylar GÜNLÜK işe yakındır (Trendyol reddettiği ürünün
             * sebebini burada gösteriyoruz), bu yüzden "Gelişmiş"e
             * gömülmedi. Hata grubuna da konmaz: onay kanalın NORMAL
             * süreci, arıza değil.
             */
            { href: '/approvals', label: k('Kanal onayları') },
        ],
    },
    {
        /*
         * MODÜLLER — 34Pazar'ın büyüyeceği yer. Muhasebe henüz yok;
         * "Yakında" olarak görünür ama tıklanmaz. Hiç gösterilmeseydi
         * satıcı ürünün nereye gittiğini bilemez; tıklanabilir olsaydı
         * boş bir sayfaya düşerdi.
         */
        heading: k('Modüller'),
        items: [
            /*
             * Kampanyalar — süreli indirim (9 Ekim). Kalıcı kanal farkı
             * Kanallar → "Fiyat kuralı"ndadır.
             */
            { href: '/campaigns', label: k('Kampanyalar') },
            { href: null, label: k('Muhasebe'), soon: true },
        ],
    },
    {
        /*
         * GELİŞMİŞ — KATLANIR, varsayılan KAPALI. Bu ekranlar sistemin
         * iç işleyişini gösterir (mutabakat, ölü gönderimler, metrikler)
         * ve satıcının günlük işi değildir; açık dursalardı menünün
         * yarısını teknik terimler kaplardı. İçlerinde YAPILMASI gereken
         * bir şey çıkarsa ana sayfadaki "Yapman gerekenler" oraya
         * bağlantı verir — satıcı menüyü karıştırmadan ulaşır.
         */
        heading: k('Gelişmiş'),
        collapsible: true,
        items: [
            { href: '/mappings', label: k('Kategori eşleştirme') },
            { href: '/reconciliation', label: k('Fiyat ve stok kontrolü') },
            { href: '/failures', label: k('Gönderilemeyenler') },
            { href: '/metrics', label: k('Sistem durumu') },
        ],
    },
];

/* Hesap öğeleri — gezinme listesinin dışında, sidebar altında. */
const accountNav = computed(() => [
    /*
     * Yönetim yalnız süper admin'e görünür. Bu SADECE görünüm: yetki
     * rotadaki `can:superAdmin`'dedir, bağlantıyı elle yazan 403 alır.
     */
    ...(page.props.auth?.user?.isSuperAdmin ? [{ href: '/admin', label: k('Yönetim') }] : []),
    { href: '/billing', label: k('Abonelik') },
    { href: '/help', label: k('Yardım') },
]);

/* Dil seçenekleri — her biri KENDİ dilinde yazılır. */
const languages = [
    { code: 'tr', label: 'TR · Türkçe' },
    { code: 'en', label: 'EN · English' },
];

/* Telefon alt menüsü — sıklık sırası; ikonlar 20×20 çizgi. */
const tabItems = [
    { href: '/panel', label: k('Ana sayfa'), icon: 'M3 9l7-6 7 6v8H3z M8 17v-5h4v5' },
    { href: '/orders', label: k('Siparişler'), icon: 'M4 4h12l-1 12H5z M7 4a3 3 0 016 0' },
    { href: '/products', label: k('Ürünler'), icon: 'M3 6l7-3 7 3-7 3z M3 6v8l7 3 7-3V6 M10 9v8' },
    { href: '/channels', label: k('Kanallar'), icon: 'M8 12l4-4 M6.5 9.5l-2 2a3 3 0 004 4l2-2 M13.5 10.5l2-2a3 3 0 00-4-4l-2 2' },
];

const currentPath = computed(() => page.url.split('?')[0]);

/*
 * Gelişmiş grubu, içindeki bir ekrandaysan AÇIK başlar: kapalı kalsaydı
 * satıcı bulunduğu sayfanın menüde nerede olduğunu göremezdi.
 */
const advancedOpen = ref(false);

watch(currentPath, (path) => {
    if (navGroups.some((g) => g.collapsible && g.items.some((i) => i.href && path.startsWith(i.href)))) {
        advancedOpen.value = true;
    }
}, { immediate: true });

/*
 * Mobil çekmece (§13 · Faz 4 · panel cilası — sidebar turunda korundu).
 *
 * Dar ekranda sidebar ekranın dışına kayar ve düğmeyle çekmece olarak
 * açılır. Cila öncesi üst şerit 390px görünüm alanında 1001px
 * genişliyordu ve YEDİ ekran ile ÇIKIŞ düğmesi erişilemezdi.
 *
 * SIDEBAR TEK KEZ RENDER EDİLİR. Masaüstü ve mobil için iki ayrı liste
 * yazılsaydı on bir öğe iki yerde yaşar ve zamanla AYRIŞIRDI.
 *
 * MENÜ GEZİNMEDE KAPANIR. Kapanmasaydı seçilen bağlantı yeni ekranı
 * açar ama çekmece açık kalıp içeriği örterdi.
 */
const mobileMenuOpen = ref(false);

watch(currentPath, () => {
    mobileMenuOpen.value = false;
});

/*
 * Çekmece açıkken arkadaki sayfa KAYDIRILMAZ. Kaydırılsaydı kullanıcı
 * çekmeceyi kaydırdığını sanır, arkadaki içerik kayar ve ekran bozuk
 * görünürdü.
 */
watch(mobileMenuOpen, (open) => {
    document.body.classList.toggle('overflow-hidden', open);
});

/* ESC ile kapanır — örtü katmanlarının en yerleşik beklentisi. */
function onKeydown(event) {
    if (event.key === 'Escape' && mobileMenuOpen.value) {
        mobileMenuOpen.value = false;
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    document.body.classList.remove('overflow-hidden');
});

/*
 * Onboarding şeridi (§13 · Faz 4) — dokümanın dört adımı.
 *
 * İlerleme VERİDEN türetilir ve `HandleInertiaRequests` ile her panel
 * ekranında paylaşılır; şerit bu yüzden layout'ta yaşar, tek bir ekranda
 * değil. Kullanıcı kurulumun ortasında herhangi bir ekrana gidebilir.
 *
 * DÖRT ADIM BİTİNCE ŞERİT KAYBOLUR — kullanıcı kararı. Kapatma butonu
 * YOKTUR: tercih saklansaydı ilerlemenin İKİ gerçek kaynağı olurdu
 * (veri + kapatma tercihi) ve türetilmiş durum kararı bozulurdu.
 */
const onboarding = computed(() => page.props.onboarding ?? null);

const showOnboarding = computed(() => onboarding.value?.visible === true);

const onboardingSteps = [
    {
        key: 'account',
        label: k('Hesap oluştur'),
        done: k('Hesabın hazır.'),
        todo: k('Hesabını oluştur.'),
        href: null,
        action: null,
    },
    {
        key: 'channel',
        label: k('Kanal bağla'),
        done: k('Kanalın bağlı ve çalışıyor.'),
        todo: k('Mağazanı bağla. Bağlantı denenip çalıştığı görülünce kanal açılır.'),
        href: '/channels/create',
        action: k('Kanal bağla'),
    },
    {
        key: 'product',
        label: k('Ürün aktar'),
        done: k('Ürünlerin sistemde.'),
        todo: k('Ürünlerini ekle ya da mağazandan / CSV dosyasından çek.'),
        href: '/products/import',
        action: k('Ürün aktar'),
    },
    {
        key: 'sync',
        label: k('İlk gönderim'),
        done: k('İlk ürünün kanala ulaştı.'),
        todo: k('Bir ürünü kanala gönder. Kanala ulaşınca kurulum biter.'),
        href: '/products',
        action: k('Ürüne git'),
    },
];

/* Adım durumu + "sıradaki" işareti tek yerde birleşir. */
const steps = computed(() =>
    onboardingSteps.map((step) => ({
        ...step,
        isDone: onboarding.value?.steps?.[step.key] === true,
        isNext: onboarding.value?.next === step.key,
    })),
);

const doneCount = computed(() => steps.value.filter((s) => s.isDone).length);

/* Sıradaki adım — şeridin çağrı düğmesi ondan gelir. */
const nextStep = computed(() => steps.value.find((s) => s.isNext) ?? null);

function isActive(href) {
    return href === '/panel' ? currentPath.value === '/panel' : currentPath.value.startsWith(href);
}

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-screen bg-stone-50">
        <!--
            İçeriğe atlama bağlantısı. Olmasaydı klavye kullanıcısı HER
            sayfa yüklemesinde on üç gezinme bağlantısını tek tek geçmek
            zorunda kalırdı.
        -->
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-60 focus:rounded focus:bg-stone-900 focus:px-3 focus:py-2 focus:text-sm focus:text-white"
        >
            {{ t('İçeriğe geç') }}
        </a>

        <!--
            Dar ekranda çekmecenin arkasını karartan örtü. Tıklanınca
            kapanır; `lg` ve üstünde sidebar sabit olduğu için hiç yoktur.
        -->
        <div
            v-if="mobileMenuOpen"
            class="fixed inset-0 z-40 bg-stone-900/40 lg:hidden"
            aria-hidden="true"
            @click="mobileMenuOpen = false"
        />

        <!--
            SIDEBAR TEK KEZ RENDER EDİLİR — masaüstünde sabit, dar ekranda
            çekmece. İki ayrı liste yazılsaydı on bir öğe iki yerde yaşar
            ve zamanla ayrışırdı.
        -->
        <aside
            id="panel-sidebar"
            class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-stone-200 bg-white transition-transform duration-200 ease-out lg:z-40 lg:translate-x-0"
            :class="mobileMenuOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex items-start justify-between gap-2 border-b border-stone-200 px-5 py-4">
                <div class="min-w-0">
                    <p>
                        <BrandMark size="md" />
                    </p>
                    <p class="mt-0.5 truncate text-xs font-medium text-stone-500">
                        {{ tenantName }}
                    </p>
                </div>

                <!--
                    Çekmeceyi kapatan düğme — yalnızca dar ekranda. Örtüye
                    dokunmak tek yol olsaydı klavye kullanıcısı çekmeceyi
                    kapatamazdı.
                -->
                <button
                    type="button"
                    class="-mr-1 shrink-0 rounded p-1 text-stone-500 transition hover:bg-stone-100 hover:text-stone-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring lg:hidden"
                    :aria-label="t('Menüyü kapat')"
                    @click="mobileMenuOpen = false"
                >
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path d="M5 5l10 10M15 5L5 15" stroke-linecap="round" />
                    </svg>
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto px-2 py-2" :aria-label="t('Ana menü')">
                <div v-for="(group, groupIndex) in navGroups" :key="group.heading ?? 'ana'">
                    <button
                        v-if="group.collapsible"
                        type="button"
                        class="mt-3 flex w-full items-center justify-between border-t border-stone-200 px-3 pb-1.5 pt-4 text-xs font-medium text-stone-500 transition hover:text-stone-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        :aria-expanded="advancedOpen"
                        @click="advancedOpen = !advancedOpen"
                    >
                        {{ t(group.heading) }}
                        <span class="transition" :class="advancedOpen ? 'rotate-90' : ''" aria-hidden="true">›</span>
                    </button>
                    <p
                        v-else-if="group.heading"
                        class="px-3 pb-1.5 text-xs font-medium text-stone-500"
                        :class="groupIndex === 0 ? 'pt-2' : 'mt-3 border-t border-stone-200 pt-4'"
                    >
                        {{ t(group.heading) }}
                    </p>

                    <ul v-show="!group.collapsible || advancedOpen" :class="groupIndex === 0 ? 'pt-1' : ''">
                        <li v-for="item in group.items" :key="item.href ?? item.label">
                            <span
                                v-if="item.soon"
                                class="flex items-center justify-between rounded px-3 py-2 text-sm text-stone-400"
                                aria-disabled="true"
                            >
                                {{ t(item.label) }}
                                <span class="rounded-full border border-stone-200 px-2 py-0.5 text-[11px] text-stone-500">{{ t('Yakında') }}</span>
                            </span>
                            <!--
                                AKTİF İŞARET 3px'LİK SOL ÇUBUKTUR, DOLGU
                                DEĞİL. Marka turuncusu dolgu olarak
                                kullanılsaydı ~200×36px'lik turuncu bir
                                yüzey olur ve panelde ZATEN uyarı rengi
                                olan amber ile karışırdı (bekleyen
                                rozetler, eşleşmemiş SKU satırları,
                                onboarding şeridi). 3px'lik bir çubuk
                                KONUM işaretidir, durum tonu değil.

                                Üç sinyal var ve yalnızca biri renkli:
                                çubuk (konum) · `font-medium` (ağırlık) ·
                                `bg-stone-100` (zemin). Renk körlüğünde
                                turuncu sarımsıya kayar; diğer iki sinyal
                                renksiz de okunur.
                            -->
                            <Link
                                v-else
                                :href="item.href"
                                :aria-current="isActive(item.href) ? 'page' : undefined"
                                class="relative flex items-center rounded px-3 py-2 text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                :class="isActive(item.href)
                                    ? 'bg-stone-100 font-medium text-stone-900 before:absolute before:bottom-1.5 before:left-0 before:top-1.5 before:w-[3px] before:rounded-full before:bg-brand-600'
                                    : 'text-stone-600 hover:bg-stone-100 hover:text-stone-900'"
                            >
                                {{ t(item.label) }}
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Hesap öğeleri ve çıkış — gezinme listesinin DIŞINDA. -->
            <div class="border-t border-stone-200 p-2">
                <ul>
                    <li v-for="item in accountNav" :key="item.href">
                        <Link
                            :href="item.href"
                            :aria-current="isActive(item.href) ? 'page' : undefined"
                            class="relative flex items-center rounded px-3 py-2 text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            :class="isActive(item.href)
                                ? 'bg-stone-100 font-medium text-stone-900 before:absolute before:bottom-1.5 before:left-0 before:top-1.5 before:w-[3px] before:rounded-full before:bg-brand-600'
                                : 'text-stone-600 hover:bg-stone-100 hover:text-stone-900'"
                        >
                            {{ t(item.label) }}
                        </Link>
                    </li>
                </ul>

                <!--
                    DİL ANAHTARI — iki seçenek, ikisi de görünür. Açılır liste
                    olsaydı İngilizce bilmeyen satıcı "Language"i, Türkçe
                    bilmeyen inceleyici "Dil"i aramak zorunda kalırdı.
                -->
                <div v-if="page.props.localeSwitch" class="mt-1 flex items-center gap-1 px-3 py-1.5" role="group" :aria-label="t('Panel dili')">
                    <button
                        v-for="option in languages"
                        :key="option.code"
                        type="button"
                        class="rounded px-2 py-0.5 text-xs font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        :class="locale() === option.code ? 'bg-stone-900 text-white' : 'text-stone-500 hover:bg-stone-100 hover:text-stone-900'"
                        :aria-pressed="locale() === option.code"
                        :lang="option.code"
                        @click="locale() !== option.code && switchLocale(option.code)"
                    >
                        {{ option.label }}
                    </button>
                </div>

                <!--
                    Çıkış gezinme listesine KONMAZ: sık tıklanan on bir
                    hedefin arasında duran bir oturum kapatma düğmesi
                    yanlış tıklamayı davet eder. Sidebar'ın en sessiz
                    öğesidir.
                -->
                <button
                    type="button"
                    class="mt-1 w-full rounded px-3 py-2 text-left text-sm text-stone-500 transition hover:bg-stone-100 hover:text-stone-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    @click="logout"
                >
                    {{ t('Çıkış') }}
                </button>
            </div>
        </aside>

        <div class="lg:pl-64">
            <!-- Dar ekranda çekmeceyi açan başlık. `lg` ve üstünde YOKTUR. -->
            <header
                class="sticky top-0 z-30 flex items-center gap-3 border-b border-stone-200 bg-white px-4 py-3 lg:hidden"
            >
                <button
                    type="button"
                    class="rounded-md border border-stone-300 p-2 text-stone-700 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    aria-controls="panel-sidebar"
                    :aria-expanded="mobileMenuOpen"
                    :aria-label="t('Menüyü aç')"
                    @click="mobileMenuOpen = true"
                >
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path d="M3 5h14M3 10h14M3 15h14" stroke-linecap="round" />
                    </svg>
                </button>

                <div class="min-w-0">
                    <p>
                        <BrandMark size="sm" />
                    </p>
                    <p class="truncate text-xs text-stone-500">{{ tenantName }}</p>
                </div>
            </header>

            <!--
                Onboarding şeridi — dört adım bitince KAYBOLUR.
                Kapatma butonu yoktur: ilerleme veriden türer ve saklanan bir
                tercih ikinci bir gerçek kaynağı olurdu.

                SIDEBAR'A TAŞINMAZ: 256px'lik bir rayda dört adımın açıklama
                metinleri kırpılırdı ve kalıcı bir yan panel öğesi "krom"
                sayılıp GÖRMEZDEN gelinirdi. Şerit içerik sütununun en
                üstünde, okuma akışının ilk yatay taramasında durur.
            -->
            <section
                v-if="showOnboarding"
                class="border-b border-amber-200 bg-amber-50"
                :aria-label="t('Kurulum adımları')"
            >
                <div class="mx-auto max-w-5xl px-6 py-5 lg:px-8">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-sm font-semibold text-amber-900">
                            {{ t('Kurulumu tamamla') }}
                        </h2>
                        <p class="text-sm tabular-nums text-amber-800">
                            {{ t(':done/:total adım', { done: doneCount, total: steps.length }) }}
                        </p>
                    </div>

                    <ol class="mt-4 grid gap-px overflow-hidden rounded-lg border border-amber-200 bg-amber-200 sm:grid-cols-4">
                        <li
                            v-for="(step, index) in steps"
                            :key="step.key"
                            class="bg-white p-3"
                            :class="step.isNext ? 'ring-1 ring-inset ring-amber-500' : ''"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full font-mono text-[10px] tabular-nums"
                                    :class="step.isDone
                                        ? 'bg-emerald-600 text-white'
                                        : step.isNext
                                            ? 'bg-amber-500 text-white'
                                            : 'bg-stone-200 text-stone-600'"
                                >
                                    <span v-if="step.isDone" aria-hidden="true">✓</span>
                                    <span v-else>{{ index + 1 }}</span>
                                </span>

                                <p
                                    class="text-sm font-medium"
                                    :class="step.isDone ? 'text-stone-500' : 'text-stone-900'"
                                >
                                    {{ t(step.label) }}
                                </p>
                            </div>

                            <p class="mt-1.5 text-xs leading-relaxed text-stone-600">
                                {{ t(step.isDone ? step.done : step.todo) }}
                            </p>
                        </li>
                    </ol>

                    <!--
                        Tek çağrı düğmesi: SIRADAKİ adım. Dört düğme birden
                        göstermek kullanıcıya hangisinden başlayacağını
                        sordurur; onboarding'in işi tam olarak bunu söylemektir.
                    -->
                    <div v-if="nextStep?.href" class="mt-4">
                        <Link
                            :href="nextStep.href"
                            class="inline-block rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-amber-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-700"
                        >
                            {{ t(nextStep.action) }} →
                        </Link>
                    </div>
                </div>
            </section>

            <!-- Alt menü yüksekliği kadar boşluk: son satır menünün altında kalmasın. -->
            <main id="main" tabindex="-1" class="mx-auto max-w-5xl px-6 pb-28 pt-10 lg:px-8 lg:pb-10">
                <slot />
            </main>

            <!--
                TELEFON ALT MENÜSÜ — en sık dört ekran başparmak altında.
                Çekmece tek yol olsaydı siparişten stoğa geçmek iki dokunuş
                ve bir kaydırma isterdi. "Menü" çekmeceyi açar; seyrek
                ekranlar (Gelişmiş, Abonelik) orada kalır.
            -->
            <nav
                class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 border-t border-stone-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden"
                :aria-label="t('Hızlı menü')"
            >
                <Link
                    v-for="item in tabItems"
                    :key="item.href"
                    :href="item.href"
                    :aria-current="isActive(item.href) ? 'page' : undefined"
                    class="flex flex-col items-center gap-1 py-2 text-[11px] transition focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                    :class="isActive(item.href) ? 'font-semibold text-brand-700' : 'text-stone-500'"
                >
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path :d="item.icon" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    {{ t(item.label) }}
                </Link>
                <button
                    type="button"
                    class="flex flex-col items-center gap-1 py-2 text-[11px] text-stone-500 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                    aria-controls="panel-sidebar"
                    :aria-expanded="mobileMenuOpen"
                    @click="mobileMenuOpen = true"
                >
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path d="M3 5h14M3 10h14M3 15h14" stroke-linecap="round" />
                    </svg>
                    {{ t('Menü') }}
                </button>
            </nav>
        </div>
    </div>
</template>
