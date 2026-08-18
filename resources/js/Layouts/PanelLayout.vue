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
    { href: '/channels', label: 'Kanallar' },
    { href: '/mappings', label: 'Eşleştirme' },
];

const currentPath = computed(() => page.url.split('?')[0]);

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
                    <p class="font-mono text-[10px] uppercase tracking-widest text-stone-500">
                        Entegrasyon
                    </p>
                    <p class="text-sm font-medium text-stone-900">{{ tenantName }}</p>
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

        <main class="mx-auto max-w-6xl px-6 py-10">
            <slot />
        </main>
    </div>
</template>
