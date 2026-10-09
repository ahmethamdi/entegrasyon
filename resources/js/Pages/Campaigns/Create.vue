<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { useI18n } from '../../lib/i18n';

const { t } = useI18n();

const props = defineProps({
    connections: { type: Array, default: () => [] },
    timezone: { type: String, default: 'Europe/Istanbul' },
    defaultStartsAt: { type: String, required: true },
    defaultEndsAt: { type: String, required: true },
});

// ⚠️ TÜM ALANLAR BAŞTAN TANIMLANIR: `useForm` yalnız kurulurken verilen
// anahtarları gönderir.
const form = useForm({
    name: '',
    discount_type: 'percent',
    discount_value: '',
    starts_at: props.defaultStartsAt,
    ends_at: props.defaultEndsAt,
    show_compare_at: true,
    product_ids: [],
    connection_ids: props.connections.map((c) => c.id),
});

// ÜRÜN SEÇİMİ — sunucuda arama (katalog binlerce ürün olabilir), seçilenler çip.
const term = ref('');
const results = ref([]);
const searching = ref(false);
const selected = ref([]);
let timer = null;

async function search() {
    searching.value = true;
    try {
        const response = await fetch(`/campaigns/products?q=${encodeURIComponent(term.value)}`, {
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

function isSelected(product) {
    return selected.value.some((p) => p.id === product.id);
}

function toggle(product) {
    selected.value = isSelected(product)
        ? selected.value.filter((p) => p.id !== product.id)
        : [...selected.value, product];
}

function addAllResults() {
    results.value.forEach((product) => { if (!isSelected(product)) selected.value.push(product); });
}

function submit() {
    form
        .transform((data) => ({ ...data, product_ids: selected.value.map((p) => p.id) }))
        .post('/campaigns');
}
</script>

<template>
    <PanelLayout>
        <PageHeader
            :section="t('Kampanyalar')"
            :title="t('Kampanya oluştur')"
            :description="t('İndirim, kanalın normal fiyatına uygulanır (fiyat kuralı dahil). Zarar koruması kampanyada da geçerli: maliyetin altına düşen fiyat gönderilmez.')"
        />

        <form class="mt-8 max-w-2xl space-y-7" @submit.prevent="submit">
            <div>
                <label for="name" class="block text-sm font-medium text-stone-700">{{ t('Kampanya adı') }}</label>
                <input id="name" v-model="form.name" type="text" maxlength="120" required :placeholder="t('Kasım indirimi')" class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-none">
                <p class="mt-1 text-xs text-stone-500">{{ t('Yalnız sende görünür.') }}</p>
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-700">{{ form.errors.name }}</p>
            </div>

            <fieldset>
                <legend class="text-sm font-medium text-stone-700">{{ t('İndirim') }}</legend>
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 text-sm text-stone-700">
                        <input v-model="form.discount_type" type="radio" value="percent"> {{ t('Yüzde') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm text-stone-700">
                        <input v-model="form.discount_type" type="radio" value="amount"> {{ t('Tutar') }}
                    </label>
                    <span class="flex items-center rounded-md border border-stone-300 bg-white focus-within:border-ring">
                        <span v-if="form.discount_type === 'percent'" class="pl-3 text-sm text-stone-500">%</span>
                        <input v-model="form.discount_value" type="number" step="0.01" min="0.01" required inputmode="decimal" class="w-28 rounded-r-md border-0 px-2 py-2 text-sm focus:outline-none">
                    </span>
                </div>
                <p v-if="form.discount_type === 'amount'" class="mt-1 text-xs text-stone-500">{{ t('Tutar indirimi yalnız ürünün para biriminde satan kanallara uygulanır (USD Etsy fiyatından TL düşülmez).') }}</p>
                <p v-if="form.errors.discount_value" class="mt-1 text-sm text-red-700">{{ form.errors.discount_value }}</p>
                <label class="mt-3 flex items-center gap-2 text-sm text-stone-700">
                    <input v-model="form.show_compare_at" type="checkbox"> {{ t('Normal fiyatı üstü çizili göster') }}
                </label>
            </fieldset>

            <fieldset>
                <legend class="text-sm font-medium text-stone-700">{{ t('Tarih') }} <span class="font-normal text-stone-500">({{ timezone }})</span></legend>
                <div class="mt-2 flex flex-wrap gap-4">
                    <label class="block">
                        <span class="block text-xs text-stone-600">{{ t('Başlangıç') }}</span>
                        <input v-model="form.starts_at" type="datetime-local" required class="mt-1 rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-none">
                    </label>
                    <label class="block">
                        <span class="block text-xs text-stone-600">{{ t('Bitiş') }}</span>
                        <input v-model="form.ends_at" type="datetime-local" required class="mt-1 rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-none">
                    </label>
                </div>
                <p class="mt-1 text-xs text-stone-500">{{ t('Başlangıç geçmişteyse kampanya kaydedince hemen başlar.') }}</p>
                <p v-if="form.errors.starts_at" class="mt-1 text-sm text-red-700">{{ form.errors.starts_at }}</p>
                <p v-if="form.errors.ends_at" class="mt-1 text-sm text-red-700">{{ form.errors.ends_at }}</p>
            </fieldset>

            <fieldset>
                <legend class="text-sm font-medium text-stone-700">{{ t('Kanallar') }}</legend>
                <p v-if="!connections.length" class="mt-2 text-sm text-amber-800">{{ t('Fiyat gönderen bağlı kanal yok.') }}</p>
                <div class="mt-2 space-y-1.5">
                    <label v-for="connection in connections" :key="connection.id" class="flex items-center gap-2 text-sm text-stone-700">
                        <input v-model="form.connection_ids" type="checkbox" :value="connection.id">
                        {{ connection.label }} <span class="text-xs text-stone-500">· {{ connection.channel }}</span>
                    </label>
                </div>
                <p v-if="form.errors.connection_ids" class="mt-1 text-sm text-red-700">{{ form.errors.connection_ids }}</p>
            </fieldset>

            <fieldset>
                <legend class="text-sm font-medium text-stone-700">{{ t('Ürünler') }} <span class="font-normal text-stone-500">({{ t(':count seçili', { count: selected.length }) }})</span></legend>

                <div v-if="selected.length" class="mt-2 flex flex-wrap gap-1.5">
                    <button
                        v-for="product in selected"
                        :key="product.id"
                        type="button"
                        class="rounded-full border border-stone-300 bg-stone-50 px-2.5 py-0.5 text-xs text-stone-700 hover:bg-stone-100"
                        :title="t('Çıkar')"
                        @click="toggle(product)"
                    >
                        {{ product.title }} ×
                    </button>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <input v-model="term" type="search" :placeholder="t('Ürün adı, SKU ya da barkod ara')" class="min-w-0 flex-1 rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-none">
                    <button type="button" class="text-sm text-stone-700 underline disabled:opacity-50" :disabled="!results.length" @click="addAllResults">
                        {{ t('Listelenenlerin hepsini ekle') }}
                    </button>
                </div>

                <ul class="mt-2 max-h-72 divide-y divide-stone-100 overflow-y-auto rounded-md border border-stone-200 bg-white">
                    <li v-if="searching" class="px-3 py-2 text-xs text-stone-500">{{ t('Aranıyor…') }}</li>
                    <li v-else-if="!results.length" class="px-3 py-2 text-xs text-stone-500">{{ t('Ürün bulunamadı.') }}</li>
                    <li v-for="product in results" :key="product.id">
                        <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-stone-50">
                            <input type="checkbox" :checked="isSelected(product)" @change="toggle(product)">
                            <span class="min-w-0 flex-1 truncate text-stone-800">{{ product.title }}</span>
                            <span class="font-mono text-xs text-stone-500">{{ product.sku }}</span>
                        </label>
                    </li>
                </ul>
                <p v-if="form.errors.product_ids" class="mt-1 text-sm text-red-700">{{ form.errors.product_ids }}</p>
            </fieldset>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing || !selected.length || !form.connection_ids.length"
                    class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ form.processing ? t('Kaydediliyor…') : t('Kampanyayı kaydet') }}
                </button>
                <Link href="/campaigns" class="text-sm text-stone-600 underline">{{ t('Vazgeç') }}</Link>
            </div>
        </form>
    </PanelLayout>
</template>
