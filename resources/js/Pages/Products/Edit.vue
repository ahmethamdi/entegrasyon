<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import PanelLayout from '../../Layouts/PanelLayout.vue';

const props = defineProps({
    product: { type: Object, required: true },
});

const form = useForm({
    title: props.product.title ?? '',
    price: props.product.price ?? '',
    description: props.product.description ?? '',
    brand: props.product.brand ?? '',
    status: props.product.status ?? 'active',
});

function submit() {
    form.put(`/products/${props.product.id}`);
}
</script>

<template>
    <PanelLayout>
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="font-mono text-[10px] uppercase tracking-widest text-stone-500">
                    Katalog
                </p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900">
                    {{ product.title }}
                </h1>
                <p class="mt-1 font-mono text-xs text-stone-500">
                    {{ product.sku }} · içerik sürümü {{ product.contentVersion }}
                </p>
            </div>

            <div class="text-right">
                <p class="text-xs text-stone-500">Toplam stok</p>
                <p
                    class="font-mono text-xl font-semibold tabular-nums"
                    :class="product.hasOversold ? 'text-red-800' : 'text-stone-900'"
                >
                    {{ product.totalOnHand }}
                </p>
                <Link
                    v-if="product.hasOversold"
                    href="/inventory?filter=oversold"
                    class="text-[11px] text-red-700 underline"
                >
                    fazla satış var
                </Link>
            </div>
        </div>

        <!--
            STOK BU EKRANDA DEĞİŞTİRİLMEZ. İçerik ve stok ayrı senkron
            alanlarıdır; başlık düzeltmesinin stok hareketi yaratması
            ledger'ı kirletirdi. Stok düzeltmesi stok ekranındadır.
        -->
        <p class="mt-6 rounded border border-stone-200 bg-stone-50 px-4 py-3 text-xs text-stone-600">
            Bu ekran yalnızca içeriği düzenler; stok değişmez.
            Stok düzeltmesi için
            <Link href="/inventory" class="font-medium text-stone-900 underline">stok ekranını</Link>
            kullanın.
        </p>

        <form class="mt-6 max-w-xl space-y-5" @submit.prevent="submit">
            <div>
                <label for="title" class="block text-sm font-medium text-stone-700">
                    Ürün adı
                </label>
                <input
                    id="title"
                    v-model="form.title"
                    type="text"
                    required
                    class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm focus:border-stone-500 focus:outline-none"
                >
                <p v-if="form.errors.title" class="mt-1 text-sm text-red-700">
                    {{ form.errors.title }}
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="price" class="block text-sm font-medium text-stone-700">
                        Fiyat
                    </label>
                    <input
                        id="price"
                        v-model="form.price"
                        type="number"
                        step="0.01"
                        min="0"
                        class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm focus:border-stone-500 focus:outline-none"
                    >
                    <p v-if="form.errors.price" class="mt-1 text-sm text-red-700">
                        {{ form.errors.price }}
                    </p>
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-stone-700">
                        Durum
                    </label>
                    <select
                        id="status"
                        v-model="form.status"
                        class="mt-1 w-full rounded border border-stone-300 bg-white px-3 py-2 text-sm focus:border-stone-500 focus:outline-none"
                    >
                        <option value="active">Yayında</option>
                        <option value="draft">Taslak</option>
                        <option value="archived">Arşiv</option>
                    </select>
                </div>
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-stone-700">
                    Açıklama
                </label>
                <textarea
                    id="description"
                    v-model="form.description"
                    rows="4"
                    class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm focus:border-stone-500 focus:outline-none"
                />
            </div>

            <div>
                <label for="brand" class="block text-sm font-medium text-stone-700">
                    Marka
                </label>
                <input
                    id="brand"
                    v-model="form.brand"
                    type="text"
                    class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm focus:border-stone-500 focus:outline-none"
                >
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Kaydediliyor…' : 'Kaydet' }}
                </button>

                <Link href="/products" class="text-sm text-stone-600 underline">
                    Vazgeç
                </Link>
            </div>
        </form>
    </PanelLayout>
</template>
