<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import BundleComponentsEditor from '../../Components/BundleComponentsEditor.vue';
import PageHeader from '../../Components/PageHeader.vue';
import WriteGuard from '../../Components/WriteGuard.vue';
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

// SET — içerik ayrı kaydedilir; stok bileşenlerden hesaplanır.
const bundleComponents = ref((props.product.bundle?.components ?? []).map((c) => ({ ...c })));
const savingBundle = ref(false);

function saveBundle() {
    savingBundle.value = true;
    router.put(
        `/products/${props.product.id}/bundle`,
        { components: bundleComponents.value.map((c) => ({ variant_id: c.variantId, quantity: c.quantity })) },
        { preserveScroll: true, onFinish: () => { savingBundle.value = false; } },
    );
}

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
                <Link
                    :href="`/products/${product.id}/channels`"
                    class="rounded-[10px] border border-stone-300 bg-white px-3.5 py-2 text-sm font-medium text-stone-800 transition hover:bg-stone-50"
                >
                    {{ t('Hangi kanallarda') }}
                </Link>
            </template>

            <template #toolbar>
                <p class="font-mono text-xs text-stone-500">
                    {{ t(':sku · içerik sürümü :version', { sku: product.sku, version: product.contentVersion }) }}
                </p>
            </template>
        </PageHeader>
        <WriteGuard permission="catalog.manage">


            <!--
                İKİ SÜTUN (Yön A): solda içerik formu ve set içeriği, sağda
                stok özeti ve alış maliyeti. Form eskiden sayfanın solunda
                dar bir sütunda yüzüyordu; sağ yarı boş kalıyordu.
            -->
            <div class="mt-6 grid items-start gap-4 lg:grid-cols-3">
            <div class="min-w-0 space-y-4 lg:col-span-2">
            <form class="space-y-5 rounded-[14px] border border-stone-200 bg-white p-5 sm:p-[22px]" @submit.prevent="submit">
                <h2 class="text-base font-semibold text-stone-900">{{ t('Ürün bilgileri') }}</h2>
                <div>
                    <label for="title" class="block text-sm font-medium text-stone-700">
                        {{ t('Ürün adı') }}
                    </label>
                    <input
                        id="title"
                        v-model="form.title"
                        type="text"
                        required
                        class="mt-1 w-full rounded-[10px] border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
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
                            class="mt-1 w-full rounded-[10px] border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
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
                            class="mt-1 w-full rounded-[10px] border border-stone-300 bg-white px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
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
                        class="mt-1 w-full rounded-[10px] border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
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
                        class="mt-1 w-full rounded-[10px] border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
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
                        class="mt-1 w-full rounded-[10px] border border-stone-300 px-3 py-2 text-sm focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring"
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
                        class="rounded-[10px] bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ form.processing ? t('Kaydediliyor…') : t('Kaydet') }}
                    </button>

                    <Link href="/products" class="text-sm text-stone-600 underline">
                        {{ t('Vazgeç') }}
                    </Link>
                </div>
            </form>

            <section v-if="product.bundle" class="rounded-[14px] border border-stone-200 bg-white p-5 sm:p-[22px]">
                <h2 class="text-base font-semibold text-stone-900">{{ t('Setin içindekiler') }}</h2>
                <p class="mt-0.5 text-xs text-stone-500">
                    {{ t('Set stoğu: :count', { count: product.bundle.available }) }}
                    <span v-if="product.bundle.limitingSku">· {{ t('Sınırlayan: :sku', { sku: product.bundle.limitingSku }) }}</span>
                </p>
                <p class="mt-1 text-xs text-stone-500">
                    {{ t('İçeriği değiştirirseniz kanallara yeni set stoğu gider. Daha önce satılan setlerin iptal ve iadesinde, satıldığı andaki içerik geri eklenir.') }}
                </p>
                <BundleComponentsEditor v-model="bundleComponents" class="mt-3" :error="$page.props.errors?.components" />
                <button
                    type="button"
                    :disabled="savingBundle || !bundleComponents.length"
                    class="mt-4 rounded-[10px] bg-stone-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="saveBundle"
                >
                    {{ savingBundle ? t('Kaydediliyor…') : t('Setin içeriğini kaydet') }}
                </button>
            </section>

            </div>

            <div class="min-w-0 space-y-4">
            <section class="rounded-[14px] border border-stone-200 bg-white p-5">
                <p class="text-[13px] text-stone-600">{{ t('Toplam stok') }}</p>
                <p
                    class="mt-1 text-[30px] font-bold tracking-tight tabular-nums"
                    :class="product.hasOversold ? 'text-brand-700' : 'text-stone-900'"
                >
                    {{ product.totalOnHand }}
                </p>
                <Link
                    v-if="product.hasOversold"
                    :href="`/products?filter=out&search=${encodeURIComponent(product.sku)}`"
                    class="text-[13px] font-medium text-brand-700 underline"
                >
                    {{ t('fazla satış var') }}
                </Link>
                <!--
                    STOK BU EKRANDA DEĞİŞTİRİLMEZ. İçerik ve stok ayrı senkron
                    alanlarıdır; başlık düzeltmesinin stok hareketi yaratması
                    ledger'ı kirletirdi. Stok sayımı ürün listesindedir.
                -->
                <p class="mt-3 border-t border-stone-100 pt-3 text-xs leading-relaxed text-stone-600">
                    {{ t('Bu ekran yalnızca içeriği düzenler; stok değişmez.') }}
                    <Link :href="`/products?search=${encodeURIComponent(product.sku)}`" class="font-medium text-stone-900 underline">{{ t('Stok sayımı ürün listesinden girilir.') }}</Link>
                </p>
            </section>

            <section v-if="product.variants?.length" class="rounded-[14px] border border-stone-200 bg-white p-5">
                <h2 class="text-base font-semibold text-stone-900">{{ t('Alış maliyeti') }}</h2>
                <p class="mt-0.5 text-xs text-stone-500">
                    {{ t('Kanala gitmez. Kanalın fiyat kuralında zarar koruması açıksa bu maliyetin altına düşen fiyat gönderilmez.') }}
                </p>

                <ul class="mt-3 space-y-2">
                    <li v-for="variant in product.variants" :key="variant.id" class="flex flex-wrap items-center gap-3">
                        <span class="min-w-0 flex-1 truncate font-mono text-xs text-stone-600">{{ variant.sku }}</span>
                        <label class="block">
                            <span class="sr-only">{{ t(':sku için alış maliyeti', { sku: variant.sku }) }}</span>
                            <span class="flex items-center rounded-[10px] border border-stone-300 bg-white focus-within:border-ring">
                                <input
                                    v-model="costs[variant.id]"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    :placeholder="t('Maliyet')"
                                    class="w-24 rounded-l-[10px] border-0 px-3 py-1.5 text-sm focus:outline-none"
                                >
                                <span class="px-2 font-mono text-xs text-stone-500">{{ variant.currency }}</span>
                            </span>
                        </label>
                        <button
                            type="button"
                            :disabled="savingCost !== null"
                            class="rounded-[10px] border border-stone-300 px-3 py-1.5 text-sm text-stone-700 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="saveCost(variant)"
                        >
                            {{ savingCost === variant.id ? t('Kaydediliyor…') : t('Kaydet') }}
                        </button>
                    </li>
                </ul>
                <p v-if="$page.props.errors?.cost_price" class="mt-2 text-sm text-red-700">{{ $page.props.errors.cost_price }}</p>
            </section>
            </div>
            </div>
        </WriteGuard>
    </PanelLayout>
</template>
