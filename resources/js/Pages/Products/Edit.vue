<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import PanelLayout from '../../Layouts/PanelLayout.vue';
import { useI18n } from '../../lib/i18n';

const props = defineProps({
    product: { type: Object, required: true },
});

const form = useForm({
    title: props.product.title ?? '',
    price: props.product.price ?? '',
    description: props.product.description ?? '',
    brand: props.product.brand ?? '',
    internal_category_id: props.product.internalCategoryId ?? '',
    status: props.product.status ?? 'active',
});

const { t } = useI18n();

function submit() {
    form.put(`/products/${props.product.id}`);
}

// ALIŞ MALİYETİ — varyant başına, ayrı kaydedilir (ürün formundan bağımsız).
const costs = ref(Object.fromEntries((props.product.variants ?? []).map((v) => [v.id, v.costPrice ?? ''])));
const savingCost = ref(null);

function saveCost(variant) {
    savingCost.value = variant.id;
    router.put(
        `/products/${props.product.id}/variants/${variant.id}/cost`,
        { cost_price: costs.value[variant.id] === '' ? null : costs.value[variant.id] },
        { preserveScroll: true, onFinish: () => { savingCost.value = null; } },
    );
}
</script>

<template>
    <PanelLayout>
        <PageHeader :section="t('Ürünler')" :title="product.title">
            <template #actions>
                <div class="text-right">
                    <p class="text-xs text-stone-500">{{ t('Toplam stok') }}</p>
                    <p
                        class="font-mono text-xl font-semibold tabular-nums"
                        :class="product.hasOversold ? 'text-red-800' : 'text-stone-900'"
                    >
                        {{ product.totalOnHand }}
                    </p>
                    <Link
                        v-if="product.hasOversold"
                        :href="`/products?filter=out&search=${encodeURIComponent(product.sku)}`"
                        class="text-[11px] text-red-700 underline"
                    >
                        {{ t('fazla satış var') }}
                    </Link>
                </div>
            </template>

            <template #toolbar>
                <p class="font-mono text-xs text-stone-500">
                    {{ t(':sku · içerik sürümü :version', { sku: product.sku, version: product.contentVersion }) }}
                </p>
            </template>
        </PageHeader>

        <!--
            STOK BU EKRANDA DEĞİŞTİRİLMEZ. İçerik ve stok ayrı senkron
            alanlarıdır; başlık düzeltmesinin stok hareketi yaratması
            ledger'ı kirletirdi. Stok sayımı ürün listesindedir.
        -->
        <p class="mt-6 rounded-lg border border-stone-200 bg-stone-50 px-4 py-3 text-xs text-stone-600">
            {{ t('Bu ekran yalnızca içeriği düzenler; stok değişmez.') }}
            <Link :href="`/products?search=${encodeURIComponent(product.sku)}`" class="font-medium text-stone-900 underline">{{ t('Stok sayımı ürün listesinden girilir.') }}</Link>
        </p>

        <form class="mt-6 max-w-xl space-y-5" @submit.prevent="submit">
            <div>
                <label for="title" class="block text-sm font-medium text-stone-700">
                    {{ t('Ürün adı') }}
                </label>
                <input
                    id="title"
                    v-model="form.title"
                    type="text"
                    required
                    class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                >
                <p v-if="form.errors.title" class="mt-1 text-sm text-red-700">
                    {{ form.errors.title }}
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="price" class="block text-sm font-medium text-stone-700">
                        {{ t('Fiyat') }}
                    </label>
                    <input
                        id="price"
                        v-model="form.price"
                        type="number"
                        step="0.01"
                        min="0"
                        class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    >
                    <p v-if="form.errors.price" class="mt-1 text-sm text-red-700">
                        {{ form.errors.price }}
                    </p>
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-stone-700">
                        {{ t('Durum') }}
                    </label>
                    <select
                        id="status"
                        v-model="form.status"
                        class="mt-1 w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                    >
                        <option value="active">{{ t('Yayında') }}</option>
                        <option value="draft">{{ t('Taslak') }}</option>
                        <option value="archived">{{ t('Arşiv') }}</option>
                    </select>
                </div>
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-stone-700">
                    {{ t('Açıklama') }}
                </label>
                <textarea
                    id="description"
                    v-model="form.description"
                    rows="4"
                    class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                />
            </div>

            <div>
                <label for="brand" class="block text-sm font-medium text-stone-700">
                    {{ t('Marka') }}
                </label>
                <input
                    id="brand"
                    v-model="form.brand"
                    type="text"
                    class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                >
            </div>

            <!--
                İç kategori kanal eşleştirmesinin çıpasıdır (§13 · Faz 2).
                Serbest metindir: ayrı bir iç kategori tablosu yoktur.
            -->
            <div>
                <label for="internal_category_id" class="block text-sm font-medium text-stone-700">
                    {{ t('İç kategori') }}
                </label>
                <input
                    id="internal_category_id"
                    v-model="form.internal_category_id"
                    type="text"
                    :placeholder="t('Örn. kadin-elbise')"
                    class="mt-1 w-full rounded-md border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                >
                <p class="mt-1 text-xs text-stone-500">
                    {{ t('Kendi kategori adınız.') }}
                    <Link href="/mappings" class="underline">{{ t('Ürünün kanalda hangi kategoriye açılacağı eşleştirme ekranında bu ad üzerinden belirlenir.') }}</Link>
                </p>
                <p v-if="form.errors.internal_category_id" class="mt-1 text-xs text-red-700">
                    {{ form.errors.internal_category_id }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ form.processing ? t('Kaydediliyor…') : t('Kaydet') }}
                </button>

                <Link href="/products" class="text-sm text-stone-600 underline">
                    {{ t('Vazgeç') }}
                </Link>
            </div>
        </form>

        <section v-if="product.variants?.length" class="mt-10 max-w-xl border-t border-stone-200 pt-6">
            <h2 class="text-sm font-medium text-stone-900">{{ t('Alış maliyeti') }}</h2>
            <p class="mt-0.5 text-xs text-stone-500">
                {{ t('Kanala gitmez. Kanalın fiyat kuralında zarar koruması açıksa bu maliyetin altına düşen fiyat gönderilmez.') }}
            </p>

            <ul class="mt-3 space-y-2">
                <li v-for="variant in product.variants" :key="variant.id" class="flex flex-wrap items-center gap-3">
                    <span class="min-w-0 flex-1 truncate font-mono text-xs text-stone-600">{{ variant.sku }}</span>
                    <label class="block">
                        <span class="sr-only">{{ t(':sku için alış maliyeti', { sku: variant.sku }) }}</span>
                        <span class="flex items-center rounded-md border border-stone-300 bg-white focus-within:border-ring">
                            <input
                                v-model="costs[variant.id]"
                                type="number"
                                min="0"
                                step="0.01"
                                inputmode="decimal"
                                :placeholder="t('Maliyet')"
                                class="w-32 rounded-l-md border-0 px-3 py-1.5 text-sm focus:outline-none"
                            >
                            <span class="px-2 font-mono text-xs text-stone-500">{{ variant.currency }}</span>
                        </span>
                    </label>
                    <button
                        type="button"
                        :disabled="savingCost !== null"
                        class="rounded-md border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="saveCost(variant)"
                    >
                        {{ savingCost === variant.id ? t('Kaydediliyor…') : t('Kaydet') }}
                    </button>
                </li>
            </ul>
            <p v-if="$page.props.errors?.cost_price" class="mt-2 text-sm text-red-700">{{ $page.props.errors.cost_price }}</p>
        </section>
    </PanelLayout>
</template>
