<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import PanelLayout from '../../Layouts/PanelLayout.vue';

const form = useForm({
    sku: '',
    title: '',
    price: '',
    opening_stock: 0,
    description: '',
    brand: '',
    internal_category_id: '',
    barcode: '',
});

function submit() {
    form.post('/products');
}
</script>

<template>
    <PanelLayout>
        <div>
            <p class="font-mono text-[10px] uppercase tracking-widest text-stone-500">
                Katalog
            </p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900">
                Ürün ekle
            </h1>
            <p class="mt-2 max-w-xl text-sm text-stone-600">
                Açılış stoğu stok defterine bir giriş hareketi olarak işlenir;
                bakiye o hareketten türer. Ürün eklendikten sonra kanallara
                gönderilebilir.
            </p>
        </div>

        <form class="mt-8 max-w-xl space-y-5" @submit.prevent="submit">
            <div>
                <label for="title" class="block text-sm font-medium text-stone-700">
                    Ürün adı
                </label>
                <input
                    id="title"
                    v-model="form.title"
                    type="text"
                    required
                    placeholder="Yün Kazak"
                    class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm focus:border-stone-500 focus:outline-none"
                >
                <p v-if="form.errors.title" class="mt-1 text-sm text-red-700">
                    {{ form.errors.title }}
                </p>
            </div>

            <div>
                <label for="sku" class="block text-sm font-medium text-stone-700">
                    SKU
                </label>
                <input
                    id="sku"
                    v-model="form.sku"
                    type="text"
                    required
                    placeholder="KAZAK-001"
                    class="mt-1 w-full rounded border border-stone-300 px-3 py-2 font-mono text-sm focus:border-stone-500 focus:outline-none"
                >
                <p class="mt-1 text-xs text-stone-500">
                    Kanallarla eşleşmenin anahtarı. Hesabınız içinde tekil olmalı.
                </p>
                <p v-if="form.errors.sku" class="mt-1 text-sm text-red-700">
                    {{ form.errors.sku }}
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
                        required
                        placeholder="249.90"
                        class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm focus:border-stone-500 focus:outline-none"
                    >
                    <p v-if="form.errors.price" class="mt-1 text-sm text-red-700">
                        {{ form.errors.price }}
                    </p>
                </div>

                <div>
                    <label for="opening_stock" class="block text-sm font-medium text-stone-700">
                        Açılış stoğu
                    </label>
                    <input
                        id="opening_stock"
                        v-model.number="form.opening_stock"
                        type="number"
                        min="0"
                        class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm focus:border-stone-500 focus:outline-none"
                    >
                    <p class="mt-1 text-xs text-stone-500">
                        Giriş hareketi olarak işlenir. Negatif olamaz.
                    </p>
                    <p v-if="form.errors.opening_stock" class="mt-1 text-sm text-red-700">
                        {{ form.errors.opening_stock }}
                    </p>
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
                <p v-if="form.errors.description" class="mt-1 text-sm text-red-700">
                    {{ form.errors.description }}
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
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

                <div>
                    <label for="barcode" class="block text-sm font-medium text-stone-700">
                        Barkod
                    </label>
                    <input
                        id="barcode"
                        v-model="form.barcode"
                        type="text"
                        class="mt-1 w-full rounded border border-stone-300 px-3 py-2 font-mono text-sm focus:border-stone-500 focus:outline-none"
                    >
                </div>
            </div>

            <!--
                İç kategori kanal eşleştirmesinin çıpasıdır (§13 · Faz 2).
                Serbest metindir: ayrı bir iç kategori tablosu yoktur ve
                satıcı kendi adlandırmasını kullanır.
            -->
            <div>
                <label for="internal_category_id" class="block text-sm font-medium text-stone-700">
                    İç kategori
                </label>
                <input
                    id="internal_category_id"
                    v-model="form.internal_category_id"
                    type="text"
                    placeholder="Örn. kadin-elbise"
                    class="mt-1 w-full rounded border border-stone-300 px-3 py-2 text-sm focus:border-stone-500 focus:outline-none"
                >
                <p class="mt-1 text-xs text-stone-500">
                    Kendi kategori adınız. Kanalın kategorisine bu ad üzerinden
                    eşleştirilir; aynı adı taşıyan ürünler tek eşleştirmeyi paylaşır.
                </p>
                <p v-if="form.errors.internal_category_id" class="mt-1 text-xs text-red-700">
                    {{ form.errors.internal_category_id }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Ekleniyor…' : 'Ürünü ekle' }}
                </button>

                <Link href="/products" class="text-sm text-stone-600 underline">
                    Vazgeç
                </Link>
            </div>
        </form>
    </PanelLayout>
</template>
