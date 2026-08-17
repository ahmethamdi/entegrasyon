<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();

const tenantName = computed(() => page.props.tenant?.name ?? '');

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

                <button
                    type="button"
                    class="rounded border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100"
                    @click="logout"
                >
                    Çıkış
                </button>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-6 py-10">
            <slot />
        </main>
    </div>
</template>
