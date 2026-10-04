<script setup>
import BrandMark from '../Components/BrandMark.vue';
import { Link } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Tanıtım sitesi iskeleti — başlık, mobil menü, alt bilgi.
 *
 * PanelLayout'tan AYRI tutulur: ziyaretçinin kiracısı yoktur ve panelin
 * sidebar'ı, kiracı adı, oturum düğmeleri burada anlamsızdır. İki düzen
 * tek dosyada `v-if` ile ayrılsaydı panel değişikliği siteyi bozardı.
 */
defineProps({
    isLoggedIn: { type: Boolean, default: false },
});

/*
 * Gezinme ÇAPA bağlantılarıdır (#...), Inertia Link DEĞİL: hepsi aynı
 * sayfadaki bölümlere gider ve sunucuya istek atmak gereksizdir.
 */
const navItems = [
    { href: '#nasil-calisir', label: 'Nasıl çalışır' },
    { href: '#kanallar', label: 'Kanallar' },
    { href: '#fiyatlar', label: 'Fiyatlar' },
    { href: '#sss', label: 'SSS' },
];

const menuOpen = ref(false);

function closeMenu() {
    menuOpen.value = false;
}

// Escape menüyü kapatır: klavye kullanıcısı menüde sıkışıp kalmasın.
function onKeydown(event) {
    if (event.key === 'Escape' && menuOpen.value) {
        closeMenu();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

const year = new Date().getFullYear();

// Odak halkası tek yerde: her bağlantıda aynı sınıf dizisi tekrar etmesin.
const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring';
</script>

<template>
    <div class="flex min-h-screen flex-col bg-white text-stone-900">
        <a
            href="#icerik"
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:shadow"
            :class="focusRing"
        >
            İçeriğe geç
        </a>

        <header class="sticky top-0 z-40 border-b border-stone-200/80 bg-white/90 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-5 sm:px-6">
                <a href="/" class="shrink-0 rounded-sm" :class="focusRing">
                    <BrandMark size="lg" />
                </a>

                <nav aria-label="Ana gezinme" class="hidden md:block">
                    <ul class="flex items-center gap-1">
                        <li v-for="item in navItems" :key="item.href">
                            <a
                                :href="item.href"
                                class="rounded-md px-3 py-2 text-sm font-medium text-stone-600 transition-colors hover:text-stone-900"
                                :class="focusRing"
                            >
                                {{ item.label }}
                            </a>
                        </li>
                    </ul>
                </nav>

                <div class="hidden items-center gap-2 md:flex">
                    <template v-if="isLoggedIn">
                        <Link
                            href="/panel"
                            class="rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-700"
                            :class="focusRing"
                        >
                            Panele git
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            href="/login"
                            class="rounded-md px-3 py-2 text-sm font-medium text-stone-700 transition-colors hover:text-stone-900"
                            :class="focusRing"
                        >
                            Giriş yap
                        </Link>
                        <Link
                            href="/register"
                            class="rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-700"
                            :class="focusRing"
                        >
                            Ücretsiz başla
                        </Link>
                    </template>
                </div>

                <button
                    type="button"
                    class="inline-flex size-10 items-center justify-center rounded-md text-stone-700 hover:bg-stone-100 md:hidden"
                    :class="focusRing"
                    :aria-expanded="menuOpen ? 'true' : 'false'"
                    aria-controls="site-mobil-menu"
                    @click="menuOpen = !menuOpen"
                >
                    <span class="sr-only">{{ menuOpen ? 'Menüyü kapat' : 'Menüyü aç' }}</span>
                    <svg v-if="!menuOpen" class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                    </svg>
                    <svg v-else class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
            </div>

            <!--
                Mobil menü başlığın İÇİNDE açılır (üstte kayan çekmece değil):
                yapışkan başlıkla birlikte kalır ve sayfayı yatayda taşırmaz.
            -->
            <div
                v-show="menuOpen"
                id="site-mobil-menu"
                class="border-t border-stone-200 bg-white md:hidden"
            >
                <nav aria-label="Mobil gezinme" class="mx-auto max-w-6xl px-5 py-3">
                    <ul class="flex flex-col">
                        <li v-for="item in navItems" :key="item.href">
                            <a
                                :href="item.href"
                                class="block rounded-md px-2 py-3 text-base font-medium text-stone-800 hover:bg-stone-50"
                                :class="focusRing"
                                @click="closeMenu"
                            >
                                {{ item.label }}
                            </a>
                        </li>
                    </ul>
                    <div class="mt-3 flex flex-col gap-2 border-t border-stone-200 pt-4 pb-2">
                        <template v-if="isLoggedIn">
                            <Link
                                href="/panel"
                                class="rounded-md bg-brand-600 px-4 py-3 text-center text-base font-semibold text-white hover:bg-brand-700"
                                :class="focusRing"
                            >
                                Panele git
                            </Link>
                        </template>
                        <template v-else>
                            <Link
                                href="/register"
                                class="rounded-md bg-brand-600 px-4 py-3 text-center text-base font-semibold text-white hover:bg-brand-700"
                                :class="focusRing"
                            >
                                Ücretsiz başla
                            </Link>
                            <Link
                                href="/login"
                                class="rounded-md border border-stone-300 px-4 py-3 text-center text-base font-medium text-stone-800 hover:bg-stone-50"
                                :class="focusRing"
                            >
                                Giriş yap
                            </Link>
                        </template>
                    </div>
                </nav>
            </div>
        </header>

        <main id="icerik" class="flex-1">
            <slot />
        </main>

        <!--
            Alt bilgi bilerek kısa: iletişim adresi ve yasal sayfalar
            (Impressum, KVKK) henüz yok. Var olmayan sayfaya bağlantı
            vermek 404'e götürür ve güveni sitenin geri kalanından fazla
            zedeler.
        -->
        <footer class="border-t border-stone-200 bg-stone-50">
            <div class="mx-auto flex max-w-6xl flex-col gap-6 px-5 py-10 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <BrandMark size="md" />
                    <p class="mt-3 text-sm text-stone-600">
                        34Devs ekibinden · © {{ year }} 34Pazar
                    </p>
                </div>
                <nav aria-label="Alt bilgi">
                    <ul class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                        <li v-if="isLoggedIn">
                            <Link href="/panel" class="rounded-sm font-medium text-stone-700 hover:text-stone-900" :class="focusRing">
                                Panel
                            </Link>
                        </li>
                        <template v-else>
                            <li>
                                <Link href="/login" class="rounded-sm font-medium text-stone-700 hover:text-stone-900" :class="focusRing">
                                    Giriş
                                </Link>
                            </li>
                            <li>
                                <Link href="/register" class="rounded-sm font-medium text-stone-700 hover:text-stone-900" :class="focusRing">
                                    Kayıt
                                </Link>
                            </li>
                        </template>
                    </ul>
                </nav>
            </div>
        </footer>
    </div>
</template>
