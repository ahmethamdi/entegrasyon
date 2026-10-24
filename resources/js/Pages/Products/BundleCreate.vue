<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import BundleComponentsEditor from '../../Components/BundleComponentsEditor.vue';
import PageHeader from '../../Components/PageHeader.vue';
import WriteGuard from '../../Components/WriteGuard.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { useI18n } from '../../lib/i18n';

defineProps({
    maxComponents: { type: Number, default: 20 },
});

const { t } = useI18n();
const components = ref([]);

const form = useForm({
    title: '',
    sku: '',
    price: '',
    description: '',
    components: [],
});

function submit() {
    form.transform((data) => ({
        ...data,
        components: components.value.map((c) => ({ variant_id: c.variantId, quantity: c.quantity })),
    })).post('/products/bundles');
}
</script>

<template>
    <PanelLayout>
        <PageHeader
            :section="t('Ürünler')"
            :title="t('Set oluştur')"
            :description="t('Birden çok ürünü tek ürün olarak sat: hediye seti, 3\'lü paket, kombin. Setin kendi stoğu yoktur; içindeki ürünlerin stoğundan hesaplanır ve set satılınca onlardan düşülür.')"
        />

        <WriteGuard permission="catalog.manage">
        <form class="mt-8 max-w-2xl space-y-6" @submit.prevent="submit">
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="title" class="block text-sm font-medium text-stone-700">{{ t('Set adı') }}</label>
                    <input
                        id="title"
                        v-model="form.title"
                        type="text"
                        required
                        :placeholder="t('Kahve hediye seti')"
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    >
                    <p v-if="form.errors.title" class="mt-1 text-sm text-red-700">{{ form.errors.title }}</p>
                </div>
                <div>
                    <label for="sku" class="block text-sm font-medium text-stone-700">SKU</label>
                    <input
                        id="sku"
                        v-model="form.sku"
                        type="text"
                        required
                        :placeholder="t('SET-KAHVE-01')"
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 font-mono text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    >
                    <p v-if="form.errors.sku" class="mt-1 text-sm text-red-700">{{ form.errors.sku }}</p>
                </div>
                <div>
                    <label for="price" class="block text-sm font-medium text-stone-700">{{ t('Set fiyatı (₺)') }}</label>
                    <input
                        id="price"
                        v-model="form.price"
                        type="number"
                        step="0.01"
                        min="0.01"
                        required
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    >
                    <p v-if="form.errors.price" class="mt-1 text-sm text-red-700">{{ form.errors.price }}</p>
                </div>
                <div class="sm:col-span-2">
                    <label for="description" class="block text-sm font-medium text-stone-700">{{ t('Açıklama (isteğe bağlı)') }}</label>
                    <textarea
                        id="description"
                        v-model="form.description"
                        rows="3"
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    />
                </div>
            </div>

            <fieldset class="border-t border-stone-200 pt-5">
                <legend class="text-sm font-medium text-stone-900">{{ t('Setin içindekiler') }}</legend>
                <p class="mt-1 text-xs text-stone-500">{{ t('Her üründen sette kaç adet olduğunu girin. Bir set başka bir sete eklenemez.') }}</p>
                <BundleComponentsEditor v-model="components" class="mt-3" :max="maxComponents" :error="form.errors.components" />
            </fieldset>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing || !components.length"
                    class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ t('Seti oluştur') }}
                </button>
                <p class="text-xs text-stone-500">{{ t('Oluşturduktan sonra seti ürün ekranından kanallara gönderebilirsiniz.') }}</p>
            </div>
        </form>
        </WriteGuard>
    </PanelLayout>
</template>
