<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();

const tenantName = computed(() => page.props.tenant?.name ?? '');

const nav = [
    { href: '/', label: 'Özet' },
    { href: '/products', label: 'Ürünler' },
    { href: '/orders', label: 'Siparişler' },
    { href: '/inventory', label: 'Stok' },
    { href: '/reconciliation', label: 'Mutabakat' },
    { href: '/failures', label: 'Hatalar' },
    { href: '/metrics', label: 'Sağlık' },
    { href: '/channels', label: 'Kanallar' },
    { href: '/mappings', label: 'Eşleştirme' },
    { href: '/billing', label: 'Abonelik' },
];

const currentPath = computed(() => page.url.split('?')[0]);

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
        <header class="border-b border-stone-200 bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <div>
                    <p class="font-mono text-base uppercase tracking-widest text-stone-900">
                        Entegrasyon
                    </p>
                    <p class="text-sm font-medium text-stone-600">{{ tenantName }}</p>
                </div>

                <div class="flex items-center gap-6">
                    <nav class="flex items-center gap-1">
                        <Link
                            v-for="item in nav"
                            :key="item.href"
                            :href="item.href"
                            class="rounded px-3 py-1.5 text-sm transition"
                            :class="isActive(item.href)
                                ? 'bg-stone-900 text-white'
                                : 'text-stone-700 hover:bg-stone-100'"
                        >
                            {{ item.label }}
                        </Link>
                    </nav>

                    <button
                        type="button"
                        class="rounded border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100"
                        @click="logout"
                    >
                        Çıkış
                    </button>
                </div>
            </div>
        </header>

        <!--
            Onboarding şeridi — dört adım bitince KAYBOLUR.
            Kapatma butonu yoktur: ilerleme veriden türer ve saklanan bir
            tercih ikinci bir gerçek kaynağı olurdu.
        -->
        <section
            v-if="showOnboarding"
            class="border-b border-amber-200 bg-amber-50"
            aria-label="Kurulum adımları"
        >
            <div class="mx-auto max-w-6xl px-6 py-5">
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
                        class="inline-block rounded bg-amber-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-amber-700"
                    >
                        {{ nextStep.action }} →
                    </Link>
                </div>
            </div>
        </section>

        <main class="mx-auto max-w-6xl px-6 py-10">
            <slot />
        </main>
    </div>
</template>
