<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const page = usePage();

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
 * açar, kataloğu haftada bir düzenler. Bu yüzden "Siparişler" ve "Stok"
 * "Ürünler"in ÜSTÜNDEDİR — alfabetik ya da CRUD sırası değil.
 *
 * "Abonelik" ve "Yardım" gezinme değil HESAP öğeleridir; sidebar
 * altında, ayrı bir bölümde yaşarlar (yerleşik SaaS konvansiyonu).
 */
const navGroups = [
    {
        heading: 'İşleyiş',
        items: [
            { href: '/', label: 'Özet' },
            { href: '/orders', label: 'Siparişler' },
            { href: '/inventory', label: 'Stok' },
        ],
    },
    {
        heading: 'Katalog',
        items: [
            { href: '/products', label: 'Ürünler' },
            { href: '/channels', label: 'Kanallar' },
            { href: '/mappings', label: 'Eşleştirme' },
        ],
    },
    {
        heading: 'İzleme',
        items: [
            { href: '/reconciliation', label: 'Mutabakat' },
            { href: '/failures', label: 'Hatalar' },
            { href: '/metrics', label: 'Sağlık' },
        ],
    },
];

/* Hesap öğeleri — gezinme listesinin dışında, sidebar altında. */
const accountNav = [
    { href: '/billing', label: 'Abonelik' },
    { href: '/help', label: 'Yardım' },
];

const currentPath = computed(() => page.url.split('?')[0]);

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
        label: 'Hesap oluştur',
        done: 'Hesabın hazır.',
        todo: 'Hesabını oluştur.',
        href: null,
        action: null,
    },
    {
        key: 'channel',
        label: 'Kanal bağla',
        done: 'Kanalın bağlı ve sağlıklı.',
        todo: 'Mağazanı bağla — sağlık kontrolü geçmeden kanal aktif olmaz.',
        href: '/channels/create',
        action: 'Kanal bağla',
    },
    {
        key: 'product',
        label: 'Ürün aktar',
        done: 'Ürünlerin sistemde.',
        todo: 'Ürünlerini ekle ya da CSV/kanaldan içe aktar.',
        href: '/products/import',
        action: 'Ürün aktar',
    },
    {
        key: 'sync',
        label: 'İlk senkron',
        done: 'İlk senkronun tamamlandı.',
        todo: 'Bir ürünü kanala gönder — senkron tamamlanınca kurulum biter.',
        href: '/products',
        action: 'Ürüne git',
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
    return href === '/' ? currentPath.value === '/' : currentPath.value.startsWith(href);
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
            İçeriğe geç
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
                    <p class="font-mono text-sm uppercase tracking-widest text-stone-900">
                        Entegrasyon
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
                    class="-mr-1 shrink-0 rounded p-1 text-stone-500 transition hover:bg-stone-100 hover:text-stone-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 lg:hidden"
                    aria-label="Menüyü kapat"
                    @click="mobileMenuOpen = false"
                >
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path d="M5 5l10 10M15 5L5 15" stroke-linecap="round" />
                    </svg>
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto px-2 py-2" aria-label="Ana menü">
                <div v-for="(group, groupIndex) in navGroups" :key="group.heading">
                    <p
                        class="px-3 pb-1.5 font-mono text-[10px] uppercase tracking-widest text-stone-400"
                        :class="groupIndex === 0 ? 'pt-2' : 'mt-3 border-t border-stone-200 pt-4'"
                    >
                        {{ group.heading }}
                    </p>

                    <ul>
                        <li v-for="item in group.items" :key="item.href">
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
                                :href="item.href"
                                :aria-current="isActive(item.href) ? 'page' : undefined"
                                class="relative flex items-center rounded px-3 py-2 text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                :class="isActive(item.href)
                                    ? 'bg-stone-100 font-medium text-stone-900 before:absolute before:bottom-1.5 before:left-0 before:top-1.5 before:w-[3px] before:rounded-full before:bg-brand-600'
                                    : 'text-stone-600 hover:bg-stone-100 hover:text-stone-900'"
                            >
                                {{ item.label }}
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
                            class="relative flex items-center rounded px-3 py-2 text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                            :class="isActive(item.href)
                                ? 'bg-stone-100 font-medium text-stone-900 before:absolute before:bottom-1.5 before:left-0 before:top-1.5 before:w-[3px] before:rounded-full before:bg-brand-600'
                                : 'text-stone-600 hover:bg-stone-100 hover:text-stone-900'"
                        >
                            {{ item.label }}
                        </Link>
                    </li>
                </ul>

                <!--
                    Çıkış gezinme listesine KONMAZ: sık tıklanan on bir
                    hedefin arasında duran bir oturum kapatma düğmesi
                    yanlış tıklamayı davet eder. Sidebar'ın en sessiz
                    öğesidir.
                -->
                <button
                    type="button"
                    class="mt-1 w-full rounded px-3 py-2 text-left text-sm text-stone-500 transition hover:bg-stone-100 hover:text-stone-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    @click="logout"
                >
                    Çıkış
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
                    class="rounded border border-stone-300 p-2 text-stone-700 transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    aria-controls="panel-sidebar"
                    :aria-expanded="mobileMenuOpen"
                    aria-label="Menüyü aç"
                    @click="mobileMenuOpen = true"
                >
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path d="M3 5h14M3 10h14M3 15h14" stroke-linecap="round" />
                    </svg>
                </button>

                <div class="min-w-0">
                    <p class="font-mono text-xs uppercase tracking-widest text-stone-900">
                        Entegrasyon
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
                aria-label="Kurulum adımları"
            >
                <div class="mx-auto max-w-5xl px-6 py-5 lg:px-8">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-sm font-semibold text-amber-900">
                            Kurulumu tamamla
                        </h2>
                        <p class="font-mono text-xs tabular-nums text-amber-700">
                            {{ doneCount }}/{{ steps.length }} adım
                        </p>
                    </div>

                    <ol class="mt-4 grid gap-px overflow-hidden rounded border border-amber-200 bg-amber-200 sm:grid-cols-4">
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
                                    {{ step.label }}
                                </p>
                            </div>

                            <p class="mt-1.5 text-xs leading-relaxed text-stone-600">
                                {{ step.isDone ? step.done : step.todo }}
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
                            class="inline-block rounded bg-amber-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-amber-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-700"
                        >
                            {{ nextStep.action }} →
                        </Link>
                    </div>
                </div>
            </section>

            <main id="main" tabindex="-1" class="mx-auto max-w-5xl px-6 py-10 lg:px-8">
                <slot />
            </main>
        </div>
    </div>
</template>
