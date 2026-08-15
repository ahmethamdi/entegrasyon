<script setup>
defineProps({
    tenantCount: { type: Number, default: 0 },
    channelTypes: { type: Array, default: () => [] },
});
</script>

<template>
    <main class="mx-auto max-w-3xl px-6 py-16">
        <p class="font-mono text-xs uppercase tracking-widest text-stone-500">
            Entegrasyon
        </p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight text-stone-900">
            Çekirdek ayakta
        </h1>
        <p class="mt-3 max-w-prose text-stone-600">
            Modüler monolit iskeleti çalışıyor. Kanal adapterleri sonraki fazda
            uygulanacak.
        </p>

        <dl class="mt-10 grid grid-cols-2 gap-px overflow-hidden rounded border border-stone-200 bg-stone-200 sm:grid-cols-3">
            <div class="bg-white p-4">
                <dt class="font-mono text-[10px] uppercase tracking-wider text-stone-500">
                    Kiracı
                </dt>
                <dd class="mt-1 text-2xl font-medium tabular-nums text-stone-900">
                    {{ tenantCount }}
                </dd>
            </div>
            <div class="bg-white p-4">
                <dt class="font-mono text-[10px] uppercase tracking-wider text-stone-500">
                    Kanal tipi
                </dt>
                <dd class="mt-1 text-2xl font-medium tabular-nums text-stone-900">
                    {{ channelTypes.length }}
                </dd>
            </div>
        </dl>

        <ul v-if="channelTypes.length" class="mt-8 divide-y divide-stone-200 border-t border-stone-200">
            <li
                v-for="type in channelTypes"
                :key="type.code"
                class="flex items-center justify-between py-3"
            >
                <span class="font-medium text-stone-900">{{ type.name }}</span>
                <span class="font-mono text-xs text-stone-500">
                    {{ type.kind }}{{ type.is_active ? '' : ' · pasif' }}
                </span>
            </li>
        </ul>
    </main>
</template>
