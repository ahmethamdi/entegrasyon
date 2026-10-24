<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from '../lib/i18n';

// Setin bileşenleri — oluşturma ve düzenleme ekranının ortak parçası.
// Kural sunucuda (`SaveBundle`): set içinde set yok, adet 1–1000. Burada
// yalnız seçim ve "kaç set çıkar" önizlemesi (min ⌊stok / adet⌋).
const props = defineProps({
    modelValue: { type: Array, required: true },
    max: { type: Number, default: 20 },
    error: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const term = ref('');
const results = ref([]);
const searching = ref(false);
let timer = null;

async function search() {
    searching.value = true;
    try {
        const response = await fetch(`/products/bundles/variants?q=${encodeURIComponent(term.value)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        results.value = response.ok ? await response.json() : [];
    } finally {
        searching.value = false;
    }
}

watch(term, () => {
    clearTimeout(timer);
    timer = setTimeout(search, 250);
});

search();

const chosen = computed(() => new Set(props.modelValue.map((c) => c.variantId)));

function add(variant) {
    if (chosen.value.has(variant.id) || props.modelValue.length >= props.max) return;
    emit('update:modelValue', [...props.modelValue, {
        variantId: variant.id,
        sku: variant.sku,
        title: variant.title,
        available: variant.available,
        quantity: 1,
    }]);
}

function remove(index) {
    emit('update:modelValue', props.modelValue.filter((_, i) => i !== index));
}

function setQuantity(index, value) {
    const quantity = Math.max(1, Math.min(1000, parseInt(value, 10) || 1));
    emit('update:modelValue', props.modelValue.map((c, i) => (i === index ? { ...c, quantity } : c)));
}

// Önizleme: sunucudaki `SellableStock` ile aynı kural.
const preview = computed(() => {
    if (!props.modelValue.length) return null;
    let sets = null;
    let limiting = null;
    for (const c of props.modelValue) {
        const value = Math.floor((c.available ?? 0) / c.quantity);
        if (sets === null || value < sets) {
            sets = value;
            limiting = c.sku;
        }
    }
    return { sets, limiting };
});
</script>

<template>
    <div>
        <ul v-if="modelValue.length" class="divide-y divide-stone-100 rounded-lg border border-stone-200 bg-white">
            <li v-for="(component, index) in modelValue" :key="component.variantId" class="flex flex-wrap items-center gap-3 px-3 py-2">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm text-stone-900">{{ component.title }}</p>
                    <p class="font-mono text-xs text-stone-500">{{ component.sku }} · {{ t('stok :count', { count: component.available ?? 0 }) }}</p>
                </div>
                <label class="flex items-center gap-2 text-xs text-stone-600">
                    {{ t('Adet') }}
                    <input
                        type="number"
                        min="1"
                        max="1000"
                        :value="component.quantity"
                        :aria-label="t(':sku adedi', { sku: component.sku })"
                        class="w-20 rounded-md border border-stone-300 px-2 py-1 text-right font-mono text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                        @input="setQuantity(index, $event.target.value)"
                    >
                </label>
                <button
                    type="button"
                    class="rounded-md border border-stone-300 px-2 py-1 text-xs text-stone-700 transition hover:bg-stone-100"
                    @click="remove(index)"
                >
                    {{ t('Çıkar') }}
                </button>
            </li>
        </ul>
        <p v-else class="rounded-lg border border-dashed border-stone-300 px-3 py-4 text-sm text-stone-500">
            {{ t('Henüz ürün yok. Aşağıdan arayıp sete ekleyin.') }}
        </p>

        <p v-if="preview" class="mt-2 text-sm text-stone-700">
            {{ t('Bu stokla :count set çıkar.', { count: preview.sets }) }}
            <span v-if="preview.limiting" class="text-stone-500">{{ t('Sınırlayan: :sku', { sku: preview.limiting }) }}</span>
        </p>
        <p v-if="error" class="mt-2 text-sm text-red-700">{{ error }}</p>

        <div class="mt-4">
            <label for="bundle-search" class="block text-xs font-medium text-stone-700">{{ t('Sete ürün ekle') }}</label>
            <input
                id="bundle-search"
                v-model="term"
                type="search"
                :placeholder="t('Ürün adı, SKU ya da barkod')"
                class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
            >
            <ul class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-stone-200 bg-white" :aria-busy="searching">
                <li v-for="variant in results" :key="variant.id" class="flex items-center gap-3 border-b border-stone-100 px-3 py-2 last:border-b-0">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm text-stone-900">{{ variant.title }}</p>
                        <p class="font-mono text-xs text-stone-500">{{ variant.sku }} · {{ t('stok :count', { count: variant.available }) }}</p>
                    </div>
                    <button
                        type="button"
                        :disabled="chosen.has(variant.id) || modelValue.length >= max"
                        class="rounded-md border border-stone-300 px-2 py-1 text-xs text-stone-700 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="add(variant)"
                    >
                        {{ chosen.has(variant.id) ? t('Sette') : t('Ekle') }}
                    </button>
                </li>
                <li v-if="!results.length && !searching" class="px-3 py-3 text-sm text-stone-500">{{ t('Ürün bulunamadı.') }}</li>
            </ul>
        </div>
    </div>
</template>
